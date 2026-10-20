<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REMOTE_ADDR'] = '127.0.0.21';
require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/inquiries.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/migrate.php';
if (db_value('SELECT DATABASE()') !== 'suppex_test') { throw new RuntimeException('Disposable database required'); }
$pass = 0; $fail = 0;
function ok(string $name, bool $yes): void { global $pass,$fail; if ($yes) { $pass++; echo "  ok   $name\n"; } else { $fail++; echo "  FAIL $name\n"; } }
function child(array $args, array $env = []): string {
    $p = proc_open(array_merge([PHP_BINARY], $args), [1=>['pipe','w'],2=>['pipe','w']], $pipes, __DIR__, array_merge(getenv(), $env));
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($p);
    if ($code !== 0) { throw new RuntimeException($out . $err); } return $out;
}
foreach(db_all("SELECT id FROM products WHERE slug='zz-group-inquiry'") as $old) {
 $oid=(int)$old['id'];
 db_query('DELETE FROM stock_inquiries WHERE product_id=?',[$oid]);
 db_query('DELETE vv FROM product_variant_values vv JOIN product_variants v ON v.id=vv.variant_id WHERE v.product_id=?',[$oid]);
 db_query('DELETE FROM product_variants WHERE product_id=?',[$oid]);
 db_query('DELETE av FROM product_attribute_values av JOIN product_attributes a ON a.id=av.attribute_id WHERE a.product_id=?',[$oid]);
 db_query('DELETE FROM product_attributes WHERE product_id=?',[$oid]);
 db_query('DELETE FROM products WHERE id=?',[$oid]);
}
$pid = db_insert("INSERT INTO products (slug,name_fa,price,price_mode,is_active,in_stock) VALUES ('zz-group-inquiry','محصول آزمون درخواست',1000000,'manual',1,1)");
db_query("INSERT INTO product_sizes (product_id,ext_id,label,price,stock_qty) VALUES (?,'900','۹۰۰ گرم',1000000,0)",[$pid]);
db_query("INSERT INTO product_flavors (product_id,ext_id,name) VALUES (?,'vanilla','وانیل'),(?,'choco','شکلات')",[$pid,$pid]);
variants_adopt($pid);
db_query('UPDATE product_variants SET requires_inquiry = 1, stock_qty = 0 WHERE product_id = ?',[$pid]);
$row = static fn(string $ext = '900|vanilla'): array => db_one('SELECT * FROM product_variants WHERE product_id = ? AND ext_id = ?',[$pid,$ext]);
$payload = static fn(int $qty = 3): array => ['slug'=>'zz-group-inquiry','variant'=>'900|vanilla','qty'=>$qty,'name'=>'مشتری آزمایشی','phone'=>'09121119988','request_key'=>bin2hex(random_bytes(16))];
$b = $payload(); $made = inquiry_create($b); $r = $made['row'];
ok('a request persists the exact flavour and quantity, without changing stock', $made['ok'] && $r['variant_label'] === '۹۰۰ گرم · وانیل' && (int)$r['qty']===3 && (int)$row()['stock_qty']===0);
ok('the response deadline is thirty minutes', strtotime($r['respond_by'])-strtotime($r['created_at'])===1800);
ok('there is no order or reservation in an enquiry', !isset($r['order_id']) && $row()['requires_inquiry']==1);
$again = inquiry_create($b);
ok('an identical retry returns the same record', $again['ok'] && $again['duplicate'] && $again['row']['id']===$r['id']);
$wrong = $b; $wrong['qty']=2;
ok('an idempotency key cannot be reused with a different quantity', !inquiry_create($wrong)['ok']);
ok('a second pending enquiry for that phone and flavour is refused', !inquiry_create($payload())['ok']);
foreach (['0','-1','21','1.5','3junk'] as $q) { $x=$payload(); $x['qty']=$q; ok('invalid quantity '.$q.' is refused',!inquiry_create($x)['ok']); }
$x=$payload(); $x['phone']='123'; ok('an invalid mobile cannot create a request',!inquiry_create($x)['ok']);
$x=$payload(); $x['website']='spam'; ok('the honeypot is checked',!inquiry_create($x)['ok']);
ok('tracking needs the matching phone', inquiry_find($r['code'],'09120000000')===null && inquiry_find($r['code'],$b['phone'])!==null);
$public=inquiry_public($r);
ok('public state has no customer or notification credentials',!isset($public['phone'],$public['customer_name'],$public['request_key']) && !$public['available']);
child([__DIR__.'/inquiry-admin-post.php',(string)$r['id'],'approved','bad']);
ok('admin mutation rejects missing CSRF',inquiry_get((int)$r['id'])['status']==='pending' && (int)$row()['stock_qty']===0);
child([__DIR__.'/inquiry-admin-post.php',(string)$r['id'],'approved','anonymous']);
ok('admin mutation requires authentication',inquiry_get((int)$r['id'])['status']==='pending');
child([__DIR__.'/inquiry-admin-post.php',(string)$r['id'],'approved','valid']);
ok('the actual admin approval adds exactly the requested count',inquiry_get((int)$r['id'])['status']==='approved' && (int)$row()['stock_qty']===3 && !(bool)$row()['requires_inquiry']);
ok('other flavours keep their own count and enquiry flag',(int)$row('900|choco')['stock_qty']===0 && (bool)$row('900|choco')['requires_inquiry']);
inquiry_decide((int)$r['id'],'approved','repeat'); inquiry_decide((int)$r['id'],'rejected','repeat');
ok('repeated or conflicting decisions cannot add stock twice',(int)$row()['stock_qty']===3 && inquiry_get((int)$r['id'])['status']==='approved');
$approved=inquiry_public(inquiry_get((int)$r['id']));
ok('the approved public state can lead back to buying',$approved['available']);
ok('detail uses approved variant stock even when the old size has zero',product_get('zz-group-inquiry')['inStock']);
$listed=array_values(array_filter(products_list(['limit'=>200]),static fn(array $p):bool=>$p['slug']==='zz-group-inquiry'));
ok('catalogue uses approved variant stock even when the old size has zero',$listed[0]['inStock']);
$prices=json_decode(child([__DIR__.'/apiget.php','prices',base64_encode(json_encode(['slugs'=>'zz-group-inquiry']))]),true);
ok('cart reconciliation uses approved variant stock instead of the old size',$prices['prices'][0]['available']);
ok('retry after approval still returns the original request',inquiry_create($b)['row']['id']===$r['id']);
$api = json_decode(child([__DIR__.'/apipost.php','inquirystate',base64_encode(json_encode(['code'=>$r['code'],'phone'=>$b['phone']]))]),true);
ok('the public tracking API returns the decision without the phone',$api['ok'] && $api['status']==='approved' && !isset($api['phone']));
$order=order_create(['items'=>[['slug'=>'zz-group-inquiry','sizeId'=>'900','flavorId'=>'vanilla','qty'=>2]],'customer'=>['name'=>'تست','phone'=>'09120000000','address'=>'آدرس تستی بدون ارسال','postal'=>'1234567890']]);
ok('normal checkout reserves approved stock',$order['ok'] && (int)$row()['stock_qty']===1);
if ($order['ok']) { order_set_status((int)$order['order']['id'],'cancelled',['name'=>'test']); }
ok('cancellation restores that approved stock',(int)$row()['stock_qty']===3);
db_query('UPDATE product_variants SET requires_inquiry=1 WHERE product_id=?',[$pid]);
$x=$payload(2); $x['phone']='09121119987'; $more=inquiry_create($x)['row'];
$procs=[];
for($i=0;$i<2;$i++) { $procs[]=proc_open([PHP_BINARY,__DIR__.'/inquiry-action.php',(string)$more['id'],'approved'],[1=>['pipe','w'],2=>['pipe','w']],$ps); $allPipes[]=$ps; }
foreach($procs as $i=>$p) { $out=stream_get_contents($allPipes[$i][1]);$err=stream_get_contents($allPipes[$i][2]);fclose($allPipes[$i][1]);fclose($allPipes[$i][2]);ok('concurrent approval process '.$i.' completes',proc_close($p)===0 && str_contains($out,'"ok":true') && $err===''); }
ok('concurrent approvals increment existing stock only once',(int)$row()['stock_qty']===5);
ok('the audit stores before and after quantities',(int)inquiry_get((int)$more['id'])['stock_before']===3 && (int)inquiry_get((int)$more['id'])['stock_after']===5);
db_query('UPDATE product_variants SET requires_inquiry=1 WHERE product_id=?',[$pid]);
$x=$payload(1); $x['phone']='09121119986'; $no=inquiry_create($x)['row'];
inquiry_decide((int)$no['id'],'rejected','test');
ok('rejection changes no stock and is visible to the buyer',(int)$row()['stock_qty']===5 && inquiry_public(inquiry_get((int)$no['id']))['status']==='rejected');
$x=$payload(1); $x['phone']='09121119985'; $off=inquiry_create($x)['row'];
db_query('UPDATE product_variants SET is_active=0 WHERE product_id=?',[$pid]);
ok('approval cannot reopen a variant manually disabled by staff',!inquiry_decide((int)$off['id'],'approved','test')['ok'] && (int)$row()['stock_qty']===5);
db_query('UPDATE product_variants SET is_active=1 WHERE product_id=?',[$pid]);
$x=$payload(2); $x['phone']='09121119984';$encoded=base64_encode(json_encode($x));$procs=[];$allPipes=[];$ids=[];
for($i=0;$i<2;$i++) { $procs[]=proc_open([PHP_BINARY,__DIR__.'/inquiry-action.php','create',$encoded,'127.0.0.'.(40+$i)],[1=>['pipe','w'],2=>['pipe','w']],$ps);$allPipes[]=$ps; }
foreach($procs as $i=>$p) { $out=stream_get_contents($allPipes[$i][1]);$err=stream_get_contents($allPipes[$i][2]);fclose($allPipes[$i][1]);fclose($allPipes[$i][2]);$res=json_decode($out,true);ok('concurrent create process '.$i.' completes',proc_close($p)===0 && !empty($res['ok']) && $err==='');$ids[]=$res['row']['id']??0; }
ok('concurrent retries from different IPs create just one request',$ids[0]>0 && $ids[0]===$ids[1]);
$_SERVER['HTTP_ORIGIN']='https://attacker.invalid'; ok('cross-origin request forms are refused',!inquiry_same_origin()); unset($_SERVER['HTTP_ORIGIN']);
$_SERVER['HTTP_SEC_FETCH_SITE']='cross-site'; ok('cross-site form submissions are refused',!inquiry_same_origin()); unset($_SERVER['HTTP_SEC_FETCH_SITE']);
$savedGroup=setting('telegram_group_id',''); settings_save(['telegram_group_id'=>'-100987654321']);
$sock=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$addr=stream_socket_get_name($sock,false);fclose($sock);$port=(int)substr($addr,strrpos($addr,':')+1);
$log=__DIR__.'/tmp/inquiry-tg.log';$failFile=__DIR__.'/tmp/inquiry-tg-fail';@unlink($log);@unlink($failFile);
$server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/inquiry-faketg.php'],[1=>['file',__DIR__.'/tmp/inquiry-tg-server.log','a'],2=>['file',__DIR__.'/tmp/inquiry-tg-server.log','a']],$unused);
$env=['SUPPEX_CONFIG'=>__DIR__.'/test-config-tg.php','SUPPEX_FAKETG'=>'http://127.0.0.1:'.$port,'SUPPEX_FAKETG_CHAT'=>'PRIVATE_CHAT_MUST_NOT_RECEIVE'];
try {
  for($i=0;$i<30;$i++){ $c=@fsockopen('127.0.0.1',$port);if($c){fclose($c);break;}usleep(100000); }
  child([__DIR__.'/inquiry-action.php','notify',(string)$off['id']],$env);
  child([__DIR__.'/inquiry-action.php','notify',(string)$off['id']],$env);
  $calls=array_map(static fn($s)=>json_decode($s,true),file($log,FILE_IGNORE_NEW_LINES));
  ok('the bot sends once to the configured group and never private chat',count($calls)===1 && $calls[0]['chat']==='-100987654321');
  ok('the group message includes the admin link but no customer phone or name',str_contains($calls[0]['text'],'/admin/inquiries.php?id='.$off['id']) && !str_contains($calls[0]['text'],$off['phone']) && !str_contains($calls[0]['text'],$off['customer_name']));
  touch($failFile); child([__DIR__.'/inquiry-action.php','notify',(string)$no['id']],$env);
  ok('a delivery failure stays recorded for retry',inquiry_get((int)$no['id'])['notify_state']==='failed');
  unlink($failFile);child([__DIR__.'/inquiry-action.php','notify',(string)$no['id']],$env);
  ok('retrying a failed group notification can succeed',inquiry_get((int)$no['id'])['notify_state']==='sent');
  settings_save(['telegram_group_id'=>'']); $count=count(file($log)); child([__DIR__.'/inquiry-action.php','notify',(string)$r['id']],$env);
  ok('missing group never falls back to private chat',count(file($log))===$count && inquiry_get((int)$r['id'])['notify_state']==='failed');
  settings_save(['telegram_group_id'=>'123456']);child([__DIR__.'/inquiry-action.php','notify',(string)$r['id']],$env);
  ok('a positive private-chat id is not accepted as a group',count(file($log))===$count);
} finally { proc_terminate($server);proc_close($server);@unlink($failFile);settings_save(['telegram_group_id'=>$savedGroup]); }
db_query('DELETE FROM stock_inquiries WHERE product_id=?',[$pid]);
db_query('DELETE vv FROM product_variant_values vv JOIN product_variants v ON v.id=vv.variant_id WHERE v.product_id=?',[$pid]);
db_query('DELETE FROM product_variants WHERE product_id=?',[$pid]);
db_query('DELETE av FROM product_attribute_values av JOIN product_attributes a ON a.id=av.attribute_id WHERE a.product_id=?',[$pid]);
db_query('DELETE FROM product_attributes WHERE product_id=?',[$pid]);db_query('DELETE FROM products WHERE id=?',[$pid]);
echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";exit($fail?1:0);
