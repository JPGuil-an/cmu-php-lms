<?php
require __DIR__.'/../core/Database.php';
require __DIR__.'/../services/ScoreReport.php';
require __DIR__.'/../services/ScoreWorkbook.php';
$config=require __DIR__.'/../config.php';$db=\CmuLms\Database::connect($config['db']);
$checked=0;$pairs=0;
foreach($db->query('SELECT id,teacher_id FROM courses')->fetchAll() as $course){
 $actor=['id'=>$course['teacher_id'],'role'=>'teacher'];$r=\CmuLms\ScoreReport::build($db,$actor,(int)$course['id']);
 $seen=[];foreach($r['rows'] as $row)foreach($row['cells'] as $i=>$cell){$a=$cell['attempt'];if(!$a)continue;$col=$r['columns'][$i];
  if($a['student_id']!=$row['student']['id']||$a['exam_id']!=$col['exam']['id']||$a['attempt_number']!=$col['number'])throw new RuntimeException('Attempt placed in wrong column');
  if(isset($seen[$a['id']]))throw new RuntimeException('Duplicate attempt');$seen[$a['id']]=true;
  if($a['score']!==null&&$a['status']!=='in_progress'&&$cell['value']!==(float)$a['score'])throw new RuntimeException('Score mismatch');$checked++;
 }
 if(count($seen)!==count($r['attempts']))throw new RuntimeException('Missing attempt');
 $by=[];foreach($r['attempts'] as $a)$by[$a['student_id'].':'.$a['exam_id']][]=$a['attempt_number'];foreach($by as $numbers)if(in_array(1,$numbers)&&in_array(2,$numbers))$pairs++;
 foreach(['midterm','final'] as $term){$filtered=\CmuLms\ScoreReport::build($db,$actor,(int)$course['id'],$term);foreach($filtered['exams'] as $e)if($e['academic_period']!==$term)throw new RuntimeException('Term filter failed');}
 if($r['rows']){$id=$r['rows'][0]['student']['id_number'];$search=\CmuLms\ScoreReport::build($db,$actor,(int)$course['id'],'all',$id);foreach($search['rows'] as $row)if(stripos($row['student']['name'].' '.$row['student']['id_number'],$id)===false)throw new RuntimeException('Search failed');}
 foreach([['id'=>0,'role'=>'teacher'],['id'=>$actor['id'],'role'=>'student']] as $denied){try{\CmuLms\ScoreReport::build($db,$denied,(int)$course['id']);throw new LogicException('Unauthorized report accepted');}catch(RuntimeException $e){if($e->getCode()!==403)throw $e;}}
}
echo "Verified $checked separate attempts and $pairs first/second-attempt pairs; term/search filters and ownership checks passed.\n";
$path=$argv[1]??null;
if($path){
 $fixture=['Scores'=>[['Last name','First name','Middle initial','ID number','Quiz · Attempt 1','Quiz · Attempt 2'],['=1+1','José','A.','001234',0,8.5]],'Attempt details'=>[['Last name','First name','Middle initial','ID number','Exam','Attempt','Status','Score','Maximum'],['=1+1','José','A.','001234','Quiz',1,'submitted',0,10]]];
 \CmuLms\ScoreWorkbook::write($path,$fixture);echo "Excel fixture written.\n";
}
