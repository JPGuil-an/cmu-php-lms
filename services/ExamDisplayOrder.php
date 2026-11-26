<?php
namespace CmuLms;
final class ExamDisplayOrder {
 public const MODES=['title_asc'=>'Title / chapter: A–Z','title_desc'=>'Title / chapter: Z–A','opens_asc'=>'Opening date: earliest first','opens_desc'=>'Opening date: latest first','created_desc'=>'Newest created first','created_asc'=>'Oldest created first','manual'=>'My custom order'];
 public static function preference(\PDO $db,int $teacherId,int $courseId):array {
  $st=$db->prepare('SELECT metadata FROM audit_logs WHERE user_id=? AND auditable_type="courses" AND auditable_id=? AND action="exam_display_order_saved" ORDER BY id DESC LIMIT 1');$st->execute([$teacherId,$courseId]);
  $saved=json_decode($st->fetchColumn()?:'{}',true)?:[];
  return ['mode'=>isset(self::MODES[$saved['mode']??''])?$saved['mode']:'opens_desc','ids'=>array_map('intval',$saved['ids']??[])];
 }
 public static function arrange(array $exams,array $preference):array {
  $mode=$preference['mode']??'opens_desc';$ranks=array_flip($preference['ids']??[]);
  usort($exams,static function($a,$b)use($mode,$ranks){
   if($mode==='manual')return (($ranks[(int)$a['id']]??PHP_INT_MAX)<=>($ranks[(int)$b['id']]??PHP_INT_MAX))?:((int)$a['id']<=>(int)$b['id']);
   if(str_starts_with($mode,'title_'))$result=strnatcasecmp($a['title'],$b['title']);
   else {$field=str_starts_with($mode,'created_')?'created_at':'starts_at';$av=$a[$field]??null;$bv=$b[$field]??null;if(empty($av)!==empty($bv))return empty($av)?1:-1;$result=strcmp((string)$av,(string)$bv);}
   if(str_ends_with($mode,'_desc'))$result=-$result;
   return $result?:((int)$a['id']<=>(int)$b['id']);
  });return $exams;
 }
 public static function save(\PDO $db,array $actor,int $courseId,string $mode,array $ids):void {
  if(($actor['role']??'')!=='teacher')throw new \RuntimeException('Only the class teacher can save this arrangement.',403);
  $st=$db->prepare('SELECT id FROM courses WHERE id=? AND teacher_id=?');$st->execute([$courseId,$actor['id']]);if(!$st->fetchColumn())throw new \RuntimeException('Course unavailable.',403);
  if(!isset(self::MODES[$mode]))throw new \InvalidArgumentException('Choose a valid sort option.');
  $ids=array_map('intval',$ids);$st=$db->prepare('SELECT id FROM exams WHERE course_id=?');$st->execute([$courseId]);$valid=array_map('intval',$st->fetchAll(\PDO::FETCH_COLUMN));
  if(count($ids)!==count(array_unique($ids))||array_diff($ids,$valid))throw new \InvalidArgumentException('The exam list changed. Reload the class and arrange it again.');
  if($mode==='manual'&&array_diff($valid,$ids))throw new \InvalidArgumentException('The exam list changed. Reload the class to include all exams.');
  $db->prepare('INSERT INTO audit_logs(user_id,action,auditable_type,auditable_id,metadata,created_at,updated_at) VALUES(?,"exam_display_order_saved","courses",?,?,NOW(),NOW())')->execute([$actor['id'],$courseId,json_encode(['mode'=>$mode,'ids'=>$ids])]);
 }
}
