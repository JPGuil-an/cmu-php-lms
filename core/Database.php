<?php
namespace CmuLms;
final class Database {
 private static ?\PDO $pdo=null;
 public static function connect(array $c):\PDO {
  if(self::$pdo)return self::$pdo;
  self::$pdo=new \PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4",$c['username'],$c['password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
  self::$pdo->exec("SET time_zone = '+08:00'");
  return self::$pdo;
 }
}
