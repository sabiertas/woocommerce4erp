<?php
/**
 * Plugin Name:     WooCommerce4AGC
 * Description:     Integra WooCommerce con el ERP propietario AGC y servicio de licencias.
 * Version:         0.0.1
 * Author:          Ángel Julian
 * Text Domain:     woocommerce4agc
 */

if ( ! defined( 'ABSPATH' ) ) {
	 exit;
 }
 
 // Autoload PSR-4 (Composer)
 require __DIR__ . '/vendor/autoload.php';
 
 use WC4AGC\WC4AGC_Stock_Sync;
 use WC4AGC\WC4AGC_Price_Sync;
 use WC4AGC\WC4AGC_Order_Sync;
 use WC4AGC\WC4AGC_Product_Sync;
 use WC4AGC\WC4AGC_Category_Sync;
 
 // Includes
 require_once __DIR__ .'/includes/class-activator.php';      // Activation/deactivation logic
 require_once __DIR__.'/includes/class-erp-client.php';
 require_once __DIR__.'/includes/class-stock-sync.php';
 require_once __DIR__.'/includes/class-price-sync.php';
 require_once __DIR__.'/includes/class-order-sync.php';
 require_once __DIR__.'/includes/class-license-service.php';
 require_once __DIR__.'/includes/class-product-sync.php';
 require_once __DIR__.'/includes/class-category-sync.php';
 
 final class WC4AGC_Plugin {
	 private static $instance;
 
	 private function __construct() {
		 // Admin hooks
		 add_action( 'admin_menu',            [ $this, 'register_admin_page' ] );
		 add_action( 'admin_init',            [ $this, 'register_settings' ] );
		 add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
 
		 // **Order sync hook moved from standalone function to class method**
		 add_action( 'woocommerce_order_status_processing', [ WC4AGC_Order_Sync::class, 'send_to_erp' ], 10, 1 );
 
		 // Cron jobs
		 add_action( 'wc4agc_sync_stock_cron',  [ WC4AGC_Stock_Sync::class,  'sync_all' ] );
		 add_action( 'wc4agc_sync_prices_cron', [ WC4AGC_Price_Sync::class,  'sync_all' ] );
	 }
 
	 public static function instance() {
		 if ( null === self::$instance ) {
			 self::$instance = new self();
		 }
		 return self::$instance;
	 }
 
	 public function enqueue_admin_assets( $hook ) {
		 if ( 'woocommerce_page_wc4agc-integration' !== $hook ) {
			 return;
		 }
		 $css = "
			 .wc4agc-dashboard-cards { display:flex; gap:20px; margin-bottom:20px; }
			 .wc4agc-dashboard-card { flex:1; background:#fff; border:1px solid #ddd; padding:20px; text-align:center; border-radius:4px; }
			 .wc4agc-dashboard-card .dashicons { font-size:32px; margin-bottom:10px; }
			 .wc4agc-dashboard-card p { margin-top:8px; font-size:13px; color:#555; }
		 ";
		 wp_add_inline_style( 'wp-admin', $css );
	 }
 
	 public function register_settings() {
		 // ERP API
		 register_setting( 'wc4agc_settings', 'wc4agc_erp_endpoint', [ 'sanitize_callback' => 'esc_url_raw' ] );
		 register_setting( 'wc4agc_settings', 'wc4agc_erp_api_key',  [ 'sanitize_callback' => 'sanitize_text_field' ] );
		 add_settings_section(
			 'wc4agc_api_section',
			 'Configuración API ERP',
			 function(){ echo '<p>Datos de conexión al ERP AGC.</p>'; },
			 'wc4agc-integration'
		 );
		 add_settings_field(
			 'wc4agc_erp_endpoint', 'ERP Endpoint',
			 function(){ $v = esc_attr( get_option('wc4agc_erp_endpoint') ); echo "<input type='url' name='wc4agc_erp_endpoint' value='$v' class='regular-text' />"; },
			 'wc4agc-integration','wc4agc_api_section'
		 );
		 add_settings_field(
			 'wc4agc_erp_api_key', 'ERP API Key',
			 function(){ $v = esc_attr( get_option('wc4agc_erp_api_key') ); echo "<input type='text' name='wc4agc_erp_api_key' value='$v' class='regular-text' />"; },
			 'wc4agc-integration','wc4agc_api_section'
		 );
		 // Licenses API
		 register_setting( 'wc4agc_settings', 'wc4agc_license_endpoint', [ 'sanitize_callback' => 'esc_url_raw' ] );
		 register_setting( 'wc4agc_settings', 'wc4agc_license_api_key',  [ 'sanitize_callback' => 'sanitize_text_field' ] );
		 add_settings_section(
			 'wc4agc_license_section',
			 'Configuración API Licencias',
			 function(){ echo '<p>Datos de conexión al sistema de licencias.</p>'; },
			 'wc4agc-integration'
		 );
		 add_settings_field(
			 'wc4agc_license_endpoint', 'Licencias Endpoint',
			 function(){ $v = esc_attr( get_option('wc4agc_license_endpoint') ); echo "<input type='url' name='wc4agc_license_endpoint' value='$v' class='regular-text' />"; },
			 'wc4agc-integration','wc4agc_license_section'
		 );
		 add_settings_field(
			 'wc4agc_license_api_key', 'Licencias API Key',
			 function(){ $v = esc_attr( get_option('wc4agc_license_api_key') ); echo "<input type='text' name='wc4agc_license_api_key' value='$v' class='regular-text' />"; },
			 'wc4agc-integration','wc4agc_license_section'
		 );
	 }
 
	 public function register_admin_page() {
		 add_submenu_page(
			 'woocommerce', 'AGC Integración', 'AGC Integración', 'manage_woocommerce', 'wc4agc-integration', [ $this, 'render_admin_page' ]
		 );
	 }
 
	 public function render_admin_page() {
		 echo '<div class="wrap">';
		 echo '<h1>WooCommerce4AGC</h1>';
		 echo '<p class="description">Integra WooCommerce con el ERP AGC y gestiona licencias automáticamente.</p>';
 
		 // Tabs
		 $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
		 echo '<h2 class="nav-tab-wrapper">';
		 foreach(['dashboard'=>'Panel','settings'=>'Ajustes','logs'=>'Logs'] as $tab=>$label) {
			 $active=($tab==$current_tab)?' nav-tab-active':'';
			 echo '<a href="'.esc_url(admin_url('admin.php?page=wc4agc-integration&tab='.$tab)).'" class="nav-tab'.$active.'">'.esc_html($label).'</a>';
		 }
		 echo '</h2><div style="margin-top:20px;"></div>';
 
		 // Content
		 if($current_tab=='settings'){
			 $this->render_settings_tab();
		 }elseif($current_tab=='logs'){
			 $this->render_logs_tab();
		 }else{
			 $this->render_dashboard_tab();
		 }
		 echo '</div>';
	 }
 
	 private function render_dashboard_tab() {
		 echo '<div class="wc4agc-dashboard-cards">';
		 foreach([
			 ['update','Sincronizar stock','sync_stock','Obtener stock desde ERP a WooCommerce'],
			 ['tag','Sincronizar precios','sync_prices','Obtener precios desde ERP a WooCommerce'],
			 ['products','Sincronizar productos','sync_products','Obtener productos desde ERP a WooCommerce'],
			 ['category','Sincronizar categorías','sync_categories','Obtener categorías desde ERP a WooCommerce'],
		 ] as list($icon,$label,$action,$desc)){
			 echo '<div class="wc4agc-dashboard-card">';
			 echo '<span class="dashicons dashicons-'.$icon.'"></span>';
			 echo '<h3>'.esc_html($label).'</h3>';
			 echo '<p>'.esc_html($desc).'</p>';
			 echo '<form method="post"><input type="hidden" name="'.$action.'" value="1" />';
			 submit_button('Ejecutar','secondary',$action,false);
			 echo '</form></div>';
		 }
		 echo '</div>';
 
		 if(isset($_POST['sync_stock'])){
			 WC4AGC_Stock_Sync::sync_all();
			 echo '<div class="updated"><p>Stock sincronizado.</p></div>';
		 }
		 if(isset($_POST['sync_prices'])){
			 WC4AGC_Price_Sync::sync_all();
			 echo '<div class="updated"><p>Precios sincronizados.</p></div>';
		 }
		 if(isset($_POST['sync_products'])){
			 if(class_exists(WC4AGC_Product_Sync::class)){
				 WC4AGC_Product_Sync::sync_all();
				 echo '<div class="updated"><p>Productos sincronizados.</p></div>';
			 } else {
				 echo '<div class="error"><p>Módulo productos no implementado.</p></div>';
			 }
		 }
		 if(isset($_POST['sync_categories'])){
			 if(class_exists(WC4AGC_Category_Sync::class)){
				 WC4AGC_Category_Sync::sync_all();
				 echo '<div class="updated"><p>Categorías sincronizadas.</p></div>';
			 } else {
				 echo '<div class="error"><p>Módulo categorías no implementado.</p></div>';
			 }
		 }
	 }
 
	 private function render_settings_tab() {
		 echo '<form action="options.php" method="post">';
		 settings_fields('wc4agc_settings');
		 do_settings_sections('wc4agc-integration');
		 submit_button('Guardar cambios');
		 echo '</form>';
	 }
 
	 private function render_logs_tab() {
		 // Logs de Pedidos y Licencias
		 $modules = ['orders'=>'wc4agc_order','licenses'=>'wc4agc_license'];
		 $sel = isset($_GET['module']) && isset($modules[$_GET['module']]) ? $_GET['module'] : 'orders';
		 echo '<form method="get"><input type="hidden" name="page" value="wc4agc-integration"/><input type="hidden" name="tab" value="logs"/>';        
		 echo '<label>Ver logs de: <select name="module">';
		 foreach($modules as $key=>$prefix){
			 $sel_attr = $key==$sel?' selected':'';
			 echo '<option value="'.$key.'"'.$sel_attr.'>'.ucfirst($key).'</option>';
		 }
		 echo '</select></label> ';
		 submit_button('Mostrar','secondary','',false);
		 echo '</form>';
		 // Read files
		 $dir = trailingslashit(wp_upload_dir()['basedir']).'wc-logs';
		 $pattern = $dir.'/'.$modules[$sel].'-*.log';
		 $files = glob($pattern);
		 if(!$files){echo '<p>No hay logs para '.esc_html($sel).'.</p>';return;}
		 usort($files,function($a,$b){return filemtime($b)-filemtime($a);});
		 $latest = $files[0];
		 $lines = file($latest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
		 $tail = array_slice($lines,-50);
		 echo '<pre style="max-height:400px;overflow:auto;">'.implode("\n",$tail).'</pre>';
	 }
 
	 public function handle_order_sync($order_id){
		 WC4AGC_Order_Sync::send_to_erp($order_id);
	 }
 }
 
 // Initialize plugin
 WC4AGC_Plugin::instance();
 
register_activation_hook( __FILE__,   [ 'WC4AGC_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WC4AGC_Activator', 'deactivate' ] );
