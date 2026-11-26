<?php
namespace CmuLms;
final class Http {
 public static function wantsJson():bool{return str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT']??'')),'application/json');}
 public static function json(array $data,int $status=200):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
 public static function redirect(string $url):never{
  if(self::wantsJson()){
   $login=str_contains($url,'route=login');
   self::json(['ok'=>false,'message'=>$login?'Your session ended. Sign in again to resume your saved attempt.':'This action needs your attention before you can continue.','redirect_url'=>$url],$login?401:409);
  }
  header('Location: '.$url);exit;
 }
 public static function id(string $key):int{return max(0,(int)($_GET[$key]??$_POST[$key]??0));}
}
