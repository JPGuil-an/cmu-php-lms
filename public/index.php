<?php
$cfg=require __DIR__.'/../config.php';
date_default_timezone_set('Asia/Manila');
session_name($cfg['session_name'] ?? 'cmu_lms_php');
session_set_cookie_params(['httponly'=>true,'secure'=>!empty($cfg['secure_cookies']),'samesite'=>'Lax','path'=>'/']);
session_start();
$autoload=__DIR__.'/../vendor/autoload.php';
if(is_file($autoload)) require $autoload;
require __DIR__.'/../core/Database.php';
require __DIR__.'/../core/Auth.php';
require __DIR__.'/../core/Http.php';
require __DIR__.'/../core/View.php';
require __DIR__.'/../core/StudentCourseAccess.php';
foreach(['Model','User','Course','Exam','Task','Announcement','Attendance','Grade','QuestionPool','Notification','Message','Submission','Material'] as $file) require __DIR__.'/../models/'.$file.'.php';

try {
    $db=\CmuLms\Database::connect($cfg['db']);
    foreach(['User','Course','Exam','Task','Announcement','Attendance','Grade','QuestionPool','Notification','Message','Submission','Material'] as $model) {
        ('\\CmuLms\\'.$model)::setDb($db);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Database unavailable · CMU LMS</title><style>body{font:16px Arial;background:#f4f7f5;color:#143f35;margin:0;padding:12vh 8%;text-align:center}.box{max-width:620px;margin:auto;background:#fff;border:1px solid #d5e3dc;border-radius:16px;padding:36px}h1{color:#9b2c2c}p{line-height:1.6}</style><div class="box"><h1>Database temporarily unavailable</h1><p>CMU LMS cannot connect to the database right now. Please start MySQL or contact the system administrator.</p><p>Try refreshing after the database service is available.</p></div>';
    exit;
}

if (!isset($_GET['route'])) {
    $path=trim(rawurldecode((string)(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)??'/')),'/');
    $path=preg_replace('#^(?:LMS PHp/)?(?:public/)?#i','',$path);
    if ($path==='login') $_GET['route']='login';
    elseif ($path==='forgot-password') $_GET['route']='forgot-password';
    elseif ($path==='reset-password') $_GET['route']='reset-password';
    elseif (preg_match('#^(?:students|teachers|admins?)/dashboard$#i',$path)) $_GET['route']='dashboard';
    elseif ($path==='notifications') $_GET['route']='notifications';
    elseif ($path==='messages') $_GET['route']='messages';
    elseif ($path==='settings') $_GET['route']='settings';
    elseif (preg_match('#^(?:students|teachers)/classes$#i',$path)) $_GET['route']='classes';
    elseif (preg_match('#^(?:students|teachers)/calendar$#i',$path)) $_GET['route']='calendar';
    elseif (preg_match('#^(?:students|teachers|admins?)/gradebook$#i',$path)) $_GET['route']='gradebook';
    elseif ($path==='gradebook') $_GET['route']='gradebook';
    elseif ($path==='admin/users') $_GET['route']='admin-users';
    elseif ($path==='admin/courses') $_GET['route']='admin-courses';
    elseif (preg_match('#^(?:students|teachers|admins?)/class/([^/]+)(?:/(?:home|activities|announcements|students|grades|classmates))?$#i',$path,$m)) {
        $st=$db->prepare('SELECT id FROM courses WHERE code=? LIMIT 1'); $st->execute([$m[1]]);
        if ($id=$st->fetchColumn()) { $_GET['route']='course'; $_GET['id']=(int)$id; }
    } elseif (preg_match('#^students/exams/(\d+)$#i',$path,$m)) {
        $_GET['route']='exam-start'; $_GET['exam_id']=(int)$m[1];
    } elseif (preg_match('#^teachers/exams/(\d+)$#i',$path,$m)) {
        $_GET['route']='exam-stats'; $_GET['id']=(int)$m[1];
    }
}

$route=$_GET['route']??(\CmuLms\Auth::user()?'dashboard':'login');
function view(string $name,array $data=[]):void { \CmuLms\View::render($name,$data); }
function notifyCourseStudents(int $course,string $title,string $body,string $url):void {
    global $db,$u;
    $st=$db->prepare('SELECT user_id FROM enrollments WHERE course_id=? AND status="active"'); $st->execute([$course]);
    $ins=$db->prepare('INSERT INTO notifications(user_id,title,body,type,target_url,created_at,updated_at) VALUES(?,?,?,"course",?,NOW(),NOW())');
    foreach($st as $row) { if(isset($u['id'])&&(int)$row['user_id']===(int)$u['id']) continue; $ins->execute([(int)$row['user_id'],$title,$body,$url]); }
}

if (!\CmuLms\Auth::validateSession($db) && !in_array($route,['login','forgot-password','reset-password'],true)) \CmuLms\Http::redirect('?route=login');
if (in_array($route,['forgot-password','reset-password'],true)) {
    require __DIR__.'/../services/PasswordRecovery.php';
    require __DIR__.'/../controllers/PasswordRecoveryController.php';
    \CmuLms\PasswordRecoveryController::handle($db,$route);
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action']??'')==='login') {
    register_shutdown_function(function() use ($db) {
        if (!empty($_SESSION['user'])) return;
        $id=trim((string)($_POST['id_number']??'')); $st=$db->prepare('SELECT id FROM users WHERE id_number=?'); $st->execute([$id]);
        $db->prepare('INSERT INTO audit_logs(user_id,action,auditable_type,metadata,ip_address,user_agent,created_at,updated_at) VALUES(?,?,?,?,?,?,NOW(),NOW())')->execute([$st->fetchColumn()?:null,'login_failed','auth',json_encode(['id_number'=>$id]),$_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??'']);
    });
}

$u=\CmuLms\Auth::user();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    \CmuLms\View::checkCsrf();
    $action=$_POST['_action']??'';
    if ($action==='logout') {
        if ($u) $db->prepare('UPDATE users SET last_logout_at=NOW(),last_activity_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$u['id']]);
        \CmuLms\Auth::logout(); \CmuLms\Http::redirect('?route=login');
    }
    if ($action==='login') {
        $id=trim((string)($_POST['id_number']??'')); $key=$id.'|'.($_SERVER['REMOTE_ADDR']??'unknown'); $candidate=\CmuLms\User::byIdNumber($id);
        if(($_SESSION['login_policy_version']??0)!==2){unset($_SESSION['login_failures'],$_SESSION['login_lockouts']);$_SESSION['login_policy_version']=2;}
        $now=time();$lockUntil=max((int)($_SESSION['login_lockouts'][$key]??0),!empty($candidate['locked_until'])?(int)strtotime($candidate['locked_until']):0);
        if($lockUntil>$now){$_SESSION['login_lock_until']=$lockUntil;$_SESSION['error']='Too many invalid attempts (5 of 5). Try again in '.($lockUntil-$now).' seconds.';\CmuLms\Http::redirect('?route=login');}
        if($lockUntil>0){unset($_SESSION['login_failures'][$key],$_SESSION['login_lockouts'][$key]);if($candidate)$db->prepare('UPDATE users SET locked_until=NULL WHERE id=?')->execute([(int)$candidate['id']]);}
        unset($_SESSION['login_lock_until']);
        $valid=$candidate&&password_verify((string)($_POST['password']??''),$candidate['password']);
        if (!$valid) {
            $failures=min(5,(int)($_SESSION['login_failures'][$key]??0)+1);$_SESSION['login_failures'][$key]=$failures;
            if($failures>=5){
                $_SESSION['login_lockouts'][$key]=$now+10;$_SESSION['login_lock_until']=$now+10;
                if($candidate)$db->prepare('UPDATE users SET locked_until=DATE_ADD(NOW(),INTERVAL 10 SECOND) WHERE id=?')->execute([(int)$candidate['id']]);
                $_SESSION['error']='Invalid login attempt 5 of 5. Account locked for 10 seconds.';
            }else{$_SESSION['error']='Invalid school ID or password. Attempt '.$failures.' of 5. '.(5-$failures).' attempt(s) remaining.';}
            \CmuLms\Http::redirect('?route=login');
        }
        if (!empty($candidate['disabled_at'])) { $_SESSION['error']='This account was disabled. Contact your admin.'; \CmuLms\Http::redirect('?route=login'); }
        unset($_SESSION['login_failures'][$key],$_SESSION['login_lockouts'][$key],$_SESSION['login_lock_until']); \CmuLms\Auth::login($candidate); $db->prepare('UPDATE users SET locked_until=NULL,last_login_at=NOW(),last_activity_at=NOW() WHERE id=?')->execute([(int)$candidate['id']]); \CmuLms\Http::redirect('?route=dashboard');
    }
}

$u=\CmuLms\Auth::user();
if ($u&&!empty($_SESSION['user'])&&!isset($_SESSION['login_audited'])) { $db->prepare('INSERT INTO audit_logs(user_id,action,auditable_type,metadata,ip_address,user_agent,created_at,updated_at) VALUES(?,?,?,?,?,?,NOW(),NOW())')->execute([$u['id'],'login_success','auth',json_encode(['role'=>$u['role']??null]),$_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??'']); $_SESSION['login_audited']=1; }

if ($route==='login') { view('auth/login'); exit; }
if ($route==='password-change') {
    if (!$u) \CmuLms\Http::redirect('?route=login');
    if ($_SERVER['REQUEST_METHOD']==='POST') { $new=(string)($_POST['new_password']??''); if(strlen($new)<8||strlen($new)>72||$new!==($_POST['new_password_confirmation']??'')){$_SESSION['error']='Password must be 8–72 bytes and match confirmation.';\CmuLms\Http::redirect('?route=password-change');} $hash=password_hash($new,PASSWORD_DEFAULT);$db->prepare('UPDATE users SET password=?,must_change_password=0,updated_at=NOW() WHERE id=?')->execute([$hash,(int)$u['id']]); $u['password']=$hash;$u['must_change_password']=0; $_SESSION['user']=$u; $_SESSION['flash']='Password changed successfully.'; \CmuLms\Http::redirect('?route=dashboard'); }
    view('auth/password-change',['user'=>$u]); exit;
}
if (!$u) \CmuLms\Http::redirect('?route=login');

$fingerprint=hash('sha256',($_SERVER['HTTP_USER_AGENT']??'').'|'.($_SERVER['REMOTE_ADDR']??'')); $now=time();
if (!empty($_SESSION['last_seen'])&&$now-(int)$_SESSION['last_seen']>1800) { \CmuLms\Auth::logout(); $_SESSION['error']='Your session expired due to inactivity. Please sign in again.'; \CmuLms\Http::redirect('?route=login'); }
if (!empty($_SESSION['session_fingerprint'])&&!hash_equals($_SESSION['session_fingerprint'],$fingerprint)) { \CmuLms\Auth::logout(); $_SESSION['error']='Your session is no longer valid. Please sign in again.'; \CmuLms\Http::redirect('?route=login'); }
$_SESSION['last_seen']=$now; $_SESSION['session_fingerprint']=$fingerprint; $db->prepare('UPDATE users SET last_activity_at=NOW() WHERE id=?')->execute([(int)$u['id']]);
if (!empty($u['must_change_password'])) \CmuLms\Http::redirect('?route=password-change');

require __DIR__.'/../routes_extra.php';
http_response_code(404); echo 'Page not found';
