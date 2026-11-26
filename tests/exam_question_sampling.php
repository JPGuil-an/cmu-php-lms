<?php
require __DIR__.'/../core/Database.php';
require __DIR__.'/../models/Model.php';
require __DIR__.'/../models/Exam.php';
$config=require __DIR__.'/../config.php';
$db=\CmuLms\Database::connect($config['db']);
\CmuLms\Exam::setDb($db);
// Connection-local fixtures shadow the real table without changing its records.
$db->exec('CREATE TEMPORARY TABLE questions (id INT PRIMARY KEY,pool_id INT,is_active INT,options TEXT)');
try {
    for($id=1;$id<=10;$id++) $db->prepare('INSERT INTO questions VALUES(?,7,1,?)')->execute([$id,'{"A":"One","B":"Two"}']);
    $db->exec("INSERT INTO questions VALUES(11,7,0,'{}'),(12,8,1,'{}')");
    $sets=$orders=[];
    for($draw=0;$draw<30;$draw++) {
        $ids=array_column(\CmuLms\Exam::questionsForAttempt(7,4),'id');
        if(count($ids)!==4||count(array_unique($ids))!==4||array_diff($ids,range(1,10))) throw new RuntimeException('Invalid sample');
        $orders[]=implode(',',$ids);sort($ids);$sets[]=implode(',',$ids);
    }
    if(count(array_unique($sets))<2||count(array_unique($orders))<2) throw new RuntimeException('Samples did not vary');
    $fullOrders=[];
    for($draw=0;$draw<10;$draw++) $fullOrders[]=implode(',',array_column(\CmuLms\Exam::questionsForAttempt(7,10),'id'));
    if(count(array_unique($fullOrders))<2) throw new RuntimeException('Full-bank order did not vary');
    foreach([0,11] as $count) {
        try {\CmuLms\Exam::questionsForAttempt(7,$count);throw new RuntimeException('Invalid count accepted');}
        catch(\InvalidArgumentException $expected) {}
    }
    echo "Passed: exact counts, unique questions, active bank only, varied sets/order, full-bank shuffle, and invalid counts.\n";
} finally {$db->exec('DROP TEMPORARY TABLE questions');}
