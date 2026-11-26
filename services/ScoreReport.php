<?php
namespace CmuLms;
require_once __DIR__.'/ExamDisplayOrder.php';
final class ScoreReport {
 public static function build(\PDO $db,array $actor,int $courseId,string $period='all',string $search=''):array {
  if(!in_array($actor['role']??'', ['teacher','admin'],true)) throw new \RuntimeException('Forbidden',403);
  $st=$db->prepare('SELECT * FROM courses WHERE id=? AND (teacher_id=? OR ?="admin")');$st->execute([$courseId,$actor['id'],$actor['role']]);$course=$st->fetch();
  if(!$course) throw new \RuntimeException('Course unavailable',403);
  $period=in_array($period,['midterm','final'],true)?$period:'all';
  $st=$db->prepare('SELECT * FROM exams WHERE course_id=?'.($period==='all'?'':' AND academic_period=?').' ORDER BY created_at,id');$st->execute($period==='all'?[$courseId]:[$courseId,$period]);$exams=$st->fetchAll();
  $exams=ExamDisplayOrder::arrange($exams,ExamDisplayOrder::preference($db,(int)$actor['id'],$courseId));
  $st=$db->prepare('SELECT DISTINCT u.id,u.name,u.last_name,u.first_name,u.middle_initial,u.id_number FROM users u WHERE u.role="student" AND (EXISTS (SELECT 1 FROM enrollments en WHERE en.user_id=u.id AND en.course_id=? AND en.status="active") OR EXISTS (SELECT 1 FROM exam_attempts a JOIN exams e ON e.id=a.exam_id WHERE a.student_id=u.id AND e.course_id=?)) ORDER BY u.last_name,u.first_name,u.name,u.id');$st->execute([$courseId,$courseId]);$students=$st->fetchAll();
  $search=trim($search);if($search!=='')$students=array_values(array_filter($students,static fn($s)=>stripos($s['name'].' '.$s['id_number'],$search)!==false));
  $studentIds=array_fill_keys(array_column($students,'id'),true);$examIds=array_fill_keys(array_column($exams,'id'),true);
  $st=$db->prepare('SELECT a.* FROM exam_attempts a JOIN exams e ON e.id=a.exam_id WHERE e.course_id=? ORDER BY a.student_id,a.exam_id,a.attempt_number,a.id');$st->execute([$courseId]);$attempts=[];$lookup=[];$highest=[];
  foreach($st as $a){if(!isset($studentIds[$a['student_id']],$examIds[$a['exam_id']]))continue;$attempts[]=$a;$lookup[$a['student_id']][$a['exam_id']][(int)$a['attempt_number']]=$a;$highest[$a['exam_id']]=max($highest[$a['exam_id']]??0,(int)$a['attempt_number']);}
  $columns=[];foreach($exams as $e){$limit=max(2,(int)$e['attempts_allowed'],$highest[$e['id']]??0);for($n=1;$n<=$limit;$n++)$columns[]=['exam'=>$e,'number'=>$n];}
  $rows=[];foreach($students as $s){$cells=[];foreach($columns as $col){$e=$col['exam'];$n=$col['number'];$a=$lookup[$s['id']][$e['id']][$n]??null;$assigned=($e['audience']??'all')!=='selected'||in_array((int)$s['id'],array_map('intval',json_decode($e['target_student_ids']??'[]',true)?:[]),true);
    $label=$assigned?($n===1?'Not started':'Not used'):'Not assigned';$value=$label;
    if($a){$label=match($a['status']){'in_progress'=>'Taking','reset'=>'Reset · retained','auto_submitted'=>'Auto-submitted','submitted'=>'Submitted',default=>ucfirst($a['status'])};$value=$a['score']!==null&&$a['status']!=='in_progress'?(float)$a['score']:$label;}
    $cells[]=['attempt'=>$a,'label'=>$label,'value'=>$value];
   }$rows[]=['student'=>$s,'cells'=>$cells];}
  return compact('course','period','search','exams','columns','rows','attempts');
 }
 public static function sheets(array $report):array {
  $headers=['Last name','First name','Middle initial','ID number'];foreach($report['columns'] as $col)$headers[]=$col['exam']['title'].' · Attempt '.$col['number'];
  $scores=[$headers];$people=[];foreach($report['rows'] as $row){$s=$row['student'];$people[$s['id']]=$s;$scores[]=array_merge([$s['last_name']?:$s['name'],$s['first_name']??'',$s['middle_initial']??'',(string)$s['id_number']],array_column($row['cells'],'value'));}
  $details=[['Last name','First name','Middle initial','ID number','Exam','Attempt','Status','Score','Maximum','Started','Submitted']];$titles=array_column($report['exams'],'title','id');
  foreach($report['attempts'] as $a){$s=$people[$a['student_id']];$details[]=[$s['last_name']?:$s['name'],$s['first_name']??'',$s['middle_initial']??'',(string)$s['id_number'],$titles[$a['exam_id']],(int)$a['attempt_number'],$a['status'],$a['score']===null?null:(float)$a['score'],$a['max_score']===null?null:(float)$a['max_score'],$a['started_at'],$a['submitted_at']];}
  return ['Scores'=>$scores,'Attempt details'=>$details];
 }
}
