<?php
/**
 * Plugin Name:     WooCommerce4AGC
 * Description:     Integra WooCommerce con el ERP propietario AGC y servicio de licencias.
 * Version:         0.0.1
 * Author:          Ángel Julian
 * Text Domain:     woocommerce4agc
 */

if (!defined('ABSPATH')) {
    exit;
}

// Autoload PSR-4 (Composer)
require __DIR__ . '/vendor/autoload.php';

// Includes base
require_once __DIR__ .'/includes/Constants.php';
require_once __DIR__.'/includes/class-logger.php';
require_once __DIR__.'/includes/class-cache.php';

// Verificar versiones mínimas
if (version_compare(PHP_VERSION, WC4AGC\Constants::MIN_PHP_VERSION, '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>WooCommerce4AGC requiere PHP ' . WC4AGC\Constants::MIN_PHP_VERSION . ' o superior.</p></div>';
    });
    return;
}

if (!class_exists('WooCommerce')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>WooCommerce4AGC requiere WooCommerce activo.</p></div>';
    });
    return;
}

if (version_compare(WC()->version, WC4AGC\Constants::MIN_WC_VERSION, '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>WooCommerce4AGC requiere WooCommerce ' . WC4AGC\Constants::MIN_WC_VERSION . ' o superior.</p></div>';
    });
    return;
}

// Includes del plugin
require_once __DIR__ .'/includes/Activator.php';
require_once __DIR__.'/includes/ERPClient.php';
require_once __DIR__.'/includes/StockSync.php';
require_once __DIR__.'/includes/PriceSync.php';
require_once __DIR__.'/includes/OrderSync.php';
require_once __DIR__.'/includes/LicenseService.php';
require_once __DIR__.'/includes/ProductSync.php';
require_once __DIR__.'/includes/CategorySync.php';

use WC4AGC\StockSync;
use WC4AGC\PriceSync;
use WC4AGC\OrderSync;
use WC4AGC\ProductSync;
use WC4AGC\CategorySync;
use WC4AGC\Logger;
use WC4AGC\Cache;
use WC4AGC\Constants;

final class WC4AGC_Plugin {
    private static $instance;
    private $logger;
    private $cache;

