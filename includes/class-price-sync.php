<?php

namespace WC4AGC;

class WC4AGC_Price_Sync {
	/** Sincroniza tarifas desde ERP a WooCommerce */
	public static function sync_all() {
		$client = ERP_Client::instance();
		$prices = $client->request('GET','/prices');
		if ( ! $prices || empty($prices['data']) ) return;
		foreach ( $prices['data'] as $item ) {
			$sku    = $item['sku'];
			$price  = floatval($item['price']);
			$product_id = wc_get_product_id_by_sku($sku);
			if ( $product_id ) {
				update_post_meta($product_id, '_regular_price', $price);
				update_post_meta($product_id, '_price', $price);
			}
		}
	}
}