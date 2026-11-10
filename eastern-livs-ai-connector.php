<?php
/**
 * Plugin Name: Eastern Livs AI Connector
 * Description: A security-first REST foundation for Eastern Livs: authenticated catalog reads and administrator-approved WooCommerce price requests. Not a ChatGPT plugin or MCP server.
 * Version: 0.5.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: eastern-livs-ai-connector
 */
if (!defined('ABSPATH')) { exit; }

final class ELAC_Connector {
    const CPT = 'elac_request';
    const ROUTE = 'eastern-livs-ai/v1';
    public static function boot() {
        add_action('init', array(__CLASS__, 'register_cpt'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_elac_decide', array(__CLASS__, 'decide'));
    }
    public static function register_cpt() {
        register_post_type(self::CPT, array('label'=>'AI Change Requests', 'public'=>false, 'show_ui'=>false, 'supports'=>array('title'), 'capability_type'=>'post'));
    }
    public static function routes() {
        register_rest_route(self::ROUTE, '/status', array('methods'=>'GET','callback'=>array(__CLASS__,'status'),'permission_callback'=>array(__CLASS__,'can_read')));
        register_rest_route(self::ROUTE, '/products', array('methods'=>'GET','callback'=>array(__CLASS__,'products'),'permission_callback'=>array(__CLASS__,'can_read'),'args'=>array('page'=>array('default'=>1,'sanitize_callback'=>'absint'),'per_page'=>array('default'=>10,'sanitize_callback'=>'absint'))));
        register_rest_route(self::ROUTE, '/catalog-search', array('methods'=>'GET','callback'=>array(__CLASS__,'catalog_search'),'permission_callback'=>array(__CLASS__,'can_read')));
        register_rest_route(self::ROUTE, '/catalog-audit', array('methods'=>'GET','callback'=>array(__CLASS__,'catalog_audit'),'permission_callback'=>array(__CLASS__,'can_read')));
        register_rest_route(self::ROUTE, '/import-preview', array('methods'=>'POST','callback'=>array(__CLASS__,'import_preview'),'permission_callback'=>array(__CLASS__,'can_edit_products')));
        register_rest_route(self::ROUTE, '/pages', array('methods'=>'GET','callback'=>array(__CLASS__,'pages'),'permission_callback'=>array(__CLASS__,'can_pages')));
        register_rest_route(self::ROUTE, '/image-request', array('methods'=>'POST','callback'=>array(__CLASS__,'request_image'),'permission_callback'=>array(__CLASS__,'can_edit_products')));
        register_rest_route(self::ROUTE, '/price-request', array('methods'=>'POST','callback'=>array(__CLASS__,'request_price'),'permission_callback'=>array(__CLASS__,'can_edit_products')));
    }
    public static function can_read() { return current_user_can('manage_woocommerce') || current_user_can('manage_options'); }
    public static function can_pages() { return current_user_can('edit_pages'); }
    public static function can_edit_products() { return current_user_can('edit_products') && current_user_can('manage_options'); }
    public static function status() { return array('plugin'=>'Eastern Livs AI Connector','version'=>'0.5.0','site'=>home_url('/'),'woocommerce'=>class_exists('WooCommerce'),'mode'=>'Authenticated REST; manual admin approval for price changes','chatgpt_plus_direct'=>false,'mcp_server'=>false); }
    public static function products($request) {
        if (!function_exists('wc_get_products')) return new WP_Error('woocommerce_missing','WooCommerce is not active',array('status'=>503));
        $page=max(1,(int)$request->get_param('page')); $per_page=min(30,max(1,(int)$request->get_param('per_page')));
        $items=wc_get_products(array('status'=>array('publish','draft','private'),'limit'=>$per_page,'page'=>$page,'orderby'=>'ID','order'=>'DESC'));
        $result=array();
        foreach ($items as $p) { $result[]=array('id'=>$p->get_id(),'name'=>$p->get_name(),'sku'=>$p->get_sku(),'status'=>$p->get_status(),'regular_price'=>$p->get_regular_price(),'sale_price'=>$p->get_sale_price(),'currency'=>get_woocommerce_currency()); }
        return array('page'=>$page,'products'=>$result);
    }
    // Read-only lookup: exact barcode/SKU first, otherwise case-insensitive product name.
    public static function catalog_search($request) {
        if (!function_exists('wc_get_products')) return new WP_Error('woocommerce_missing','WooCommerce unavailable',array('status'=>503));
        $query=trim(sanitize_text_field((string)$request->get_param('q')));
        if (strlen($query)<2 || strlen($query)>120) return new WP_Error('invalid_query','Query must be 2 to 120 characters',array('status'=>400));
        $limit=min(30,max(1,absint($request->get_param('limit') ?: 20)));
        $items=array(); $seen=array();
        $sku=wc_get_product_id_by_sku($query);
        if ($sku) { $p=wc_get_product($sku); if ($p && in_array($p->get_status(),array('publish','draft','private'),true)) { $items[]=$p; $seen[$p->get_id()]=true; } }
        $ids=wc_get_products(array('status'=>array('publish','draft','private'),'limit'=>$limit,'s'=>$query,'return'=>'ids'));
        foreach ($ids as $id) { if (!isset($seen[$id])) { $p=wc_get_product($id); if ($p) { $items[]=$p; $seen[$id]=true; } } if (count($items)>=$limit) break; }
        $result=array(); foreach ($items as $p) $result[]=self::product_summary($p);
        return array('query'=>$query,'count'=>count($result),'products'=>$result,'read_only'=>true);
    }
    private static function product_summary($p) {
        return array('id'=>$p->get_id(),'name'=>$p->get_name(),'sku'=>$p->get_sku(),'status'=>$p->get_status(),'regular_price'=>$p->get_regular_price(),'currency'=>get_woocommerce_currency(),'image_id'=>$p->get_image_id(),'missing_image'=>!$p->get_image_id(),'missing_price'=>$p->get_regular_price()==='');
    }
    // Page-by-page audit, bounded to avoid costly full-catalog scans.
    public static function catalog_audit($request) {
        if (!function_exists('wc_get_products')) return new WP_Error('woocommerce_missing','WooCommerce unavailable',array('status'=>503));
        $page=max(1,absint($request->get_param('page') ?: 1));
        $limit=min(100,max(1,absint($request->get_param('per_page') ?: 50)));
        $items=wc_get_products(array('status'=>array('publish','draft','private'),'limit'=>$limit,'page'=>$page,'orderby'=>'ID','order'=>'ASC'));
        $missing_images=array(); $missing_prices=array(); $missing_skus=array();
        foreach ($items as $p) { $entry=self::product_summary($p); if (!$p->get_image_id()) $missing_images[]=$entry; if ($p->get_regular_price()==='') $missing_prices[]=$entry; if ($p->get_sku()==='') $missing_skus[]=$entry; }
        return array('page'=>$page,'scanned'=>count($items),'missing_images'=>$missing_images,'missing_prices'=>$missing_prices,'missing_barcodes'=>$missing_skus,'read_only'=>true);
    }
    // Dry-run only: inspect up to 100 normalized Yabi records per call. No writes.
    public static function import_preview($request) {
        if (!function_exists('wc_get_product_id_by_sku')) return new WP_Error('woocommerce_missing','WooCommerce unavailable',array('status'=>503));
        $rows=$request->get_param('rows');
        if (!is_array($rows) || !$rows || count($rows)>100) return new WP_Error('invalid_rows','Supply 1 to 100 rows',array('status'=>400));
        $result=array(); $seen=array();
        foreach ($rows as $index=>$row) {
            if (!is_array($row)) return new WP_Error('invalid_row','Each row must be an object',array('status'=>400));
            $name=sanitize_text_field((string)($row['name'] ?? ''));
            $sku=trim((string)($row['barcode'] ?? ''));
            $price=trim((string)($row['price'] ?? ''));
            $issues=array();
            if ($name==='' || strlen($name)>200) $issues[]='invalid_name';
            if ($sku==='' || !preg_match('/^[0-9]{8,14}$/D',$sku)) $issues[]='missing_or_invalid_barcode';
            if ($price==='' || !preg_match('/^\\d{1,7}(?:\\.\\d{1,2})?$/D',$price)) $issues[]='missing_or_invalid_price';
            if ($sku!=='' && isset($seen[$sku])) $issues[]='duplicate_barcode_in_batch';
            if ($sku!=='') $seen[$sku]=true;
            $existing=($sku!=='' && preg_match('/^[0-9]{8,14}$/D',$sku)) ? wc_get_product_id_by_sku($sku) : 0;
            if ($existing) $issues[]='existing_product_barcode';
            $result[]=array('row'=>$index,'name'=>$name,'barcode'=>$sku,'price'=>$price,'existing_product_id'=>(int)$existing,'issues'=>$issues,'eligible_for_manual_review'=>empty($issues));
        }
        return array('read_only'=>true,'imported'=>0,'scanned'=>count($result),'rows'=>$result,'note'=>'Preview only; no products, prices, images or stock modified.');
    }
    public static function pages($request) {
        $items=get_posts(array('post_type'=>'page','post_status'=>array('publish','draft','private'),'posts_per_page'=>min(30,max(1,(int)$request->get_param('per_page') ?: 10)),'orderby'=>'ID','order'=>'DESC'));
        $result=array(); foreach($items as $p) { $result[]=array('id'=>$p->ID,'title'=>get_the_title($p),'status'=>$p->post_status,'edit_url'=>get_edit_post_link($p->ID,'raw')); }
        return array('pages'=>$result,'note'=>'Read-only metadata. Elementor layouts are not modified by this plugin.');
    }
    public static function request_price($request) {
        if (!function_exists('wc_get_product')) return new WP_Error('woocommerce_missing','WooCommerce is not active',array('status'=>503));
        $id=absint($request->get_param('product_id')); $price=$request->get_param('regular_price');
        if (!$id || !is_scalar($price) || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', (string)$price)) return new WP_Error('invalid_request','Provide product_id and a nonnegative price with max 2 decimals',array('status'=>400));
        $product=wc_get_product($id);
        if (!$product || !$product->is_type('simple')) return new WP_Error('unsupported','Only existing simple WooCommerce products are supported',array('status'=>400));
        $request_id=wp_insert_post(array('post_type'=>self::CPT,'post_status'=>'private','post_title'=>'Price request for #'.$id));
        if (is_wp_error($request_id) || !$request_id) return new WP_Error('save_failed','Could not save request',array('status'=>500));
        update_post_meta($request_id,'_elac_product_id',$id);
        update_post_meta($request_id,'_elac_old_price',$product->get_regular_price());
        update_post_meta($request_id,'_elac_price',wc_format_decimal((string)$price,2));
        update_post_meta($request_id,'_elac_state','pending');
        update_post_meta($request_id,'_elac_requested_by',get_current_user_id());
        return new WP_REST_Response(array('request_id'=>$request_id,'state'=>'pending','message'=>'Administrator must approve in WordPress dashboard. No price changed.'),201);
    }
    public static function request_image($request) {
        if (!function_exists('wc_get_product')) return new WP_Error('woocommerce_missing','WooCommerce unavailable',array('status'=>503));
        $id=absint($request->get_param('product_id'));
        $url=esc_url_raw((string)$request->get_param('image_url'));
        $expected_sku=trim((string)$request->get_param('expected_sku'));
        $product=wc_get_product($id);
        if (!$product || !$product->is_type('simple')) return new WP_Error('invalid_product','Existing simple product required',array('status'=>400));
        if ($expected_sku!=='' && $expected_sku!==$product->get_sku()) return new WP_Error('sku_mismatch','Product barcode does not match expected_sku',array('status'=>409));
        if ($product->get_image_id() && !$request->get_param('allow_replace')) return new WP_Error('image_exists','Existing featured image: set allow_replace explicitly to request replacement',array('status'=>409));
        if (!$url || !wp_http_validate_url($url) || strtolower((string)wp_parse_url($url,PHP_URL_SCHEME))!=='https') return new WP_Error('invalid_url','Public HTTPS image URL required',array('status'=>400));
        $request_id=wp_insert_post(array('post_type'=>self::CPT,'post_status'=>'private','post_title'=>'Image request for #'.$id));
        if (is_wp_error($request_id) || !$request_id) return new WP_Error('save_failed','Could not save request',array('status'=>500));
        update_post_meta($request_id,'_elac_type','image');
        update_post_meta($request_id,'_elac_product_id',$id);
        update_post_meta($request_id,'_elac_image_url',$url);
        update_post_meta($request_id,'_elac_expected_sku',$product->get_sku());
        update_post_meta($request_id,'_elac_old_image_id',$product->get_image_id());
        update_post_meta($request_id,'_elac_state','pending');
        update_post_meta($request_id,'_elac_requested_by',get_current_user_id());
        return new WP_REST_Response(array('request_id'=>$request_id,'state'=>'pending','message'=>'Admin must verify exact packaging and approve; no image changed.'),201);
    }
    private static function attach_image($url,$product_id) {
        if (!wp_http_validate_url($url) || strtolower((string)wp_parse_url($url,PHP_URL_SCHEME))!=='https') return new WP_Error('invalid_url','Unsafe image URL');
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        $tmp=download_url($url,30);
        if (is_wp_error($tmp)) return $tmp;
        if (filesize($tmp)>8*1024*1024) { @unlink($tmp); return new WP_Error('too_large','Image exceeds 8MB'); }
        $check=wp_check_filetype_and_ext($tmp,'product.jpg');
        $mime=isset($check['type'])?$check['type']:'';
        $ext=isset($check['ext'])?$check['ext']:'';
        if (!in_array($mime,array('image/jpeg','image/png','image/webp'),true) || !$ext) { @unlink($tmp); return new WP_Error('invalid_image','Only JPEG, PNG and WebP are accepted'); }
        $file=array('name'=>'eastern-livs-product-'.$product_id.'.'.$ext,'tmp_name'=>$tmp);
        $attachment=media_handle_sideload($file,$product_id,'Verified product package');
        if (is_wp_error($attachment)) { @unlink($tmp); return $attachment; }
        return $attachment;
    }
    public static function menu() {
        add_menu_page('Eastern Livs AI','Eastern Livs AI','manage_options','elac-dashboard',array(__CLASS__,'dashboard'),'dashicons-shield-alt',58);
    }
    public static function dashboard() {
        if (!current_user_can('manage_options')) wp_die('Not permitted');
        echo '<div class="wrap"><h1>Eastern Livs AI Connector</h1><p><strong>Version 0.3.0:</strong> An authenticated WordPress REST foundation, not direct ChatGPT Plus integration. No shared API keys or public write access.</p>';
        echo '<p>API base: <code>'.esc_html(rest_url(self::ROUTE)).'</code>. Use WordPress Application Passwords over HTTPS with a dedicated least-privilege account. Never paste passwords or API credentials into chat.</p>';
        echo '<p>Read: <code>/status</code>, <code>/products</code>, <code>/catalog-search?q=barcode-or-name</code>, <code>/catalog-audit?page=1&amp;per_page=50</code>, <code>/pages</code>. Submit: <code>POST /image-request</code> with <code>product_id</code> and <code>image_url</code> (HTTPS), or <code>POST /price-request</code> with <code>product_id</code> and <code>regular_price</code>. No modification occurs until an administrator approves.</p>';
        $requests=get_posts(array('post_type'=>self::CPT,'post_status'=>'private','posts_per_page'=>50,'orderby'=>'date','order'=>'DESC'));
        echo '<h2>Price and image requests</h2><table class="widefat striped"><thead><tr><th>Request</th><th>Product</th><th>Old</th><th>Requested</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach($requests as $r) {
            $state=get_post_meta($r->ID,'_elac_state',true); $pid=(int)get_post_meta($r->ID,'_elac_product_id',true); $is_image=get_post_meta($r->ID,'_elac_type',true)==='image';
            echo '<tr><td>'.esc_html($r->ID).'</td><td>'.esc_html($pid).'</td><td>'.esc_html($is_image?'Image #'.get_post_meta($r->ID,'_elac_old_image_id',true):get_post_meta($r->ID,'_elac_old_price',true)).'</td><td>'.($is_image?'<a target="_blank" rel="noopener noreferrer" href="'.esc_url(get_post_meta($r->ID,'_elac_image_url',true)).'">Review image URL</a>':esc_html(get_post_meta($r->ID,'_elac_price',true))).'</td><td>'.esc_html($state).'</td><td>';
            if($state==='pending') { foreach(array('approve'=>'Approve','reject'=>'Reject') as $decision=>$label) {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline;margin-right:5px">';
                echo '<input type="hidden" name="action" value="elac_decide"><input type="hidden" name="request_id" value="'.esc_attr($r->ID).'"><input type="hidden" name="decision" value="'.esc_attr($decision).'">';
                wp_nonce_field('elac_decide_'.$r->ID); submit_button($label,'secondary','submit',false); echo '</form>';
            }} echo '</td></tr>';
        }
        echo '</tbody></table><p>Before activation on a live store, back up your WordPress database and files. This plugin has not been tested on your hosting environment.</p></div>';
    }
    public static function decide() {
        if (!current_user_can('manage_options')) wp_die('Not permitted');
        $id=isset($_POST['request_id'])?absint(wp_unslash($_POST['request_id'])):0;
        check_admin_referer('elac_decide_'.$id);
        if (get_post_type($id)!==self::CPT || get_post_meta($id,'_elac_state',true)!=='pending') wp_die('Invalid or already processed request.');
        $decision=isset($_POST['decision'])?sanitize_key(wp_unslash($_POST['decision'])):'';
        if ($decision==='reject') { update_post_meta($id,'_elac_state','rejected'); }
        elseif($decision==='approve' && get_post_meta($id,'_elac_type',true)==='image') {
            if (!function_exists('wc_get_product')) wp_die('WooCommerce inactive');
            $pid=(int)get_post_meta($id,'_elac_product_id',true); $p=wc_get_product($pid);
            if (!$p || !$p->is_type('simple')) wp_die('Product no longer supported');
            if ((int)$p->get_image_id() !== (int)get_post_meta($id,'_elac_old_image_id',true)) wp_die('Image changed since request; reject and submit a new request.');
            if ((string)$p->get_sku() !== (string)get_post_meta($id,'_elac_expected_sku',true)) wp_die('Barcode changed since request; reject and submit a new request.');
            $attachment=self::attach_image((string)get_post_meta($id,'_elac_image_url',true),$pid);
            if (is_wp_error($attachment)) wp_die(esc_html($attachment->get_error_message()));
            $p->set_image_id($attachment); $p->save(); update_post_meta($id,'_elac_state','approved');
            update_post_meta($id,'_elac_approved_by',get_current_user_id());
        }
        elseif($decision==='approve') {
            if (!function_exists('wc_get_product')) wp_die('WooCommerce inactive');
            $pid=(int)get_post_meta($id,'_elac_product_id',true); $p=wc_get_product($pid);
            if (!$p || !$p->is_type('simple')) wp_die('Product no longer supported');
            // Protect against stale requests and avoid overwriting a newer price.
            $old=(string)get_post_meta($id,'_elac_old_price',true);
            if ((string)$p->get_regular_price() !== $old) wp_die('Product price changed since request. Reject and submit a new request.');
            $new=(string)get_post_meta($id,'_elac_price',true);
            $p->set_regular_price($new); $p->save(); update_post_meta($id,'_elac_state','approved');
            update_post_meta($id,'_elac_approved_by',get_current_user_id());
        } else wp_die('Invalid action');
        wp_safe_redirect(admin_url('admin.php?page=elac-dashboard')); exit;
    }
}
ELAC_Connector::boot();

/**
 * Optional official GitHub release updater. Configure in wp-config.php:
 * define('ELAC_UPDATE_REPOSITORY', 'owner/repository');
 * The repository must have a public GitHub release with a ZIP asset named
 * eastern-livs-ai-connector.zip, containing the eastern-livs-ai-connector folder.
 * No updates are fetched or installed without an explicitly configured repository.
 */
final class ELAC_Release_Updater {
    const SLUG = 'eastern-livs-ai-connector';
    public static function boot() {
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'check'));
        add_filter('plugins_api', array(__CLASS__, 'details'), 10, 3);
    }
    private static function repository() {
        if (!defined('ELAC_UPDATE_REPOSITORY')) return '';
        $repo = trim((string) ELAC_UPDATE_REPOSITORY);
        return preg_match('/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+$/D', $repo) ? $repo : '';
    }
    private static function release() {
        $repo = self::repository();
        if (!$repo) return false;
        $cache_key = 'elac_release_' . md5($repo);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;
        $url = 'https://api.github.com/repos/' . $repo . '/releases/latest';
        $response = wp_remote_get($url, array('timeout'=>10,'redirection'=>0,'headers'=>array('Accept'=>'application/vnd.github+json','User-Agent'=>'EasternLivs-AI-Connector-Updater')));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) return false;
        $release = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) return false;
        $version = ltrim((string)($release['tag_name'] ?? ''), 'vV');
        if (!preg_match('/^\d+\.\d+\.\d+(?:[-.][a-zA-Z0-9.]+)?$/D', $version)) return false;
        $download = '';
        foreach (($release['assets'] ?? array()) as $asset) {
            if (($asset['name'] ?? '') === 'eastern-livs-ai-connector.zip') {
                $candidate = (string)($asset['browser_download_url'] ?? '');
                $parts = wp_parse_url($candidate);
                if (($parts['scheme'] ?? '')==='https' && ($parts['host'] ?? '')==='github.com') $download=$candidate;
                break;
            }
        }
        if (!$download) return false;
        $data = array('version'=>$version,'download'=>$download,'url'=>'https://github.com/'.$repo,'description'=>wp_strip_all_tags((string)($release['body'] ?? '')));
        set_transient($cache_key,$data,6*HOUR_IN_SECONDS);
        return $data;
    }
    public static function check($transient) {
        if (!is_object($transient) || empty($transient->checked)) return $transient;
        $file = plugin_basename(__FILE__);
        if (!isset($transient->checked[$file])) return $transient;
        $release = self::release();
        if (!$release || !version_compare($release['version'], $transient->checked[$file], '>')) return $transient;
        $transient->response[$file] = (object) array('id'=>'elac-github-release','slug'=>self::SLUG,'plugin'=>$file,'new_version'=>$release['version'],'url'=>$release['url'],'package'=>$release['download'],'requires_php'=>'7.4');
        return $transient;
    }
    public static function details($result, $action, $args) {
        if ($action!=='plugin_information' || !is_object($args) || ($args->slug ?? '')!==self::SLUG) return $result;
        $release = self::release();
        if (!$release) return $result;
        return (object) array('name'=>'Eastern Livs AI Connector','slug'=>self::SLUG,'version'=>$release['version'],'author'=>'Eastern Livs','homepage'=>$release['url'],'download_link'=>$release['download'],'sections'=>array('description'=>'Eastern Livs WooCommerce connector.','changelog'=>esc_html($release['description'])));
    }
}
ELAC_Release_Updater::boot();
