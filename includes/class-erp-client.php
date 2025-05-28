<?php 

namespace WC4AGC;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use WC_Logger;

if ( ! class_exists( 'ERP_Client' ) ) :
	
class ERP_Client {
	private static $instance;
	private $http;
	private $endpoint;
	private $api_key;
	private $logger;

	private function __construct() {
		$this->endpoint = get_option( 'wc4agc_erp_endpoint', '' );
		$this->api_key  = get_option( 'wc4agc_erp_api_key', '' );
		$this->logger   = new WC_Logger();

		$this->http = new Client([
			'base_uri' => untrailingslashit( $this->endpoint ) . '/',
			'timeout'  => 30,
			'headers'  => [
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => 'application/json',
			],
		]);
	}

	/**
	 * Singleton access
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Low-level request wrapper
	 */
	private function request( $method, $uri, $options = [] ) {
		try {
			$response = $this->http->request( $method, ltrim( $uri, '/' ), $options );
			$body     = $response->getBody()->getContents();
			return json_decode( $body, true );
		} catch ( RequestException $e ) {
			$msg = $e->getMessage();
			if ( $e->hasResponse() ) {
				$msg .= ' | Response: ' . $e->getResponse()->getBody();
			}
			$this->logger->error( "ERP API error: {$msg}", [
				'method'  => $method,
				'uri'     => $uri,
				'options' => $options,
			] );
			return false;
		}
	}

	/**
	 * Obtener stock
	 */
	public function getStock( $sku, $warehouse = null ) {
		$query = [ 'sku' => $sku ];
		if ( $warehouse ) {
			$query['warehouse'] = $warehouse;
		}
		return $this->request( 'GET', '/stock', [ 'query' => $query ] );
	}
	
	/**
	 * Crear o buscar cliente
	 */
	public function findOrCreateCustomer( array $data ) {
		return $this->request( 'POST', '/customers', [ 'json' => $data ] );
	}
	
	/**
	 * Crear dirección de envío
	 */
	public function createShippingAddress( $customerId, array $address ) {
		return $this->request( 'POST', "/customers/{$customerId}/addresses", [ 'json' => $address ] );
	}
	
	/* ----------------------------------------------------
	   MÉTODOS PARA CREAR ALBARANES (ENTREGA)
	   ---------------------------------------------------- */
	
	/**
	 * Crea la cabecera de un albarán en la tabla 44
	 * Devuelve el número interno que ha generado el ERP.
	 */
	public function create_albaran_header( array $fields ) {
		// 1) Pedimos siguiente número libre
		$next = $this->request( 'GET', '/Control', [
			'query' => [
				'Tabla'  => 44,
				'accion' => 'INSERT_SIGUIENTE',
			]
		] );
		if ( ! isset( $next['Numero'] ) ) {
			throw new \Exception( 'No se pudo obtener el siguiente número de albarán.' );
		}
		$numero = $next['Numero'];
	
		// 2) Insertamos cabecera
		$payload = [
			'Tabla'  => 44,
			'accion' => 'INSERT',
			'valor'  => json_encode( array_merge( [ 'Numero' => $numero ], $fields ) ),
		];
		$this->request( 'POST', '/Control', [ 'query' => $payload ] );
	
		return $numero;
	}
	
	/**
	 * Crea una línea de albarán en la tabla 14, asociada al albarán $numero
	 */
	public function create_albaran_line( $numero, array $fields ) {
		$payload = [
			'Tabla'  => 14,
			'accion' => 'INSERT',
			'valor'  => json_encode( array_merge( [ 'Numero' => $numero ], $fields ) ),
		];
		return $this->request( 'POST', '/Control', [ 'query' => $payload ] );
	}
	
	/**
	 * (Opcional) Obtener producto por SKU
	 * Para mapear SKU Woo → referencia interna ERP (Ref1)
	 */
	public function getProductBySku( $sku ) {
		return $this->request( 'GET', '/products', [ 'query' => [ 'sku' => $sku ] ] );
	}
}

endif;
