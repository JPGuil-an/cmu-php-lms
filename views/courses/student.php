<?php
use CmuLms\View;
$studentTab = in_array(($tab ?? 'home'), ['home', 'announcements', 'activities', 'students'], true) ? ($tab ?? 'home') : 'home';
$activeExam = static function (array $exam) use ($user): bool {
    if (empty($exam['is_enabled'])) return false;
    if (($exam['audience'] ?? 'all') !== 'selected') return true;
    return in_array((int) $user['id'], array_map('intval', json_decode($exam['target_student_ids'] ?? '[]', true) ?: []), true);
};
$visibleExams = array_values(array_filter($exams, $activeExam));
?>
<section class="hero">
  <p class="eyebrow">Course home · <?=View::e($course['code'])?></p>
  <h1><?=View::e($course['title'])?></h1>
  <p><?=View::e($course['description'] ?? 'Your course workspace for announcements, activities, assessments, and progress.')?></p>
  <div class="course-tags-php"><span><?=View::e($course['section_code'] ?? 'Assigned section')?></span><span><?=View::e(($course['academic_year'] ?? '').' · '.($course['semester'] ?? '').' semester')?></span><span><?=count($students)?> learners</span></div>
</section>
<nav class="card course-tabs-php" aria-label="Course navigation">
  <a class="<?=$studentTab==='home'?'active':''?>" href="?route=course&amp;id=<?=(int)$course['id']?>&amp;tab=home">Course home</a>
  <a class="<?=$studentTab==='announcements'?'active':''?>" href="?route=course&amp;id=<?=(int)$course['id']?>&amp;tab=announcements">Announcements</a>
  <a class="<?=$studentTab==='activities'?'active':''?>" href="?route=course&amp;id=<?=(int)$course['id']?>&amp;tab=activities">Activities</a>
  <a class="<?=$studentTab==='grades'?'active':''?>" href="?route=course-gradebook&amp;id=<?=(int)$course['id']?>">Grades</a>
  <a class="<?=$studentTab==='students'?'active':''?>" href="?route=course&amp;id=<?=(int)$course['id']?>&amp;tab=students">Classmates</a>
</nav>
<?php if ($studentTab === 'home'): ?>
  <div class="grid">
    <a class="card course-summary-link" href="?route=course&amp;id=<?=(int)$course['id']?>&amp;tab=announcements"><span class="eyebrow">Communication</span><strong><?=count($announcements)?></strong><h3>Announcements</h3><small>Open class updates and notices.</small></a>
    <a class="card course-summary-link" href="?route=course&amp;id=<?=(int)$course['id']?>&amp;tab=activities"><span class="eyebrow">Learning space</span><strong><?=count($tasks)+count($visibleExams)+count($attendance)+count($materials)?></strong><h3>Activities</h3><small>Lessons, attendance, assignments, and quizzes.</small></a>
    <a class="card course-summary-link" href="?route=course-gradebook&amp;id=<?=(int)$course['id']?>"><span class="eyebrow">Progress</span><strong><?=count(array_filter($grades, static fn($grade) => !empty($grade['released']) && (int)$grade['student_id'] === (int)$user['id']))?></strong><h3>Grades</h3><small>View your released scores and class record.</small></a>
    <a class="card course-summary-link" href="?route=course&amp;id=<?=(int)$course['id']?>&amp;tab=students"><span class="eyebrow">Community</span><strong><?=count($students)?></strong><h3>Classmates</h3><small>See the learners enrolled in this section.</small></a>
  </div>
  <section class="card"><div class="student-heading-php"><div><p class="eyebrow">Assessment schedule</p><h2>Quizzes and exams</h2></div><span class="badge"><?=count($visibleExams)?></span></div>
    <?php foreach ($visibleExams as $exam): $upcoming=!empty($exam['starts_at'])&&strtotime($exam['starts_at'])>time(); $closed=!empty($exam['ends_at'])&&strtotime($exam['ends_at'])<time()&&empty($exam['retake_after_close']); $done=(int)($exam['attempts_remaining']??0)<=0&&empty($exam['has_active_attempt']); ?>
      <article class="activity-row-php"><div><strong><?=View::e($exam['title'])?></strong><small><?=View::e($exam['duration_minutes'])?> minutes · Taken <?=View::e($exam['attempts_taken']??0)?> time<?=((int)($exam['attempts_taken']??0)===1?'':'s')?> · <?=View::e($exam['attempts_remaining']??0)?> attempts remaining</small><?php if(!empty($exam['retake_after_close'])):?><small class="quiz-retake-approved-php">Teacher approved a retake after closing.</small><?php endif;?></div><span class="badge"><?= $upcoming ? 'Upcoming' : ($closed ? 'Closed' : ($done ? 'Completed' : (!empty($exam['retake_after_close'])?'Retake approved':'Open'))) ?></span><?php include __DIR__.'/../exams/student-action.php'; ?></article>
    <?php endforeach; ?><?php if (!$visibleExams): ?><p class="muted">No quizzes or exams are posted in this course yet.</p><?php endif; ?>
  </section>
