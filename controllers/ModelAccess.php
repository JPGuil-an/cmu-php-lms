<?php
namespace CmuLms;
final class ModelAccess { public static function query(\PDO $db,string $sql,array $p=[]):\PDOStatement{$s=$db->prepare($sql);$s->execute($p);return $s;}public static function one(\PDO $db,string $sql,array $p=[]):?array{$r=self::query($db,$sql,$p)->fetch();return $r?:null;}public static function all(\PDO $db,string $sql,array $p=[]):array{return self::query($db,$sql,$p)->fetchAll();} }
