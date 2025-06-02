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

// Autoload PSR-4 (Composer)
require __DIR__ . '/vendor/autoload.php';

use WC4AGC\WC4AGC_Stock_Sync;
use WC4AGC\WC4AGC_Price_Sync;
use WC4AGC\WC4AGC_Order_Sync;
use WC4AGC\WC4AGC_Product_Sync;
use WC4AGC\WC4AGC_Category_Sync;
use WC4AGC\Logger;
use WC4AGC\Cache;
use WC4AGC\Constants;

// Includes
require_once __DIR__ .'/includes/class-activator.php';
require_once __DIR__.'/includes/class-erp-client.php';
require_once __DIR__.'/includes/class-stock-sync.php';
require_once __DIR__.'/includes/class-price-sync.php';
require_once __DIR__.'/includes/class-order-sync.php';
require_once __DIR__.'/includes/class-license-service.php';
require_once __DIR__.'/includes/class-product-sync.php';
require_once __DIR__.'/includes/class-category-sync.php';
require_once __DIR__.'/includes/class-constants.php';
require_once __DIR__.'/includes/class-logger.php';
require_once __DIR__.'/includes/class-cache.php';

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
        add_action('woocommerce_order_status_processing', [ WC4AGC_Order_Sync::class, 'send_to_erp' ], 10, 1 );

        // Cron jobs
        add_action(Constants::CRON_SYNC_STOCK, [ WC4AGC_Stock_Sync::class, 'sync_all' ]);
        add_action(Constants::CRON_SYNC_PRICES, [ WC4AGC_Price_Sync::class, 'sync_all' ]);

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
        echo '<h1>WooCommerce4AGC</h1>';
        echo '<p class="description">Integra WooCommerce con el ERP AGC y gestiona licencias automáticamente.</p>';

        // Tabs
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        echo '<h2 class="nav-tab-wrapper">';
        foreach(['dashboard'=>'Panel','settings'=>'Ajustes','logs'=>'Logs'] as $tab=>$label) {
            $active = ($tab == $current_tab) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url(admin_url('admin.php?page=wc4agc-integration&tab=' . $tab)) . '" class="nav-tab' . $active . '">' . esc_html($label) . '</a>';
        }
        echo '</h2><div style="margin-top:20px;"></div>';

        // Content
        if ($current_tab == 'settings') {
            $this->render_settings_tab();
        } elseif ($current_tab == 'logs') {
            $this->render_logs_tab();
        } else {
            $this->render_dashboard_tab();
        }
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
            submit_button('Ejecutar', 'secondary', $action, false);
            echo '</form></div>';
        }
        echo '</div>';

        // Procesar acciones
        if (isset($_POST['sync_stock']) && check_admin_referer('sync_stock', Constants::NONCE_SYNC_STOCK)) {
            WC4AGC_Stock_Sync::sync_all();
            $this->logger->log('stock', 'Sincronización de stock iniciada manualmente', 'info');
            echo '<div class="updated"><p>Stock sincronizado.</p></div>';
        }

        if (isset($_POST['sync_prices']) && check_admin_referer('sync_prices', Constants::NONCE_SYNC_PRICES)) {
            WC4AGC_Price_Sync::sync_all();
            $this->logger->log('prices', 'Sincronización de precios iniciada manualmente', 'info');
            echo '<div class="updated"><p>Precios sincronizados.</p></div>';
        }

        if (isset($_POST['sync_products']) && check_admin_referer('sync_products', Constants::NONCE_SYNC_PRODUCTS)) {
            if (class_exists(WC4AGC_Product_Sync::class)) {
                WC4AGC_Product_Sync::sync_all();
                $this->logger->log('products', 'Sincronización de productos iniciada manualmente', 'info');
                echo '<div class="updated"><p>Productos sincronizados.</p></div>';
            } else {
                echo '<div class="error"><p>Módulo productos no implementado.</p></div>';
            }
        }

        if (isset($_POST['sync_categories']) && check_admin_referer('sync_categories', Constants::NONCE_SYNC_CATEGORIES)) {
            if (class_exists(WC4AGC_Category_Sync::class)) {
                WC4AGC_Category_Sync::sync_all();
                $this->logger->log('categories', 'Sincronización de categorías iniciada manualmente', 'info');
                echo '<div class="updated"><p>Categorías sincronizadas.</p></div>';
            } else {
                echo '<div class="error"><p>Módulo categorías no implementado.</p></div>';
            }
        }
    }

    private function render_settings_tab() {
        echo '<form action="options.php" method="post">';
        settings_fields('wc4agc_settings');
        do_settings_sections('wc4agc-integration');
        submit_button('Guardar cambios');
        echo '</form>';
    }

    private function render_logs_tab() {
        $modules = ['orders'=>'wc4agc_order','licenses'=>'wc4agc_license'];
        $sel = isset($_GET['module']) && isset($modules[$_GET['module']]) ? $_GET['module'] : 'orders';
        
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="wc4agc-integration"/>';
        echo '<input type="hidden" name="tab" value="logs"/>';
        echo '<label>Ver logs de: <select name="module">';
        foreach($modules as $key=>$prefix) {
            $sel_attr = $key==$sel ? ' selected' : '';
            echo '<option value="' . esc_attr($key) . '"' . $sel_attr . '>' . esc_html(ucfirst($key)) . '</option>';
        }
        echo '</select></label> ';
        submit_button('Mostrar', 'secondary', '', false);
        echo '</form>';

        $logs = $this->logger->get_recent_logs($sel);
        if (empty($logs)) {
            echo '<p>No hay logs para ' . esc_html($sel) . '.</p>';
            return;
        }

        echo '<div class="wc4agc-logs-container">';
        echo '<pre>' . esc_html(implode("\n", $logs)) . '</pre>';
        echo '</div>';
    }

    public function cleanup_old_logs() {
        $this->logger->cleanup_old_logs();
    }

    public function handle_order_sync($order_id) {
        WC4AGC_Order_Sync::send_to_erp($order_id);
    }
}

// Initialize plugin
WC4AGC_Plugin::instance();

register_activation_hook(__FILE__, [ 'WC4AGC_Activator', 'activate' ]);
register_deactivation_hook(__FILE__, [ 'WC4AGC_Activator', 'deactivate' ]);
