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
            self::$logger->log('products', 'Iniciando sincronización de productos (uno a uno por SKU)', 'info');
            $updated = 0;
            $created = 0;
            $skipped = 0;
            $errors = 0;
            $error_msgs = [];

            // Obtener todos los productos y variaciones de WooCommerce
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
                    $erp_product = self::$erp_client->get_product($sku);
                    if (!$erp_product) {
                        $msg = 'Producto SKU ' . $sku . ' no encontrado en ERP';
                        if ($debug) {
                            $msg .= ' | Consulta: get_product(' . $sku . ')';
                        }
                        self::$logger->log('products', $msg, 'warning');
                        $skipped++;
                        continue;
                    }
                    // Solo campos necesarios
                    $name = $erp_product[2] ?? '';
                    $description = $erp_product[3] ?? '';
                    $price = isset($erp_product[75]) ? floatval($erp_product[75]) : 0;
                    $stock = isset($erp_product[42]) ? intval($erp_product[42]) : null;
                    $product_type = $erp_product[78] ?? 100;

                    $wc_product->set_name($name);
                    $wc_product->set_description($description);
                    $wc_product->set_regular_price($price);
                    if ($stock !== null) {
                        $wc_product->set_stock_quantity($stock);
                        $wc_product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
                    }
                    $wc_product->save();
                    self::$cache->delete(self::$cache->generate_key('product', ['sku' => $sku]));
                    self::$logger->log('products', sprintf('Producto SKU %s actualizado desde ERP', $sku), 'info');
                    $updated++;
                } catch (\Exception $e) {
                    $msg = sprintf('Error al procesar producto SKU %s: %s', $sku, $e->getMessage());
                    if ($debug) {
                        $msg .= ' | Consulta: get_product(' . $sku . ')';
                    }
                    self::$logger->log('products', $msg, 'error');
                    $errors++;
                    $error_msgs[] = $msg;
                }
            }
            self::$logger->log('products', sprintf('Sincronización completada: %d actualizados, %d omitidos, %d errores', $updated, $skipped, $errors), 'info');
            return [
                'success' => true,
                'updated' => $updated,
                'created' => $created,
                'skipped' => $skipped,
                'errors' => $errors,
                'error_msgs' => $error_msgs
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