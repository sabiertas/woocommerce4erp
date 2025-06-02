<?php
namespace WC4AGC;

if ( ! class_exists( 'WC4AGC_Activator' ) ) :

class WC4AGC_Activator {

	/**
	 * Activa el plugin
	 */
	public static function activate() {
		// Verificar requisitos
		if (version_compare(PHP_VERSION, Constants::MIN_PHP_VERSION, '<')) {
			deactivate_plugins(plugin_basename(__FILE__));
			wp_die('WooCommerce4AGC requiere PHP ' . Constants::MIN_PHP_VERSION . ' o superior.');
		}

		if (!class_exists('WooCommerce')) {
			deactivate_plugins(plugin_basename(__FILE__));
			wp_die('WooCommerce4AGC requiere WooCommerce activo.');
		}

		if (version_compare(WC()->version, Constants::MIN_WC_VERSION, '<')) {
			deactivate_plugins(plugin_basename(__FILE__));
			wp_die('WooCommerce4AGC requiere WooCommerce ' . Constants::MIN_WC_VERSION . ' o superior.');
		}

		// Crear directorio de logs
		$upload_dir = wp_upload_dir();
		$log_dir = trailingslashit($upload_dir['basedir']) . Constants::LOG_DIR;
		if (!file_exists($log_dir)) {
			wp_mkdir_p($log_dir);
		}

		// Programar tareas cron
		if (!wp_next_scheduled(Constants::CRON_SYNC_STOCK)) {
			wp_schedule_event(time(), 'hourly', Constants::CRON_SYNC_STOCK);
		}
		if (!wp_next_scheduled(Constants::CRON_SYNC_PRICES)) {
			wp_schedule_event(time(), 'hourly', Constants::CRON_SYNC_PRICES);
		}
		if (!wp_next_scheduled('wc4agc_daily_cleanup')) {
			wp_schedule_event(time(), 'daily', 'wc4agc_daily_cleanup');
		}

		// Registrar opciones por defecto
		add_option(Constants::OPTION_ERP_ENDPOINT, '');
		add_option(Constants::OPTION_ERP_API_KEY, '');
		add_option(Constants::OPTION_LICENSE_ENDPOINT, '');
		add_option(Constants::OPTION_LICENSE_API_KEY, '');

		// Registrar versión
		add_option('wc4agc_version', '0.0.1');

		// Log de activación
		$logger = Logger::instance();
		$logger->log('system', 'Plugin activado', 'info');
	}

	/**
	 * Desactiva el plugin
	 */
	public static function deactivate() {
		// Limpiar tareas cron
		wp_clear_scheduled_hook(Constants::CRON_SYNC_STOCK);
		wp_clear_scheduled_hook(Constants::CRON_SYNC_PRICES);
		wp_clear_scheduled_hook('wc4agc_daily_cleanup');

		// Limpiar caché
		$cache = Cache::instance();
		$cache->flush();

		// Log de desactivación
		$logger = Logger::instance();
		$logger->log('system', 'Plugin desactivado', 'info');
	}
}

endif;
