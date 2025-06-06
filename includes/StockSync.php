<?php

namespace WC4AGC;

use WC4AGC\Logger;
use WC4AGC\Cache;
use WC4AGC\Constants;

class StockSync {
    private static $logger;
    private static $cache;
    private static $erp_client;

    private static function init() {
        if (!self::$logger) {
            self::$logger = Logger::instance();
        }
        if (!self::$cache) {
            self::$cache = Cache::instance();
        }
        if (!self::$erp_client) {
            self::$erp_client = ERPClient::instance();
        }
    }

    public static function sync_all() {
        self::init();
        $debug = get_option(\WC4AGC\Constants::OPTION_DEBUG_MODE, '0') === '1';
        try {
            self::$logger->log('stock', 'Iniciando sincronización de stock', 'info');
            $products = self::$erp_client->get_products();
            if (!$products || !is_array($products)) {
                $msg = 'No se pudieron obtener los productos del ERP';
                if ($debug) {
                    $msg .= ' | Consulta: get_products()';
                }
                throw new \Exception($msg);
            }
            $updated = 0;
            $skipped = 0;
            $errors = 0;
            foreach ($products as $product) {
                try {
                    $sku = $product[1] ?? null;
                    $stock = isset($product[42]) ? intval($product[42]) : null;
                    if (empty($sku) || $stock === null) {
                        $msg = sprintf('Registro de stock omitido: SKU vacío o stock inválido', $sku ?? 'N/A');
                        if ($debug) {
                            $msg .= ' | Datos: ' . json_encode($product) . ' | Consulta: get_products()';
                        }
                        self::$logger->log('stock', $msg, 'warning');
                        $skipped++;
                        continue;
                    }
                    $product_id = wc_get_product_id_by_sku($sku);
                    if (!$product_id) {
                        $msg = sprintf('Producto SKU %s no encontrado en WooCommerce', $sku);
                        if ($debug) {
                            $msg .= ' | Datos: ' . json_encode($product) . ' | Consulta: get_products()';
                        }
                        self::$logger->log('stock', $msg, 'warning');
                        $skipped++;
                        continue;
                    }
                    $wc_product = wc_get_product($product_id);
                    if (!$wc_product) {
                        $msg = "No se pudo cargar el producto ID: $product_id";
                        if ($debug) {
                            $msg .= ' | SKU: ' . $sku . ' | Datos: ' . json_encode($product) . ' | Consulta: get_products()';
                        }
                        throw new \Exception($msg);
                    }
                    $wc_product->set_stock_quantity($stock);
                    $wc_product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
                    $wc_product->save();
                    self::$cache->delete(self::$cache->generate_key('product_stock', ['sku' => $sku]));
                    self::$logger->log('stock', sprintf('Stock actualizado para SKU %s: %d unidades', $sku, $stock), 'info');
                    $updated++;
                } catch (\Exception $e) {
                    $msg = sprintf('Error al actualizar stock SKU %s: %s', $sku ?? 'N/A', $e->getMessage());
                    if ($debug) {
                        $msg .= isset($product) ? ' | Datos: ' . json_encode($product) . ' | Consulta: get_products()' : '';
                    }
                    self::$logger->log('stock', $msg, 'error');
                    $errors++;
                }
            }
            self::$logger->log('stock', sprintf('Sincronización completada: %d actualizados, %d omitidos, %d errores', $updated, $skipped, $errors), 'info');
            return [
                'success' => true,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors
            ];
        } catch (\Exception $e) {
            $msg = 'Error en sincronización: ' . $e->getMessage();
            if ($debug) {
                $msg .= isset($product) ? ' | Datos: ' . json_encode($product) . ' | Consulta: get_products()' : '';
            }
            self::$logger->log('stock', $msg, 'error');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
} 