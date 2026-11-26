<?php
$studentAction = \CmuLms\Exam::studentAction($exam);
if ($studentAction['available']): ?>
<form method="post" action="?route=exam-start">
  <?=\CmuLms\View::csrfField()?>
  <input type="hidden" name="exam_id" value="<?=\CmuLms\View::e($exam['id'])?>">
  <button class="btn" type="submit"><?=\CmuLms\View::e($studentAction['label'])?></button>
</form>
<?php else: ?>
<span class="badge"><?=\CmuLms\View::e($studentAction['label'])?></span>
<?php endif; ?>
