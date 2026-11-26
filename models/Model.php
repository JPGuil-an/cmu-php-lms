<?php
namespace CmuLms;
abstract class Model {
 protected static \PDO $db;
 public static function setDb(\PDO $db):void{static::$db=$db;}
 protected static function query(string $sql,array $p=[]):\PDOStatement{$s=static::$db->prepare($sql);$s->execute($p);return $s;}
 protected static function one(string $sql,array $p=[]):?array{$r=static::query($sql,$p)->fetch();return $r?:null;}
 protected static function all(string $sql,array $p=[]):array{return static::query($sql,$p)->fetchAll();}
}
