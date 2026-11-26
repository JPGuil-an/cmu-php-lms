<?php
use CmuLms\View;

$courseGrades = array_values(array_filter($grades, function ($grade) use ($user) {
    return (int) ($grade['student_id'] ?? 0) === (int) $user['id'] && !empty($grade['released']);
}));
$taskWindows = [];
foreach (($tasks ?? []) as $task) {
    $taskWindows[(int) $task['id']] = [
        'due_at' => !empty($task['due_at']) ? date(DATE_ATOM, strtotime($task['due_at'])) : null,
        'submissions_open' => !isset($task['submissions_open']) || !empty($task['submissions_open']),
    ];
}
?>
<section class="card"><h2>Class record</h2><p>Your released scores for this course.</p><div style="overflow-x:auto"><table><tr><th>Assessment</th><th>Category</th><th>Score</th><th>Released</th></tr><?php foreach($courseGrades as $grade): ?><tr><td><?=View::e($grade['title'])?></td><td><?=View::e($grade['category'])?></td><td><?=View::e($grade['score'])?> / <?=View::e($grade['max_score'])?></td><td>Yes</td></tr><?php endforeach; ?><?php if(!$courseGrades): ?><tr><td colspan="4">No released grades are available yet.</td></tr><?php endif; ?></table></div></section>
<?php $attendanceRecord=\CmuLms\Attendance::studentSummary((int)$course['id'],(int)$user['id']); $attendanceSubmitted=count(array_filter($attendanceRecord,static fn($row)=>!empty($row['status']))); ?>
<section class="card"><h2>Attendance record</h2><p><?=View::e($attendanceSubmitted)?> of <?=View::e(count($attendanceRecord))?> sessions submitted.</p><div style="overflow-x:auto"><table><tr><th>Session</th><th>Schedule</th><th>Status</th><th>Submitted</th></tr><?php foreach($attendanceRecord as $session): ?><tr><td><?=View::e($session['title'])?></td><td><?=View::e($session['opens_at'])?> – <?=View::e($session['closes_at'])?></td><td><span class="badge"><?=View::e($session['status']??'Unsubmitted')?></span></td><td><?=View::e($session['submitted_at']??'—')?></td></tr><?php endforeach; ?><?php if(!$attendanceRecord): ?><tr><td colspan="4">No attendance sessions are available.</td></tr><?php endif; ?></table></div></section>
<script>(function(){const windows=<?=json_encode($taskWindows,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;function closeExpired(){document.querySelectorAll('form[action*="route=task-submit"] input[name="task_id"]').forEach(function(input){const item=windows[input.value];if(!item)return;const closed=!item.submissions_open||(item.due_at&&new Date(item.due_at).getTime()<=Date.now());if(!closed)return;const form=input.form;form.querySelectorAll('textarea,input[type="file"],button').forEach(function(control){control.disabled=true});const note=document.createElement('p');note.className='error';note.textContent=item.due_at&&new Date(item.due_at).getTime()<=Date.now()?'The submission deadline is over. This activity can no longer be submitted.':'Submissions are closed by the teacher.';form.replaceWith(note);});}if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',closeExpired);else closeExpired();})();</script>
