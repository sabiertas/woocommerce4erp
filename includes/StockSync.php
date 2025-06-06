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
            self::$logger->log('stock', 'Iniciando sincronización de stock (uno a uno por SKU, solo campos necesarios)', 'info');
            $updated = 0;
            $skipped = 0;
            $errors = 0;
            $error_msgs = [];

            $args = [
                'post_type' => ['product', 'product_variation'],
                'posts_per_page' => -1,
                'fields' => 'ids',
            ];
            $products = get_posts($args);
            foreach ($products as $product_id) {
                $wc_product = wc_get_product($product_id);
                if (!$wc_product) continue;
                $sku = $wc_product->get_sku();
                if (empty($sku)) {
                    $skipped++;
                    continue;
                }
                try {
                    // Solo pedir campos necesarios: 1 (SKU), 42 (stock)
                    $erp_product = self::$erp_client->get_product($sku, [1, 42]);
                    if (!$erp_product) {
                        $msg = 'Producto SKU ' . $sku . ' no encontrado en ERP';
                        if ($debug) {
                            $msg .= ' | Consulta: get_product(' . $sku . ')';
                        }
                        self::$logger->log('stock', $msg, 'warning');
                        $skipped++;
                        continue;
                    }
                    $stock = isset($erp_product[42]) ? intval($erp_product[42]) : null;
                    if ($stock === null) {
                        $msg = sprintf('Registro de stock omitido: SKU %s sin stock válido', $sku);
                        if ($debug) {
                            $msg .= ' | Consulta: get_product(' . $sku . ')';
                        }
                        self::$logger->log('stock', $msg, 'warning');
                        $skipped++;
                        continue;
                    }
                    $wc_product->set_stock_quantity($stock);
                    $wc_product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
                    $wc_product->save();
                    self::$cache->delete(self::$cache->generate_key('product_stock', ['sku' => $sku]));
                    self::$logger->log('stock', sprintf('Stock actualizado para SKU %s: %d unidades', $sku, $stock), 'info');
                    $updated++;
                } catch (\Exception $e) {
                    $msg = sprintf('Error al actualizar stock SKU %s: %s', $sku, $e->getMessage());
                    if ($debug) {
                        $msg .= ' | Consulta: get_product(' . $sku . ')';
                    }
                    self::$logger->log('stock', $msg, 'error');
                    $errors++;
                    $error_msgs[] = $msg;
                }
            }
            self::$logger->log('stock', sprintf('Sincronización completada: %d actualizados, %d omitidos, %d errores', $updated, $skipped, $errors), 'info');
            return [
                'success' => true,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors,
                'error_msgs' => $error_msgs
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