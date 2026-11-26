<?php
namespace CmuLms;
final class Auth {
 public static function user():?array{return $_SESSION['user']??null;}
 public static function login(array $u):void{session_regenerate_id(true);$_SESSION['user']=$u;}
 public static function validateSession(\PDO $db):bool {
  $cached=self::user();if(!$cached)return true;
  $st=$db->prepare('SELECT * FROM users WHERE id=?');$st->execute([(int)$cached['id']]);$fresh=$st->fetch();
  if(!$fresh||!empty($fresh['disabled_at'])||!is_string($cached['password']??null)||!hash_equals($fresh['password'],$cached['password'])){
   $_SESSION=[];session_regenerate_id(true);$_SESSION['error']='Your account or password has changed. Please sign in again.';return false;
  }
  $_SESSION['user']=$fresh;return true;
 }
 public static function logout():void{$_SESSION=[];session_destroy();}
 public static function require():array{$u=self::user();if(!$u){header('Location: ?route=login');exit;}return $u;}
 public static function role(string ...$roles):array{$u=self::require();if(!in_array($u['role'],$roles,true)){http_response_code(403);exit('Forbidden');}return $u;}
}
