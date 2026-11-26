<?php
namespace CmuLms;
final class Course extends Model {
 public static function studentActivity(int $courseId,array $input):array {
  $search=trim((string)($input['students_search']??''));$status=(string)($input['students_status']??'all');
  if(!in_array($status,['all','enabled','disabled','online','offline'],true))$status='all';
  $per=(int)($input['students_per_page']??20);if(!in_array($per,[10,20,50,100],true))$per=20;
  $sorts=['name'=>'u.last_name','id_number'=>'u.id_number','section'=>'u.section_code','last_login'=>'u.last_login_at','last_logout'=>'u.last_logout_at'];
  $sort=(string)($input['students_sort']??'name');if(!isset($sorts[$sort]))$sort='name';$direction=($input['students_direction']??'asc')==='desc'?'desc':'asc';
  $online='(u.disabled_at IS NULL AND u.last_activity_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE) AND (u.last_logout_at IS NULL OR u.last_login_at>u.last_logout_at))';
  $where=' FROM users u JOIN enrollments e ON e.user_id=u.id WHERE e.course_id=? AND e.status="active" AND u.role="student"';$params=[$courseId];
  if($search!==''){$where.=' AND (u.name LIKE ? OR u.id_number LIKE ? OR u.section_code LIKE ?)';$like='%'.$search.'%';array_push($params,$like,$like,$like);}
  if($status==='enabled')$where.=' AND u.disabled_at IS NULL';
  if($status==='disabled')$where.=' AND u.disabled_at IS NOT NULL';
  if($status==='online')$where.=' AND '.$online;
  if($status==='offline')$where.=' AND NOT COALESCE('.$online.',0)';
  $total=(int)self::one('SELECT COUNT(*) total'.$where,$params)['total'];$pages=max(1,(int)ceil($total/$per));$page=max(1,min((int)($input['students_page']??1),$pages));
  $rows=self::all('SELECT u.id,u.name,u.id_number,u.section_code,u.disabled_at,u.last_login_at,u.last_logout_at,COALESCE('.$online.',0) is_online'.$where.' ORDER BY '.$sorts[$sort].' '.$direction.',u.name '.$direction.',u.id LIMIT '.$per.' OFFSET '.(($page-1)*$per),$params);
  return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$per,'search'=>$search,'status'=>$status,'sort'=>$sort,'direction'=>$direction];
 }
 public static function enrollmentCandidates(int $sourceId,int $destinationId,string $search,int $page):array {
  $where=' FROM users u JOIN enrollments e ON e.user_id=u.id WHERE e.course_id=? AND e.status="active" AND u.role="student" AND u.disabled_at IS NULL AND NOT EXISTS (SELECT 1 FROM enrollments d WHERE d.course_id=? AND d.user_id=u.id AND d.status="active")';
  $params=[$sourceId,$destinationId];
  if($search!==''){$where.=' AND (u.name LIKE ? OR u.id_number LIKE ? OR u.section_code LIKE ?)';$like='%'.$search.'%';array_push($params,$like,$like,$like);}
  $total=(int)self::one('SELECT COUNT(*) total'.$where,$params)['total'];$pages=max(1,(int)ceil($total/20));$page=max(1,min($page,$pages));
  return ['students'=>self::all('SELECT u.id,u.name,u.id_number,u.section_code'.$where.' ORDER BY u.last_name,u.name,u.id LIMIT 20 OFFSET '.(($page-1)*20),$params),'total'=>$total,'page'=>$page,'pages'=>$pages];
 }
 public static function forUser(array $u):array{if($u['role']==='student')return self::all('SELECT c.* FROM courses c JOIN enrollments e ON e.course_id=c.id WHERE e.user_id=? AND e.status="active" AND c.active=1 ORDER BY c.code',[$u['id']]);if($u['role']==='teacher')return self::all('SELECT c.*,COUNT(e.user_id) students FROM courses c LEFT JOIN enrollments e ON e.course_id=c.id AND e.status="active" WHERE c.teacher_id=? AND c.active=1 GROUP BY c.id ORDER BY c.code',[$u['id']]);return self::all('SELECT c.*,COUNT(e.user_id) students FROM courses c LEFT JOIN enrollments e ON e.course_id=c.id GROUP BY c.id ORDER BY c.code');}
 public static function find(int $id):?array{return self::one('SELECT c.*,u.name teacher_name FROM courses c JOIN users u ON u.id=c.teacher_id WHERE c.id=?',[$id]);}
 public static function students(int $id):array{return self::all('SELECT u.id,u.name,u.id_number,u.section_code,u.year_level,u.course,u.disabled_at,u.last_login_at,u.last_logout_at,u.last_activity_at FROM users u JOIN enrollments e ON e.user_id=u.id WHERE e.course_id=? AND e.status="active" AND u.role="student" ORDER BY u.last_name,u.name',[$id]);}
}
