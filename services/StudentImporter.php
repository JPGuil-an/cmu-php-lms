<?php
namespace CmuLms;

final class StudentImporter {
 public function __construct(private \PDO $db) {}

 public function parse(string $file):array {
  $handle=fopen($file,'rb');if(!$handle)throw new \InvalidArgumentException('Cannot read the CSV file.');
  try {
   $header=fgetcsv($handle);if(!$header)throw new \InvalidArgumentException('The CSV file is empty.');
   $header[0]=preg_replace('/^\xEF\xBB\xBF/','',(string)$header[0]);
   $header=array_map(static fn($value)=>strtolower(str_replace([' ','-'],'_',trim((string)$value))),$header);
   if(count(array_unique($header))!==count($header))throw new \InvalidArgumentException('CSV column names must be unique.');
   if(!in_array('id_number',$header,true)&&!in_array('school_id',$header,true))throw new \InvalidArgumentException('CSV must contain id_number or school_id.');
   if(!in_array('full_name',$header,true)&&!(in_array('first_name',$header,true)&&in_array('last_name',$header,true)))throw new \InvalidArgumentException('CSV must contain full_name, or first_name and last_name.');
   $rows=[];$errors=[];$seen=[];$line=1;
   while(($values=fgetcsv($handle))!==false){$line++;if(count($values)===1&&trim((string)$values[0])==='')continue;
    if(count($values)!==count($header)){$errors[]="Row $line: expected ".count($header).' columns.';continue;}
    $r=array_map(static fn($value)=>trim((string)$value),array_combine($header,$values));
    $first=$r['first_name']??'';$last=$r['last_name']??'';$middle=$r['middle_initial']??'';
    $name=trim($r['full_name']??trim($first.' '.($middle!==''?$middle.' ':'').$last));$id=$r['id_number']??$r['school_id']??'';$year=$r['year_level']??'';
    if($id===''||$name===''){$errors[]="Row $line: school ID and student name are required.";continue;}
    if(isset($seen[$id])){$errors[]="Row $line: duplicate school ID $id in this file.";continue;}$seen[$id]=true;
    if($year!==''&&(!ctype_digit($year)||(int)$year<1||(int)$year>20)){$errors[]="Row $line: year level must be a whole number between 1 and 20, or blank.";continue;}
    if(strlen($id)>255||strlen($name)>255||strlen($middle)>5){$errors[]="Row $line: name, school ID, or middle initial is too long.";continue;}
    $rows[]=['row'=>$line,'id_number'=>$id,'name'=>$name,'first_name'=>$first,'middle_initial'=>$middle,'last_name'=>$last,'section_code'=>$r['section_code']??'','year_level'=>$year===''?null:(int)$year,'course'=>$r['course']??''];
   }
   if(!$rows&&!$errors)$errors[]='The CSV has no student rows.';
   return ['rows'=>$rows,'errors'=>$errors];
  } finally {fclose($handle);}
 }

 public function plan(array $parsed,int $courseId):array {
  $rows=[];$errors=$parsed['errors'];$new=0;$existing=0;$already=0;
  $find=$this->db->prepare('SELECT u.id,u.name,u.role,u.disabled_at,(SELECT status FROM enrollments WHERE course_id=? AND user_id=u.id) enrollment_status,(SELECT GROUP_CONCAT(c.code ORDER BY c.code SEPARATOR ", ") FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE e.user_id=u.id AND e.status="active") enrolled_classes FROM users u WHERE u.id_number=?');
  $normalize=static fn($name)=>strtolower(preg_replace('/[^\p{L}\p{N}]/u','',(string)$name));
  foreach($parsed['rows'] as $row){$find->execute([$courseId,$row['id_number']]);$account=$find->fetch();
   if($account&&($account['role']!=='student'||$account['disabled_at'])){$errors[]='Row '.$row['row'].': school ID belongs to an unavailable or non-student account.';continue;}
   if($account&&$normalize($account['name'])!==$normalize($row['name'])){$errors[]='Row '.$row['row'].': school ID '.$row['id_number'].' already belongs to a different student name. Check the ID before importing.';continue;}
   if($account){$existing++;if($account['enrollment_status']==='active')$already++;}else $new++;
   $rows[]=$row+['user_id'=>$account?(int)$account['id']:null,'already_enrolled'=>$account&&$account['enrollment_status']==='active','enrolled_classes'=>$account['enrolled_classes']??''];
  }
  return ['rows'=>$rows,'errors'=>$errors,'new'=>$new,'existing'=>$existing,'already'=>$already];
 }

 public function import(array $parsed,array $destination):array {
  $ownTransaction=!$this->db->inTransaction();if($ownTransaction)$this->db->beginTransaction();
  try {
   $plan=$this->plan($parsed,(int)$destination['id']);if($plan['errors'])throw new \InvalidArgumentException(implode(' ',$plan['errors']));
   $create=$this->db->prepare('INSERT INTO users(name,first_name,middle_initial,last_name,id_number,role,password,must_change_password,section_code,year_level,course,created_at,updated_at) VALUES(:name,:first,:middle,:last,:school_id,"student",:password,1,:section,:year,:course,NOW(),NOW())');
   $enroll=$this->db->prepare('INSERT INTO enrollments(course_id,user_id,status,created_at,updated_at) VALUES(?,?,"active",NOW(),NOW()) ON DUPLICATE KEY UPDATE status="active",updated_at=NOW()');
   $hash=password_hash('ChangeMe!',PASSWORD_DEFAULT);$created=0;$enrolled=0;
   foreach($plan['rows'] as $row){$userId=$row['user_id'];
    if(!$userId){$create->execute(['name'=>$row['name'],'first'=>$row['first_name']?:null,'middle'=>$row['middle_initial']?:null,'last'=>$row['last_name']?:null,'school_id'=>$row['id_number'],'password'=>$hash,'section'=>$destination['section_code']?:null,'year'=>$row['year_level'],'course'=>$row['course']?:null]);$userId=(int)$this->db->lastInsertId();$created++;}
    if(!$row['already_enrolled']){$enroll->execute([(int)$destination['id'],$userId]);$enrolled++;}
   }
   if($ownTransaction)$this->db->commit();return ['imported'=>$created,'enrolled'=>$enrolled,'already'=>$plan['already']];
  } catch(\Throwable $e){if($ownTransaction&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
