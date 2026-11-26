<?php use CmuLms\View; $title='Exam access warning'; include __DIR__.'/../layout/header.php'; ?>
<section class="card" style="max-width:680px;margin:48px auto;text-align:center;border-top:5px solid #b45a3c">
  <div style="font-size:42px;margin-bottom:10px">⚠</div>
  <h1>Exam access unavailable</h1>
  <p><?=View::e($message)?></p>
  <p class="muted">Attempt reference: <?=View::e($attempt_id??'Unknown')?></p>
  <a class="btn" href="?route=dashboard">Back to dashboard</a>
</section>
<?php include __DIR__.'/../layout/footer.php'; ?>
