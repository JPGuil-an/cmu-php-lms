<?php
namespace CmuLms;
final class User extends Model {
 public static function byIdNumber(string $id):?array{return self::one('SELECT * FROM users WHERE id_number=?',[$id]);}
 public static function students(string $search='',int $limit=100,int $offset=0):array{$p=[];$w='role="student"';if($search!==''){$w.=' AND (name LIKE ? OR id_number LIKE ? OR section_code LIKE ?)';$x='%'.$search.'%';$p=[$x,$x,$x];}$limit=max(1,min(100,$limit));$offset=max(0,$offset);return self::all('SELECT * FROM users WHERE '.$w.' ORDER BY last_name,name LIMIT '.$limit.' OFFSET '.$offset,$p);}
 public static function studentCount(string $search=''):int{$p=[];$w='role="student"';if($search!==''){$w.=' AND (name LIKE ? OR id_number LIKE ? OR section_code LIKE ?)';$x='%'.$search.'%';$p=[$x,$x,$x];}return (int)self::one('SELECT COUNT(*) total FROM users WHERE '.$w,$p)['total'];}
 public static function users(string $search='',string $role='all',int $limit=100,int $offset=0):array{$p=[];$w='1=1';if(in_array($role,['student','teacher','admin'],true)){$w.=' AND role=?';$p[]=$role;}if($search!==''){$w.=' AND (name LIKE ? OR id_number LIKE ? OR section_code LIKE ? OR course LIKE ?)';$x='%'.$search.'%';array_push($p,$x,$x,$x,$x);}$limit=max(1,min(100,$limit));$offset=max(0,$offset);return self::all('SELECT * FROM users WHERE '.$w.' ORDER BY role,last_name,name LIMIT '.$limit.' OFFSET '.$offset,$p);}
 public static function userCount(string $search='',string $role='all'):int{$p=[];$w='1=1';if(in_array($role,['student','teacher','admin'],true)){$w.=' AND role=?';$p[]=$role;}if($search!==''){$w.=' AND (name LIKE ? OR id_number LIKE ? OR section_code LIKE ? OR course LIKE ?)';$x='%'.$search.'%';array_push($p,$x,$x,$x,$x);}return (int)self::one('SELECT COUNT(*) total FROM users WHERE '.$w,$p)['total'];}
 public static function teachers():array{return self::all('SELECT id,name,id_number FROM users WHERE role="teacher" AND disabled_at IS NULL ORDER BY name');}
 public static function messageable(int $exclude):array{return self::all('SELECT id,name,id_number,role FROM users WHERE id<>? AND disabled_at IS NULL ORDER BY role,name',[$exclude]);}
 public static function setDisabled(int $id,bool $disabled):void{self::query('UPDATE users SET disabled_at=? WHERE id=?',[$disabled?date('Y-m-d H:i:s'):null,$id]);}
}
