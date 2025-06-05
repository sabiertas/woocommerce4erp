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
        
        try {
            self::$logger->log('stock', 'Iniciando sincronización de stock', 'info');
            
            // Obtener stock del ERP
            $response = self::$erp_client->getStock();
            
            if (!$response || !isset($response['data'])) {
                throw new \Exception('No se pudo obtener el stock del ERP');
            }

            $updated = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($response['data'] as $stock) {
                try {
                    // Verificar campos requeridos
                    if (empty($stock['1']) || !isset($stock['2'])) {
                        self::$logger->log('stock', sprintf(
                            'Registro de stock omitido: SKU vacío o stock inválido',
                            $stock['1'] ?? 'N/A'
                        ), 'warning');
                        $skipped++;
                        continue;
                    }

                    $sku = sanitize_text_field($stock['1']);
                    $quantity = intval($stock['2']);

                    // Buscar producto por SKU
                    $product_id = wc_get_product_id_by_sku($sku);
                    
                    if (!$product_id) {
                        self::$logger->log('stock', sprintf(
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

                    // Actualizar stock
                    $wc_product->set_stock_quantity($quantity);
                    $wc_product->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
                    $wc_product->save();

                    // Limpiar caché
                    self::$cache->delete(self::$cache->generate_key('product_stock', ['sku' => $sku]));

                    self::$logger->log('stock', sprintf(
                        'Stock actualizado para SKU %s: %d unidades',
                        $sku,
                        $quantity
                    ), 'info');
                    
                    $updated++;

                } catch (\Exception $e) {
                    self::$logger->log('stock', sprintf(
                        'Error al actualizar stock SKU %s: %s',
                        $sku ?? 'N/A',
                        $e->getMessage()
                    ), 'error');
                    $errors++;
                }
            }

            self::$logger->log('stock', sprintf(
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
            self::$logger->log('stock', 'Error en sincronización: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
} 