    private function __construct() {
        $this->logger = Logger::instance();
        $this->cache = Cache::instance();

        // Admin hooks
        add_action('admin_menu', [ $this, 'register_admin_page' ]);
        add_action('admin_init', [ $this, 'register_settings' ]);
        add_action('admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ]);

        // Order sync hook
        add_action('woocommerce_order_status_processing', [ OrderSync::class, 'send_to_erp' ], 10, 1 );

        // Cron jobs
        add_action(Constants::CRON_SYNC_STOCK, [ StockSync::class, 'sync_all' ]);
        add_action(Constants::CRON_SYNC_PRICES, [ PriceSync::class, 'sync_all' ]);

        // Limpieza de logs
        add_action('wc4agc_daily_cleanup', [ $this, 'cleanup_old_logs' ]);
        if (!wp_next_scheduled('wc4agc_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wc4agc_daily_cleanup');
        }
    }

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function enqueue_admin_assets($hook) {
        if ('woocommerce_page_wc4agc-integration' !== $hook) {
            return;
        }
        wp_enqueue_style(
            'wc4agc-admin',
            plugins_url('assets/css/admin.css', __FILE__),
            [],
            filemtime(plugin_dir_path(__FILE__) . 'assets/css/admin.css')
        );
        wp_enqueue_script(
            'wc4agc-admin',
            plugins_url('assets/js/admin.js', __FILE__),
            ['jquery'],
            filemtime(plugin_dir_path(__FILE__) . 'assets/js/admin.js'),
            true
        );
        wp_localize_script('wc4agc-admin', 'wc4agc_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc4agc_sync'),
        ]);
    }

    public function register_settings() {
        // ERP API
        register_setting('wc4agc_settings', Constants::OPTION_ERP_ENDPOINT, [
            'sanitize_callback' => 'esc_url_raw',
            'type' => 'string',
            'default' => ''
        ]);
        register_setting('wc4agc_settings', Constants::OPTION_ERP_API_KEY, [
            'sanitize_callback' => 'sanitize_text_field',
            'type' => 'string',
            'default' => ''
        ]);

        add_settings_section(
            'wc4agc_api_section',
            'Configuración API ERP',
            function(){ echo '<p>Datos de conexión al ERP AGC.</p>'; },
            'wc4agc-integration'
        );

        add_settings_field(
            Constants::OPTION_ERP_ENDPOINT,
            'ERP Endpoint',
            function(){
                $v = esc_attr(get_option(Constants::OPTION_ERP_ENDPOINT));
                echo "<input type='url' name='" . Constants::OPTION_ERP_ENDPOINT . "' value='$v' class='regular-text' />";
            },
            'wc4agc-integration',
            'wc4agc_api_section'
        );

        add_settings_field(
            Constants::OPTION_ERP_API_KEY,
            'ERP API Key',
            function(){
                $v = esc_attr(get_option(Constants::OPTION_ERP_API_KEY));
                echo "<input type='text' name='" . Constants::OPTION_ERP_API_KEY . "' value='$v' class='regular-text' />";
            },
            'wc4agc-integration',
            'wc4agc_api_section'
        );

        // Licenses API
        register_setting('wc4agc_settings', Constants::OPTION_LICENSE_ENDPOINT, [
            'sanitize_callback' => 'esc_url_raw',
            'type' => 'string',
            'default' => ''
        ]);
        register_setting('wc4agc_settings', Constants::OPTION_LICENSE_API_KEY, [
            'sanitize_callback' => 'sanitize_text_field',
            'type' => 'string',
            'default' => ''
        ]);

        add_settings_section(
            'wc4agc_license_section',
            'Configuración API Licencias',
            function(){ echo '<p>Datos de conexión al sistema de licencias.</p>'; },
            'wc4agc-integration'
        );

        add_settings_field(
            Constants::OPTION_LICENSE_ENDPOINT,
            'Licencias Endpoint',
            function(){
                $v = esc_attr(get_option(Constants::OPTION_LICENSE_ENDPOINT));
                echo "<input type='url' name='" . Constants::OPTION_LICENSE_ENDPOINT . "' value='$v' class='regular-text' />";
            },
            'wc4agc-integration',
            'wc4agc_license_section'
        );

        add_settings_field(
            Constants::OPTION_LICENSE_API_KEY,
            'Licencias API Key',
            function(){
                $v = esc_attr(get_option(Constants::OPTION_LICENSE_API_KEY));
                echo "<input type='text' name='" . Constants::OPTION_LICENSE_API_KEY . "' value='$v' class='regular-text' />";
            },
            'wc4agc-integration',
            'wc4agc_license_section'
        );

        // Opción de depuración
        register_setting('wc4agc_settings', Constants::OPTION_DEBUG_MODE, [
            'sanitize_callback' => function($v){ return $v === '1' ? '1' : '0'; },
            'type' => 'string',
            'default' => '0'
        ]);

        add_settings_section(
            'wc4agc_debug_section',
            'Depuración y soporte',
            function(){ echo '<p>Activa el modo depuración solo para soporte técnico. Mostrará información detallada en los mensajes de error, incluyendo la consulta enviada al ERP.</p>'; },
            'wc4agc-integration'
        );

        add_settings_field(
            Constants::OPTION_DEBUG_MODE,
            'Modo depuración',
            function(){
                $v = get_option(Constants::OPTION_DEBUG_MODE, '0');
                echo '<label class="wc4agc-switch">';
                echo '<input type="checkbox" name="' . Constants::OPTION_DEBUG_MODE . '" value="1"' . ($v === '1' ? ' checked' : '') . ' />';
                echo '<span class="wc4agc-slider"></span>';
                echo '</label>';
                echo '<span style="margin-left:16px;color:#d32f2f;font-size:0.98em;">No activar en producción salvo para soporte técnico.</span>';
            },
            'wc4agc-integration',
            'wc4agc_debug_section'
        );
    }

    public function register_admin_page() {
        add_submenu_page(
            'woocommerce',
            'WooCommerce4AGC',
            'WooCommerce4AGC',
            'manage_woocommerce',
            'wc4agc-integration',
            [ $this, 'render_admin_page' ]
        );
        // Registrar AJAX
        add_action('wp_ajax_wc4agc_sync_stock', [ $this, 'ajax_sync_stock' ]);
        add_action('wp_ajax_wc4agc_sync_prices', [ $this, 'ajax_sync_prices' ]);
        add_action('wp_ajax_wc4agc_sync_products', [ $this, 'ajax_sync_products' ]);
        add_action('wp_ajax_wc4agc_sync_categories', [ $this, 'ajax_sync_categories' ]);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('No tienes permisos suficientes para acceder a esta página.'));
        }

        echo '<div class="wrap">';
        // Cabecera limpia: solo título y descripción
        echo '<h1>WooCommerce4AGC</h1>';
        echo '<p class="description">Integra WooCommerce con el ERP AGC y gestiona licencias automáticamente.</p>';

        // Tabs
        $tabs = [
            'dashboard' => 'Panel',
            'settings' => 'Ajustes',
            'cronjobs' => 'Cronjobs',
            'logs' => 'Logs',
            'query' => 'Consultas'
        ];
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        echo '<nav class="nav-tab-wrapper wc4agc-tabs" style="margin-bottom:0;">';
        foreach($tabs as $tab=>$label) {
            $active = ($tab == $current_tab) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url(admin_url('admin.php?page=wc4agc-integration&tab=' . $tab)) . '" class="nav-tab' . $active . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        echo '<div style="margin-top:0;"></div>';

        // Content
        echo '<div class="wc4agc-panel-section">';
        if ($current_tab == 'settings') {
            $this->render_settings_tab();
        } elseif ($current_tab == 'cronjobs') {
            $this->render_cronjobs_tab();
        } elseif ($current_tab == 'logs') {
            $this->render_logs_tab();
        } elseif ($current_tab == 'query') {
            $this->render_query_tab();
        } else {
            $this->render_dashboard_tab();
        }
        echo '</div>';
        echo '</div>';
    }

    private function render_dashboard_tab() {
        $debug = get_option(\WC4AGC\Constants::OPTION_DEBUG_MODE, '0') === '1';
        $sync_summary = null;
        echo '<div class="wc4agc-dashboard-cards">';
        foreach ([
            ['update','Sincronizar stock','sync_stock','stock','Obtener stock desde ERP a WooCommerce'],
            ['tag','Sincronizar precios','sync_prices','prices','Obtener precios desde ERP a WooCommerce'],
            ['products','Sincronizar productos','sync_products','products','Obtener productos desde ERP a WooCommerce'],
            ['category','Sincronizar categorías','sync_categories','categories','Obtener categorías desde ERP a WooCommerce'],
        ] as list($icon,$label,$action,$sync_id,$desc)) {
            echo '<div class="wc4agc-dashboard-card" id="wc4agc-card-'.$sync_id.'" style="position:relative;">';
            // Overlay spinner
            echo '<div class="wc4agc-dashboard-overlay" id="wc4agc-overlay-'.$sync_id.'" style="display:none;">';
            echo '<div class="wc4agc-dashboard-spinner active"><svg viewBox="0 0 50 50"><circle cx="25" cy="25" r="20" fill="none" stroke="#FF9800" stroke-width="5" stroke-linecap="round" stroke-dasharray="90 150"/></svg></div>';
            echo '<div style="margin:18px 24px 0 0;font-weight:600;color:#1A237E;font-size:1.08em;">Sincronizando... Esto puede tardar</div>';
            echo '</div>';
            echo '<span class="dashicons dashicons-' . esc_attr($icon) . '"></span>';
            echo '<h3>' . esc_html($label) . '</h3>';
            echo '<p>' . esc_html($desc) . '</p>';
            echo '<button type="button" class="button button-primary wc4agc-sync-btn" data-sync="' . esc_attr($sync_id) . '" id="wc4agc-btn-'.$sync_id.'">Ejecutar</button>';
            echo '<div class="wc4agc-sync-result" id="wc4agc-result-'.$sync_id.'" style="margin-top:12px;"></div>';
            echo '</div>';
        }
        echo '</div>';
    }

    private function render_settings_tab() {
        echo '<form action="options.php" method="post">';
        settings_fields('wc4agc_settings');
        // Sección ERP (manual, sin do_settings_sections global)
        echo '<div class="wc4agc-settings-section">';
        global $wp_settings_sections, $wp_settings_fields;
        $page = 'wc4agc-integration';
        if (isset($wp_settings_sections[$page]['wc4agc_api_section'])) {
            $section = $wp_settings_sections[$page]['wc4agc_api_section'];
            echo '<h2 style="margin-top:0;">' . esc_html($section['title']) . '</h2>';
            if ($section['callback']) call_user_func($section['callback'], $section);
            if (isset($wp_settings_fields[$page]['wc4agc_api_section'])) {
                echo '<table class="form-table">';
                foreach ($wp_settings_fields[$page]['wc4agc_api_section'] as $field) {
                    echo '<tr>';
                    echo '<th scope="row">' . esc_html($field['title']) . '</th>';
                    echo '<td>';
                    call_user_func($field['callback'], $field['id']);
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }
        }
        echo '</div>';
        // Separador visual entre ERP y Licencias
        echo '<div class="wc4agc-separator"></div>';
        // Sección Licencias
        echo '<div class="wc4agc-settings-section">';
        if (isset($wp_settings_sections[$page]['wc4agc_license_section'])) {
            $section = $wp_settings_sections[$page]['wc4agc_license_section'];
            echo '<h2 style="margin-top:0;">' . esc_html($section['title']) . '</h2>';
            if ($section['callback']) call_user_func($section['callback'], $section);
            if (isset($wp_settings_fields[$page]['wc4agc_license_section'])) {
                echo '<table class="form-table">';
                foreach ($wp_settings_fields[$page]['wc4agc_license_section'] as $field) {
                    echo '<tr>';
                    echo '<th scope="row">' . esc_html($field['title']) . '</th>';
                    echo '<td>';
                    call_user_func($field['callback'], $field['id']);
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }
        }
        echo '</div>';
        // Separador visual antes de la sección de depuración
        echo '<div class="wc4agc-separator"></div>';
        // Sección Depuración
        echo '<div class="wc4agc-settings-section">';
        if (isset($wp_settings_sections[$page]['wc4agc_debug_section'])) {
            $section = $wp_settings_sections[$page]['wc4agc_debug_section'];
            echo '<h2 style="margin-top:0;">' . esc_html($section['title']) . '</h2>';
            if ($section['callback']) call_user_func($section['callback'], $section);
            if (isset($wp_settings_fields[$page]['wc4agc_debug_section'])) {
                echo '<table class="form-table">';
                foreach ($wp_settings_fields[$page]['wc4agc_debug_section'] as $field) {
                    echo '<tr>';
                    echo '<th scope="row">' . esc_html($field['title']) . '</th>';
                    echo '<td>';
                    call_user_func($field['callback'], $field['id']);
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }
        }
        echo '</div>';
        // Separador visual antes del botón
        echo '<div class="wc4agc-separator"></div>';
        submit_button('Guardar cambios', 'button-primary');
        echo '</form>';
    }

    private function render_logs_tab() {
        echo '<div class="wc4agc-settings-section" style="display:flex;align-items:center;justify-content:space-between;">';
        $modules = [
            'orders' => 'Pedidos',
            'licenses' => 'Licencias',
            'stock' => 'Stock',
            'prices' => 'Precios',
            'products' => 'Productos',
            'categories' => 'Categorías'
        ];
        $sel = isset($_GET['module']) && isset($modules[$_GET['module']]) ? $_GET['module'] : '';
        echo '<form method="get" style="display:inline-block;margin-right:24px;">';
        echo '<input type="hidden" name="page" value="wc4agc-integration"/>';
        echo '<input type="hidden" name="tab" value="logs"/>';
        echo '<label>Ver logs de: <select name="module">';
        echo '<option value="">Todos</option>';
        foreach($modules as $key=>$label) {
            $sel_attr = $key==$sel ? ' selected' : '';
            echo '<option value="' . esc_attr($key) . '"' . $sel_attr . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> ';
        submit_button('Mostrar', 'button-secondary', '', false);
        echo '</form>';
        // Botón borrar logs alineado a la derecha
        echo '<form method="post" style="margin-left:auto;">';
        echo '<input type="hidden" name="wc4agc_delete_logs" value="1" />';
        echo '<input type="hidden" name="module" value="' . esc_attr($sel) . '" />';
        submit_button('Borrar logs', 'wc4agc-delete-logs', 'delete_logs', false, [ 'onclick' => 'return confirm(\'¿Seguro que quieres borrar los logs?\')' ]);
        echo '</form>';
        echo '</div>';
        // Procesar borrado
        if (isset($_POST['wc4agc_delete_logs']) && current_user_can('manage_woocommerce')) {
            $mod = !empty($_POST['module']) ? sanitize_key($_POST['module']) : null;
            $deleted = $this->logger->delete_logs($mod);
            if ($deleted > 0) {
                echo '<div class="updated notice notice-success wc4agc-notice"><p>Se han borrado ' . esc_html($deleted) . ' archivos de logs.</p></div>';
            } else {
                echo '<div class="notice notice-warning wc4agc-notice"><p>No se encontraron logs para borrar.</p></div>';
            }
        }
        $logs = $this->logger->get_recent_logs($sel ?: 'orders');
        if (empty($logs)) {
            echo '<div class="wc4agc-settings-section">';
            echo '<p>No hay logs para ' . ($sel ? esc_html($modules[$sel]) : 'ningún módulo') . '.</p>';
            echo '</div>';
            return;
        }
        echo '<div class="wc4agc-settings-section">';
        echo '<div class="wc4agc-logs-container">';
        echo '<table class="widefat">';
        echo '<thead><tr><th>Fecha</th><th>Nivel</th><th>Mensaje</th></tr></thead>';
        echo '<tbody>';
        foreach ($logs as $log) {
            if (preg_match('/^\[(.*?)\] \[(.*?)\] (.*)$/', $log, $matches)) {
                echo '<tr>';
                echo '<td>' . esc_html($matches[1]) . '</td>';
                $level = strtolower($matches[2]);
                $badge_class = 'log-level log-level-' . $level;
                echo '<td><span class="' . $badge_class . '">' . esc_html($matches[2]) . '</span></td>';
                echo '<td>' . esc_html($matches[3]) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
    }

    private function render_query_tab() {
        echo '<div class="wc4agc-settings-section">';
        echo '<div class="wc4agc-query-container">';
        echo '<h3>Consultar Producto en ERP</h3>';
        echo '<form method="post" class="wc4agc-query-form">';
        wp_nonce_field('query_product', 'wc4agc_query_nonce');
        echo '<p>';
        echo '<label for="product_sku">SKU del producto:</label><br/>';
        echo '<input type="text" id="product_sku" name="product_sku" class="regular-text" required/>';
        echo '</p>';
        submit_button('Consultar', 'button-primary', 'query_product', false);
        echo '</form>';
        if (isset($_POST['query_product']) && check_admin_referer('query_product', 'wc4agc_query_nonce')) {
            $sku = sanitize_text_field($_POST['product_sku']);
            $erp_client = \WC4AGC\ERPClient::instance();
            $debug = get_option(\WC4AGC\Constants::OPTION_DEBUG_MODE, '0') === '1';
            $endpoint = rtrim(get_option(\WC4AGC\Constants::OPTION_ERP_ENDPOINT, ''), '/');
            $campos = '[[' . implode(',', [0,1,2,3,4,37,38,40,42,46,59,60,61,62,63,64,65,66,67,68,69,74,75,78]) . ']]';
            $params = [
                'Tabla'    => 31,
                'Campos'   => $campos,
                'Texto'    => $sku,
                'CamposB'  => 1,
                'token'    => get_option(\WC4AGC\Constants::OPTION_ERP_API_KEY, ''),
            ];
            $url = $endpoint . '/listado?Tabla=' . $params['Tabla'] . '&Campos=' . $campos . '&Texto=' . urlencode($params['Texto']) . '&CamposB=' . $params['CamposB'] . '&token=' . urlencode($params['token']);
            $api_response = null;
            $api_response_pretty = '';
            try {
                $product = $erp_client->get_product($sku);
                if ($debug) {
                    try {
                        $api_response = wp_remote_get($url);
                        if (is_array($api_response) && isset($api_response['body'])) {
                            $body = $api_response['body'];
                            $json = json_decode($body, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $api_response_pretty = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                            } else {
                                $api_response_pretty = $body;
                            }
                        } else {
                            $api_response_pretty = is_string($api_response) ? $api_response : print_r($api_response, true);
                        }
                    } catch (\Exception $e) {
                        $api_response_pretty = $e->getMessage();
                    }
                }
                if ($product) {
                    echo '<div class="wc4agc-query-results">';
                    echo '<h4>Resultados para SKU: ' . esc_html($sku) . '</h4>';
                    echo '<table class="widefat">';
                    echo '<tbody>';
                    foreach ($product as $key => $value) {
                        echo '<tr>';
                        echo '<th>' . esc_html($key) . '</th>';
                        echo '<td>' . esc_html($value) . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                    echo '</div>';
                } else {
                    echo '<div class="notice notice-error wc4agc-notice"><p>No se encontró el producto en el ERP.</p>';
                    if ($debug) {
                        echo '<pre style="margin-top:10px;font-size:0.98em;background:#fffbe7;border:1px solid #ffe082;padding:12px;border-radius:7px;">Consulta enviada: ' . esc_html(json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "\nURL: " . esc_html($url);
                        if ($api_response_pretty) {
                            echo "\nRespuesta API:\n" . esc_html($api_response_pretty);
                        }
                        echo '</pre>';
                    }
                    echo '</div>';
                }
            } catch (\Exception $e) {
                if ($debug) {
                    try {
                        $api_response = wp_remote_get($url);
                        if (is_array($api_response) && isset($api_response['body'])) {
                            $body = $api_response['body'];
                            $json = json_decode($body, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $api_response_pretty = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                            } else {
                                $api_response_pretty = $body;
                            }
                        } else {
                            $api_response_pretty = is_string($api_response) ? $api_response : print_r($api_response, true);
                        }
                    } catch (\Exception $ex) {
                        $api_response_pretty = $ex->getMessage();
                    }
                }
                echo '<div class="notice notice-error wc4agc-notice"><p>Error al consultar el producto: ' . esc_html($e->getMessage()) . '</p>';
                if ($debug) {
                    echo '<pre style="margin-top:10px;font-size:0.98em;background:#fffbe7;border:1px solid #ffe082;padding:12px;border-radius:7px;">Consulta enviada: ' . esc_html(json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "\nURL: " . esc_html($url);
                    if ($api_response_pretty) {
                        echo "\nRespuesta API:\n" . esc_html($api_response_pretty);
                    }
                    echo '</pre>';
                }
                echo '</div>';
            }
        }
        echo '</div>';
        echo '</div>';
    }

    private function render_cronjobs_tab() {
        // Definición de cronjobs (puedes ampliar este array en el futuro)
        $cronjobs = [
            [
                'id' => 'products',
                'name' => 'Sincronización de productos',
                'desc' => 'Actualiza el catálogo de productos desde el ERP automáticamente.',
                'option_enabled' => 'wc4agc_cron_products_enabled',
                'option_freq' => 'wc4agc_cron_products_freq',
                'option_unit' => 'wc4agc_cron_products_unit',
                'implemented' => true,
                'last_sync' => get_option('wc4agc_cron_products_last', ''),
                'next_sync' => get_option('wc4agc_cron_products_next', ''),
                'last_count' => get_option('wc4agc_cron_products_last_count', ''),
            ],
            // Puedes añadir más cronjobs aquí...
        ];
        $units = [ 'minutes' => 'minutos', 'hours' => 'horas', 'days' => 'días' ];
        echo '<form method="post">';
        echo '<div class="wc4agc-cronjobs-list">';
        foreach ($cronjobs as $cron) {
            $enabled = get_option($cron['option_enabled'], '0') === '1';
            $freq = get_option($cron['option_freq'], '1');
            $unit = get_option($cron['option_unit'], 'hours');
            $is_implemented = $cron['implemented'];
            $class = $is_implemented ? '' : ' style="opacity:0.5;filter:grayscale(0.7);"';
            echo '<div class="wc4agc-cronjob-item"' . $class . '>';
            // Primera línea: nombre, descripción y badge
            echo '<div style="display:flex;align-items:center;gap:16px;">';
            echo '<div style="flex:1;display:flex;align-items:baseline;gap:12px;">';
            echo '<span style="font-size:1.18em;font-weight:700;">' . esc_html($cron['name']) . '</span>';
            echo '<span style="font-size:0.98em;font-weight:400;color:#555;margin-left:0;">' . esc_html($cron['desc']) . '</span>';
            echo '<span class="wc4agc-badge ' . ($enabled ? 'wc4agc-badge-on' : 'wc4agc-badge-off') . '">' . ($enabled ? 'Activado' : 'Desactivado') . '</span>';
            echo '</div>';
            // Switch de activación
            echo '<label class="wc4agc-switch" style="margin-left:18px;">';
            echo '<input type="checkbox" name="cron_enabled[' . esc_attr($cron['id']) . ']" value="1"' . ($enabled ? ' checked' : '') . ($is_implemented ? '' : ' disabled') . ' />';
            echo '<span class="wc4agc-slider"></span>';
            echo '</label>';
            // Frecuencia
            echo '<span style="margin-left:18px;">';
            echo '<input type="number" min="1" max="999" name="cron_freq[' . esc_attr($cron['id']) . ']" value="' . esc_attr($freq) . '" style="width:60px;"' . ($is_implemented ? '' : ' disabled') . ' /> ';
            echo '<select name="cron_unit[' . esc_attr($cron['id']) . ']"' . ($is_implemented ? '' : ' disabled') . '>';
            foreach ($units as $k=>$v) {
                $sel = $unit === $k ? ' selected' : '';
                echo '<option value="' . esc_attr($k) . '"' . $sel . '>' . esc_html($v) . '</option>';
            }
            echo '</select>';
            echo '</span>';
            echo '</div>';
            // Segunda línea: fechas y recuento
            echo '<div style="font-size:0.97em;color:#444;margin-top:6px;">';
            echo 'Última sincronización: <strong>' . ($cron['last_sync'] ? esc_html($cron['last_sync']) : '-') . '</strong> &nbsp;|&nbsp; ';
            echo 'Próxima: <strong>' . ($cron['next_sync'] ? esc_html($cron['next_sync']) : '-') . '</strong> &nbsp;|&nbsp; ';
            echo 'Elementos sincronizados: <strong>' . ($cron['last_count'] !== '' ? esc_html($cron['last_count']) : '-') . '</strong>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        submit_button('Guardar cambios', 'button-primary', 'save_cronjobs');
        echo '</form>';
        // Procesar guardado
        if (isset($_POST['save_cronjobs']) && current_user_can('manage_woocommerce')) {
            foreach ($cronjobs as $cron) {
                $id = $cron['id'];
                // Guardar activación
                $enabled_val = isset($_POST['cron_enabled'][$id]) && $_POST['cron_enabled'][$id] === '1' ? '1' : '0';
                update_option('wc4agc_cron_' . $id . '_enabled', $enabled_val);
                // Guardar frecuencia
                $freq_val = isset($_POST['cron_freq'][$id]) ? max(1, intval($_POST['cron_freq'][$id])) : 1;
                update_option('wc4agc_cron_' . $id . '_freq', $freq_val);
                // Guardar unidad
                $unit_val = isset($_POST['cron_unit'][$id]) && in_array($_POST['cron_unit'][$id], array_keys($units)) ? $_POST['cron_unit'][$id] : 'hours';
                update_option('wc4agc_cron_' . $id . '_unit', $unit_val);
            }
            // Redirigir para evitar reenvío y refrescar valores
            echo '<script>window.location = "' . esc_url_raw(admin_url('admin.php?page=wc4agc-integration&tab=cronjobs')) . '";</script>';
            exit;
        }
    }

    public function cleanup_old_logs() {
        $this->logger->cleanup_old_logs();
    }

    public function handle_order_sync($order_id) {
        \WC4AGC\OrderSync::send_to_erp($order_id);
    }

    // AJAX handlers
    public function ajax_sync_stock() {
        check_ajax_referer('wc4agc_sync', 'nonce');
        $result = \WC4AGC\StockSync::sync_all();
        wp_send_json_success($result);
    }
    public function ajax_sync_prices() {
        check_ajax_referer('wc4agc_sync', 'nonce');
        $result = \WC4AGC\PriceSync::sync_all();
        wp_send_json_success($result);
    }
    public function ajax_sync_products() {
        check_ajax_referer('wc4agc_sync', 'nonce');
        $result = \WC4AGC\ProductSync::sync_all();
        wp_send_json_success($result);
    }
    public function ajax_sync_categories() {
        check_ajax_referer('wc4agc_sync', 'nonce');
        $result = \WC4AGC\CategorySync::sync_all();
        wp_send_json_success($result);
    }
}

// Initialize plugin
WC4AGC_Plugin::instance();

register_activation_hook(__FILE__, [ Activator::class, 'activate' ]);
register_deactivation_hook(__FILE__, [ Activator::class, 'deactivate' ]);
