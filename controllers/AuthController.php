<?php
namespace CmuLms;
final class AuthController {
    public function login(\PDO $db): never { if(!Http::post()){View::render('auth/login');exit;} View::checkCsrf();$u=User::byIdNumber(trim($_POST['id_number']??''));if(!$u||!password_verify($_POST['password']??'',$u['password'])){$_SESSION['error']='Invalid school ID or password.';Http::redirect('?route=login');}if($u['disabled_at']){$_SESSION['error']='This account was disabled. Contact your admin.';Http::redirect('?route=login');}Auth::login($u);$s=$db->prepare('UPDATE users SET last_login_at=NOW(),last_activity_at=NOW() WHERE id=?');$s->execute([$u['id']]);Http::redirect('?route=dashboard');}
    public function logout(): never { View::checkCsrf();Auth::logout();Http::redirect('?route=login'); }
}
