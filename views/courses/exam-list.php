<?php use CmuLms\View; ?>
<section class="card compact-exams" id="quizzes-and-exams">
 <header class="compact-section-heading"><div><h2>Quizzes and exams <span class="compact-count"><?=count($exams)?></span></h2><p class="muted">Select an exam to view its schedule and actions.</p></div></header>
 <?php if($user['role']==='teacher'): include __DIR__.'/exam-order.php'; endif; ?>
 <div class="compact-exam-list">
 <?php foreach($exams as $e):
  $upcoming=!empty($e['starts_at'])&&strtotime($e['starts_at'])>time();
  $closed=!empty($e['ends_at'])&&strtotime($e['ends_at'])<time();
  $status=empty($e['is_enabled'])?'Disabled':($upcoming?'Upcoming':($closed?'Closed':'Open'));
 ?>
 <details class="compact-exam" name="course-exam">
  <summary><span class="compact-exam-info"><strong><?=View::e($e['title'])?></strong><small><?=View::e($e['question_count'])?> items · <?=View::e($e['duration_minutes'])?> min · <?=View::e($e['attempts_allowed'])?> attempts</small></span><span class="compact-status <?=$status==='Open'?'is-open':''?>"><?=$status?></span><span class="compact-chevron" aria-hidden="true">⌄</span></summary>
  <div class="compact-exam-body"><dl><div><dt>Opens</dt><dd><?=!empty($e['starts_at'])?View::e(date('M j, Y · g:i A',strtotime($e['starts_at']))):'Not scheduled'?></dd></div><div><dt>Closes</dt><dd><?=!empty($e['ends_at'])?View::e(date('M j, Y · g:i A',strtotime($e['ends_at']))):'No closing time'?></dd></div></dl>
   <?php if($user['role']==='teacher'): ?><nav class="compact-exam-actions" aria-label="<?=View::e($e['title'].' actions')?>"><a class="btn" href="?route=exam-stats&amp;id=<?=(int)$e['id']?>">View statistics</a><a class="compact-secondary" href="?route=exam-preview&amp;id=<?=(int)$e['id']?>">Preview quiz</a><a class="compact-secondary" href="?route=exam-edit&amp;id=<?=(int)$e['id']?>">Edit exam</a></nav><?php endif; ?>
  </div>
 </details>
 <?php endforeach; ?>
 <?php if(!$exams): ?><p class="muted">No exams have been published for this class yet.</p><?php endif; ?>
 </div>
</section>
<style>
.compact-section-heading{position:static;background:none;color:inherit;padding:0;display:flex;align-items:center;justify-content:space-between}.compact-section-heading h2{margin:0;font-size:21px}.compact-section-heading p{font-size:13px;margin:6px 0 16px}.compact-count{font-size:12px;background:#eaf2ee;color:#526e61;border-radius:20px;padding:3px 8px;vertical-align:middle;margin-left:5px}.compact-exam-list{border-top:1px solid var(--line)}.compact-exam{border-bottom:1px solid var(--line)}.compact-exam:last-child{border-bottom:0}.compact-exam>summary{display:flex;align-items:center;gap:14px;padding:15px 2px;list-style:none;cursor:pointer}.compact-exam>summary::-webkit-details-marker{display:none}.compact-exam>summary:hover{background:#f7faf8}.compact-exam-info{flex:1;min-width:0;display:grid;gap:5px}.compact-exam-info strong{font-size:15px}.compact-exam-info small{color:#6b7d73;font-size:12px}.compact-status{font-size:11px;background:#f0f2f1;color:#63746b;padding:4px 9px;border-radius:20px;white-space:nowrap}.compact-status.is-open{color:#24634c;background:#e7f3eb}.compact-chevron{color:#6b7d73;transition:transform .15s}.compact-exam[open] .compact-chevron{transform:rotate(180deg)}.compact-exam-body{padding:0 2px 16px}.compact-exam-body dl{display:flex;flex-wrap:wrap;gap:12px 36px;margin:0 0 14px}.compact-exam-body dt{font-size:11px;color:#6b7d73;margin-bottom:4px}.compact-exam-body dd{font-size:13px;margin:0}.compact-exam-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.compact-exam-actions a{font-size:12px;min-height:34px;padding:9px 12px;width:auto}.compact-secondary{display:inline-block;text-decoration:none;border:1px solid var(--line);border-radius:7px;color:var(--deep);background:white}.compact-exam>summary:focus-visible{outline:2px solid var(--green);outline-offset:2px}@media(max-width:500px){.compact-exam>summary{gap:8px}.compact-exam-body dl{gap:12px}.compact-exam-info small{font-size:11px}}
</style>
