<?php
use CmuLms\View;
$otherBanks=\CmuLms\QuestionPool::fromOtherCourses((int)$user['id'],(int)$course['id']);
?>
<div class="import-bank-panel">
<h3>Add a question bank from another class</h3>
<p class="muted">Copy an entire bank from another class you teach, then select it under Post / publish exam. The copy includes questions, choices, correct answers, points, term, and topic.</p>
<?php if(!$otherBanks): ?><p class="muted">No question banks with active questions are available in your other classes yet.</p><?php else: ?>
<form method="post" action="?route=pool-copy" id="importClassBankForm">
<?=View::csrfField()?><input type="hidden" name="target_course_id" value="<?=(int)$course['id']?>"><input type="hidden" name="return_to_course" value="1">
<label for="otherClassBank">Question banks from your other classes</label>
<div class="import-bank-actions"><select id="otherClassBank" name="pool_id" required><option value="">Choose a class and question bank</option>
<?php $group=null;foreach($otherBanks as $bank): if($group!==$bank['course_id']): if($group!==null): ?></optgroup><?php endif;$group=$bank['course_id']; ?><optgroup label="<?=View::e($bank['course_code'].' · '.$bank['course_title'])?>"><?php endif; ?>
<option value="<?=(int)$bank['id']?>"><?=View::e($bank['title'].' · '.$bank['questions_count'].' questions · '.ucfirst($bank['academic_period']??'').' · '.($bank['topic']??''))?></option>
<?php endforeach;if($group!==null): ?></optgroup><?php endif; ?></select>
<button type="submit" id="copyClassBank" disabled>Add bank to this class</button></div>
</form><p class="muted">The copied bank can be edited independently. The original class keeps its questions.</p>
<script>(()=>{const form=document.getElementById('importClassBankForm'),select=document.getElementById('otherClassBank'),button=document.getElementById('copyClassBank');select.addEventListener('change',()=>button.disabled=!select.value);form.addEventListener('submit',()=>{button.disabled=true;button.textContent='Copying question bank…';});})();</script>
<?php endif; ?>
</div>
<style>
.import-bank-panel{margin-top:20px;padding-top:16px;border-top:1px solid var(--line)}.import-bank-actions{display:flex;gap:12px;align-items:center}.import-bank-actions select{flex:1;min-width:0}.import-bank-actions button{flex-shrink:0;white-space:nowrap}.import-bank-actions button:disabled{opacity:.55;cursor:not-allowed}@media(max-width:600px){.import-bank-actions{flex-direction:column;align-items:stretch}.import-bank-actions button{width:100%}}
</style>