<?php elseif ($studentTab === 'announcements'): ?>
  <section class="card"><p class="eyebrow">Teacher-configured communication</p><h2>Announcements</h2><?php foreach ($announcements as $announcement): ?><article class="activity-row-php"><div><strong><?=View::e($announcement['title'])?></strong><small><?=nl2br(View::e($announcement['body']))?></small></div><span class="muted"><?=View::e($announcement['publish_at'] ?? '')?></span></article><?php endforeach; ?><?php if (!$announcements): ?><p class="muted">No teacher-configured announcements have been posted yet.</p><?php endif; ?></section>
<?php elseif ($studentTab === 'students'): ?>
  <section class="card"><p class="eyebrow">Course community</p><h2>Classmates</h2><div class="grid"><?php $visibleClassmates=array_values(array_filter($students, static fn($student)=>empty($student['disabled_at']))); foreach ($visibleClassmates as $classmate): $parts=preg_split('/\s+/',trim($classmate['name']??'')); $first=$parts[0]??''; $last=count($parts)>1?array_pop($parts):$first; ?><article class="card"><strong><?=View::e($last.', '.strtoupper(substr($first,0,1)).'.')?></strong><small class="muted">Classmate</small></article><?php endforeach; ?><?php if (!$visibleClassmates): ?><p class="muted">No classmates are currently enrolled.</p><?php endif; ?></div></section>
