<?php
namespace CmuLms;

final class PasswordRecoveryController {
    public static function handle(\PDO $db,string $route): never {
        header('Cache-Control: no-store');
        header('Referrer-Policy: same-origin');
        header('X-Robots-Tag: noindex, nofollow');
        $recovery=new PasswordRecovery($db);
        $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');$agent=(string)($_SERVER['HTTP_USER_AGENT']??'');
        if($_SERVER['REQUEST_METHOD']==='POST'){
            View::checkCsrf();
            try{
                if($route==='forgot-password'){
                    unset($_SESSION['password_recovery_grant']);
                    $id=is_string($_POST['id_number']??null)?trim($_POST['id_number']):'';
                    $last=is_string($_POST['last_name']??null)?trim($_POST['last_name']):'';
                    $result=$recovery->verifyIdentity($id,$last,$ip,$agent);
                    if($result['ok']){session_regenerate_id(true);$_SESSION['password_recovery_grant']=$result['grant'];unset($_SESSION['_csrf'],$_SESSION['recovery_old']);Http::redirect('?route=reset-password');}
                    $_SESSION['recovery_old']=['id_number'=>mb_substr($id,0,64),'last_name'=>mb_substr($last,0,120)];
                    $_SESSION['error']=$result['error'];$_SESSION['recovery_retry_until']=time()+(int)($result['retry_after']??0);
                    Http::redirect('?route=forgot-password');
                }
                $result=$recovery->resetPassword($_SESSION['password_recovery_grant']??null,
                    is_string($_POST['new_password']??null)?$_POST['new_password']:'',
                    is_string($_POST['new_password_confirmation']??null)?$_POST['new_password_confirmation']:'',$ip,$agent);
                if($result['ok']){
                    $_SESSION=[];session_regenerate_id(true);
                    $_SESSION['flash']='Password reset successfully. Sign in with your school ID number and new password.';
                    Http::redirect('?route=login');
                }
                $_SESSION['error']=$result['error'];
                if(!empty($result['expired'])){unset($_SESSION['password_recovery_grant']);Http::redirect('?route=forgot-password');}
                Http::redirect('?route=reset-password');
            }catch(\Throwable $e){error_log('Password recovery unavailable: '.$e->getMessage());$_SESSION['error']='Password recovery is temporarily unavailable. Please try again later.';Http::redirect('?route=forgot-password');}
        }
        if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET, POST');exit('Method not allowed.');}
        if($route==='reset-password'&&!PasswordRecovery::validGrant($_SESSION['password_recovery_grant']??null)){
            unset($_SESSION['password_recovery_grant']);$_SESSION['error']='Verify your school ID number and last name before resetting your password.';Http::redirect('?route=forgot-password');
        }
        if($route==='forgot-password')unset($_SESSION['password_recovery_grant']);
        View::render($route==='forgot-password'?'auth/forgot-password':'auth/reset-password');
        exit;
    }
}
