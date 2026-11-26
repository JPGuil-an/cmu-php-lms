<?php
require __DIR__.'/../core/Database.php';
require __DIR__.'/../services/ExamDisplayOrder.php';
require __DIR__.'/../services/ScoreReport.php';
use CmuLms\ExamDisplayOrder;
$fixtures=[['id'=>10,'title'=>'Chapter 10','starts_at'=>null,'created_at'=>'2026-01-03'],['id'=>2,'title'=>'Chapter 2','starts_at'=>'2026-01-02','created_at'=>'2026-01-02'],['id'=>1,'title'=>'Chapter 1','starts_at'=>'2026-01-01','created_at'=>'2026-01-01']];
foreach(['title_asc'=>[1,2,10],'title_desc'=>[10,2,1],'opens_asc'=>[1,2,10],'opens_desc'=>[2,1,10],'created_asc'=>[1,2,10],'created_desc'=>[10,2,1]] as $mode=>$expected){if(array_column(ExamDisplayOrder::arrange($fixtures,['mode'=>$mode]),'id')!==$expected)throw new RuntimeException('Sort failed: '.$mode);}
if(array_column(ExamDisplayOrder::arrange($fixtures,['mode'=>'manual','ids'=>[2,10]]),'id')!==[2,10,1])throw new RuntimeException('Custom order/new exam append failed');
$config=require __DIR__.'/../config.php';$db=\CmuLms\Database::connect($config['db']);
$course=$db->query('SELECT c.id,c.teacher_id,COUNT(e.id) n FROM courses c JOIN exams e ON e.course_id=c.id GROUP BY c.id HAVING n>=2 ORDER BY n DESC LIMIT 1')->fetch();
if(!$course)throw new RuntimeException('No course with multiple exams for verification');
$actor=['id'=>$course['teacher_id'],'role'=>'teacher'];$id=(int)$course['id'];$st=$db->prepare('SELECT * FROM exams WHERE course_id=? ORDER BY id');$st->execute([$id]);$exams=$st->fetchAll();$ids=array_reverse(array_map('intval',array_column($exams,'id')));
$before=$db->query('SELECT * FROM exam_attempts ORDER BY id')->fetchAll();
$db->beginTransaction();
try {
 ExamDisplayOrder::save($db,$actor,$id,'manual',$ids);
 $preference=ExamDisplayOrder::preference($db,(int)$actor['id'],$id);
 if($preference!==['mode'=>'manual','ids'=>$ids])throw new RuntimeException('Preference did not persist');
 if(array_map('intval',array_column(ExamDisplayOrder::arrange($exams,$preference),'id'))!==$ids)throw new RuntimeException('Course ordering failed');
 $report=\CmuLms\ScoreReport::build($db,$actor,$id);
 if(array_map('intval',array_column($report['exams'],'id'))!==$ids)throw new RuntimeException('Dashboard ordering differs');
 $sheets=\CmuLms\ScoreReport::sheets($report);foreach($report['columns'] as $index=>$col)if($sheets['Scores'][0][$index+4]!==$col['exam']['title'].' · Attempt '.$col['number'])throw new RuntimeException('Export ordering differs');
 foreach([['id'=>0,'role'=>'teacher'],['id'=>$actor['id'],'role'=>'student']] as $denied){try{ExamDisplayOrder::save($db,$denied,$id,'manual',$ids);throw new LogicException('Unauthorized write');}catch(RuntimeException $e){if($e->getCode()!==403)throw $e;}}
 foreach([['invalid',$ids],['manual',array_merge($ids,[$ids[0]])],['manual',[999999999]],['manual',[]]] as [$mode,$invalidIds]){try{ExamDisplayOrder::save($db,$actor,$id,$mode,$invalidIds);throw new LogicException('Invalid ordering accepted');}catch(InvalidArgumentException $expected){}}
 if($db->query('SELECT * FROM exam_attempts ORDER BY id')->fetchAll()!==$before)throw new RuntimeException('Attempt records changed');
 echo "Passed natural/date sorting, manual order, saved preference, dashboard/export consistency, authorization, stale/invalid input and attempt preservation.\n";
} finally {$db->rollBack();}
