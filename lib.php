<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tehran');
ini_set('session.use_strict_mode','1');
session_name('anishop_session');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
session_start();
//if(PHP_SAPI!=='cli'&&(empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off')&&!in_array($_SERVER['SERVER_NAME']??'',['localhost','127.0.0.1'],true)){http_response_code(426);exit('برنامه را با آدرس HTTPS باز کنید.');}
if(isset($_SESSION['last_activity'])&&time()-$_SESSION['last_activity']>28800)unset($_SESSION['uid']);$_SESSION['last_activity']=time();
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');header('Cache-Control: no-store');
$_SESSION['csrf']??=bin2hex(random_bytes(32));
function csrf():string{return $_SESSION['csrf'];}
function check_csrf(?string $token):void{if(!$token||!hash_equals(csrf(),$token))throw new AppError('صفحه را تازه‌سازی کنید و دوباره تلاش کنید.',403);}
class AppError extends RuntimeException{public int $status;public function __construct(string $message,int $status=400){parent::__construct($message);$this->status=$status;}}
function database():PDO{static $db=null;if($db)return $db;if(!is_file(__DIR__.'/config.php'))throw new AppError('ابتدا نصب برنامه را کامل کنید.',503);$c=require __DIR__.'/config.php';$db=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$db->exec("SET time_zone='+00:00'");return $db;}
function query(PDO $db,string $sql,array $args=[]):PDOStatement{$s=$db->prepare($sql);$s->execute($args);return $s;}
function current_user(PDO $db):?array{if(empty($_SESSION['uid']))return null;$u=query($db,'SELECT id,name,username,email,role,active,session_version FROM app_users WHERE id=?',[$_SESSION['uid']])->fetch();if(!$u||!(int)$u['active']||(int)$u['session_version']!==($_SESSION['ver']??0)){unset($_SESSION['uid']);return null;}return $u;}
function require_user(PDO $db):array{$u=current_user($db);if(!$u)throw new AppError('وارد حساب شوید؛ ممکن است دسترسی شما غیرفعال شده باشد.',401);return $u;}
function require_admin(array $u):void{if($u['role']!=='admin')throw new AppError('این عملیات فقط برای مدیر مجاز است.',403);}
function uid():string{return bin2hex(random_bytes(16));}
function now():string{return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');}
function normalize(mixed $v):string{if(!is_scalar($v)&&$v!==null)throw new AppError('اطلاعات نامعتبر است.');return trim(strtr((string)$v,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']));}
function jalali_leap(int $jy):bool{$breaks=[-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178];$jp=$breaks[0];foreach(array_slice($breaks,1) as $jm){$jump=$jm-$jp;if($jy<$jm)break;$jp=$jm;}$n=$jy-$jp;if($jump-$n<6)$n=$n-$jump+intdiv($jump+4,33)*33;$leap=(($n+1)%33-1)%4;if($leap===-1)$leap=4;return $leap===0;}
function text_length(string $s):int{$n=preg_match_all('/./us',$s);if($n===false)throw new AppError('متن نامعتبر است.');return $n;}
function validate_trade(array $raw):array{$d=[];foreach(['imei1','imei2','date','kind','firstName','lastName','nationalId','contact','model','color','memory','condition','registration','notes'] as $k)$d[$k]=normalize($raw[$k]??'');
 if(!preg_match('/^\d{15}$/D',$d['imei1'])||($d['imei2']!==''&&!preg_match('/^\d{15}$/D',$d['imei2'])))throw new AppError('IMEI باید دقیقاً ۱۵ رقم باشد.');
 if($d['imei1']===$d['imei2'])throw new AppError('دو IMEI نباید یکسان باشند.');
 if(!preg_match('/^\d{10}$/D',$d['nationalId']))throw new AppError('کد ملی باید ۱۰ رقم باشد.');
 if($d['contact']!==''&&!preg_match('/^\+?\d{7,15}$/D',$d['contact']))throw new AppError('شماره تماس باید ۷ تا ۱۵ رقم باشد.');
 foreach(['kind'=>['buy','sell'],'condition'=>['new','used'],'registration'=>['company','passenger','none']] as $k=>$options)if(!in_array($d[$k],$options,true))throw new AppError('گزینه‌های معامله را بررسی کنید.');
 foreach(['firstName','lastName','model','color','memory'] as $k)if($d[$k]===''||text_length($d[$k])>100)throw new AppError('مشخصات گوشی و طرف مقابل را کامل کنید.');
 if(text_length($d['notes'])>5000)throw new AppError('توضیحات طولانی است.');
 if(!preg_match('/^(\d{4})\/(\d{2})\/(\d{2})$/D',$d['date'],$m))throw new AppError('تاریخ شمسی را به شکل ۱۴۰۵/۰۱/۰۱ وارد کنید.');
 [$y,$mo,$day]=[(int)$m[1],(int)$m[2],(int)$m[3]];if($y<1300||$y>1500||$mo<1||$mo>12||$day<1||$day>($mo<=6?31:($mo<=11?30:(jalali_leap($y)?30:29))))throw new AppError('تاریخ شمسی معتبر نیست.');return $d;}
function trade_columns(array $d):array{return ['date'=>$d['date'],'kind'=>$d['kind'],'first_name'=>$d['firstName'],'last_name'=>$d['lastName'],'national_id'=>$d['nationalId'],'contact'=>$d['contact'],'model'=>$d['model'],'color'=>$d['color'],'memory'=>$d['memory'],'condition'=>$d['condition'],'registration'=>$d['registration'],'notes'=>$d['notes']];}
function insert_row(PDO $db,string $table,array $data):void{$cols=implode(',',array_map(fn($c)=>'`'.$c.'`',array_keys($data)));query($db,'INSERT INTO '.$table.' ('.$cols.') VALUES ('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));}
function audit_trade(PDO $db,string $id,string $action,array $u,array $snapshot):void{insert_row($db,'app_audit',['id'=>uid(),'trade_id'=>$id,'action'=>$action,'actor_id'=>$u['id'],'actor_name'=>$u['name'],'at'=>now(),'snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);}
function json_response(mixed $data,int $status=200):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function valid_password(string $p):void{if(strlen($p)<10||strlen($p)>72)throw new AppError('رمز باید بین ۱۰ و ۷۲ بایت باشد؛ رمز طولانی با حروف و اعداد انتخاب کنید.');}

function save_trade(PDO $db,array $d,array $u,array $source=[]):string{$matches=query($db,'SELECT p.* FROM app_phones p JOIN app_aliases a ON p.id=a.phone_id WHERE a.imei IN (?,?) ORDER BY a.imei FOR UPDATE',[$d['imei1'],$d['imei2']])->fetchAll();$ids=array_unique(array_column($matches,'id'));if(count($ids)>1)throw new AppError('این دو IMEI متعلق به دو گوشی متفاوت هستند.',409);$phone=$matches[0]??null;$phoneId=$phone['id']??uid();
  if(!$phone){insert_row($db,'app_phones',['id'=>$phoneId,'imei1'=>$d['imei1'],'imei2'=>$d['imei2']?:null]);foreach([$d['imei1'],$d['imei2']] as $i=>$imei)if($imei!=='')insert_row($db,'app_aliases',['imei'=>$imei,'phone_id'=>$phoneId,'slot'=>$i+1]);}
  else{$known=array_filter([$phone['imei1'],$phone['imei2']]);$extra=array_values(array_diff(array_filter([$d['imei1'],$d['imei2']]),$known));if($extra&&(count($known)===2||count($extra)>1))throw new AppError('شماره دوم با IMEIهای این گوشی مطابقت ندارد.',409);if($extra){insert_row($db,'app_aliases',['imei'=>$extra[0],'phone_id'=>$phoneId,'slot'=>2]);query($db,'UPDATE app_phones SET imei2=? WHERE id=?',[$extra[0],$phoneId]);}}
  $id=uid();insert_row($db,'app_trades',['id'=>$id,'phone_id'=>$phoneId]+trade_columns($d)+['created_by'=>$u['id'],'created_name'=>isset($source['created_name'])&&text_length((string)$source['created_name'])<=100?(string)$source['created_name']:$u['name'],'updated_by'=>$u['id'],'updated_name'=>$u['name'],'created_at'=>isset($source['created_at'])&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?Z$/D',(string)$source['created_at'])?$source['created_at']:now(),'updated_at'=>now()]);audit_trade($db,$id,'create',$u,$d);return $id;}


