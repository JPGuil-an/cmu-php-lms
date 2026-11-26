<?php use CmuLms\View; $title='Course grades'; include __DIR__.'/../layout/header.php'; ?>
<section class="hero">
  <span><?=View::e($course['code'])?> · Class record</span>
  <h1><?=View::e($course['title'])?></h1>
  <p>Your released grades and attendance for this course.</p>
</section>
<nav class="card" aria-label="Course navigation" style="display:flex;gap:16px;flex-wrap:wrap">
  <a href="?route=course&amp;id=<?=View::e($course['id'])?>&amp;tab=home">Course home</a>
  <a href="?route=course&amp;id=<?=View::e($course['id'])?>&amp;tab=announcements">Announcements</a>
  <a href="?route=course&amp;id=<?=View::e($course['id'])?>&amp;tab=activities">Activities</a>
  <a class="badge" href="?route=course-gradebook&amp;id=<?=View::e($course['id'])?>">Grades</a>
  <a href="?route=course&amp;id=<?=View::e($course['id'])?>&amp;tab=students">Classmates</a>
</nav>
<section class="card">
  <h2>Released assessment scores</h2>
  <div style="overflow-x:auto">
    <table>
      <tr><th>Assessment</th><th>Category</th><th>Score</th><th>Released</th></tr>
      <?php foreach ($grades as $grade): ?>
        <tr><td><?=View::e($grade['title'])?></td><td><?=View::e($grade['category'])?></td><td><?=View::e($grade['score'])?> / <?=View::e($grade['max_score'])?></td><td>Yes</td></tr>
      <?php endforeach; ?>
      <?php if (!$grades): ?><tr><td colspan="4">No released grades are available yet.</td></tr><?php endif; ?>
    </table>
  </div>
</section>
<section class="card">
  <h2>Attendance record</h2>
  <div style="overflow-x:auto">
    <table>
      <tr><th>Session</th><th>Schedule</th><th>Status</th><th>Submitted</th></tr>
      <?php foreach ($attendance as $session): ?>
        <tr><td><?=View::e($session['title'])?></td><td><?=View::e($session['opens_at'])?> – <?=View::e($session['closes_at'])?></td><td><span class="badge"><?=View::e($session['status'] ?? 'Unsubmitted')?></span></td><td><?=View::e($session['submitted_at'] ?? '—')?></td></tr>
      <?php endforeach; ?>
      <?php if (!$attendance): ?><tr><td colspan="4">No attendance sessions are available.</td></tr><?php endif; ?>
    </table>
  </div>
</section>
<?php include __DIR__.'/../layout/footer.php'; ?>
