<?php
namespace WC4AGC;

class Constants {
    // Versiones mínimas
    const MIN_PHP_VERSION = '7.4';
    const MIN_WC_VERSION = '5.0.0';
    
    // Nombres de opciones
    const OPTION_ERP_ENDPOINT = 'wc4agc_erp_endpoint';
    const OPTION_ERP_API_KEY = 'wc4agc_erp_api_key';
    const OPTION_LICENSE_ENDPOINT = 'wc4agc_license_endpoint';
    const OPTION_LICENSE_API_KEY = 'wc4agc_license_api_key';
    const OPTION_DEBUG_MODE = 'wc4agc_debug_mode';
    
    // Nombres de nonces
    const NONCE_SYNC_STOCK = 'wc4agc_sync_stock';
    const NONCE_SYNC_PRICES = 'wc4agc_sync_prices';
    const NONCE_SYNC_PRODUCTS = 'wc4agc_sync_products';
    const NONCE_SYNC_CATEGORIES = 'wc4agc_sync_categories';
    
    // Nombres de acciones
    const ACTION_SYNC_STOCK = 'wc4agc_sync_stock';
    const ACTION_SYNC_PRICES = 'wc4agc_sync_prices';
    const ACTION_SYNC_PRODUCTS = 'wc4agc_sync_products';
    const ACTION_SYNC_CATEGORIES = 'wc4agc_sync_categories';
    
    // Nombres de cron jobs
    const CRON_SYNC_STOCK = 'wc4agc_sync_stock';
    const CRON_SYNC_PRICES = 'wc4agc_sync_prices';
    
    // Configuración de API
    const API_TIMEOUT = 30;
    const API_RETRY_ATTEMPTS = 3;
    const API_RETRY_DELAY = 5;
    
    // Configuración de caché
    const CACHE_EXPIRATION = 3600; // 1 hora
    const CACHE_PREFIX = 'wc4agc_';
    
    // Configuración de logs
    const LOG_DIR = 'wc4agc-logs';
    const LOG_RETENTION_DAYS = 30;
    const LOG_PREFIX = 'wc4agc_';
} 