<?php
namespace CmuLms;

final class StudentCourseAccess {
 public static function allows(\PDO $db,int $studentId,string $route,array $input):bool {
  $resource=null;$id=0;
  switch($route){
   case 'course':case 'course-gradebook':$courseId=(int)($input['id']??0);break;
   case 'exam-start':case 'exam-begin':case 'exam-resume':$resource='SELECT course_id FROM exams WHERE id=?';$id=(int)($input['exam_id']??$input['id']??0);break;
   case 'exam':case 'exam-state':case 'exam-answer':case 'exam-position':case 'exam-flag':case 'exam-submit':case 'exam-event':
    $id=(int)($input['attempt']??$input['attempt_id']??0);$st=$db->prepare('SELECT e.course_id FROM exam_attempts a JOIN exams e ON e.id=a.exam_id WHERE a.id=? AND a.student_id=?');$st->execute([$id,$studentId]);$found=$st->fetchColumn();
    // The exam router records invalid or someone else's attempt URLs separately.
    if($found===false)return true;$courseId=(int)$found;break;
   case 'task-submit':$resource='SELECT course_id FROM tasks WHERE id=?';$id=(int)($input['task_id']??0);break;
   case 'submission-file':$resource='SELECT t.course_id FROM task_submissions s JOIN tasks t ON t.id=s.task_id WHERE s.id=?';$id=(int)($input['id']??0);break;
   case 'attendance-submit':$resource='SELECT course_id FROM attendance_sessions WHERE id=?';$id=(int)($input['session_id']??0);break;
   case 'material-open':case 'material-file':case 'material-read':$resource='SELECT course_id FROM course_materials WHERE id=?';$id=(int)($input['material_id']??$input['id']??0);break;
   case 'announcement-comments':case 'announcement-comment':$resource='SELECT course_id FROM announcements WHERE id=?';$id=(int)($input['announcement_id']??$input['id']??0);break;
   case 'announcement-comment-delete':$resource='SELECT a.course_id FROM announcement_comments x JOIN announcements a ON a.id=x.announcement_id WHERE x.id=?';$id=(int)($input['comment_id']??0);break;
   case 'announcement-attachment-file':$resource='SELECT a.course_id FROM announcement_attachments x JOIN announcements a ON a.id=x.announcement_id WHERE x.id=?';$id=(int)($input['id']??0);break;
   default:return true;
  }
  if($resource!==null){$st=$db->prepare($resource);$st->execute([$id]);$courseId=(int)$st->fetchColumn();}
  $st=$db->prepare('SELECT 1 FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE e.user_id=? AND e.course_id=? AND e.status="active" AND c.active=1');$st->execute([$studentId,$courseId]);return (bool)$st->fetchColumn();
 }
}
