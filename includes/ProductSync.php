<?php

namespace WC4AGC;

use WC4AGC\Logger;
use WC4AGC\Cache;
use WC4AGC\Constants;

class ProductSync {
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
            self::$logger->log('products', 'Iniciando sincronización de productos', 'info');
            $products = self::$erp_client->get_products();
            if (!$products || !is_array($products)) {
                $msg = 'No se pudieron obtener los productos del ERP';
                if ($debug) {
                    $msg .= ' | Consulta: get_products()';
                }
                throw new \Exception($msg);
            }
            $updated = 0;
            $created = 0;
            $skipped = 0;
            $errors = 0;
            foreach ($products as $product) {
                try {
                    $sku = $product[1] ?? null;
                    $name = $product[2] ?? '';
                    $description = $product[3] ?? '';
                    $price = isset($product[75]) ? floatval($product[75]) : 0;
                    $stock = isset($product[42]) ? intval($product[42]) : null;
                    $product_type = $product[78] ?? 100;
                    if (empty($sku)) {
                        $msg = 'Producto omitido: SKU vacío';
                        if ($debug) {
                            $msg .= ' | Datos: ' . json_encode($product) . ' | Consulta: get_products()';
                        }
                        self::$logger->log('products', $msg, 'warning');
                        $skipped++;
                        continue;
                    }
                    $product_id = wc_get_product_id_by_sku($sku);
                    if ($product_id) {
                        $wc_product = wc_get_product($product_id);
                        if (!$wc_product) {
                            $msg = "No se pudo cargar el producto ID: $product_id";
                            if ($debug) {
                                $msg .= ' | SKU: ' . $sku . ' | Datos: ' . json_encode($product) . ' | Consulta: get_products()';
                            }
                            throw new \Exception($msg);
                        }
                        $wc_product->set_name($name);
                        $wc_product->set_description($description);
                        $wc_product->set_regular_price($price);
                        if ($stock !== null) {
                            $wc_product->set_stock_quantity($stock);
                            $wc_product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
                        }
                        $wc_product->save();
                        $updated++;
                    } else {
                        $wc_product = new \WC_Product_Simple();
                        $wc_product->set_name($name);
                        $wc_product->set_description($description);
                        $wc_product->set_sku($sku);
                        $wc_product->set_regular_price($price);
                        if ($stock !== null) {
                            $wc_product->set_stock_quantity($stock);
                            $wc_product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
                        }
                        $wc_product->save();
                        $created++;
                    }
                    self::$cache->delete(self::$cache->generate_key('product', ['sku' => $sku]));
                    self::$logger->log('products', sprintf('Producto SKU %s procesado: %s', $sku, $product_id ? 'actualizado' : 'creado'), 'info');
                } catch (\Exception $e) {
                    $msg = sprintf('Error al procesar producto SKU %s: %s', $sku ?? 'N/A', $e->getMessage());
                    if ($debug) {
                        $msg .= isset($product) ? ' | Datos: ' . json_encode($product) . ' | Consulta: get_products()' : '';
                    }
                    self::$logger->log('products', $msg, 'error');
                    $errors++;
                }
            }
            self::$logger->log('products', sprintf('Sincronización completada: %d actualizados, %d creados, %d omitidos, %d errores', $updated, $created, $skipped, $errors), 'info');
            return [
                'success' => true,
                'updated' => $updated,
                'created' => $created,
                'skipped' => $skipped,
                'errors' => $errors
            ];
        } catch (\Exception $e) {
            $msg = 'Error en sincronización: ' . $e->getMessage();
            if ($debug) {
                $msg .= isset($product) ? ' | Datos: ' . json_encode($product) . ' | Consulta: get_products()' : '';
            }
            self::$logger->log('products', $msg, 'error');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
} 