<?php use CmuLms\View; ?>
<details class="exam-sort-panel"><summary>Sort or rearrange exams</summary>
<form method="post" action="?route=exam-display-order" id="examOrderForm" class="exam-order-form">
 <?=View::csrfField()?><input type="hidden" name="course_id" value="<?=(int)$course['id']?>">
 <div class="exam-sort-toolbar"><label for="examOrderMode">Sort quizzes and exams</label><select name="sort_mode" id="examOrderMode"><?php foreach(\CmuLms\ExamDisplayOrder::MODES as $mode=>$label): ?><option value="<?=$mode?>" <?=($examOrder['mode']??'opens_desc')===$mode?'selected':''?>><?=View::e($label)?></option><?php endforeach; ?></select><button type="submit">Save arrangement</button></div>
 <p class="muted">Your saved order is used in this class, your dashboard score columns, and Excel exports. Title sorting puts Chapter 2 before Chapter 10.</p>
 <details id="examOrderDetails"><summary>Arrange exams manually</summary>
  <p class="muted">Move exams up or down, then select Save arrangement.</p>
  <ol id="examOrderList" class="exam-order-list"><?php foreach($exams as $orderedExam): ?><li><input type="hidden" name="exam_ids[]" value="<?=(int)$orderedExam['id']?>"><span><?=View::e($orderedExam['title'])?></span><button type="button" data-move="up" aria-label="<?=View::e('Move '.$orderedExam['title'].' up')?>">↑ Up</button><button type="button" data-move="down" aria-label="<?=View::e('Move '.$orderedExam['title'].' down')?>">↓ Down</button></li><?php endforeach; ?></ol>
  <p id="examOrderStatus" class="muted" aria-live="polite"></p>
 </details>
</form>
</details>
<style>.exam-sort-panel{margin-bottom:12px}.exam-sort-panel>summary{cursor:pointer;font-size:13px;font-weight:600;padding:8px 0}.exam-order-form{margin-top:6px}.exam-order-form{margin-bottom:20px;padding:15px;background:#f4f8f5;border:1px solid var(--line);border-radius:10px}.exam-sort-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.exam-sort-toolbar select{flex:1;min-width:190px;max-width:350px}.exam-order-list{padding:0;list-style:none;display:grid;gap:8px}.exam-order-list li{display:flex;align-items:center;gap:8px;background:white;padding:10px;border:1px solid var(--line);border-radius:8px}.exam-order-list span{flex:1}.exam-order-list button{width:auto;min-height:34px;white-space:nowrap}.exam-order-list button:disabled{opacity:.4;cursor:default}.exam-order-form summary{cursor:pointer;font-weight:bold}</style>
<script>
(()=>{
 const list=document.getElementById('examOrderList'),mode=document.getElementById('examOrderMode'),details=document.getElementById('examOrderDetails'),status=document.getElementById('examOrderStatus');
 function refresh(){Array.from(list.children).forEach((row,i)=>{row.querySelector('[data-move="up"]').disabled=i===0;row.querySelector('[data-move="down"]').disabled=i===list.children.length-1;});}
 list.addEventListener('click',event=>{const button=event.target.closest('button[data-move]');if(!button||button.disabled)return;const row=button.closest('li');if(button.dataset.move==='up'){const previous=row.previousElementSibling;if(previous)list.insertBefore(row,previous);}else{const next=row.nextElementSibling;if(next)list.insertBefore(next,row);}mode.value='manual';refresh();status.textContent='Arrangement changed. Select Save arrangement to apply it.';button.focus();});
 mode.addEventListener('change',()=>{if(mode.value==='manual')details.open=true;});refresh();
})();
</script>
