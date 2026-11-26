<?php
namespace CmuLms;
require_once __DIR__.'/../core/StudentCourseAccess.php';
final class Notification extends Model {
 private static function accessible(array $rows,int $userId):array {
  $user=self::one('SELECT role FROM users WHERE id=?',[$userId]);
  if(($user['role']??'')!=='student')return $rows;
  return array_values(array_filter($rows,static function(array $row)use($userId):bool{
   $target=(string)($row['target_url']??'');if($target==='')return true;
   parse_str((string)(parse_url($target,PHP_URL_QUERY)??''),$query);
   if(isset($query['route']))return StudentCourseAccess::allows(self::$db,$userId,(string)$query['route'],$query);
   $path=(string)(parse_url($target,PHP_URL_PATH)??'');
   if(preg_match('#/(?:students/)?class/([^/]+)#i',$path,$matches)){
    $course=self::one('SELECT id FROM courses WHERE code=?',[rawurldecode($matches[1])]);
    return $course&&StudentCourseAccess::allows(self::$db,$userId,'course',['id'=>$course['id']]);
   }
   return true;
  }));
 }
 public static function forUser(int $id):array{return array_slice(self::accessible(self::all('SELECT * FROM notifications WHERE user_id=? ORDER BY read_at IS NULL DESC,created_at DESC',[$id]),$id),0,50);}
 public static function unread(int $id):int{return count(self::accessible(self::all('SELECT target_url FROM notifications WHERE user_id=? AND read_at IS NULL',[$id]),$id));}
}
