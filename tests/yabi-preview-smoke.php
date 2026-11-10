<?php
// Isolated parser smoke tests: no WordPress installation or database writes.
define('ABSPATH', __DIR__);
function add_action() {}
function add_filter() {}
function wc_get_product_id_by_sku($sku) { return $sku === '12345678' ? 42 : 0; }
function sanitize_text_field($s) { return trim(strip_tags($s)); }
class WP_Error {
    public $code;
    public function __construct($code, $message, $data = array()) { $this->code=$code; }
}
class WP_REST_Request {
    private $params=array();
    public function __construct($method, $route) {}
    public function set_param($key, $value) { $this->params[$key]=$value; }
    public function get_param($key) { return $this->params[$key] ?? null; }
}
require dirname(__DIR__).'/eastern-livs-ai-connector.php';
function check($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: ".$message."\n"); exit(1); }
}
function preview($rows) {
    $r=new WP_REST_Request('POST','/yabi-preview');
    $r->set_param('rows',$rows);
    return ELAC_Connector::yabi_preview($r);
}
$make=function($name,$ean,$cents) {
    return array('Product Name'=>$name,'EANs (JSON)'=>json_encode($ean),'Price List (JSON)'=>json_encode($cents));
};
$valid=$make('Test product',array('87654321'),array(array('centAmount'=>4100)));
$result=preview(array($valid));
check($result['rows'][0]['eligible_for_manual_review']===true,'valid row eligible');
check($result['rows'][0]['price']==='41.00','centAmount converted to SEK units');
$duplicate=preview(array($valid,$valid));
check(in_array('duplicate_barcode_in_batch',$duplicate['rows'][1]['issues'],true),'duplicate detected');
$existing=preview(array($make('Existing',array('12345678'),array(array('centAmount'=>1200)))));
check(in_array('existing_product_barcode',$existing['rows'][0]['issues'],true),'existing SKU detected');
$ambiguous=preview(array($make('Ambiguous',array('87654321','87654322'),array(array('centAmount'=>100),array('centAmount'=>200)))));
check(!$ambiguous['rows'][0]['eligible_for_manual_review'],'ambiguous EAN and price blocked');
check(in_array('multiple_eans_require_selection',$ambiguous['rows'][0]['issues'],true),'multiple EAN warning');
check(in_array('multiple_prices_require_selection',$ambiguous['rows'][0]['issues'],true),'multiple prices warning');
$missing=preview(array($make('',array(),array())));
check(!$missing['rows'][0]['eligible_for_manual_review'],'missing values blocked');
check(preview(array()) instanceof WP_Error,'empty batch rejected');
echo "Yabi preview smoke tests passed\n";
