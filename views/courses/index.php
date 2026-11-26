<?php use CmuLms\View; $title='Classes'; include __DIR__.'/../layout/header.php'; ?>
<section class="hero"><p class="eyebrow">Learning spaces</p><h1>Classes</h1><p>Subjects assigned to your account for the current academic period.</p></section>
<section class="grid classes-grid-php">
  <?php foreach ($courses as $course): ?>
    <a class="card class-card-php" href="?route=course&amp;id=<?=View::e($course['id'])?>">
      <span class="badge"><?=View::e($course['code'])?></span>
      <h2><?=View::e($course['title'])?></h2>
      <p><?=View::e($course['description'] ?? 'Course workspace for announcements, activities, assessments, and progress.')?></p>
      <footer><?=View::e($course['section_code'] ?? 'Assigned section')?> <strong>Open course →</strong></footer>
    </a>
  <?php endforeach; ?>
  <?php if (!$courses): ?><div class="card"><h2>No classes assigned</h2><p class="muted">No active learning spaces are currently assigned to your account.</p></div><?php endif; ?>
</section>
<?php if ($user['role']==='student'):
    $retryExams=array_values(array_filter($exams??[],static fn($exam)=>(!empty($exam['completed'])||!empty($exam['has_active_attempt']))&&\CmuLms\Exam::studentAction($exam)['available']));
?>
<section class="card"><h2>Quiz attempts</h2><p class="muted">Continue an attempt or try again when your teacher allows more attempts.</p>
  <?php foreach ($retryExams as $exam): ?>
  <article class="card">
    <h3><?=View::e($exam['title'])?></h3>
    <p><a href="?route=course&amp;id=<?=(int)$exam['course_id']?>"><?=View::e($exam['course_code'])?></a> · <?=View::e($exam['attempts_taken'])?> of <?=View::e($exam['attempts_allowed'])?> attempts used · <?=View::e($exam['attempts_remaining'])?> remaining</p>
    <?php include __DIR__.'/../exams/student-action.php'; ?>
  </article>
  <?php endforeach; ?>
  <?php if (!$retryExams): ?><p class="muted">No quizzes are available to attempt again right now.</p><?php endif; ?>
</section>
<?php endif; ?>
<style>
.class-card-php{display:grid;gap:8px;text-decoration:none;color:var(--ink);transition:transform .15s ease,box-shadow .15s ease}.class-card-php:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(20,63,53,.1)}.class-card-php h2{margin:2px 0;font-size:21px}.class-card-php p{color:#6c7e74;line-height:1.55;margin:0}.class-card-php footer{margin:10px 0 0;padding-top:12px;border-top:1px solid var(--line);display:flex;justify-content:space-between;color:#6c7e74;font-size:12px}.class-card-php footer strong{color:var(--green)}
</style>
<?php include __DIR__.'/../layout/footer.php'; ?>
