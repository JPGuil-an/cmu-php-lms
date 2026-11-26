<?php
namespace CmuLms;
trait QuestionPoolSharing {
 public static function fromOtherCourses(int $teacherId,int $destination):array {
  return self::all('SELECT p.*,c.code course_code,c.title course_title,COUNT(q.id) questions_count FROM question_pools p JOIN courses c ON c.id=p.course_id LEFT JOIN questions q ON q.pool_id=p.id AND q.is_active=1 WHERE c.teacher_id=? AND c.id<>? GROUP BY p.id,c.code,c.title HAVING COUNT(q.id)>0 ORDER BY c.code,p.title',[$teacherId,$destination]);
 }
 public static function copyIntoCourse(int $source,int $destination,int $teacherId):array {
  $pool=self::one('SELECT p.* FROM question_pools p JOIN courses c ON c.id=p.course_id WHERE p.id=? AND c.teacher_id=?',[$source,$teacherId]);
  $target=self::one('SELECT id FROM courses WHERE id=? AND teacher_id=? AND active=1',[$destination,$teacherId]);
  if(!$pool||!$target||(int)$pool['course_id']===$destination)throw new \InvalidArgumentException('Choose a bank from another class you teach and an active destination class.');
  $ownTransaction=!self::$db->inTransaction();if($ownTransaction)self::$db->beginTransaction();
  try {
   $questions=self::questions($source);if(!$questions)throw new \InvalidArgumentException('This bank has no active questions to copy.');
   self::query('INSERT INTO question_pools(course_id,title,description,academic_period,topic,copied_from_pool_id,created_at,updated_at) VALUES(?,?,?,?,?,?,NOW(),NOW())',[$destination,$pool['title'].' (Copy)',$pool['description'],$pool['academic_period'],$pool['topic'],$source]);
   $id=(int)self::$db->lastInsertId();
   foreach($questions as $q)self::query('INSERT INTO questions(pool_id,copied_from_question_id,type,prompt,options,answer,points,tags,difficulty,is_active,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,1,NOW(),NOW())',[$id,$q['id'],$q['type'],$q['prompt'],$q['options'],$q['answer'],$q['points'],$q['tags'],$q['difficulty']]);
   if($ownTransaction)self::$db->commit();return ['id'=>$id,'count'=>count($questions)];
  } catch(\Throwable $e){if($ownTransaction&&self::$db->inTransaction())self::$db->rollBack();throw $e;}
 }
}
final class QuestionPool extends Model { use QuestionPoolSharing; public static function forCourse(int $course):array{return self::all('SELECT p.*,COUNT(q.id) questions_count FROM question_pools p LEFT JOIN questions q ON q.pool_id=p.id AND q.is_active=1 WHERE p.course_id=? GROUP BY p.id ORDER BY p.title',[$course]);} public static function questions(int $pool,int $limit=0,int $offset=0):array{if($limit>0)return self::all('SELECT * FROM questions WHERE pool_id=? AND is_active=1 ORDER BY id LIMIT '.(int)$limit.' OFFSET '.(int)$offset,[$pool]);return self::all('SELECT * FROM questions WHERE pool_id=? AND is_active=1 ORDER BY id',[$pool]);} public static function questionCount(int $pool):int{return (int)self::one('SELECT COUNT(*) total FROM questions WHERE pool_id=? AND is_active=1',[$pool])['total'];} }
