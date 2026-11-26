<?php use CmuLms\View; ?>
<section class="card teacher-scores" id="quiz-scores">
 <h2>Quiz scores by attempt</h2>
 <p class="muted">Every try stays separate. Click a recorded score to review its answers. Scores are shown as earned / maximum; reset attempts remain in the history.</p>
 <form method="get" class="score-filters">
  <input type="hidden" name="route" value="dashboard">
  <input type="hidden" name="archived" value="<?=!empty($show_archived)?1:0?>">
  <label>Class<select name="score_course" required><?php foreach($courses as $c): ?><option value="<?=(int)$c['id']?>" <?=isset($scoreReport)&&$scoreReport['course']['id']==$c['id']?'selected':''?>><?=View::e($c['code'].' · '.$c['title'].' · '.($c['section_code']??''))?></option><?php endforeach; ?></select></label>
  <label>Academic term<select name="score_period"><?php foreach(['all'=>'All terms','midterm'=>'Midterm','final'=>'Final'] as $key=>$label): ?><option value="<?=$key?>" <?=($scoreReport['period']??'all')===$key?'selected':''?>><?=$label?></option><?php endforeach; ?></select></label>
  <label>Student name or ID<input name="score_search" value="<?=View::e($scoreReport['search']??'')?>" placeholder="Search students"></label>
  <button type="submit">View scores</button>
 </form>
 <?php if(!empty($scoreReport)): $exportUrl='?'.http_build_query(['route'=>'teacher-scores-export','course_id'=>$scoreReport['course']['id'],'score_period'=>$scoreReport['period'],'score_search'=>$scoreReport['search']]); ?>
 <div class="score-heading"><p><strong><?=View::e($scoreReport['course']['code'])?></strong> · <?=count($scoreReport['rows'])?> students · <?=count($scoreReport['exams'])?> exams</p><a class="btn" href="<?=View::e($exportUrl)?>">Export Excel (.xlsx)</a></div>
 <p class="muted">Export includes the selected class, term, and student search. Formerly enrolled students with recorded attempts are included. <a href="?route=course&amp;id=<?=(int)$scoreReport['course']['id']?>#quizzes-and-exams">Arrange exam columns</a>.</p>
 <?php if($scoreReport['exams']): ?>
 <div class="score-scroll" tabindex="0" role="region" aria-label="Student quiz scores; scroll horizontally for more exams">
 <table class="score-table"><thead><tr><th rowspan="2" scope="col">Last name</th><th rowspan="2" scope="col">First name</th><th rowspan="2" scope="col">Middle initial</th><th rowspan="2" scope="col">ID number</th>
 <?php foreach($scoreReport['exams'] as $e): $span=count(array_filter($scoreReport['columns'],static fn($col)=>$col['exam']['id']==$e['id'])); ?><th colspan="<?=$span?>" scope="colgroup"><a href="?route=exam-stats&amp;id=<?=(int)$e['id']?>"><?=View::e($e['title'])?></a></th><?php endforeach; ?></tr><tr>
 <?php foreach($scoreReport['columns'] as $col): ?><th scope="col">Attempt <?=$col['number']?></th><?php endforeach; ?></tr></thead><tbody>
 <?php foreach($scoreReport['rows'] as $row): $s=$row['student']; ?><tr><td><?=View::e($s['last_name']?:$s['name'])?></td><td><?=View::e($s['first_name']??'')?></td><td><?=View::e($s['middle_initial']??'')?></td><td><?=View::e($s['id_number'])?></td>
 <?php foreach($row['cells'] as $cell): $a=$cell['attempt']; ?><td><?php if($a): ?><a href="?route=attempt-review&amp;id=<?=(int)$a['id']?>"><?php if(is_float($cell['value'])): ?><?=View::e($cell['value'])?> / <?=View::e((float)$a['max_score'])?><?php else: ?><?=View::e($cell['label'])?><?php endif; ?></a><?php if(in_array($a['status'],['reset','auto_submitted'],true)): ?><small><?=View::e($cell['label'])?></small><?php endif; ?><?php else: ?><span class="muted"><?=View::e($cell['label'])?></span><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
 <?php if(!$scoreReport['rows']): ?><tr><td colspan="<?=4+count($scoreReport['columns'])?>">No students match this search.</td></tr><?php endif; ?>
 </tbody></table></div>
 <?php else: ?><p class="muted">No exams in this class for the selected term.</p><?php endif; ?>
 <?php else: ?><p class="muted">No classes are available. Create a class to start tracking scores.</p><?php endif; ?>
</section>
<style>
.teacher-scores{scroll-margin-top:85px}.score-filters{display:flex;flex-wrap:wrap;align-items:end;gap:12px}.score-filters label{display:grid;gap:6px;flex:1;min-width:160px}.score-filters button{width:auto}.score-heading{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-top:18px}.score-scroll{max-height:580px;overflow:auto;border:1px solid var(--line);border-radius:8px}.score-table{display:table!important;width:max-content;min-width:100%;font-size:13px;white-space:nowrap}.score-table th,.score-table td{border:1px solid var(--line);padding:10px 13px}.score-table thead{position:sticky;top:0;z-index:2}.score-table th{background:var(--deep);color:white;text-align:center}.score-table th a{color:white}.score-table tbody tr:nth-child(even){background:#f1f7f3}.score-table tbody tr:hover{background:#e2f0e8}.score-table td:nth-child(n+5){text-align:center}.score-table small{display:block;font-size:10px;color:#62766e;margin-top:3px}
</style>
