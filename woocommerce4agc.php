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
    }

    public function register_admin_page() {
        add_submenu_page(
            'woocommerce',
            'AGC Integración',
            'AGC Integración',
            'manage_woocommerce',
            'wc4agc-integration',
            [ $this, 'render_admin_page' ]
        );
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
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        echo '<nav class="nav-tab-wrapper wc4agc-tabs" style="margin-bottom:0;">';
        foreach(['dashboard'=>'Panel','settings'=>'Ajustes','logs'=>'Logs','query'=>'Consultas'] as $tab=>$label) {
            $active = ($tab == $current_tab) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url(admin_url('admin.php?page=wc4agc-integration&tab=' . $tab)) . '" class="nav-tab' . $active . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        echo '<div style="margin-top:0;"></div>';

        // Content
        echo '<div class="wc4agc-panel-section">';
        if ($current_tab == 'settings') {
            $this->render_settings_tab();
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
        echo '<div class="wc4agc-dashboard-cards">';
        foreach([
            ['update','Sincronizar stock','sync_stock',Constants::NONCE_SYNC_STOCK,'Obtener stock desde ERP a WooCommerce'],
            ['tag','Sincronizar precios','sync_prices',Constants::NONCE_SYNC_PRICES,'Obtener precios desde ERP a WooCommerce'],
            ['products','Sincronizar productos','sync_products',Constants::NONCE_SYNC_PRODUCTS,'Obtener productos desde ERP a WooCommerce'],
            ['category','Sincronizar categorías','sync_categories',Constants::NONCE_SYNC_CATEGORIES,'Obtener categorías desde ERP a WooCommerce'],
        ] as list($icon,$label,$action,$nonce,$desc)) {
            echo '<div class="wc4agc-dashboard-card">';
            echo '<span class="dashicons dashicons-' . esc_attr($icon) . '"></span>';
            echo '<h3>' . esc_html($label) . '</h3>';
            echo '<p>' . esc_html($desc) . '</p>';
            echo '<form method="post">';
            wp_nonce_field($action, $nonce);
            echo '<input type="hidden" name="' . esc_attr($action) . '" value="1" />';
            submit_button('Ejecutar', 'button-primary', $action, false);
            echo '</form></div>';
        }
        echo '</div>';

        // Procesar acciones
        if (isset($_POST['sync_stock']) && check_admin_referer('sync_stock', Constants::NONCE_SYNC_STOCK)) {
            StockSync::sync_all();
            $this->logger->log('stock', 'Sincronización de stock iniciada manualmente', 'info');
            echo '<div class="updated notice notice-success wc4agc-notice"><p>Stock sincronizado.</p></div>';
        }

        if (isset($_POST['sync_prices']) && check_admin_referer('sync_prices', Constants::NONCE_SYNC_PRICES)) {
            PriceSync::sync_all();
            $this->logger->log('prices', 'Sincronización de precios iniciada manualmente', 'info');
            echo '<div class="updated notice notice-success wc4agc-notice"><p>Precios sincronizados.</p></div>';
        }

        if (isset($_POST['sync_products']) && check_admin_referer('sync_products', Constants::NONCE_SYNC_PRODUCTS)) {
            if (class_exists(ProductSync::class)) {
                ProductSync::sync_all();
                $this->logger->log('products', 'Sincronización de productos iniciada manualmente', 'info');
                echo '<div class="updated notice notice-success wc4agc-notice"><p>Productos sincronizados.</p></div>';
            } else {
                echo '<div class="error notice notice-error wc4agc-notice"><p>Módulo productos no implementado.</p></div>';
            }
        }

        if (isset($_POST['sync_categories']) && check_admin_referer('sync_categories', Constants::NONCE_SYNC_CATEGORIES)) {
            if (class_exists(CategorySync::class)) {
                CategorySync::sync_all();
                $this->logger->log('categories', 'Sincronización de categorías iniciada manualmente', 'info');
                echo '<div class="updated notice notice-success wc4agc-notice"><p>Categorías sincronizadas.</p></div>';
            } else {
                echo '<div class="error notice notice-error wc4agc-notice"><p>Módulo categorías no implementado.</p></div>';
            }
        }
    }

    private function render_settings_tab() {
        echo '<form action="options.php" method="post">';
        settings_fields('wc4agc_settings');
        // Sección ERP
        echo '<div class="wc4agc-settings-section">';
        do_settings_sections('wc4agc-integration');
        echo '</div>';
        // Separador visual
        echo '<div class="wc4agc-separator"></div>';
        // Sección Licencias (solo el título y descripción, los campos ya los imprime do_settings_sections)
        // (No imprimir tabla manual)
        submit_button('Guardar cambios', 'button-primary');
        echo '</form>';
    }

    private function render_logs_tab() {
        echo '<div class="wc4agc-settings-section">';
        $modules = [
            'orders' => 'Pedidos',
            'licenses' => 'Licencias',
            'stock' => 'Stock',
            'prices' => 'Precios',
            'products' => 'Productos',
            'categories' => 'Categorías'
        ];
        $sel = isset($_GET['module']) && isset($modules[$_GET['module']]) ? $_GET['module'] : 'orders';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="wc4agc-integration"/>';
        echo '<input type="hidden" name="tab" value="logs"/>';
        echo '<label>Ver logs de: <select name="module">';
        foreach($modules as $key=>$label) {
            $sel_attr = $key==$sel ? ' selected' : '';
            echo '<option value="' . esc_attr($key) . '"' . $sel_attr . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> ';
        submit_button('Mostrar', 'button-secondary', '', false);
        echo '</form>';
        echo '</div>';
        $logs = $this->logger->get_recent_logs($sel);
        if (empty($logs)) {
            echo '<div class="wc4agc-settings-section">';
            echo '<p>No hay logs para ' . esc_html($modules[$sel]) . '.</p>';
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
            try {
                $product = $erp_client->get_product($sku);
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
                    echo '<div class="notice notice-error wc4agc-notice"><p>No se encontró el producto en el ERP.</p></div>';
                }
            } catch (\Exception $e) {
                echo '<div class="notice notice-error wc4agc-notice"><p>Error al consultar el producto: ' . esc_html($e->getMessage()) . '</p></div>';
            }
        }
        echo '</div>';
        echo '</div>';
    }

    public function cleanup_old_logs() {
        $this->logger->cleanup_old_logs();
    }

    public function handle_order_sync($order_id) {
        \WC4AGC\OrderSync::send_to_erp($order_id);
    }
}

// Initialize plugin
WC4AGC_Plugin::instance();

register_activation_hook(__FILE__, [ Activator::class, 'activate' ]);
register_deactivation_hook(__FILE__, [ Activator::class, 'deactivate' ]);
