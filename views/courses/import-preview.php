<?php use CmuLms\View;$title='Review student import';include __DIR__.'/../layout/header.php'; ?>
<section class="hero"><p>Student CSV import</p><h1>Review import into <?=View::e($course['code'])?></h1><p><?=View::e($course['title'])?> · Class section <?=View::e($course['section_code']??'Unspecified')?></p></section>
<section class="card">
<h2>Destination: <?=View::e($course['code'])?></h2>
<p>Every student in this import will be enrolled in this class only. Existing accounts keep their passwords, profiles, grades, and other enrollments. New accounts use this class’s section and the temporary password <strong>ChangeMe!</strong>.</p>
<div class="grid"><div><strong><?=(int)$plan['new']?></strong><p>New student accounts</p></div><div><strong><?=(int)$plan['existing']?></strong><p>Existing accounts reused</p></div><div><strong><?=(int)$plan['already']?></strong><p>Already enrolled in this class</p></div></div>
<?php if($plan['existing']): ?><p class="flash">This file includes school IDs that already exist. Check the current classes below. Importing will give those same students access to <?=View::e($course['code'])?> as well.</p><?php endif; ?>
<?php if($plan['errors']): ?><div class="error"><strong>Correct these CSV errors before importing.</strong><ul><?php foreach($plan['errors'] as $error): ?><li><?=View::e($error)?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div style="overflow-x:auto"><table><thead><tr><th>CSV row</th><th>Student</th><th>School ID</th><th>Account</th><th>Current classes</th><th>Destination</th></tr></thead><tbody><?php foreach(array_slice($plan['rows'],0,100) as $row): ?><tr><td><?=(int)$row['row']?></td><td><?=View::e($row['name'])?></td><td><?=View::e($row['id_number'])?></td><td><?=$row['user_id']?'Reuse existing':'Create new'?><?=$row['already_enrolled']?' · Already enrolled':''?></td><td><?=View::e($row['enrolled_classes']?:'None')?></td><td><?=View::e($course['code'])?></td></tr><?php endforeach; ?></tbody></table></div>
<?php if(count($plan['rows'])>100): ?><p>Showing the first 100 of <?=count($plan['rows'])?> student rows.</p><?php endif; ?>
<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-top:20px">
<?php if(!$plan['errors']&&$plan['rows']): ?><form method="post" action="?route=student-import&amp;id=<?=(int)$course['id']?>" onsubmit="this.querySelector('button').disabled=true"><?=View::csrfField()?><input type="hidden" name="course_id" value="<?=(int)$course['id']?>"><input type="hidden" name="import_token" value="<?=View::e($import_token)?>"><button>Confirm import into <?=View::e($course['code'])?></button></form><?php endif; ?>
<a href="?route=course&amp;id=<?=(int)$course['id']?>&amp;tab=students#student-import">Cancel and return to class</a>
</div></section>
<?php include __DIR__.'/../layout/footer.php'; ?>
