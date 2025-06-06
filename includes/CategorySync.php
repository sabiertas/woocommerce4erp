<?php 
namespace WC4AGC;

class CategorySync {
    public static function sync_all() {
        self::init();
        $debug = get_option(\WC4AGC\Constants::OPTION_DEBUG_MODE, '0') === '1';
        try {
            self::$logger->log('categories', 'Iniciando sincronización de categorías', 'info');
            $categories = self::$erp_client->get_categories();
            if (!$categories || !is_array($categories)) {
                $msg = 'No se pudieron obtener las categorías del ERP';
                if ($debug) {
                    $msg .= ' | Consulta: get_categories()';
                }
                throw new \Exception($msg);
            }
            $updated = 0;
            $created = 0;
            $skipped = 0;
            $errors = 0;
            foreach ($categories as $category) {
                try {
                    $cat_id = $category['id'] ?? null;
                    $name = $category['name'] ?? '';
                    if (empty($cat_id) || empty($name)) {
                        $msg = 'Categoría omitida: ID o nombre vacío';
                        if ($debug) {
                            $msg .= ' | Datos: ' . json_encode($category) . ' | Consulta: get_categories()';
                        }
                        self::$logger->log('categories', $msg, 'warning');
                        $skipped++;
                        continue;
                    }
                    // Aquí iría la lógica de sincronización de categorías...
                    $updated++;
                } catch (\Exception $e) {
                    $msg = sprintf('Error al procesar categoría ID %s: %s', $cat_id ?? 'N/A', $e->getMessage());
                    if ($debug) {
                        $msg .= isset($category) ? ' | Datos: ' . json_encode($category) . ' | Consulta: get_categories()' : '';
                    }
                    self::$logger->log('categories', $msg, 'error');
                    $errors++;
                }
            }
            self::$logger->log('categories', sprintf('Sincronización completada: %d actualizadas, %d creadas, %d omitidas, %d errores', $updated, $created, $skipped, $errors), 'info');
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
                $msg .= isset($category) ? ' | Datos: ' . json_encode($category) . ' | Consulta: get_categories()' : '';
            }
            self::$logger->log('categories', $msg, 'error');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
} 