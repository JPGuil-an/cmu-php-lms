<?php
namespace CmuLms;
final class View {
 public static function render(string $name,array $data=[]):void{extract($data);require __DIR__.'/../views/'.$name.'.php';}
 public static function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
 public static function csrf():string{return $_SESSION['_csrf']??=bin2hex(random_bytes(24));}
 public static function csrfField():string{return '<input type="hidden" name="_csrf" value="'.self::e(self::csrf()).'">';}
 public static function checkCsrf():void{$saved=$_SESSION['_csrf']??null;$given=$_POST['_csrf']??null;if(!is_string($saved)||$saved===''||!is_string($given)||!hash_equals($saved,$given)){if(Http::wantsJson())Http::json(['ok'=>false,'message'=>'Your page verification expired. Reopen this attempt to continue with your saved answers.'],419);http_response_code(419);exit('Invalid form token.');}}
}
