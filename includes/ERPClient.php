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

    private function __construct() {
        $this->endpoint = get_option(Constants::OPTION_ERP_ENDPOINT, '');
        $this->api_key = get_option(Constants::OPTION_ERP_API_KEY, '');
        $this->logger = Logger::instance();
        $this->cache = Cache::instance();

        $this->http = new Client([
            'base_uri' => untrailingslashit($this->endpoint) . '/',
            'timeout' => Constants::API_TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept' => 'application/json',
            ],
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

    public function get_product($sku) {
        try {
            $response = $this->request('GET', '/products', [
                'query' => [
                    'sku' => $sku
                ]
            ]);
            
            if (!$response || !isset($response['data']) || empty($response['data'])) {
                return false;
            }

            return $response['data'][0];

        } catch (\Exception $e) {
            $this->logger->log('erp', 'Error al obtener producto: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }

    public function get_products() {
        $cache_key = $this->cache->generate_key('erp_products');
        
        // Intentar obtener del caché
        $cached = $this->cache->get($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        try {
            $response = $this->http->get('/products', [
                'query' => [
                    'fields' => '1,46,75' // SKU, parent_sku, precio_dolares
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Error en la respuesta del ERP: ' . $response->getStatusCode());
            }

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);
            
            if (!$data || !isset($data['data'])) {
                throw new \Exception('Formato de respuesta inválido del ERP');
            }

            // Guardar en caché
            $this->cache->set($cache_key, $data, Constants::CACHE_EXPIRATION);
            
            return $data;

        } catch (GuzzleException $e) {
            $this->logger->log('erp', 'Error al obtener productos: ' . $e->getMessage(), 'error');
            throw new \Exception('Error al conectar con el ERP: ' . $e->getMessage());
        } catch (\Exception $e) {
            $this->logger->log('erp', 'Error al procesar respuesta: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }
} 