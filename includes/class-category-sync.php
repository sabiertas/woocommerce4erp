<?php

namespace WC4AGC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Servicio de sincronización de categorías desde el ERP a WooCommerce.
 */
class WC4AGC_Category_Sync {
	/**
	 * Ejecuta la sincronización completa de categorías.
	 */
	public static function sync_all() {
		// TODO: implementa aquí la llamada a ERP_Client::request('/categories')
		// y la creación/actualización de taxonomías en WooCommerce.
	}
}
