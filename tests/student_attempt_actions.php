<?php
require __DIR__.'/../models/Model.php';
require __DIR__.'/../models/Exam.php';
require __DIR__.'/../core/View.php';

$now = time();
$base = ['id'=>42, 'is_enabled'=>1, 'attempts_allowed'=>2, 'attempts_taken'=>1, 'attempts_remaining'=>1];
$cases = [
    'remaining second attempt'=>[[], true, 'Attempt again'],
    'first attempt'=>[['attempts_taken'=>0, 'attempts_remaining'=>2], true, 'Start attempt'],
    'single attempt used'=>[['attempts_allowed'=>1, 'attempts_remaining'=>0], false, 'Attempts used'],
    'all attempts used'=>[['attempts_taken'=>2, 'attempts_remaining'=>0], false, 'Attempts used'],
    'resume final attempt'=>[['attempts_taken'=>2, 'attempts_remaining'=>0, 'has_active_attempt'=>1], true, 'Continue attempt'],
    'not yet open'=>[['starts_at'=>date('Y-m-d H:i:s', $now+3600)], false, 'Scheduled'],
    'closed'=>[['ends_at'=>date('Y-m-d H:i:s', $now-3600)], false, 'Closed'],
    'approved after closing'=>[['ends_at'=>date('Y-m-d H:i:s', $now-3600), 'retake_after_close'=>1], true, 'Start approved retake'],
    'disabled'=>[['is_enabled'=>0], false, 'Unavailable'],
];
foreach ($cases as $name=>[$overrides, $available, $label]) {
    $exam=array_replace($base, $overrides);
    $action=\CmuLms\Exam::studentAction($exam, $now);
    if ($action!==['available'=>$available, 'label'=>$label]) throw new RuntimeException($name);
    ob_start(); include __DIR__.'/../views/exams/student-action.php'; $html=ob_get_clean();
    if (str_contains($html, '<form')!==$available || !str_contains($html, $label)) throw new RuntimeException('Rendering: '.$name);
    if ($available && (!str_contains($html, 'name="_csrf"') || !str_contains($html, 'value="42"'))) throw new RuntimeException('Missing form data: '.$name);
}
echo count($cases)." attempt action and rendering cases passed.\n";
