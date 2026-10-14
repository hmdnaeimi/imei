<?php
declare(strict_types=1);
require_once __DIR__.'/lib.php';
try{
 $db=database();$u=require_user($db);$admin=$u['role']==='admin';$method=$_SERVER['REQUEST_METHOD'];
 if($method==='GET'){
  if(isset($_GET['members'])){require_admin($u);json_response(['members'=>query($db,"SELECT email,name,username,active,id AS user_id,updated_at FROM app_users WHERE role='staff' ORDER BY created_at DESC")->fetchAll()]);}
  if(isset($_GET['history'])){$id=(string)$_GET['history'];$t=query($db,'SELECT deleted_at FROM app_trades WHERE id=?',[$id])->fetch();if(!$t||($t['deleted_at']&&!$admin))throw new AppError('معامله یافت نشد.',404);json_response(query($db,'SELECT action,actor_name,at,snapshot FROM app_audit WHERE trade_id=? ORDER BY at DESC,id DESC',[$id])->fetchAll());}
  $deleted=($_GET['deleted']??'')==='1';if($deleted)require_admin($u);$rows=query($db,'SELECT t.*,p.imei1,p.imei2 FROM app_trades t JOIN app_phones p ON p.id=t.phone_id WHERE t.deleted_at IS '.($deleted?'NOT ':'').'NULL ORDER BY t.`date` DESC,t.created_at DESC,t.id DESC')->fetchAll();foreach($rows as &$row)$row['version']=(int)$row['version'];unset($row);json_response(['rows'=>$rows,'admin'=>$admin,'user'=>$u['name']]);
 }
 if(!in_array($method,['POST','PATCH'],true))throw new AppError('روش درخواست نامعتبر است.',405);
 check_csrf($_SERVER['HTTP_X_CSRF_TOKEN']??null);$raw=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);if(!is_array($raw))throw new AppError('درخواست نامعتبر است.');$action=$raw['action']??'';if($method==='POST'&&($raw['_method']??'')==='PATCH')$method='PATCH';
 if($action==='addMember'){
  require_admin($u);$name=trim((string)($raw['name']??''));$email=strtolower(trim((string)($raw['email']??'')));$username=strtolower(trim((string)($raw['username']??'')));$password=(string)($raw['password']??'');
  if(!$name||text_length($name)>100||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254||!preg_match('/^[a-z0-9_.-]{3,60}$/D',$username))throw new AppError('نام، ایمیل و نام کاربری معتبر وارد کنید. نام کاربری با حروف انگلیسی و اعداد باشد.');valid_password($password);
  $db->beginTransaction();$id=uid();insert_row($db,'app_users',['id'=>$id,'name'=>$name,'username'=>$username,'email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'staff','active'=>1,'created_at'=>now(),'updated_at'=>now()]);insert_row($db,'app_user_events',['id'=>uid(),'user_id'=>$id,'action'=>'add','actor_id'=>$u['id'],'at'=>now()]);$db->commit();json_response(['ok'=>true],201);
 }
 if(in_array($action,['memberActive','resetPassword'],true)){
  require_admin($u);$db->beginTransaction();$m=query($db,'SELECT id,role FROM app_users WHERE email=? FOR UPDATE',[strtolower(trim((string)($raw['email']??'')))])->fetch();if(!$m)throw new AppError('کاربر یافت نشد.',404);if($m['role']==='admin')throw new AppError('دسترسی مدیر از این بخش قابل تغییر نیست.');
  if($action==='memberActive'){if(!is_bool($raw['active']??null))throw new AppError('وضعیت نامعتبر است.');query($db,'UPDATE app_users SET active=?,updated_at=?,session_version=session_version+1 WHERE id=?',[$raw['active']?1:0,now(),$m['id']]);$event=$raw['active']?'enable':'disable';}else{$p=(string)($raw['password']??'');valid_password($p);query($db,'UPDATE app_users SET password_hash=?,updated_at=?,session_version=session_version+1 WHERE id=?',[password_hash($p,PASSWORD_DEFAULT),now(),$m['id']]);$event='password';}
  insert_row($db,'app_user_events',['id'=>uid(),'user_id'=>$m['id'],'action'=>$event,'actor_id'=>$u['id'],'at'=>now()]);$db->commit();json_response(['ok'=>true]);
 }
 if($action==='importBackup'){
  require_admin($u);$items=$raw['transactions']??null;if(!is_array($items)||count($items)>5000)throw new AppError('پشتیبان معتبر با حداکثر ۵۰۰۰ معامله انتخاب کنید.');
  $db->beginTransaction();$imported=0;$skipped=0;
  foreach($items as $r){if(!is_array($r)||!is_string($r['id']??null))throw new AppError('ساختار پشتیبان نامعتبر است.');$key=hash('sha256','backup:'.$r['id']);
   if(query($db,'SELECT source_key FROM app_imports WHERE source_key=?',[$key])->fetch()||query($db,'SELECT id FROM app_trades WHERE id=?',[$r['id']])->fetch()){++$skipped;continue;}
   $d=validate_trade(['imei1'=>$r['imei1']??'','imei2'=>$r['imei2']??'','date'=>$r['date']??'','kind'=>$r['kind']??'','firstName'=>$r['first_name']??'','lastName'=>$r['last_name']??'','nationalId'=>$r['national_id']??'','contact'=>$r['contact']??'','model'=>$r['model']??'','color'=>$r['color']??'','memory'=>$r['memory']??'','condition'=>$r['condition']??'','registration'=>$r['registration']??'','notes'=>$r['notes']??'']);
   $id=save_trade($db,$d,$u,$r);insert_row($db,'app_imports',['source_key'=>$key,'trade_id'=>$id]);++$imported;
  }$db->commit();json_response(['imported'=>$imported,'skipped'=>$skipped]);
 }
 if($method==='POST'){
  $d=validate_trade($raw);$db->beginTransaction();$id=save_trade($db,$d,$u);$db->commit();json_response(['id'=>$id],201);
 }
 $db->beginTransaction();$id=(string)($raw['id']??'');$old=query($db,'SELECT * FROM app_trades WHERE id=? FOR UPDATE',[$id])->fetch();if(!$old)throw new AppError('معامله یافت نشد.',404);if((int)$old['version']!==($raw['version']??null))throw new AppError('این معامله تغییر کرده است. صفحه را تازه‌سازی کنید.',409);$action=$action?:'edit';
 if(in_array($action,['delete','restore'],true)){require_admin($u);if(($action==='delete'&&$old['deleted_at'])||($action==='restore'&&!$old['deleted_at']))throw new AppError('وضعیت معامله تغییر کرده است.',409);$fields=['deleted_at'=>$action==='delete'?now():null];}
 else{if($action!=='edit'||$old['deleted_at'])throw new AppError('معامله قابل ویرایش نیست.');$phone=query($db,'SELECT imei1,imei2 FROM app_phones WHERE id=?',[$old['phone_id']])->fetch();$raw['imei1']=$phone['imei1'];$raw['imei2']=$phone['imei2']??'';$fields=trade_columns(validate_trade($raw));}
 $fields+=['updated_by'=>$u['id'],'updated_name'=>$u['name'],'updated_at'=>now(),'version'=>(int)$old['version']+1];$assign=implode(',',array_map(fn($k)=>'`'.$k.'`=?',array_keys($fields)));query($db,'UPDATE app_trades SET '.$assign.' WHERE id=?',[...array_values($fields),$id]);audit_trade($db,$id,$action,$u,$old);$db->commit();json_response(['ok'=>true]);
}catch(Throwable $err){if(isset($db)&&$db->inTransaction())$db->rollBack();if($err instanceof AppError)json_response(['error'=>$err->getMessage()],$err->status);if($err instanceof JsonException)json_response(['error'=>'درخواست نامعتبر است.'],400);if($err instanceof PDOException&&$err->getCode()==='23000')json_response(['error'=>'IMEI، نام کاربری یا ایمیل تکراری است؛ اطلاعات را بررسی کنید.'],409);error_log('Anishop API error: '.get_class($err));json_response(['error'=>'عملیات انجام نشد. ارتباط پایگاه داده را بررسی کنید.'],500);}


