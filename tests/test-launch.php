<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
require __DIR__ . '/../lib/bootstrap.php';
if (db_value('SELECT DATABASE()') !== 'suppex_test') { exit(1); }
require SUPPEX_ROOT . '/lib/auth.php';
require SUPPEX_ROOT . '/lib/orders.php';
require SUPPEX_ROOT . '/lib/catalog_photos.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $condition): void { global $pass,$fail; if($condition){$pass++;echo "  ok   $name\n";}else{$fail++;echo "  FAIL $name\n";} }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
db_query('DELETE FROM login_attempts');
$pw = 'launch-audit-test-password';
$username = 'launch-auth-' . bin2hex(random_bytes(4));
db_query('INSERT INTO admins(username,password_hash,name,role) VALUES(?,?,?,?)',[$username,password_hash($pw,PASSWORD_DEFAULT),'test','support']);
$id = (int)db()->lastInsertId();
ok('genuine login succeeds', auth_login($username,$pw)['ok']);
$sessionA = $_SESSION;
auth_login($username,$pw); $sessionB = $_SESSION;
db_query('UPDATE admins SET role=? WHERE id=?',['manager',$id]);
$_SESSION=$sessionA;
ok('role reduction reaches an already open session', !auth_can('maintenance') && auth_user()['role']==='manager');
db_query('UPDATE admins SET is_active=0 WHERE id=?',[$id]);
$_SESSION=$sessionB;
ok('disabled principal loses an independent session', auth_user()===null);
db_query('UPDATE admins SET is_active=1 WHERE id=?',[$id]);
$_SESSION=$sessionA;
unset($_SESSION['admin_auth']);
ok('pre-upgrade session must sign in again', auth_user()===null);
auth_login($username,$pw);$sessionA=$_SESSION;auth_login($username,$pw);$sessionB=$_SESSION;
db_query('UPDATE admins SET password_hash=? WHERE id=?',[password_hash('new-test-password',PASSWORD_DEFAULT),$id]);
$_SESSION=$sessionA;ok('password reset revokes first session',auth_user()===null);
$_SESSION=$sessionB;ok('password reset revokes second session',auth_user()===null);
db_query('DELETE FROM admins WHERE id=?',[$id]);
$post=static function(string $page,array $fields):array {
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/maintenance-post.php').' '.escapeshellarg($page).' '.escapeshellarg(base64_encode(json_encode($fields)));
    return json_decode((string)shell_exec($cmd),true) ?: [];
};
foreach(['users.php','migrate.php','preflight.php'] as $page) {
    ok($page.' rejects missing CSRF',($post($page,['action'=>'prepare-launch'])['status']??0)===419);
}
$new='launch-created-'.bin2hex(random_bytes(4));
$fields=['action'=>'create','username'=>$new,'name'=>'new tester','password'=>$pw,'role'=>'manager','_csrf'=>'fixture-csrf'];
ok('user form creates without a target id',($post('users.php',$fields)['status']??0)===302 && (int)db_value('SELECT COUNT(*) FROM admins WHERE username=?',[$new])===1);
ok('duplicate username refused',!empty($post('users.php',$fields)['errors']) && (int)db_value('SELECT COUNT(*) FROM admins WHERE username=?',[$new])===1);
ok('valid CSRF still runs migrations',($post('migrate.php',['_csrf'=>'fixture-csrf'])['status']??0)===200);
db_query('DELETE FROM admins WHERE username=?',[$new]);
settings_save(['free_shipping_threshold'=>'3500000']);
ok('PHP shipping announcement reads settings',strpos(shipping_announcement_html(),'۳,۵۰۰,۰۰۰')!==false);
settings_save(['free_shipping_threshold'=>'0']);
ok('disabled free shipping does not promise free delivery',strpos(shipping_announcement_html(),'ارسال رایگان')===false);
settings_save(['free_shipping_threshold'=>'3500000']);
$uncounted=['status'=>'new','items'=>[['stock_from'=>null]]];
ok('uncounted stock requires confirmation before paying',order_requires_stock_confirmation($uncounted));
ok('reserved counted stock needs no extra confirmation',!order_requires_stock_confirmation(['status'=>'new','items'=>[['stock_from'=>'product']]]));
ok('verified payment does not ask for stock confirmation',!order_requires_stock_confirmation(['status'=>'paid','items'=>[['stock_from'=>null]]]));
$fixture=[];
$add=static function(string $slug,int $price,string $image) use (&$fixture):int {
    $id=db_insert('INSERT INTO products(slug,name_fa,price,image,stock_qty) VALUES(?,?,?,?,11)',[$slug,$slug,$price,$image]);$fixture[]=$id;return $id;
};
$photo='assets/images/products/verified-webp-20261014/mutant-amino-300.webp';
$bad=$add('launch-unpriced-'.bin2hex(random_bytes(3)),1000000000,$photo);
$missing=$add('launch-no-photo-'.bin2hex(random_bytes(3)),2000000,'');
$good=$add('launch-ready-'.bin2hex(random_bytes(3)),2000000,$photo);
db_query('UPDATE products SET brand=? WHERE id=?',['Mutant',$good]);
db_query("INSERT INTO brands(slug,name,logo) VALUES('mutant','Mutant','') ON DUPLICATE KEY UPDATE logo=''");
db_query('UPDATE products SET brand=? WHERE id=?',['Zoomad Labs',$missing]);
db_query("INSERT INTO brands(slug,name,logo) VALUES('zoomad-labs','Zoomad Labs','assets/icons/favicon.svg') ON DUPLICATE KEY UPDATE logo='assets/icons/favicon.svg'");
$slug=(string)db_value('SELECT slug FROM products WHERE id=?',[$bad]);
ok('order server refuses placeholder even if browser supplies a price',order_price_line(['slug'=>$slug,'qty'=>1,'price'=>1])===null);
db_query('UPDATE products SET price=2000000 WHERE id=?',[$bad]);
$attribute=variants_attribute_upsert($bad,'flavor','طعم',0);
$value=variants_value_upsert($attribute,'test','Test','',0);
$variant=db_insert('INSERT INTO product_variants(product_id,ext_id,price,is_active) VALUES(?,?,?,1)',[$bad,'test',1000000000]);
db_query('INSERT INTO product_variant_values(variant_id,value_id) VALUES(?,?)',[$variant,$value]);
ok('order server refuses placeholder variant',order_price_line(['slug'=>$slug,'flavorId'=>'test','qty'=>1])===null);
$before=db_one('SELECT price,stock_qty FROM products WHERE id=?',[$good]);
$result=launch_prepare();
ok('missing brand logo is filled with reviewed WebP',str_ends_with((string)db_value("SELECT logo FROM brands WHERE slug='mutant'"),'mutant.webp'));
ok('custom brand logo is preserved',db_value("SELECT logo FROM brands WHERE slug='zoomad-labs'")==='assets/icons/favicon.svg');
ok('incomplete price is hidden',!(int)db_value('SELECT is_active FROM products WHERE id=?',[$bad]));
ok('missing exact photo is hidden',!(int)db_value('SELECT is_active FROM products WHERE id=?',[$missing]));
ok('ready product remains active',(int)db_value('SELECT is_active FROM products WHERE id=?',[$good])===1);
ok('launch preparation preserves price and stock',$before===db_one('SELECT price,stock_qty FROM products WHERE id=?',[$good]));
ok('launch preparation can be repeated',launch_prepare()['hidden']===[]);
foreach($fixture as $pid){db_query('DELETE FROM products WHERE id=?',[$pid]);}
echo "\n$pass passed, $fail failed\n";
exit($fail?1:0);
