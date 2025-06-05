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
        
        try {
            self::$logger->log('prices', 'Iniciando sincronización de precios', 'info');
            
            // Obtener productos del ERP
            $response = self::$erp_client->get_products();
            
            if (!$response || !isset($response['data'])) {
                throw new \Exception('No se pudieron obtener los productos del ERP');
            }

            $updated = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($response['data'] as $product) {
                try {
                    // Verificar campos requeridos
                    if (empty($product['1']) || empty($product['75']) || floatval($product['75']) <= 0) {
                        self::$logger->log('prices', sprintf(
                            'Producto SKU %s omitido: SKU vacío o precio en dólares inválido',
                            $product['1'] ?? 'N/A'
                        ), 'warning');
                        $skipped++;
                        continue;
                    }

                    $sku = sanitize_text_field($product['1']);
                    $price_usd = floatval($product['75']);
                    $parent_sku = !empty($product['46']) ? sanitize_text_field($product['46']) : null;

                    // Buscar producto por SKU
                    $product_id = wc_get_product_id_by_sku($sku);
                    
                    if (!$product_id) {
                        self::$logger->log('prices', sprintf(
                            'Producto SKU %s no encontrado en WooCommerce',
                            $sku
                        ), 'warning');
                        $skipped++;
                        continue;
                    }

                    $wc_product = wc_get_product($product_id);
                    
                    if (!$wc_product) {
                        throw new \Exception("No se pudo cargar el producto ID: $product_id");
                    }

                    // Si es una variación, verificar el SKU del padre
                    if ($wc_product->is_type('variation') && $parent_sku) {
                        $parent_id = wc_get_product_id_by_sku($parent_sku);
                        if (!$parent_id || $parent_id !== $wc_product->get_parent_id()) {
                            self::$logger->log('prices', sprintf(
                                'Variación SKU %s omitida: SKU padre no coincide',
                                $sku
                            ), 'warning');
                            $skipped++;
                            continue;
                        }
                    }

                    // Actualizar precio
                    $wc_product->set_regular_price($price_usd);
                    $wc_product->save();

                    // Limpiar caché
                    self::$cache->delete(self::$cache->generate_key('product_price', ['sku' => $sku]));

                    self::$logger->log('prices', sprintf(
                        'Precio actualizado para SKU %s: $%.2f',
                        $sku,
                        $price_usd
                    ), 'info');
                    
                    $updated++;

                } catch (\Exception $e) {
                    self::$logger->log('prices', sprintf(
                        'Error al actualizar producto SKU %s: %s',
                        $sku ?? 'N/A',
                        $e->getMessage()
                    ), 'error');
                    $errors++;
                }
            }

            self::$logger->log('prices', sprintf(
                'Sincronización completada: %d actualizados, %d omitidos, %d errores',
                $updated,
                $skipped,
                $errors
            ), 'info');

            return [
                'success' => true,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors
            ];

        } catch (\Exception $e) {
            self::$logger->log('prices', 'Error en sincronización: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
} 