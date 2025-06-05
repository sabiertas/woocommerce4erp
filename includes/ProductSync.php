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
        
        try {
            self::$logger->log('products', 'Iniciando sincronización de productos', 'info');
            
            // Obtener productos del ERP
            $response = self::$erp_client->get_products();
            
            if (!$response || !isset($response['data'])) {
                throw new \Exception('No se pudieron obtener los productos del ERP');
            }

            $updated = 0;
            $created = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($response['data'] as $product) {
                try {
                    // Verificar campos requeridos
                    if (empty($product['1'])) {
                        self::$logger->log('products', 'Producto omitido: SKU vacío', 'warning');
                        $skipped++;
                        continue;
                    }

                    $sku = sanitize_text_field($product['1']);
                    $name = sanitize_text_field($product['2']);
                    $description = sanitize_textarea_field($product['3']);
                    $price = floatval($product['75']);
                    $stock = intval($product['2']);

                    // Buscar producto por SKU
                    $product_id = wc_get_product_id_by_sku($sku);
                    
                    if ($product_id) {
                        // Actualizar producto existente
                        $wc_product = wc_get_product($product_id);
                        
                        if (!$wc_product) {
                            throw new \Exception("No se pudo cargar el producto ID: $product_id");
                        }

                        $wc_product->set_name($name);
                        $wc_product->set_description($description);
                        $wc_product->set_regular_price($price);
                        $wc_product->set_stock_quantity($stock);
                        $wc_product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
                        $wc_product->save();

                        $updated++;
                    } else {
                        // Crear nuevo producto
                        $wc_product = new \WC_Product_Simple();
                        $wc_product->set_name($name);
                        $wc_product->set_description($description);
                        $wc_product->set_sku($sku);
                        $wc_product->set_regular_price($price);
                        $wc_product->set_stock_quantity($stock);
                        $wc_product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
                        $wc_product->save();

                        $created++;
                    }

                    // Limpiar caché
                    self::$cache->delete(self::$cache->generate_key('product', ['sku' => $sku]));

                    self::$logger->log('products', sprintf(
                        'Producto SKU %s procesado: %s',
                        $sku,
                        $product_id ? 'actualizado' : 'creado'
                    ), 'info');

                } catch (\Exception $e) {
                    self::$logger->log('products', sprintf(
                        'Error al procesar producto SKU %s: %s',
                        $sku ?? 'N/A',
                        $e->getMessage()
                    ), 'error');
                    $errors++;
                }
            }

            self::$logger->log('products', sprintf(
                'Sincronización completada: %d actualizados, %d creados, %d omitidos, %d errores',
                $updated,
                $created,
                $skipped,
                $errors
            ), 'info');

            return [
                'success' => true,
                'updated' => $updated,
                'created' => $created,
                'skipped' => $skipped,
                'errors' => $errors
            ];

        } catch (\Exception $e) {
            self::$logger->log('products', 'Error en sincronización: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
} 