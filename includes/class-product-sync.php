<?php

namespace WC4AGC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Servicio de sincronización de productos desde el ERP a WooCommerce.
 */
class WC4AGC_Product_Sync {
	/**
	 * Ejecuta la sincronización completa de productos.
	 */
	public static function sync_all() {
		// TODO: implementa aquí la llamada a ERP_Client::request('/products')
		// y el mapeo/creación/actualización de productos en WooCommerce.
	}
}
