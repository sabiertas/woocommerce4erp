<?php
namespace WC4AGC;

class Logger {
    private static $instance = null;
    private $log_dir;
    
    private function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_dir = trailingslashit($upload_dir['basedir']) . Constants::LOG_DIR;
        
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }
    }
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Registra un mensaje en el log
     *
     * @param string $module Nombre del módulo (orders, licenses, etc)
     * @param string $message Mensaje a registrar
     * @param string $level Nivel del log (info, error, warning)
     * @return bool
     */
    public function log($module, $message, $level = 'info') {
        $prefix = 'wc4agc_' . $module;
        $filename = $this->log_dir . '/' . $prefix . '-' . date('Y-m-d') . '.log';
        
        $log_entry = sprintf(
            "[%s] [%s] %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message
        );
        
        return file_put_contents($filename, $log_entry, FILE_APPEND);
    }
    
    /**
     * Obtiene las últimas entradas del log
     *
     * @param string $module Nombre del módulo
     * @param int $lines Número de líneas a obtener
     * @return array
     */
    public function get_recent_logs($module, $lines = 50) {
        $prefix = 'wc4agc_' . $module;
        $pattern = $this->log_dir . '/' . $prefix . '-*.log';
        $files = glob($pattern);
        
        if (empty($files)) {
            return [];
        }
        
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        $latest = $files[0];
        $content = file($latest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        return array_slice($content, -$lines);
    }
    
    /**
     * Limpia logs antiguos
     *
     * @param int $days_to_keep Días a mantener
     * @return void
     */
    public function cleanup_old_logs($days_to_keep = 30) {
        $files = glob($this->log_dir . '/*.log');
        $now = time();
        
        foreach ($files as $file) {
            if (is_file($file)) {
                if ($now - filemtime($file) >= $days_to_keep * 86400) {
                    unlink($file);
                }
            }
        }
    }
} 