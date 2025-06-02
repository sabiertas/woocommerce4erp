<?php
namespace WC4AGC;

class Cache {
    private static $instance = null;
    private $cache_group = 'wc4agc';
    
    private function __construct() {}
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Obtiene un valor del caché
     *
     * @param string $key Clave del caché
     * @return mixed
     */
    public function get($key) {
        return wp_cache_get($key, $this->cache_group);
    }
    
    /**
     * Guarda un valor en el caché
     *
     * @param string $key Clave del caché
     * @param mixed $value Valor a guardar
     * @param int $expiration Tiempo de expiración en segundos
     * @return bool
     */
    public function set($key, $value, $expiration = null) {
        if (null === $expiration) {
            $expiration = Constants::CACHE_EXPIRATION;
        }
        return wp_cache_set($key, $value, $this->cache_group, $expiration);
    }
    
    /**
     * Elimina un valor del caché
     *
     * @param string $key Clave del caché
     * @return bool
     */
    public function delete($key) {
        return wp_cache_delete($key, $this->cache_group);
    }
    
    /**
     * Limpia todo el caché del grupo
     *
     * @return bool
     */
    public function flush() {
        return wp_cache_flush_group($this->cache_group);
    }
    
    /**
     * Genera una clave única para el caché
     *
     * @param string $prefix Prefijo de la clave
     * @param array $params Parámetros adicionales
     * @return string
     */
    public function generate_key($prefix, $params = []) {
        $key = $prefix;
        if (!empty($params)) {
            $key .= '_' . md5(serialize($params));
        }
        return $key;
    }
} 