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
        register_rest_route(self::ROUTE, '/yabi-preview', array('methods'=>'POST','callback'=>array(__CLASS__,'yabi_preview'),'permission_callback'=>array(__CLASS__,'can_edit_products')));
        register_rest_route(self::ROUTE, '/yabi-csv-page', array('methods'=>'POST','callback'=>array(__CLASS__,'yabi_csv_page'),'permission_callback'=>array(__CLASS__,'can_edit_products')));
        register_rest_route(self::ROUTE, '/yabi-csv-queue', array('methods'=>'POST','callback'=>array(__CLASS__,'yabi_csv_queue'),'permission_callback'=>array(__CLASS__,'can_edit_products')));
        register_rest_route(self::ROUTE, '/yabi-csv-preview', array('methods'=>'POST','callback'=>array(__CLASS__,'yabi_csv_preview'),'permission_callback'=>array(__CLASS__,'can_edit_products')));
        register_rest_route(self::ROUTE, '/import-create-requests', array('methods'=>'POST','callback'=>array(__CLASS__,'import_create_requests'),'permission_callback'=>array(__CLASS__,'can_edit_products')));
        register_rest_route(self::ROUTE, '/voice-command-preview', array('methods'=>'POST','callback'=>array(__CLASS__,'voice_command_preview'),'permission_callback'=>array(__CLASS__,'can_read')));
        register_rest_route(self::ROUTE, '/voice-product-search', array('methods'=>'POST','callback'=>array(__CLASS__,'voice_product_search'),'permission_callback'=>array(__CLASS__,'can_read')));
        register_rest_route(self::ROUTE, '/create-request', array('methods'=>'POST','callback'=>array(__CLASS__,'request_create'),'permission_callback'=>array(__CLASS__,'can_edit_products')));
        register_rest_route(self::ROUTE, '/requests', array('methods'=>'GET','callback'=>array(__CLASS__,'list_requests'),'permission_callback'=>array(__CLASS__,'can_read')));
        register_rest_route(self::ROUTE, '/remove-request', array('methods'=>'POST','callback'=>array(__CLASS__,'request_remove'),'permission_callback'=>array(__CLASS__,'can_edit_products')));
        register_rest_route(self::ROUTE, '/pages', array('methods'=>'GET','callback'=>array(__CLASS__,'pages'),'permission_callback'=>array(__CLASS__,'can_pages')));
        register_rest_route(self::ROUTE, '/image-search-links', array('methods'=>'GET','callback'=>array(__CLASS__,'image_search_links'),'permission_callback'=>array(__CLASS__,'can_read')));
        register_rest_route(self::ROUTE, '/image-candidates', array('methods'=>'GET','callback'=>array(__CLASS__,'image_candidates'),'permission_callback'=>array(__CLASS__,'can_read')));
        register_rest_route(self::ROUTE, '/image-request', array('methods'=>'POST','callback'=>array(__CLASS__,'request_image'),'permission_callback'=>array(__CLASS__,'can_edit_products')));
        register_rest_route(self::ROUTE, '/price-request', array('methods'=>'POST','callback'=>array(__CLASS__,'request_price'),'permission_callback'=>array(__CLASS__,'can_edit_products')));
        register_rest_route(self::ROUTE, '/stock-request', array('methods'=>'POST','callback'=>array(__CLASS__,'request_stock'),'permission_callback'=>array(__CLASS__,'can_edit_products')));
        register_rest_route(self::ROUTE, '/category-request', array('methods'=>'POST','callback'=>array(__CLASS__,'request_category'),'permission_callback'=>array(__CLASS__,'can_edit_products')));
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
        // WooCommerce product queries do not consistently implement the WP_Query 's' parameter.
        // Search product titles using WordPress' native search, then hydrate through WooCommerce.
        $search=new WP_Query(array('post_type'=>'product','post_status'=>array('publish','draft','private'),
            's'=>$query,'posts_per_page'=>$limit,'fields'=>'ids','no_found_rows'=>true,
            'ignore_sticky_posts'=>true,'orderby'=>'relevance'));
        $ids=$search->posts;
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
    // Resolve spoken product queries against the real WooCommerce catalogue, never demo items.
    public static function voice_product_search($request) {
        $text=trim(sanitize_text_field((string)$request->get_param('text')));
        if ($text==='' || strlen($text)>500) return new WP_Error('invalid_text','Transcript must be 1-500 characters',array('status'=>400));
        $clean=preg_replace('/(?:تلاش کریں|تلاش کرو|ڈھونڈو|ڈھونڈیں|کی قیمت|کا ریٹ|کی تصویر|کی فوٹو|search for|find|show me|what is the price of|price of|picture of|image of)/iu',' ',$text);
        $clean=trim(preg_replace('/\\s+/u',' ',$clean));
        $barcode=null;
        if (preg_match('/(?:^|\\D)([0-9]{8,14})(?:\\D|$)/u',$clean,$match)) $barcode=$match[1];
        $query=$barcode ?: $clean;
        if (strlen($query)<2 || strlen($query)>120) return new WP_Error('invalid_query','Recognized search must be 2-120 characters',array('status'=>400));
        $lookup=new WP_REST_Request('GET','/'.self::ROUTE.'/catalog-search');
        $lookup->set_param('q',$query);
        $lookup->set_param('limit',20);
        $results=self::catalog_search($lookup);
        if (is_wp_error($results)) return $results;
        return array('read_only'=>true,'transcript'=>$text,'query'=>$query,'results'=>$results,
            'message'=>'Live WooCommerce catalogue search; no changes made.');
    }
    // Urdu/English voice transcript interpretation; no mutation or external AI calls.
    // This endpoint is deliberately a preview: user confirms before any request is created.
    public static function voice_command_preview($request) {
        $spoken=trim(sanitize_text_field((string)$request->get_param('text')));
        if ($spoken==='' || strlen($spoken)>500) return new WP_Error('invalid_text','Transcript must be 1-500 characters',array('status'=>400));
        $normalized=preg_replace('/\\s+/u',' ',$spoken);
        $intent='search';
        if (preg_match('/(?:قیمت\s*(?:بدل|تبدیل|تبدیل\s*کرو|لگاؤ|لگائیں)|(?:change|set|update)\s+(?:the\s+)?price|price\s+(?:change|update))/iu',$normalized)) $intent='price_request';
        elseif (preg_match('/(?:اسٹاک|stock|quantity)/iu',$normalized)) $intent='stock_request';
        elseif (preg_match('/(?:تصویر|image|picture|photo)/iu',$normalized)) $intent='image_search';
        elseif (preg_match('/(?:ہٹاؤ|ہٹائیں|remove|unpublish)/iu',$normalized)) $intent='remove_request';
        $amount=null;
        if (preg_match('/(?:^|\\s)([0-9]+(?:[.,][0-9]{1,2})?)\\s*(?:kr|sek|کرونر)?(?:\\s|$)/iu',$normalized,$m)) $amount=str_replace(',','.',$m[1]);
        $barcode=null;
        if (preg_match('/(?:^|\\D)([0-9]{8,14})(?:\\D|$)/u',$normalized,$m)) $barcode=$m[1];
        return array('read_only'=>true,'transcript'=>$spoken,'language_hint'=>preg_match('/[\\x{0600}-\\x{06FF}]/u',$spoken)?'ur':'en',
            'intent'=>$intent,'amount_candidate'=>$amount,'barcode_candidate'=>$barcode,
            'requires_user_confirmation'=>true,'requires_admin_approval'=>true,
            'warning'=>'Rule-based transcript preview only. No microphone capture, speech recognition, AI generation, or product mutation.');
    }
    // Create individually reviewable draft-product requests from a small validated batch.
    // This never creates, publishes or modifies WooCommerce products.
    public static function import_create_requests($request) {
        if (!function_exists('wc_get_product_id_by_sku')) return new WP_Error('woocommerce_missing','WooCommerce unavailable',array('status'=>503));
        $rows=$request->get_param('rows');
        if (!is_array($rows) || count($rows)<1 || count($rows)>20)
            return new WP_Error('invalid_rows','Provide 1 to 20 normalized rows',array('status'=>400));
        $validated=array(); $seen=array();
        foreach ($rows as $row) {
            if (!is_array($row)) return new WP_Error('invalid_row','Invalid row',array('status'=>400));
            $name=trim(sanitize_text_field((string)($row['name'] ?? '')));
            $sku=trim((string)($row['barcode'] ?? ''));
            $price=trim((string)($row['price'] ?? ''));
            if ($name==='' || strlen($name)>200 || !preg_match('/^[0-9]{8,14}$/D',$sku) ||
                !preg_match('/^\\d{1,7}(?:\\.\\d{1,2})?$/D',$price) || isset($seen[$sku]) || wc_get_product_id_by_sku($sku))
                return new WP_Error('invalid_batch','All rows must be valid, unique and absent from WooCommerce',array('status'=>409));
            $seen[$sku]=true;
            $validated[]=array('name'=>$name,'barcode'=>$sku,'regular_price'=>$price);
        }
        // Refuse duplicates already awaiting approval to prevent repeated requests.
        // Query each requested barcode directly: a fixed-size recent scan could miss older pending requests.
        foreach ($validated as $row) {
            $duplicates=get_posts(array('post_type'=>self::CPT,'post_status'=>'private','fields'=>'ids',
                'posts_per_page'=>1,'no_found_rows'=>true,
                'meta_query'=>array('relation'=>'AND',
                    array('key'=>'_elac_type','value'=>'create','compare'=>'='),
                    array('key'=>'_elac_state','value'=>'pending','compare'=>'='),
                    array('key'=>'_elac_new_sku','value'=>$row['barcode'],'compare'=>'='))));
            if ($duplicates) return new WP_Error('pending_duplicate','A create request for this barcode is already pending',array('status'=>409));
        }
        $created=array();
        foreach ($validated as $row) {
            // A second pending check closes the common gap between batch validation and creation.
            $pending=get_posts(array('post_type'=>self::CPT,'post_status'=>'private','fields'=>'ids',
                'posts_per_page'=>1,'no_found_rows'=>true,
                'meta_query'=>array('relation'=>'AND',
                    array('key'=>'_elac_type','value'=>'create'),
                    array('key'=>'_elac_state','value'=>'pending'),
                    array('key'=>'_elac_new_sku','value'=>$row['barcode']))));
            if ($pending) {
                foreach ($created as $pending_id) {
                    if (get_post_meta($pending_id,'_elac_state',true)==='pending') {
                        update_post_meta($pending_id,'_elac_state','cancelled');
                        update_post_meta($pending_id,'_elac_cancel_reason','Duplicate pending barcode detected');
                    }
                }
                return new WP_Error('pending_duplicate','Another pending request uses this barcode; batch cancelled',array('status'=>409,'cancelled_request_ids'=>$created));
            }
            if (wc_get_product_id_by_sku($row['barcode'])) {
                foreach ($created as $pending_id) {
                    if (get_post_meta($pending_id,'_elac_state',true)==='pending') {
                        update_post_meta($pending_id,'_elac_state','cancelled');
                        update_post_meta($pending_id,'_elac_cancel_reason','Batch failed barcode recheck');
                    }
                }
                return new WP_Error('barcode_taken','Barcode appeared during processing; new batch requests cancelled',array('status'=>409,'cancelled_request_ids'=>$created));
            }
            $single=new WP_REST_Request('POST','/'.self::ROUTE.'/create-request');
            foreach ($row as $key=>$value) $single->set_param($key,$value);
            $result=self::request_create($single);
            if (is_wp_error($result)) {
                foreach ($created as $pending_id) {
                    if (get_post_meta($pending_id,'_elac_state',true)==='pending') {
                        update_post_meta($pending_id,'_elac_state','cancelled');
                        update_post_meta($pending_id,'_elac_cancel_reason','Batch creation failed');
                    }
                }
                return new WP_Error('batch_cancelled','Batch creation failed; previously created requests marked cancelled',array('status'=>500,'cancelled_request_ids'=>$created));
            }
            $data=$result instanceof WP_REST_Response ? $result->get_data() : $result;
            $created[]=(int)$data['request_id'];
        }
        return new WP_REST_Response(array('created_request_ids'=>$created,'pending_approval'=>count($created),
            'products_created'=>0,'message'=>'Requests saved; each product requires explicit administrator approval.'),201);
    }
    // Parse a bounded Yabi CSV upload into the existing read-only preview pipeline.
    // Read a page from a full Yabi export without importing or retaining the uploaded file.
    // The client resubmits the same CSV and advances offset by the returned next_offset.
    public static function yabi_csv_page($request) {
        $files=$request->get_file_params();
        $upload=isset($files['file']) ? $files['file'] : null;
        if (!is_array($upload) || !isset($upload['tmp_name'],$upload['error'],$upload['size']) ||
            (int)$upload['error']!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']))
            return new WP_Error('invalid_upload','A valid CSV upload is required',array('status'=>400));
        // Allow larger exports while keeping processing memory bounded to one page.
        if ((int)$upload['size']<1 || (int)$upload['size']>20971520)
            return new WP_Error('invalid_size','CSV must be at most 20 MiB',array('status'=>400));
        $offset=$request->get_param('offset');
        $offset=($offset===null) ? 0 : $offset;
        if (!is_scalar($offset) || !preg_match('/^\\d{1,7}$/D',(string)$offset) || (int)$offset>1000000)
            return new WP_Error('invalid_offset','Offset must be between 0 and 1000000',array('status'=>400));
        $offset=(int)$offset;
        $handle=fopen($upload['tmp_name'],'rb');
        if (!$handle) return new WP_Error('csv_open_failed','Cannot open CSV',array('status'=>400));
        $first=fgets($handle);
        if ($first===false) { fclose($handle); return new WP_Error('empty_csv','Missing header',array('status'=>400)); }
        $delimiter=count(str_getcsv($first,';','"','\\'))>count(str_getcsv($first,',','"','\\'))?';':',';
        rewind($handle);
        $headers=fgetcsv($handle,0,$delimiter,'"','\\');
        if (!is_array($headers)) { fclose($handle); return new WP_Error('invalid_headers','Invalid header',array('status'=>400)); }
        $headers[0]=preg_replace('/^\\xEF\\xBB\\xBF/','',$headers[0]);
        $required=array('Product Name','EANs (JSON)','Price List (JSON)');
        if (count($headers)!==count(array_unique($headers)) || array_diff($required,$headers)) {
            fclose($handle); return new WP_Error('invalid_headers','Missing or duplicated Yabi columns',array('status'=>400));
        }
        $rows=array(); $index=0; $more=false;
        while (($values=fgetcsv($handle,0,$delimiter,'"','\\'))!==false) {
            if (count($values)===1 && trim((string)$values[0])==='') continue;
            if (count($values)!==count($headers)) {
                fclose($handle); return new WP_Error('invalid_csv_row','CSV column count mismatch at data row '.($index+1),array('status'=>400));
            }
            if ($index >= $offset+100) { $more=true; break; }
            if ($index >= $offset) $rows[]=array_combine($headers,$values);
            $index++;
        }
        fclose($handle);
        if (!$rows) return array('read_only'=>true,'rows'=>array(),'scanned'=>0,'offset'=>$offset,'next_offset'=>null,'has_more'=>false);
        $preview=new WP_REST_Request('POST','/'.self::ROUTE.'/yabi-preview');
        $preview->set_param('rows',$rows);
        $result=self::yabi_preview($preview);
        if (is_wp_error($result)) return $result;
        foreach ($result['rows'] as $i=>&$row) $row['row']=$offset+$i;
        unset($row);
        $result['offset']=$offset;
        $result['next_offset']=$more?$offset+count($rows):null;
        $result['has_more']=$more;
        $result['uploaded_file_sha256']=hash_file('sha256',$upload['tmp_name']);
        return $result;
    }
    // Explicitly queue selected rows from a re-uploaded CSV page. Never create products here.
    // Each queued request still needs a separate administrator approval.
    public static function yabi_csv_queue($request) {
        $expected=strtolower(trim((string)$request->get_param('file_sha256')));
        if (!preg_match('/^[a-f0-9]{64}$/D',$expected))
            return new WP_Error('invalid_hash','A SHA-256 file hash is required',array('status'=>400));
        $files=$request->get_file_params();
        $upload=isset($files['file']) ? $files['file'] : null;
        if (!is_array($upload) || empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name']) ||
            !isset($upload['error']) || (int)$upload['error']!==UPLOAD_ERR_OK ||
            !isset($upload['size']) || (int)$upload['size']<1 || (int)$upload['size']>20971520)
            return new WP_Error('invalid_upload','Valid CSV upload of at most 20 MiB required',array('status'=>400));
        if (!hash_equals($expected,hash_file('sha256',$upload['tmp_name'])))
            return new WP_Error('file_changed','CSV file differs from the reviewed preview',array('status'=>409));
        $selected=$request->get_param('row_indices');
        if (!is_array($selected) || count($selected)<1 || count($selected)>20)
            return new WP_Error('invalid_selection','Select 1 to 20 row indices from one page',array('status'=>400));
        $offset=$request->get_param('offset');
        if (!is_scalar($offset) || !preg_match('/^\\d{1,7}$/D',(string)$offset) || (int)$offset>1000000)
            return new WP_Error('invalid_offset','Valid page offset required',array('status'=>400));
        $offset=(int)$offset; $seen=array();
        foreach ($selected as $index) {
            if (!is_int($index) && !(is_string($index) && preg_match('/^\\d+$/D',$index)))
                return new WP_Error('invalid_index','Row indices must be integers',array('status'=>400));
            $index=(int)$index;
            if ($index<$offset || $index>=$offset+100 || isset($seen[$index]))
                return new WP_Error('invalid_index','Select unique indices within the requested page',array('status'=>400));
            $seen[$index]=true;
        }
        $page=self::yabi_csv_page($request);
        if (is_wp_error($page)) return $page;
        if (!isset($page['uploaded_file_sha256']) || !hash_equals($expected,$page['uploaded_file_sha256']))
            return new WP_Error('file_changed','Preview file hash mismatch',array('status'=>409));
        $rows=array();
        foreach ($page['rows'] as $row) {
            if (!isset($seen[(int)$row['row']])) continue;
            if (empty($row['eligible_for_manual_review']))
                return new WP_Error('ineligible_row','Selected row has validation issues',array('status'=>409,'row'=>$row['row']));
            $rows[]=array('name'=>$row['name'],'barcode'=>$row['barcode'],'price'=>$row['price']);
        }
        if (count($rows)!==count($seen))
            return new WP_Error('missing_row','Some selected rows are not in the CSV page',array('status'=>400));
        $batch=new WP_REST_Request('POST','/'.self::ROUTE.'/import-create-requests');
        $batch->set_param('rows',$rows);
        return self::import_create_requests($batch);
    }
    public static function yabi_csv_preview($request) {
        $files=$request->get_file_params();
        $upload=isset($files['file']) ? $files['file'] : null;
        if (!is_array($upload) || !isset($upload['tmp_name'],$upload['error'],$upload['size']) || (int)$upload['error']!==UPLOAD_ERR_OK)
            return new WP_Error('invalid_upload','A valid CSV file upload is required',array('status'=>400));
        if ((int)$upload['size']<1 || (int)$upload['size']>1048576 || !is_uploaded_file($upload['tmp_name']))
            return new WP_Error('invalid_size','CSV must be an uploaded file of at most 1 MiB',array('status'=>400));
        $handle=fopen($upload['tmp_name'],'rb');
        if (!$handle) return new WP_Error('csv_open_failed','Cannot open CSV',array('status'=>400));
        // Detect comma or semicolon exports without assuming the customer's Yabi locale.
        $first_line=fgets($handle);
        if ($first_line===false) { fclose($handle); return new WP_Error('empty_csv','CSV has no header',array('status'=>400)); }
        $comma=str_getcsv($first_line,',','"','\\');
        $semicolon=str_getcsv($first_line,';','"','\\');
        $delimiter=count($semicolon)>count($comma)?';':',';
        rewind($handle);
        $headers=fgetcsv($handle,0,$delimiter,'"','\\');
        if (!is_array($headers)) { fclose($handle); return new WP_Error('empty_csv','CSV has no header',array('status'=>400)); }
        $headers[0]=preg_replace('/^\\xEF\\xBB\\xBF/','',$headers[0]);
        $required=array('Product Name','EANs (JSON)','Price List (JSON)');
        if (count($headers)!==count(array_unique($headers)) || count(array_diff($required,$headers))>0) {
            fclose($handle); return new WP_Error('invalid_headers','Yabi CSV headers are missing or duplicated',array('status'=>400));
        }
        $rows=array();
        while (($values=fgetcsv($handle,0,$delimiter,'"','\\'))!==false) {
            if (count($values)===1 && trim($values[0])==='') continue;
            if (count($values)!==count($headers)) { fclose($handle); return new WP_Error('invalid_csv_row','CSV column count mismatch',array('status'=>400)); }
            $rows[]=array_combine($headers,$values);
            if (count($rows)>100) { fclose($handle); return new WP_Error('too_many_rows','Preview supports at most 100 rows per upload',array('status'=>400)); }
        }
        fclose($handle);
        if (!$rows) return new WP_Error('empty_csv','No data rows found',array('status'=>400));
        $preview_request=new WP_REST_Request('POST','/'.self::ROUTE.'/yabi-preview');
        $preview_request->set_param('rows',$rows);
        return self::yabi_preview($preview_request);
    }
    // Read-only Yabi export preview. Parse the actual JSON columns, never import implicitly.
    public static function yabi_preview($request) {
        if (!function_exists('wc_get_product_id_by_sku')) return new WP_Error('woocommerce_missing','WooCommerce unavailable',array('status'=>503));
        $rows=$request->get_param('rows');
        if (!is_array($rows) || count($rows)<1 || count($rows)>100) return new WP_Error('invalid_rows','Provide 1 to 100 Yabi rows',array('status'=>400));
        $normalized=array(); $warnings=array();
        foreach ($rows as $i=>$row) {
            if (!is_array($row)) return new WP_Error('invalid_row','Invalid Yabi row',array('status'=>400));
            $name=trim((string)($row['Product Name'] ?? ''));
            $eans=json_decode((string)($row['EANs (JSON)'] ?? ''),true);
            $prices=json_decode((string)($row['Price List (JSON)'] ?? ''),true);
            $codes=array();
            if (is_array($eans)) {
                foreach ($eans as $ean) {
                    $candidate=is_scalar($ean)?(string)$ean:(is_array($ean)?(string)($ean['ean'] ?? $ean['EAN'] ?? $ean['barcode'] ?? ''):'');
                    if (preg_match('/^[0-9]{8,14}$/D',$candidate)) $codes[]=$candidate;
                }
            }
            $codes=array_values(array_unique($codes));
            $cents=array();
            if (is_array($prices)) {
                foreach ($prices as $entry) {
                    if (!is_array($entry)) continue;
                    $amount=$entry['centAmount'] ?? null;
                    if ((is_int($amount) || (is_string($amount) && preg_match('/^\\d+$/D',$amount))) && (float)$amount<=999999999) $cents[]=(int)$amount;
                }
            }
            $issues=array();
            if (count($codes)===0) $issues[]='missing_or_unrecognized_ean';
            if (count($codes)>1) $issues[]='multiple_eans_require_selection';
            if (count($cents)===0) $issues[]='missing_or_unrecognized_price';
            if (count(array_unique($cents))>1) $issues[]='multiple_prices_require_selection';
            if ($name==='') $issues[]='missing_name';
            $normalized[]=array('name'=>$name,'barcode'=>count($codes)===1?$codes[0]:'','price'=>count(array_unique($cents))===1?number_format($cents[0]/100,2,'.',''):'');
            $warnings[]=array('row'=>$i,'issues'=>$issues,'eans'=>$codes,'price_cent_amounts'=>$cents);
        }
        // Explicitly validate the normalized rows below; no database mutations.
        $checked=array(); $seen=array();
        foreach ($normalized as $i=>$item) {
            $issues=$warnings[$i]['issues']; $sku=$item['barcode'];
            if ($sku!=='') {
                if (isset($seen[$sku])) $issues[]='duplicate_barcode_in_batch';
                $seen[$sku]=true;
                $existing=wc_get_product_id_by_sku($sku);
                if ($existing) $issues[]='existing_product_barcode';
            } else $existing=0;
            $checked[]=array('row'=>$i,'name'=>sanitize_text_field($item['name']),'barcode'=>$sku,'price'=>$item['price'],'existing_product_id'=>(int)$existing,'issues'=>array_values(array_unique($issues)),'eligible_for_manual_review'=>empty($issues));
        }
        return array('read_only'=>true,'imported'=>0,'scanned'=>count($checked),'rows'=>$checked,'note'=>'Yabi preview only; no WooCommerce records changed.');
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
    // Draft-only creation, always administrator approved. Never publishes products.
    // Read-only request queue for authenticated store administrators.
    // Soft removal only: move an approved product to draft, never trash/delete.
    public static function request_remove($request) {
        if (!function_exists('wc_get_product')) return new WP_Error('woocommerce_missing','WooCommerce unavailable',array('status'=>503));
        $pid=absint($request->get_param('product_id')); $p=wc_get_product($pid);
        if (!$p || !$p->is_type('simple')) return new WP_Error('invalid_product','Existing simple product required',array('status'=>400));
        $status=$p->get_status();
        if ($status!=='publish') return new WP_Error('invalid_status','Only published products can be unpublished',array('status'=>400));
        $id=wp_insert_post(array('post_type'=>self::CPT,'post_status'=>'private','post_title'=>'Unpublish request for #'.$pid));
        if (is_wp_error($id) || !$id) return new WP_Error('save_failed','Could not save request',array('status'=>500));
        update_post_meta($id,'_elac_type','remove');
        update_post_meta($id,'_elac_product_id',$pid);
        update_post_meta($id,'_elac_old_status',$status);
        update_post_meta($id,'_elac_state','pending');
        update_post_meta($id,'_elac_requested_by',get_current_user_id());
        return new WP_REST_Response(array('request_id'=>$id,'state'=>'pending','message'=>'Admin approval required; product remains published.'),201);
    }
    public static function list_requests($request) {
        $page=max(1,absint($request->get_param('page') ?: 1));
        $per_page=min(50,max(1,absint($request->get_param('per_page') ?: 20)));
        $state=sanitize_key((string)$request->get_param('state'));
        if ($state!=='' && !in_array($state,array('pending','approved','rejected','cancelled'),true)) return new WP_Error('invalid_state','Invalid request state',array('status'=>400));
        $args=array('post_type'=>self::CPT,'post_status'=>'private','posts_per_page'=>$per_page,'paged'=>$page,'orderby'=>'date','order'=>'DESC');
        if ($state!=='') $args['meta_query']=array(array('key'=>'_elac_state','value'=>$state,'compare'=>'='));
        $query=new WP_Query($args);
        $items=array();
        foreach ($query->posts as $post) {
            $type=(string)get_post_meta($post->ID,'_elac_type',true);
            $items[]=array('request_id'=>$post->ID,'type'=>$type===''?'price':$type,'state'=>get_post_meta($post->ID,'_elac_state',true),'product_id'=>(int)get_post_meta($post->ID,'_elac_product_id',true),'created_product_id'=>(int)get_post_meta($post->ID,'_elac_created_product_id',true),'requested_by'=>(int)get_post_meta($post->ID,'_elac_requested_by',true),'approved_by'=>(int)get_post_meta($post->ID,'_elac_approved_by',true),'decided_by'=>(int)get_post_meta($post->ID,'_elac_decided_by',true),'decided_at'=>(string)get_post_meta($post->ID,'_elac_decided_at',true),'date'=>$post->post_date_gmt);
        }
        return array('read_only'=>true,'page'=>$page,'total'=>(int)$query->found_posts,'pages'=>(int)$query->max_num_pages,'requests'=>$items);
    }
    public static function request_create($request) {
        if (!function_exists('wc_get_product_id_by_sku')) return new WP_Error('woocommerce_missing','WooCommerce unavailable',array('status'=>503));
        $name=trim(sanitize_text_field((string)$request->get_param('name')));
        $sku=trim((string)$request->get_param('barcode'));
        $price=trim((string)$request->get_param('regular_price'));
        if ($name==='' || strlen($name)>200) return new WP_Error('invalid_name','Valid product name required',array('status'=>400));
        if (!preg_match('/^[0-9]{8,14}$/D',$sku)) return new WP_Error('invalid_barcode','8-14 digit barcode required',array('status'=>400));
        if (!preg_match('/^\\d{1,7}(?:\\.\\d{1,2})?$/D',$price)) return new WP_Error('invalid_price','Nonnegative price required',array('status'=>400));
        if (wc_get_product_id_by_sku($sku)) return new WP_Error('duplicate_barcode','Product already exists',array('status'=>409));
        $existing_pending=get_posts(array('post_type'=>self::CPT,'post_status'=>'private','fields'=>'ids',
            'posts_per_page'=>1,'no_found_rows'=>true,
            'meta_query'=>array('relation'=>'AND',
                array('key'=>'_elac_type','value'=>'create'),
                array('key'=>'_elac_state','value'=>'pending'),
                array('key'=>'_elac_new_sku','value'=>$sku))));
        if ($existing_pending) return new WP_Error('pending_duplicate','A create request for this barcode is already awaiting approval',array('status'=>409));
        $id=wp_insert_post(array('post_type'=>self::CPT,'post_status'=>'private','post_title'=>'Create draft: '.$name));
        if (is_wp_error($id) || !$id) return new WP_Error('save_failed','Could not save request',array('status'=>500));
        update_post_meta($id,'_elac_type','create');
        update_post_meta($id,'_elac_new_name',$name);
        update_post_meta($id,'_elac_new_sku',$sku);
        update_post_meta($id,'_elac_new_price',wc_format_decimal($price,2));
        update_post_meta($id,'_elac_state','pending');
        update_post_meta($id,'_elac_requested_by',get_current_user_id());
        return new WP_REST_Response(array('request_id'=>$id,'state'=>'pending','message'=>'Administrator approval required; no product created.'),201);
    }
    public static function request_category($request) {
        if (!function_exists('wc_get_product')) return new WP_Error('woocommerce_missing','WooCommerce unavailable',array('status'=>503));
        $id=absint($request->get_param('product_id')); $category_id=absint($request->get_param('category_id'));
        $p=wc_get_product($id);
        if (!$p || !$p->is_type('simple')) return new WP_Error('invalid_product','Existing simple product required',array('status'=>400));
        if (!$category_id || !term_exists($category_id,'product_cat')) return new WP_Error('invalid_category','Existing product category required',array('status'=>400));
        $request_id=wp_insert_post(array('post_type'=>self::CPT,'post_status'=>'private','post_title'=>'Category request for #'.$id));
        if (is_wp_error($request_id) || !$request_id) return new WP_Error('save_failed','Could not save request',array('status'=>500));
        update_post_meta($request_id,'_elac_type','category');
        update_post_meta($request_id,'_elac_product_id',$id);
        update_post_meta($request_id,'_elac_old_categories',$p->get_category_ids());
        update_post_meta($request_id,'_elac_category_id',$category_id);
        update_post_meta($request_id,'_elac_state','pending');
        update_post_meta($request_id,'_elac_requested_by',get_current_user_id());
        return new WP_REST_Response(array('request_id'=>$request_id,'state'=>'pending','message'=>'Admin approval required; categories unchanged.'),201);
    }
    public static function request_stock($request) {
        if (!function_exists('wc_get_product')) return new WP_Error('woocommerce_missing','WooCommerce unavailable',array('status'=>503));
        $id=absint($request->get_param('product_id'));
        $raw=$request->get_param('quantity');
        if (!is_scalar($raw) || !preg_match('/^\\d{1,7}$/D',(string)$raw)) return new WP_Error('invalid_quantity','Nonnegative integer stock quantity required',array('status'=>400));
        $product=wc_get_product($id);
        if (!$product || !$product->is_type('simple')) return new WP_Error('invalid_product','Existing simple product required',array('status'=>400));
        $request_id=wp_insert_post(array('post_type'=>self::CPT,'post_status'=>'private','post_title'=>'Stock request for #'.$id));
        if (is_wp_error($request_id) || !$request_id) return new WP_Error('save_failed','Could not save request',array('status'=>500));
        update_post_meta($request_id,'_elac_type','stock');
        update_post_meta($request_id,'_elac_product_id',$id);
        update_post_meta($request_id,'_elac_old_manage_stock',$product->get_manage_stock()?'yes':'no');
        update_post_meta($request_id,'_elac_old_stock',$product->get_stock_quantity());
        update_post_meta($request_id,'_elac_stock',absint($raw));
        update_post_meta($request_id,'_elac_state','pending');
        update_post_meta($request_id,'_elac_requested_by',get_current_user_id());
        return new WP_REST_Response(array('request_id'=>$request_id,'state'=>'pending','message'=>'Administrator approval required; stock unchanged.'),201);
    }
    public static function request_price($request) {
        if (!function_exists('wc_get_product')) return new WP_Error('woocommerce_missing','WooCommerce is not active',array('status'=>503));
        $id=absint($request->get_param('product_id')); $price=$request->get_param('regular_price');
        if (!$id || !is_scalar($price) || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', (string)$price)) return new WP_Error('invalid_request','Provide product_id and a nonnegative price with max 2 decimals',array('status'=>400));
        $product=wc_get_product($id);
        if (!$product || !$product->is_type('simple')) return new WP_Error('unsupported','Only existing simple WooCommerce products are supported',array('status'=>400));
        $request_id=wp_insert_post(array('post_type'=>self::CPT,'post_status'=>'private','post_title'=>'Price request for #'.$id));
        if (is_wp_error($request_id) || !$request_id) return new WP_Error('save_failed','Could not save request',array('status'=>500));
        update_post_meta($request_id,'_elac_type','price');
        update_post_meta($request_id,'_elac_product_id',$id);
        update_post_meta($request_id,'_elac_old_price',$product->get_regular_price());
        update_post_meta($request_id,'_elac_price',wc_format_decimal((string)$price,2));
        update_post_meta($request_id,'_elac_state','pending');
        update_post_meta($request_id,'_elac_requested_by',get_current_user_id());
        return new WP_REST_Response(array('request_id'=>$request_id,'state'=>'pending','message'=>'Administrator must approve in WordPress dashboard. No price changed.'),201);
    }
    // Research handoff: trusted search-engine links only, no scraping or auto-upload.
    public static function image_search_links($request) {
        if (!function_exists('wc_get_product')) return new WP_Error('woocommerce_missing','WooCommerce unavailable',array('status'=>503));
        $id=absint($request->get_param('product_id'));
        $product=wc_get_product($id);
        if (!$product) return new WP_Error('invalid_product','Product not found',array('status'=>404));
        $name=trim((string)$product->get_name());
        $sku=trim((string)$product->get_sku());
        $terms=trim($name.' '.$sku.' product packaging');
        return array('product_id'=>$id,'product_name'=>$name,'barcode'=>$sku,
            'google_images'=>'https://www.google.com/search?tbm=isch&q='.rawurlencode($terms),
            'bing_images'=>'https://www.bing.com/images/search?q='.rawurlencode($terms),
            'automatic_image_retrieval'=>false,'verified_match'=>false,'uploaded'=>false,
            'note'=>'Research links only. Check manufacturer, exact package/weight, image license and source before submitting an admin-approved image request.');
    }
    // Google Programmable Search image results via server-side credentials.
    // Configure ELAC_GOOGLE_CSE_KEY and ELAC_GOOGLE_CSE_CX in wp-config.php.
    // Never return credentials to clients or write them to logs.
    public static function google_image_candidates($product) {
        if (!defined('ELAC_GOOGLE_CSE_KEY') || !defined('ELAC_GOOGLE_CSE_CX')) {
            return array('configured'=>false,'candidates'=>array(),'error'=>'Google Programmable Search credentials not configured');
        }
        $key=trim((string)ELAC_GOOGLE_CSE_KEY);
        $cx=trim((string)ELAC_GOOGLE_CSE_CX);
        if ($key==='' || $cx==='') return array('configured'=>false,'candidates'=>array(),'error'=>'Google search configuration incomplete');
        $query=trim($product->get_name().' '.$product->get_sku().' product packaging');
        $endpoint=add_query_arg(array('key'=>$key,'cx'=>$cx,'q'=>$query,'searchType'=>'image','num'=>10,'safe'=>'active'),'https://www.googleapis.com/customsearch/v1');
        $response=wp_safe_remote_get($endpoint,array('timeout'=>12,'redirection'=>0,'limit_response_size'=>300000));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) return array('configured'=>true,'candidates'=>array(),'error'=>'Google image search unavailable');
        $data=json_decode(wp_remote_retrieve_body($response),true);
        $items=array();
        foreach (($data['items'] ?? array()) as $item) {
            $url=(string)($item['link'] ?? '');
            if (!wp_http_validate_url($url) || strtolower((string)wp_parse_url($url,PHP_URL_SCHEME))!=='https') continue;
            $items[]=array('title'=>sanitize_text_field($item['title'] ?? ''),'image_url'=>esc_url_raw($url),'source_url'=>esc_url_raw($item['image']['contextLink'] ?? ''),'thumbnail_url'=>esc_url_raw($item['image']['thumbnailLink'] ?? ''),'verified_package'=>false,'verified_license'=>false);
        }
        return array('configured'=>true,'candidates'=>$items,'count'=>count($items),'verified'=>false);
    }
    // Real external image discovery using Wikimedia Commons public API.
    // Candidate metadata is unverified; an administrator must inspect package, weight and licensing.
    public static function image_candidates($request) {
        if (!function_exists('wc_get_product')) return new WP_Error('woocommerce_missing','WooCommerce unavailable',array('status'=>503));
        $id=absint($request->get_param('product_id'));
        $product=wc_get_product($id);
        if (!$product) return new WP_Error('invalid_product','Product not found',array('status'=>404));
        $query=trim(sanitize_text_field($product->get_name()));
        if ($query==='' || strlen($query)>150) return new WP_Error('invalid_name','Product name missing or too long',array('status'=>400));
        $endpoint=add_query_arg(array('action'=>'query','generator'=>'search','gsrsearch'=>$query,'gsrnamespace'=>6,'gsrlimit'=>10,'prop'=>'imageinfo','iiprop'=>'url|mime|extmetadata','iiurlwidth'=>400,'format'=>'json','formatversion'=>2),'https://commons.wikimedia.org/w/api.php');
        $response=wp_safe_remote_get($endpoint,array('timeout'=>12,'redirection'=>0,'limit_response_size'=>300000,'headers'=>array('User-Agent'=>'EasternLivsAIConnector/0.5 (product image research; contact via easternlivs.se)')));
        if (is_wp_error($response)) return new WP_Error('image_search_unavailable','Image source unavailable',array('status'=>502));
        if (wp_remote_retrieve_response_code($response)!==200) return new WP_Error('image_search_http','Image provider returned an error',array('status'=>502));
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if (!is_array($data) || !empty($data['error'])) return new WP_Error('image_search_invalid','Invalid provider response',array('status'=>502));
        $items=array();
        foreach (($data['query']['pages'] ?? array()) as $page) {
            $info=$page['imageinfo'][0] ?? array();
            $url=$info['url'] ?? '';
            $mime=$info['mime'] ?? '';
            if (!in_array($mime,array('image/jpeg','image/png','image/webp'),true) || !wp_http_validate_url($url)) continue;
            $meta=$info['extmetadata'] ?? array();
            $items[]=array('title'=>sanitize_text_field($page['title'] ?? ''),'source_url'=>esc_url_raw($info['descriptionurl'] ?? ''),'image_url'=>esc_url_raw($url),'thumbnail_url'=>esc_url_raw($info['thumburl'] ?? ''),'license'=>wp_strip_all_tags($meta['LicenseShortName']['value'] ?? ''),'artist'=>wp_strip_all_tags($meta['Artist']['value'] ?? ''),'verified_package'=>false,'verified_license'=>false);
        }
        $google=self::google_image_candidates($product);
        return array('product_id'=>$id,'product_name'=>$product->get_name(),'barcode'=>$product->get_sku(),'provider'=>'Wikimedia Commons + Google Programmable Search','candidates'=>$items,'google'=>$google,'count'=>count($items)+count($google['candidates']),'automatically_approved'=>false,'uploaded'=>false,'note'=>'Search results are NOT verified product matches or license approvals. Review exact packaging, weight, rights and image source before requesting upload.');
    }
    public static function request_image($request) {
        if (!function_exists('wc_get_product')) return new WP_Error('woocommerce_missing','WooCommerce unavailable',array('status'=>503));
        $id=absint($request->get_param('product_id'));
        $url=esc_url_raw((string)$request->get_param('image_url'));
        $expected_sku=trim((string)$request->get_param('expected_sku'));
        if ($expected_sku==='') return new WP_Error('missing_expected_sku','Barcode confirmation required before requesting an image',array('status'=>400));
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
        echo '<div class="wrap"><h1>Eastern Livs AI Connector</h1><p><strong>Version 0.5.0 development:</strong> An authenticated WordPress REST foundation, not direct ChatGPT Plus integration. No shared API keys or public write access.</p>';
        echo '<p>API base: <code>'.esc_html(rest_url(self::ROUTE)).'</code>. Use WordPress Application Passwords over HTTPS with a dedicated least-privilege account. Never paste passwords or API credentials into chat.</p>';
        echo '<p>Read: <code>/status</code>, <code>/products</code>, <code>/catalog-search?q=barcode-or-name</code>, <code>/catalog-audit?page=1&amp;per_page=50</code>, <code>/pages</code>. Submit: <code>POST /image-request</code> with <code>product_id</code> and <code>image_url</code> (HTTPS), or <code>POST /price-request</code> with <code>product_id</code> and <code>regular_price</code>. No modification occurs until an administrator approves.</p>';
        $requests=get_posts(array('post_type'=>self::CPT,'post_status'=>'private','posts_per_page'=>50,'orderby'=>'date','order'=>'DESC'));
        echo '<h2>Price and image requests</h2><table class="widefat striped"><thead><tr><th>Request</th><th>Product</th><th>Old</th><th>Requested</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach($requests as $r) {
            $state=get_post_meta($r->ID,'_elac_state',true); $pid=(int)get_post_meta($r->ID,'_elac_product_id',true); $is_image=get_post_meta($r->ID,'_elac_type',true)==='image'; $is_stock=get_post_meta($r->ID,'_elac_type',true)==='stock'; $is_category=get_post_meta($r->ID,'_elac_type',true)==='category'; $is_create=get_post_meta($r->ID,'_elac_type',true)==='create';
            echo '<tr><td>'.esc_html($r->ID).'</td><td>'.esc_html($is_create?'NEW DRAFT':$pid).'</td><td>'.esc_html($is_image?'Image #'.get_post_meta($r->ID,'_elac_old_image_id',true):($is_stock?'Stock: '.get_post_meta($r->ID,'_elac_old_stock',true):($is_category?'Categories':($is_create?'New product':get_post_meta($r->ID,'_elac_old_price',true))))).'</td><td>'.($is_image?'<a target="_blank" rel="noopener noreferrer" href="'.esc_url(get_post_meta($r->ID,'_elac_image_url',true)).'">Review image URL</a>':esc_html($is_stock?get_post_meta($r->ID,'_elac_stock',true):($is_category?'Category #'.get_post_meta($r->ID,'_elac_category_id',true):($is_create?get_post_meta($r->ID,'_elac_new_name',true):get_post_meta($r->ID,'_elac_price',true))))).'</td><td>'.esc_html($state).'</td><td>';
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
        $type=(string)get_post_meta($id,'_elac_type',true);
        if (!in_array($decision,array('approve','reject'),true)) wp_die('Invalid decision');
        if (!in_array($type,array('price','image','remove','create','category','stock'),true)) wp_die('Unknown request type; no changes made.');
        if ($decision==='reject') { update_post_meta($id,'_elac_state','rejected'); update_post_meta($id,'_elac_decided_by',get_current_user_id()); update_post_meta($id,'_elac_decided_at',current_time('mysql',true)); }
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
        elseif($decision==='approve' && get_post_meta($id,'_elac_type',true)==='remove') {
            if (!function_exists('wc_get_product')) wp_die('WooCommerce inactive');
            $pid=(int)get_post_meta($id,'_elac_product_id',true); $p=wc_get_product($pid);
            if (!$p || !$p->is_type('simple') || $p->get_status()!=='publish') wp_die('Product status changed; reject and resubmit.');
            $p->set_status('draft');
            $p->save();
            update_post_meta($id,'_elac_state','approved');
            update_post_meta($id,'_elac_approved_by',get_current_user_id());
        }
        elseif($decision==='approve' && get_post_meta($id,'_elac_type',true)==='create') {
            if (!class_exists('WC_Product_Simple')) wp_die('WooCommerce inactive');
            $sku=(string)get_post_meta($id,'_elac_new_sku',true);
            if (wc_get_product_id_by_sku($sku)) wp_die('Barcode now exists; reject and resubmit.');
            $p=new WC_Product_Simple();
            $p->set_name((string)get_post_meta($id,'_elac_new_name',true));
            $p->set_sku($sku);
            $p->set_regular_price((string)get_post_meta($id,'_elac_new_price',true));
            $p->set_status('draft');
            $new_id=$p->save();
            if (!$new_id) wp_die('Could not create draft');
            update_post_meta($id,'_elac_created_product_id',$new_id);
            update_post_meta($id,'_elac_state','approved');
            update_post_meta($id,'_elac_approved_by',get_current_user_id());
        }
        elseif($decision==='approve' && get_post_meta($id,'_elac_type',true)==='category') {
            if (!function_exists('wc_get_product')) wp_die('WooCommerce inactive');
            $pid=(int)get_post_meta($id,'_elac_product_id',true); $p=wc_get_product($pid);
            if (!$p || !$p->is_type('simple')) wp_die('Product no longer supported');
            $old=get_post_meta($id,'_elac_old_categories',true);
            $current=$p->get_category_ids();
            if (!is_array($old)) wp_die('Invalid category snapshot');
            sort($old); sort($current);
            if ($old!==$current) wp_die('Categories changed since request; reject and resubmit.');
            $category_id=(int)get_post_meta($id,'_elac_category_id',true);
            if (!term_exists($category_id,'product_cat')) wp_die('Category no longer exists');
            $p->set_category_ids(array($category_id)); $p->save();
            update_post_meta($id,'_elac_state','approved');
            update_post_meta($id,'_elac_approved_by',get_current_user_id());
        }
        elseif($decision==='approve' && get_post_meta($id,'_elac_type',true)==='stock') {
            if (!function_exists('wc_get_product')) wp_die('WooCommerce inactive');
            $pid=(int)get_post_meta($id,'_elac_product_id',true); $p=wc_get_product($pid);
            if (!$p || !$p->is_type('simple')) wp_die('Product no longer supported');
            $old_manage=get_post_meta($id,'_elac_old_manage_stock',true)==='yes';
            $old_stock=get_post_meta($id,'_elac_old_stock',true);
            if ($p->get_manage_stock()!==$old_manage || (string)$p->get_stock_quantity()!==(string)$old_stock) wp_die('Stock changed since request; reject and resubmit.');
            $p->set_manage_stock(true);
            $p->set_stock_quantity((int)get_post_meta($id,'_elac_stock',true));
            $p->save();
            update_post_meta($id,'_elac_state','approved');
            update_post_meta($id,'_elac_approved_by',get_current_user_id());
        }
        elseif($decision==='approve' && $type==='price') {
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
        // Timestamp only successful, final decisions; failures above do not create audit events.
        update_post_meta($id,'_elac_decided_by',get_current_user_id());
        update_post_meta($id,'_elac_decided_at',current_time('mysql',true));
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
