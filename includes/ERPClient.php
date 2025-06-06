<?php 

namespace WC4AGC;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use WC_Logger;
use GuzzleHttp\Exception\GuzzleException;
use WC4AGC\Logger;
use WC4AGC\Cache;
use WC4AGC\Constants;

class ERPClient {
    private static $instance;
    private $http;
    private $endpoint;
    private $api_key;
    private $logger;
    private $cache;
    private $erp_tables;

    private function __construct() {
        $this->endpoint = get_option(Constants::OPTION_ERP_ENDPOINT, '');
        $this->api_key = get_option(Constants::OPTION_ERP_API_KEY, '');
        $this->logger = Logger::instance();
        $this->cache = Cache::instance();
        $this->erp_tables = include __DIR__ . '/erp_tables.php';

        $this->http = new Client([
            'base_uri' => untrailingslashit($this->endpoint) . '/',
            'timeout' => Constants::API_TIMEOUT,
        ]);
    }

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function request($method, $uri, $options = []) {
        try {
            $response = $this->http->request($method, ltrim($uri, '/'), $options);
            $body = $response->getBody()->getContents();
            return json_decode($body, true);
        } catch (RequestException $e) {
            $msg = $e->getMessage();
            if ($e->hasResponse()) {
                $msg .= ' | Response: ' . $e->getResponse()->getBody();
            }
            $this->logger->log('erp', "Error en API: {$msg}", 'error');
            throw new \Exception("Error en API: {$msg}");
        }
    }

    public function getStock($sku, $warehouse = null) {
        $query = ['sku' => $sku];
        if ($warehouse) {
            $query['warehouse'] = $warehouse;
        }
        return $this->request('GET', '/stock', ['query' => $query]);
    }
    
    public function findOrCreateCustomer(array $data) {
        return $this->request('POST', '/customers', ['json' => $data]);
    }
    
    public function createShippingAddress($customerId, array $address) {
        return $this->request('POST', "/customers/{$customerId}/addresses", ['json' => $address]);
    }
    
    public function create_albaran_header(array $fields) {
        $next = $this->request('GET', '/Control', [
            'query' => [
                'Tabla' => 44,
                'accion' => 'INSERT_SIGUIENTE',
            ]
        ]);
        
        if (!isset($next['Numero'])) {
            throw new \Exception('No se pudo obtener el siguiente número de albarán.');
        }
        
        $numero = $next['Numero'];
        $payload = [
            'Tabla' => 44,
            'accion' => 'INSERT',
            'valor' => json_encode(array_merge(['Numero' => $numero], $fields)),
        ];
        
        $this->request('POST', '/Control', ['query' => $payload]);
        return $numero;
    }
    
    public function create_albaran_line($numero, array $fields) {
        $payload = [
            'Tabla' => 14,
            'accion' => 'INSERT',
            'valor' => json_encode(array_merge(['Numero' => $numero], $fields)),
        ];
        return $this->request('POST', '/Control', ['query' => $payload]);
    }

    /**
     * Obtener un producto por SKU
     */
    public function get_product($sku) {
        $table = $this->erp_tables['products'];
        $params = [
            'Tabla'    => $table['table'],
            'Campos'   => json_encode([$table['fields']]),
            'Texto'    => $sku,
            'CamposB'  => $table['search_field'],
            'token'    => $this->api_key,
        ];
        $result = $this->request('GET', 'listado', ['query' => $params]);
        if (!$result || !isset($result['data']) || empty($result['data'])) {
            return false;
        }
        return $result['data'][0];
    }

    /**
     * Obtener todos los productos
     */
    public function get_products() {
        $table = $this->erp_tables['products'];
        $params = [
            'Tabla'    => $table['table'],
            'Campos'   => json_encode([$table['fields']]),
            'Texto'    => '', // Sin filtro, traer todos
            'CamposB'  => $table['search_field'],
            'token'    => $this->api_key,
        ];
        $result = $this->request('GET', 'listado', ['query' => $params]);
        if (!$result || !isset($result['data'])) {
            return [];
        }
        return $result['data'];
    }
} 