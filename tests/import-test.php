<?php
declare(strict_types=1);
define('ABSPATH', '/tmp/test/');
$GLOBALS['posts'] = [];
$GLOBALS['meta'] = [];
$GLOBALS['id'] = 100;
$GLOBALS['archive_root'] = sys_get_temp_dir() . '/smup-test-' . bin2hex(random_bytes(5));
class WP_Error {
    public function __construct(public string $code, public string $message, public array $args = []) {}
    public function get_error_message() { return $this->message; }
}
class WP_Block_Type_Registry {
    static function get_instance() { return new self(); }
    function is_registered($name) { return str_starts_with($name, 'core/'); }
}
function is_wp_error($arg) { return $arg instanceof WP_Error; }
function trailingslashit($v) { return rtrim($v, '/') . '/'; }
function plugin_dir_path($file) { return dirname($file) . "/"; }
function wp_upload_dir(...$args) { return ['basedir' => $GLOBALS['archive_root']]; }
function wp_mkdir_p($p) { return is_dir($p) || mkdir($p,0750,true); }
function has_blocks($s) { return str_contains($s, '<!-- wp:'); }
function parse_blocks($s) {
    // Smoke parser checks nested Core block names; it is not a WP parser.
    preg_match_all('~<!-- wp:([a-z-]+(?:/[a-z-]+)?)(?:\\s+\\{[^>]*\\})?\\s*(?:/)?-->~', $s, $m);
    return array_map(fn($name)=>['blockName'=>(str_contains($name, '/') ? $name : 'core/'.$name), 'innerBlocks'=>[]], $m[1]);
}
function add_action(...$args) {}
function add_filter(...$args) {}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function sanitize_text_field($s){return trim(strip_tags($s));}
function wp_slash($v){return $v;}
function current_user_can(...$unused){return true;}
function get_posts($args){$found=[];foreach($GLOBALS['posts'] as $p){if(($GLOBALS['meta'][$p->ID]['_smup_poc_slug']??'')===($args['meta_value']??''))$found[]=$p;}return array_slice($found,0,1);}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function wp_insert_post($data,$error=false){$id=$data['ID']??$GLOBALS['id']++;$GLOBALS['posts'][$id]=(object)['ID'=>$id,'post_type'=>$data['post_type'],'post_title'=>$data['post_title'],'post_content'=>$data['post_content'],'post_status'=>$data['post_status']];return $id;}
function wp_delete_post($id,$force=false){unset($GLOBALS['posts'][$id]);}
function wp_update_post($data){$p=$GLOBALS['posts'][$data['ID']];foreach($data as $k=>$v)$p->$k=$v;return $data['ID'];}
function update_post_meta($id,$key,$val){$GLOBALS['meta'][$id][$key]=$val;}
function term_exists($slug,$tax){return ['term_id'=>7];}
function wp_set_object_terms(...$args){return true;}
function wp_insert_term(...$args){return ['term_id'=>7];}
require dirname(__DIR__) . '/syndamorph-unpacker-poc/syndamorph-unpacker-poc.php';
function expect($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$package=smup_read_package();expect(is_array($package),'built-in package invalid: '.(is_wp_error($package)?$package->get_error_message():''));
expect(is_wp_error(smup_validate_blocks('<!-- wp:custom/test --><div>x</div><!-- /wp:custom/test -->')),'custom allowed');
$first=smup_import($package);expect(is_array($first),'first import failed: '.(is_wp_error($first)?$first->get_error_message():''));
expect($first['version']==='v000001','first version incorrect');
$second=smup_import($package);expect(is_array($second),'second import failed: '.(is_wp_error($second)?$second->get_error_message():''));
expect($second['version']==='v000002','second version incorrect');
expect($first['id']===$second['id'],'reimport duplicated pattern');
$dir=smup_archive_dir($first['id']);
foreach(['v000001','v000002'] as $v){$d=$dir.'/versions/'.$v; $m=json_decode(file_get_contents($d.'/manifest.json'),true);$c=file_get_contents($d.'/pattern.blocks.html');expect(hash('sha256',$c)===$m['content_sha256'],'checksum corrupt');}
expect($GLOBALS['posts'][$first['id']]->post_type==='wp_block','not WordPress-owned');
expect(str_starts_with($dir,$GLOBALS['archive_root']),'archive inside plugin instead of uploads');
print "PASS: mock package read, Core filtering, wp_block save, reimport, two immutable snapshots, SHA-256, uploads location\n";
