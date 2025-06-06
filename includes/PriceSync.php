<?php

namespace WC4AGC;

use WC4AGC\Logger;
use WC4AGC\Cache;
use WC4AGC\Constants;

class PriceSync {
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
            self::$logger->log('prices', 'Iniciando sincronización de precios (uno a uno por SKU, solo campos necesarios)', 'info');
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
                    // Solo pedir campos necesarios: 1 (SKU), 46 (parent_sku), 75 (precio USD)
                    $erp_product = self::$erp_client->get_product($sku, [1, 46, 75]);
                    if (!$erp_product) {
                        $msg = 'Producto SKU ' . $sku . ' no encontrado en ERP';
                        if ($debug) {
                            $msg .= ' | Consulta: get_product(' . $sku . ')';
                        }
                        self::$logger->log('prices', $msg, 'warning');
                        $skipped++;
                        continue;
                    }
                    $price_usd = isset($erp_product[75]) ? floatval($erp_product[75]) : null;
                    $parent_sku = $erp_product[46] ?? null;
                    if ($price_usd === null || $price_usd <= 0) {
                        $msg = sprintf('Producto SKU %s omitido: precio en dólares inválido', $sku);
                        if ($debug) {
                            $msg .= ' | Consulta: get_product(' . $sku . ')';
                        }
                        self::$logger->log('prices', $msg, 'warning');
                        $skipped++;
                        continue;
                    }
                    if ($wc_product->is_type('variation') && $parent_sku) {
                        $parent_id = wc_get_product_id_by_sku($parent_sku);
                        if (!$parent_id || $parent_id !== $wc_product->get_parent_id()) {
                            $msg = sprintf('Variación SKU %s omitida: SKU padre no coincide', $sku);
                            if ($debug) {
                                $msg .= ' | Consulta: get_product(' . $sku . ')';
                            }
                            self::$logger->log('prices', $msg, 'warning');
                            $skipped++;
                            continue;
                        }
                    }
                    $wc_product->set_regular_price($price_usd);
                    $wc_product->save();
                    self::$cache->delete(self::$cache->generate_key('product_price', ['sku' => $sku]));
                    self::$logger->log('prices', sprintf('Precio actualizado para SKU %s: $%.2f', $sku, $price_usd), 'info');
                    $updated++;
                } catch (\Exception $e) {
                    $msg = sprintf('Error al actualizar producto SKU %s: %s', $sku, $e->getMessage());
                    if ($debug) {
                        $msg .= ' | Consulta: get_product(' . $sku . ')';
                    }
                    self::$logger->log('prices', $msg, 'error');
                    $errors++;
                    $error_msgs[] = $msg;
                }
            }
            self::$logger->log('prices', sprintf('Sincronización completada: %d actualizados, %d omitidos, %d errores', $updated, $skipped, $errors), 'info');
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
            self::$logger->log('prices', $msg, 'error');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
} 