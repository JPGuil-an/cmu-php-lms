<?php
namespace CmuLms;
final class Exam extends Model {
 public static function questionsForAttempt(int $poolId,int $count):array {
  if($count<1) throw new \InvalidArgumentException('The number of items must be at least 1.');
  $questions=self::all('SELECT id,options FROM questions WHERE pool_id=? AND is_active=1 ORDER BY RAND() LIMIT '.$count,[$poolId]);
  if(count($questions)!==$count) throw new \InvalidArgumentException('The question bank does not have enough active questions. Please contact your teacher.');
  return $questions;
 }
 public static function studentAction(array $exam, ?int $now=null):array {
  $now ??= time();
  if(empty($exam['is_enabled'])) return ['available'=>false,'label'=>'Unavailable'];
  if(!empty($exam['starts_at'])&&strtotime($exam['starts_at'])>$now) return ['available'=>false,'label'=>'Scheduled'];
  if(!empty($exam['ends_at'])&&strtotime($exam['ends_at'])<$now&&empty($exam['retake_after_close'])) return ['available'=>false,'label'=>'Closed'];
  if(!empty($exam['has_active_attempt'])) return ['available'=>true,'label'=>'Continue attempt'];
  if((int)($exam['attempts_remaining']??0)<=0) return ['available'=>false,'label'=>'Attempts used'];
  if(!empty($exam['retake_after_close'])) return ['available'=>true,'label'=>'Start approved retake'];
  return ['available'=>true,'label'=>(int)($exam['attempts_taken']??0)>0?'Attempt again':'Start attempt'];
 }
 public static function allowedAttempts(int $examId,int $studentId,int $default):int {
  $row=self::one("SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.attempt_limit')) attempt_limit FROM audit_logs WHERE auditable_type='exams' AND auditable_id=? AND action='exam_attempts_reset' AND JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.student_id'))=? ORDER BY id DESC LIMIT 1",[$examId,(string)$studentId]);
  $limit=(int)($row['attempt_limit']??0);return $limit>0?min(100,$limit):max(1,$default);
 }
 public static function attemptUsage(int $examId,int $studentId,int $defaultAllowed=1):array {
  $row=self::one('SELECT COALESCE(SUM(status<>"reset"),0) used,COALESCE(MAX(attempt_number),0)+1 next_number FROM exam_attempts WHERE exam_id=? AND student_id=?',[$examId,$studentId]);return ['used'=>(int)$row['used'],'next_number'=>(int)$row['next_number'],'allowed'=>self::allowedAttempts($examId,$studentId,$defaultAllowed)];
 }
 public static function resetStudentAttempts(int $examId,int $studentId,array $actor,int $attemptLimit=0):int {
  $ownTransaction=!self::$db->inTransaction();if($ownTransaction)self::$db->beginTransaction();
  try {
   $exam=self::one('SELECT e.*,c.teacher_id FROM exams e JOIN courses c ON c.id=e.course_id WHERE e.id=? FOR UPDATE',[$examId]);
   if(!$exam||!in_array($actor['role']??'',['teacher','admin'],true)||($actor['role']==='teacher'&&(int)$exam['teacher_id']!==(int)$actor['id']))throw new \InvalidArgumentException('You cannot reset attempts for this exam.');
   if($attemptLimit===0)$attemptLimit=(int)$exam['attempts_allowed'];
   if($attemptLimit<1||$attemptLimit>100)throw new \InvalidArgumentException('The attempt limit must be between 1 and 100.');
   $student=self::one('SELECT u.id FROM users u JOIN enrollments en ON en.user_id=u.id WHERE en.course_id=? AND en.status="active" AND u.id=? AND u.role="student" AND u.disabled_at IS NULL',[$exam['course_id'],$studentId]);
   $targets=array_map('intval',json_decode($exam['target_student_ids']??'[]',true)?:[]);
   if(!$student||(($exam['audience']??'all')==='selected'&&!in_array($studentId,$targets,true)))throw new \InvalidArgumentException('This student is not currently assigned to this exam.');
   $previous=self::all('SELECT id,status,attempt_number FROM exam_attempts WHERE exam_id=? AND student_id=? AND status<>"reset" FOR UPDATE',[$examId,$studentId]);
   $changed=self::query('UPDATE exam_attempts SET status="reset",updated_at=NOW() WHERE exam_id=? AND student_id=? AND status<>"reset"',[$examId,$studentId])->rowCount();
   if($changed)self::query('INSERT INTO audit_logs(user_id,action,auditable_type,auditable_id,metadata,created_at,updated_at) VALUES(?,"exam_attempts_reset","exams",?,?,NOW(),NOW())',[$actor['id'],$examId,json_encode(['student_id'=>$studentId,'course_id'=>(int)$exam['course_id'],'previous_attempts'=>$previous,'attempt_limit'=>$attemptLimit,'post_close_retake_granted'=>!empty($exam['ends_at'])&&strtotime($exam['ends_at'])<=time()])]);
   if($ownTransaction)self::$db->commit();return $changed;
  } catch(\Throwable $e){if($ownTransaction&&self::$db->inTransaction())self::$db->rollBack();throw $e;}
 }
 public static function retakeAllowedAfterClose(int $examId,int $studentId):bool {
  return (bool)self::one("SELECT 1 FROM audit_logs l JOIN exams e ON e.id=l.auditable_id WHERE l.auditable_type='exams' AND l.action='exam_attempts_reset' AND l.auditable_id=? AND e.ends_at IS NOT NULL AND l.created_at>=e.ends_at AND JSON_UNQUOTE(JSON_EXTRACT(l.metadata,'$.student_id'))=? AND JSON_UNQUOTE(JSON_EXTRACT(l.metadata,'$.post_close_retake_granted'))='true' LIMIT 1",[$examId,(string)$studentId]);
 }
 public static function forCourse(int $course):array{return self::all('SELECT e.*,q.title bank FROM exams e LEFT JOIN question_pools q ON q.id=e.question_pool_id WHERE e.course_id=? ORDER BY e.starts_at IS NULL,e.starts_at DESC',[$course]);}
 public static function forStudent(int $student):array{$rows=self::all('SELECT e.*,c.code course_code,c.title course_title,COUNT(a.id) attempts_taken,MAX(a.status="in_progress") has_active_attempt,GREATEST(e.attempts_allowed-COUNT(a.id),0) attempts_remaining,MAX(a.status IN ("submitted","auto_submitted")) completed,COALESCE((SELECT CASE WHEN l.action="exam_scores_shown" THEN 1 ELSE 0 END FROM audit_logs l WHERE l.auditable_type="exams" AND l.auditable_id=e.id AND l.action IN ("exam_scores_shown","exam_scores_hidden") ORDER BY l.id DESC LIMIT 1),0) show_score_to_students,(SELECT best.score FROM exam_attempts best WHERE best.exam_id=e.id AND best.student_id=? AND best.status IN ("submitted","auto_submitted") AND best.max_score>0 ORDER BY (best.score/best.max_score) DESC,best.id DESC LIMIT 1) best_score,(SELECT best.max_score FROM exam_attempts best WHERE best.exam_id=e.id AND best.student_id=? AND best.status IN ("submitted","auto_submitted") AND best.max_score>0 ORDER BY (best.score/best.max_score) DESC,best.id DESC LIMIT 1) best_max_score FROM exams e JOIN courses c ON c.id=e.course_id JOIN enrollments en ON en.course_id=c.id AND en.user_id=? AND en.status="active" LEFT JOIN exam_attempts a ON a.exam_id=e.id AND a.student_id=? AND a.status<>"reset" WHERE e.is_enabled=1 AND c.active=1 GROUP BY e.id ORDER BY e.starts_at IS NULL,e.starts_at',[$student,$student,$student,$student]);foreach($rows as &$exam){$exam['attempts_allowed']=self::allowedAttempts((int)$exam['id'],$student,(int)$exam['attempts_allowed']);$exam['attempts_remaining']=max(0,$exam['attempts_allowed']-(int)$exam['attempts_taken']);$exam['retake_after_close']=!empty($exam['ends_at'])&&strtotime($exam['ends_at'])<time()&&self::retakeAllowedAfterClose((int)$exam['id'],$student);}unset($exam);return array_values(array_filter($rows,function($e)use($student){if(($e['audience']??'all')!=='selected')return true;$ids=json_decode($e['target_student_ids']??'[]',true)?:[];return in_array($student,array_map('intval',$ids),true);}));}
}