<?php else: ?>
  <section class="card"><p class="eyebrow">Teacher-configured learning activities</p><h2>Lessons and assessments</h2>
    <?php foreach ($tasks as $task):
      $mine = null;
      foreach (($submissions ?? []) as $submission) if ((int) $submission['task_id'] === (int) $task['id']) {$mine = $submission; break;}
      $closed = empty($task['submissions_open']) || (!empty($task['due_at']) && strtotime($task['due_at']) <= time() && empty($task['allow_late']));
    ?>
      <article class="activity-row-php activity-stack-php"><div><strong><?=View::e($task['title'])?></strong><small><?=nl2br(View::e($task['description'] ?? ''))?><?=!empty($task['due_at'])?'<br>Due '.View::e($task['due_at']):''?></small></div>
      <?php if ($mine): ?><p class="flash">Submitted <?=View::e($mine['submitted_at'])?>. This submission is final.</p>
      <?php elseif ($closed): ?><p class="error">The deadline is over or submissions were closed. This activity cannot be submitted.</p>
      <?php elseif (($task['submission_type'] ?? 'none') === 'none'): ?><p class="muted">This activity is for viewing only.</p>
      <?php else: ?><form method="post" action="?route=task-submit" enctype="multipart/form-data"><?=View::csrfField()?><input type="hidden" name="task_id" value="<?=View::e($task['id'])?>"><input type="hidden" name="course_id" value="<?=View::e($course['id'])?>"><textarea name="body" placeholder="Your response"></textarea><input type="file" name="file" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.zip"><button>Submit final activity</button></form><?php endif; ?></article>
    <?php endforeach; ?>
    <?php foreach ($visibleExams as $exam): $upcoming=!empty($exam['starts_at'])&&strtotime($exam['starts_at'])>time(); $closed=!empty($exam['ends_at'])&&strtotime($exam['ends_at'])<time()&&empty($exam['retake_after_close']); $done=(int)($exam['attempts_remaining']??0)<=0&&empty($exam['has_active_attempt']); ?><article class="activity-row-php"><div><strong><?=View::e($exam['title'])?></strong><small>Quiz / exam · <?=View::e($exam['duration_minutes'])?> minutes · Taken <?=View::e($exam['attempts_taken']??0)?> time<?=((int)($exam['attempts_taken']??0)===1?'':'s')?></small><?php if(!empty($exam['retake_after_close'])):?><small class="quiz-retake-approved-php">Teacher approved a retake after closing.</small><?php endif;?></div><span class="badge"><?=$upcoming?'Upcoming':($closed?'Closed':($done?'Completed':(!empty($exam['retake_after_close'])?'Retake approved':'Open')))?></span><?php include __DIR__.'/../exams/student-action.php'; ?></article><?php endforeach; ?>
    <?php foreach ($attendance as $session): $available=strtotime($session['opens_at'])<=time()&&strtotime($session['closes_at'])>=time(); ?><article class="activity-row-php"><div><strong><?=View::e($session['title'])?></strong><small>Attendance · <?=View::e($session['opens_at'])?> to <?=View::e($session['closes_at'])?></small></div><?php if (!empty($session['status'])): ?><span class="badge"><?=View::e($session['status'])?></span><?php elseif ($available): ?><form method="post" action="?route=attendance-submit"><?=View::csrfField()?><input type="hidden" name="course_id" value="<?=View::e($course['id'])?>"><input type="hidden" name="session_id" value="<?=View::e($session['id'])?>"><input name="access_code" placeholder="Access code (if required)"><button>Submit attendance</button></form><?php else: ?><span class="badge"><?=$available?'Open':'Closed'?></span><?php endif; ?></article><?php endforeach; ?>
    <?php foreach ($materials as $material): $open=empty($material['opens_at'])||strtotime($material['opens_at'])<=time(); $open=$open&&(empty($material['closes_at'])||strtotime($material['closes_at'])>=time()); ?><article class="activity-row-php"><div><strong><?=View::e($material['title'])?></strong><small>Learning material · <?=View::e($material['description']??'')?></small></div><?php if ($open): ?><a class="btn" href="?route=material-open&amp;id=<?=View::e($material['id'])?>">Open material</a><?php else: ?><span class="badge">Scheduled</span><?php endif; ?></article><?php endforeach; ?>
    <?php if (!$tasks && !$visibleExams && !$attendance && !$materials): ?><p class="muted">No activities are available in this course yet.</p><?php endif; ?>
  </section>
<?php endif; ?>
<style>
.course-tags-php{display:flex;gap:8px;flex-wrap:wrap;margin-top:20px}.course-tags-php span{border:1px solid #79a895;border-radius:999px;padding:7px 11px;font-size:12px}.course-tabs-php{display:flex;gap:7px;flex-wrap:wrap;padding:8px}.course-tabs-php a{padding:10px 13px;border-radius:8px;text-decoration:none;color:var(--ink)}.course-tabs-php a.active{background:var(--green);color:#fff}.course-summary-link{text-decoration:none;color:var(--ink);display:grid;gap:5px}.course-summary-link strong{font-size:28px;color:var(--green)}.course-summary-link small{color:#6c7e74}.activity-row-php{display:flex;align-items:center;gap:13px;border-top:1px solid var(--line);padding:15px 0}.activity-row-php>div{display:grid;gap:4px;flex:1}.activity-row-php small{color:#6c7e74;white-space:pre-line}.activity-row-php form{display:block}.activity-row-php .btn{white-space:nowrap}@media(max-width:600px){.activity-row-php{align-items:flex-start;flex-wrap:wrap}.activity-row-php .badge{margin-left:auto}.activity-row-php form{width:100%}.activity-row-php form .btn{width:100%}}
</style>
