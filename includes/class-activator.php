<?php
if ( ! class_exists( 'WC4AGC_Activator' ) ) :

class WC4AGC_Activator {

	/**
	 * Ejecutado en la activación del plugin.
	 * Programa los crons para sincronización de stock y precios.
	 */
	public static function activate() {
		// Programar sincronización de stock cada hora
		if ( ! wp_next_scheduled( 'wc4agc_sync_stock_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'wc4agc_sync_stock_cron' );
		}
		// Programar sincronización de precios cada día
		if ( ! wp_next_scheduled( 'wc4agc_sync_prices_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'wc4agc_sync_prices_cron' );
		}
	}

	/**
	 * Ejecutado en la desactivación del plugin.
	 * Elimina los hooks programados.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'wc4agc_sync_stock_cron' );
		wp_clear_scheduled_hook( 'wc4agc_sync_prices_cron' );
	}
}

endif;
