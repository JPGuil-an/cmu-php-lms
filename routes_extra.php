<?php
/* Additional MVC routes kept separate from the legacy front-controller routes. */
global $db, $u, $route;

if($u&&$u['role']==='student'&&!\CmuLms\StudentCourseAccess::allows($db,(int)$u['id'],$route,array_merge($_GET,$_POST))){
    if(\CmuLms\Http::wantsJson())\CmuLms\Http::json(['ok'=>false,'message'=>'This class or exam is no longer available to your account.'],403);
    http_response_code(403);
    view('errors/course-access',['user'=>$u]);exit;
}

if ($u) {
    $sessionUser = $db->prepare('SELECT disabled_at FROM users WHERE id=?');
    $sessionUser->execute([(int)$u['id']]);
    if ($sessionUser->fetchColumn()) {
        \CmuLms\Auth::logout();
        $_SESSION['error']='This account was disabled. Contact your admin.';
        \CmuLms\Http::redirect('?route=login');
    }
    if ($_SERVER['REQUEST_METHOD']==='POST' && !in_array($route,['login','logout'],true)) {
        $meta=['route'=>$route];foreach(['course_id','exam_id','attempt_id','task_id','submission_id','pool_id','user_id','session_id'] as $key)if(isset($_POST[$key]))$meta[$key]=(int)$_POST[$key];
        $db->prepare('INSERT INTO audit_logs(user_id,action,auditable_type,metadata,ip_address,user_agent,created_at,updated_at) VALUES(?,?,?,?,?,?,NOW(),NOW())')->execute([$u['id'],'php_'.$route,'request',json_encode($meta),$_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??'']);
    }
}

/* Administrator audit trail: searchable, paginated, and read-only. */
if ($route === 'admin-audit' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && $u['role'] === 'admin') {
    $q = trim($_GET['q'] ?? '');
    $requestedPer=(int)($_GET['per_page']??25); $per = in_array($requestedPer, [10, 25, 50, 100], true) ? $requestedPer : 25;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $where = '';
    $params = [];
    if ($q !== '') {
        $where = 'WHERE a.action LIKE ? OR a.auditable_type LIKE ? OR u.name LIKE ? OR u.id_number LIKE ? OR a.ip_address LIKE ?';
        $needle = '%' . $q . '%';
        $params = [$needle, $needle, $needle, $needle, $needle];
    }
    $count = $db->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id $where");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);
    $st = $db->prepare("SELECT a.id,a.action,a.auditable_type,a.auditable_id,a.metadata,a.ip_address,a.user_agent,a.created_at,u.name,u.id_number FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id $where ORDER BY a.created_at DESC,a.id DESC LIMIT ? OFFSET ?");
    foreach ($params as $i => $value) $st->bindValue($i + 1, $value, \PDO::PARAM_STR);
    $st->bindValue(count($params) + 1, $per, \PDO::PARAM_INT);
    $st->bindValue(count($params) + 2, ($page - 1) * $per, \PDO::PARAM_INT);
    $st->execute();
    view('admin/audit', ['user'=>$u,'logs'=>$st->fetchAll(),'q'=>$q,'per_page'=>$per,'page'=>$page,'pages'=>$pages,'total'=>$total]);
    exit;
}

/* Generate quiz items from teacher-owned PDF/PPTX material. Accept both a raw JSON array and
   the common {"questions":[...]} provider response shape. */
if ($route === 'ai-generate' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'teacher') {
    \CmuLms\View::checkCsrf();
    $poolId=(int)($_POST['pool_id']??0);$st=$db->prepare('SELECT p.*,c.id course_id FROM question_pools p JOIN courses c ON c.id=p.course_id WHERE p.id=? AND c.teacher_id=? AND c.active=1');$st->execute([$poolId,$u['id']]);$pool=$st->fetch();if(!$pool)exit('Question bank not found.');
    if(empty($u['grok_api_key'])){$_SESSION['error']='Save your Groq API key in Settings first.';\CmuLms\Http::redirect('?route=course&id='.(int)$pool['course_id'].'&tab=question-banks');}
    if(empty($_FILES['file']['tmp_name'])||!is_uploaded_file($_FILES['file']['tmp_name']))exit('Choose a PDF or PPTX file.');$ext=strtolower(pathinfo($_FILES['file']['name'],PATHINFO_EXTENSION));if(!in_array($ext,['pdf','pptx'],true))exit('Only PDF and PPTX files are accepted.');
    $text='';$tmp=$_FILES['file']['tmp_name'];
    $text=implode("\n",cmuMaterialPages($tmp,$ext));
    $text=trim(preg_replace('/\s+/',' ',$text));if(strlen($text)<20)exit('The uploaded material did not contain readable text.');
    $count=max(1,min(50,(int)($_POST['item_count']??10)));$type=in_array($_POST['question_type']??'multiple_choice',['multiple_choice','true_false','identification'],true)?$_POST['question_type']:'multiple_choice';$model=trim($_POST['model']??'llama-3.1-8b-instant');
    $prompt="Create exactly $count quiz questions from this chapter. Type: $type. Return JSON only, either an array or an object with a questions array. For multiple choice use options as an object with A,B,C,D and answer as one letter. For true/false use options A=True and B=False. For identification use an empty options object and answer as a short string. Do not include markdown.\n\nCHAPTER:\n".substr($text,0,120000);
    $payload=json_encode(['model'=>$model,'messages'=>[['role'=>'user','content'=>$prompt]],'temperature'=>0.4]);$ch=curl_init('https://api.groq.com/openai/v1/chat/completions');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$u['grok_api_key'],'Content-Type: application/json'],CURLOPT_POSTFIELDS=>$payload,CURLOPT_TIMEOUT=>120]);$raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$res=json_decode($raw,true);$content=trim((string)($res['choices'][0]['message']['content']??''));$content=preg_replace('/^```(?:json)?\s*|\s*```$/i','',$content);$decoded=json_decode(trim($content),true);$items=is_array($decoded)&&isset($decoded['questions'])&&is_array($decoded['questions'])?$decoded['questions']:$decoded;if($code>=300||!is_array($items)){$_SESSION['error']='AI generation failed. Check the provider key and model.';\CmuLms\Http::redirect('?route=course&id='.(int)$pool['course_id'].'&tab=question-banks');}
    $n=0;foreach($items as $item){if(!is_array($item))continue;$q=trim((string)($item['prompt']??$item['question']??''));if($q==='')continue;$opts=$item['options']??[];if(is_array($opts)&&array_is_list($opts)){$mapped=[];foreach($opts as $i=>$value)$mapped[chr(65+$i)]=is_array($value)?($value['text']??''):(string)$value;$opts=$mapped;}$ans=$item['answer']??$item['correct_answer']??'';if(is_array($ans))$ans=$ans[0]??'';if($type==='true_false'&&is_bool($ans))$ans=$ans?'A':'B';if($type==='multiple_choice')$ans=strtoupper(trim((string)$ans,'. '));$db->prepare('INSERT INTO questions(pool_id,type,prompt,options,answer,points,is_active,created_at,updated_at) VALUES(?,?,?,?,?,?,1,NOW(),NOW())')->execute([$poolId,$type,$q,json_encode($opts),json_encode([(string)$ans]),1]);$n++;}
    $_SESSION['flash']="$n AI questions added to the question bank.";\CmuLms\Http::redirect('?route=pool-view&id='.$poolId);
}

if ($route === 'exam-begin' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'student') {
    $beginId=(int)($_POST['exam_id']??0);$guard=$db->prepare('SELECT e.*,c.active FROM exams e JOIN courses c ON c.id=e.course_id JOIN enrollments en ON en.course_id=c.id AND en.user_id=? AND en.status="active" WHERE e.id=?');$guard->execute([$u['id'],$beginId]);$guardExam=$guard->fetch();$now=time();$assigned=$guardExam&&((($guardExam['audience']??'all')!=='selected')||in_array((int)$u['id'],array_map('intval',json_decode($guardExam['target_student_ids']??'[]',true)?:[]),true));$retakeAfterClose=$guardExam&&\CmuLms\Exam::retakeAllowedAfterClose($beginId,(int)$u['id']);if(!$guardExam||(int)$guardExam['active']!==1||(int)$guardExam['is_enabled']!==1||!$assigned||(!empty($guardExam['starts_at'])&&strtotime($guardExam['starts_at'])>$now)||(!empty($guardExam['ends_at'])&&strtotime($guardExam['ends_at'])<$now&&!$retakeAfterClose))exit('This exam is unavailable at this time.');
}

if ($route === 'exam-answer' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'student') {
    \CmuLms\View::checkCsrf();
    $attemptId=(int)($_POST['attempt_id']??0); $questionId=(int)($_POST['question_id']??0); $answer=(string)($_POST['answer']??'');
    $st=$db->prepare('SELECT a.question_ids,a.status,a.ends_at,a.current_question_index,e.allow_back_navigation,q.options FROM exam_attempts a JOIN exams e ON e.id=a.exam_id JOIN questions q ON q.id=? WHERE a.id=? AND a.student_id=?');
    $st->execute([$questionId,$attemptId,$u['id']]); $row=$st->fetch();
    if (!$row) { if(\CmuLms\Http::wantsJson())\CmuLms\Http::json(['ok'=>false,'message'=>'This attempt is unavailable.'],404);http_response_code(422); exit('Invalid answer or expired attempt.'); }
    $ids=array_map('intval',json_decode($row['question_ids']??'[]',true)?:[]); $options=json_decode($row['options']??'{}',true)?:[]; $index=array_search($questionId,$ids,true); $backAllowed=!empty($row['allow_back_navigation']);
    if ($row['status']!=='in_progress'||strtotime($row['ends_at'])<=time()) {
        cmuExpireStudentAttempts($db,(int)$u['id']);
        if(\CmuLms\Http::wantsJson())\CmuLms\Http::json(['ok'=>false,'message'=>'This attempt has ended. Your previously saved answers are retained.','attempt_closed'=>true],409);
        http_response_code(422);exit('Invalid answer or expired attempt.');
    }
    if ($index===false||(!$backAllowed&&$index!==(int)$row['current_question_index'])||($options&&!array_key_exists($answer,$options))) {
        if(\CmuLms\Http::wantsJson())\CmuLms\Http::json(['ok'=>false,'message'=>'This question cannot be changed under the current exam navigation settings.'],422);
        http_response_code(422);exit('Invalid answer or expired attempt.');
    }
    if($answer===''&&!$options)$db->prepare('DELETE FROM exam_answers WHERE attempt_id=? AND question_id=?')->execute([$attemptId,$questionId]);
    else $db->prepare('INSERT INTO exam_answers(attempt_id,question_id,answer,answered_at,created_at,updated_at) VALUES(?,?,?,NOW(),NOW(),NOW()) ON DUPLICATE KEY UPDATE answer=VALUES(answer),answered_at=NOW(),updated_at=NOW()')->execute([$attemptId,$questionId,json_encode([$answer],JSON_UNESCAPED_UNICODE)]);
    if ($index>(int)$row['current_question_index']) $db->prepare('UPDATE exam_attempts SET current_question_index=?,updated_at=NOW() WHERE id=? AND status="in_progress"')->execute([$index,$attemptId]);
    if(\CmuLms\Http::wantsJson())\CmuLms\Http::json(['ok'=>true,'question_id'=>$questionId,'status'=>'in_progress']);
    \CmuLms\Http::redirect('?route=exam&attempt='.$attemptId);
}

function cmuAnswerMatches($given, array $correct): bool {
    $given = trim((string)$given);
    foreach ($correct as $expected) {
        if (strcasecmp($given, trim((string)$expected)) === 0) return true;
    }
    return false;
}

function cmuFinalizeAttempt(PDO $db, array $attempt, bool $automatic = false): void {
    $ids=array_map('intval',json_decode($attempt['question_ids']??'[]',true)?:[]);$score=0;$max=0;$saved=[];
    $ans=$db->prepare('SELECT question_id,answer FROM exam_answers WHERE attempt_id=?');$ans->execute([(int)$attempt['id']]);
    foreach($ans as $row) $saved[(int)$row['question_id']]=json_decode($row['answer'],true)[0]??null;
    if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$q=$db->prepare("SELECT id,answer,points FROM questions WHERE id IN ($marks)");$q->execute($ids);foreach($q as $question){$correct=json_decode($question['answer'],true)?:[];$points=(int)$question['points'];$max+=$points;if(array_key_exists((int)$question['id'],$saved)&&cmuAnswerMatches($saved[(int)$question['id']],$correct))$score+=$points;}}
    $status=$automatic?'auto_submitted':'submitted';
    $changed=$db->prepare('UPDATE exam_attempts SET status=?,submitted_at=NOW(),score=?,max_score=?,updated_at=NOW() WHERE id=? AND status="in_progress"');$changed->execute([$status,$score,$max,(int)$attempt['id']]);
    if($changed->rowCount()){$find=$db->prepare('SELECT id FROM grades WHERE course_id=? AND student_id=? AND title=? AND category="quiz" ORDER BY id DESC LIMIT 1');$find->execute([(int)$attempt['course_id'],(int)$attempt['student_id'],$attempt['title']]);if($gradeId=$find->fetchColumn())$db->prepare('UPDATE grades SET score=?,max_score=?,released=1,graded_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$score,$max,$gradeId]);else $db->prepare('INSERT INTO grades(course_id,student_id,title,category,score,max_score,released,graded_at,created_at,updated_at) VALUES(?,?,?,?,?,?,1,NOW(),NOW(),NOW())')->execute([(int)$attempt['course_id'],(int)$attempt['student_id'],$attempt['title'],'quiz',$score,$max]);}
}

function cmuExpireStudentAttempts(PDO $db, int $studentId): void {
    $st=$db->prepare('SELECT a.*,e.course_id,e.title FROM exam_attempts a JOIN exams e ON e.id=a.exam_id WHERE a.student_id=? AND a.status="in_progress" AND a.ends_at<=NOW()');$st->execute([$studentId]);
    foreach($st as $attempt) cmuFinalizeAttempt($db,$attempt,true);
}

/* The maintained PHP router exits before the legacy front-controller cleanup runs.
   Expire attempts here so a student dashboard refresh also finalizes timed-out work. */
if ($u && ($u['role'] ?? '') === 'student') cmuExpireStudentAttempts($db, (int)$u['id']);

if ($route==='exam-state' && $_SERVER['REQUEST_METHOD']==='GET' && $u && $u['role']==='student') {
    $st=$db->prepare('SELECT a.id,a.status,a.ends_at,a.current_question_index,e.allow_back_navigation,e.allow_question_jump FROM exam_attempts a JOIN exams e ON e.id=a.exam_id WHERE a.id=? AND a.student_id=?');
    $st->execute([(int)($_GET['attempt']??0),(int)$u['id']]);$state=$st->fetch();
    if(!$state)\CmuLms\Http::json(['ok'=>false,'message'=>'This attempt is unavailable.'],404);
    \CmuLms\Http::json(['ok'=>true,'status'=>$state['status'],'server_now_ms'=>(int)floor(microtime(true)*1000),'ends_at_ms'=>(int)strtotime($state['ends_at'])*1000,'current_question_index'=>(int)$state['current_question_index'],'allow_back_navigation'=>(bool)$state['allow_back_navigation'],'allow_question_jump'=>(bool)$state['allow_question_jump']]);
}

/* Render an attempt from its persisted snapshot so a teacher changing a quiz does not
   retroactively remove camera monitoring from an attempt already in progress. */
if ($route === 'exam' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && $u['role'] === 'student') {
    header('Cache-Control: no-store');
    $requestedAttempt=(int)($_GET['attempt']??0);
    $st=$db->prepare('SELECT a.*,e.course_id,e.title,e.duration_minutes,e.allow_back_navigation,e.allow_question_jump,e.camera_required AS exam_camera_required FROM exam_attempts a JOIN exams e ON e.id=a.exam_id WHERE a.id=? AND a.student_id=?');
    $st->execute([$requestedAttempt,$u['id']]);$a=$st->fetch();
    if(!$a){
        $context=$db->prepare('SELECT a.id,e.course_id,e.title,c.teacher_id FROM exam_attempts a JOIN exams e ON e.id=a.exam_id JOIN courses c ON c.id=e.course_id WHERE a.id=?');
        $context->execute([$requestedAttempt]);$examContext=$context->fetch();
        $metadata=['attempt_id'=>$requestedAttempt,'known_exam'=>$examContext['title']??null,'requested_url'=>$_SERVER['REQUEST_URI']??''];
        $db->prepare('INSERT INTO audit_logs(user_id,action,auditable_type,auditable_id,metadata,ip_address,user_agent,created_at,updated_at) VALUES(?,?,?,?,?,?,?,NOW(),NOW())')->execute([(int)$u['id'],'exam_access_denied','exam_attempt',$requestedAttempt,json_encode($metadata,JSON_UNESCAPED_SLASHES),$_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??'']);
        if($examContext && !empty($examContext['teacher_id'])){
            $db->prepare('INSERT INTO notifications(user_id,title,body,type,target_url,created_at,updated_at) VALUES(?,?,?,"security",?,NOW(),NOW())')->execute([(int)$examContext['teacher_id'],'Exam access warning',$u['name'].' attempted to open an invalid or unauthorized attempt URL for '.$examContext['title'].'. The event was recorded in the audit log.','?route=exam-stats&id='.$requestedAttempt]);
        }
        view('errors/exam-access',['user'=>$u,'attempt_id'=>$requestedAttempt,'message'=>'This exam attempt is invalid, unavailable, or does not belong to your account. The access attempt has been recorded and will be reported to your professor for review.']);
        exit;
    }
    if($a['status']==='in_progress'&&strtotime($a['ends_at'])<=time()){cmuFinalizeAttempt($db,$a,true);$a['status']='auto_submitted';}
    $a['camera_required']=$a['camera_required']!==null?(int)$a['camera_required']:(int)$a['exam_camera_required'];$ids=array_map('intval',json_decode($a['question_ids']??'[]',true)?:[]);$qs=[];
    if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$q=$db->prepare("SELECT * FROM questions WHERE id IN ($marks)");$q->execute($ids);$by=[];foreach($q as $row)$by[(int)$row['id']]=$row;foreach($ids as $qid)if(isset($by[$qid]))$qs[]=$by[$qid];}
    $answers=[];$st=$db->prepare('SELECT question_id,answer FROM exam_answers WHERE attempt_id=?');$st->execute([$a['id']]);foreach($st as $row)$answers[(int)$row['question_id']]=json_decode($row['answer'],true)[0]??'';view('exams/take',['user'=>$u,'attempt'=>$a,'questions'=>$qs,'answers'=>$answers]);exit;
}

if ($route === 'exam-start' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'student') {
    \CmuLms\View::checkCsrf();$examId=(int)($_POST['exam_id']??0);$st=$db->prepare('SELECT e.*,c.code,c.title course_title,c.active FROM exams e JOIN courses c ON c.id=e.course_id JOIN enrollments en ON en.course_id=e.course_id AND en.user_id=? AND en.status="active" WHERE e.id=?');$st->execute([$u['id'],$examId]);$exam=$st->fetch();if(!$exam||empty($exam['active'])||empty($exam['is_enabled']))exit('This exam is unavailable.');if(($exam['audience']??'all')==='selected'&&!in_array((int)$u['id'],array_map('intval',json_decode($exam['target_student_ids']??'[]',true)?:[]),true))exit('You are not assigned to this exam.');$now=time();$retakeAfterClose=\CmuLms\Exam::retakeAllowedAfterClose($examId,(int)$u['id']);if(!empty($exam['starts_at'])&&strtotime($exam['starts_at'])>$now)exit('This exam is not open yet.');if(!empty($exam['ends_at'])&&strtotime($exam['ends_at'])<$now&&!$retakeAfterClose)exit('This exam is closed.');$activeAttempt=$db->prepare('SELECT id FROM exam_attempts WHERE exam_id=? AND student_id=? AND status="in_progress" ORDER BY id DESC LIMIT 1');$activeAttempt->execute([$examId,$u['id']]);if($id=$activeAttempt->fetchColumn())\CmuLms\Http::redirect('?route=exam&attempt='.$id);view('exams/before',['user'=>$u,'exam'=>$exam]);exit;
}

if ($route === 'exam-start' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && $u['role'] === 'student') {
    $examId=(int)($_GET['exam_id']??0);$st=$db->prepare('SELECT e.*,c.code,c.title course_title,c.active FROM exams e JOIN courses c ON c.id=e.course_id JOIN enrollments en ON en.course_id=e.course_id AND en.user_id=? AND en.status="active" WHERE e.id=?');$st->execute([$u['id'],$examId]);$exam=$st->fetch();$targets=$exam?array_map('intval',json_decode($exam['target_student_ids']??'[]',true)?:[]):[];if(!$exam||empty($exam['active'])||empty($exam['is_enabled'])||(($exam['audience']??'all')==='selected'&&!in_array((int)$u['id'],$targets,true)))exit('This exam is unavailable.');$active=$db->prepare('SELECT id FROM exam_attempts WHERE exam_id=? AND student_id=? AND status="in_progress" ORDER BY id DESC LIMIT 1');$active->execute([$examId,$u['id']]);if($id=$active->fetchColumn())\CmuLms\Http::redirect('?route=exam&attempt='.$id);view('exams/before',['user'=>$u,'exam'=>$exam]);exit;
}

/* Start attempts with a persisted random question order and persisted option order. */
if ($route === 'exam-begin' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'student') {
    \CmuLms\View::checkCsrf();
    $examId=(int)($_POST['exam_id']??0);
    if (empty($_POST['disclosure']) || empty($_POST['honesty'])) { $_SESSION['error']='Accept the disclosure and honesty statement before starting.'; \CmuLms\Http::redirect('?route=exam-start&exam_id='.$examId); }
    $st=$db->prepare('SELECT e.*,c.active FROM exams e JOIN courses c ON c.id=e.course_id JOIN enrollments en ON en.course_id=e.course_id AND en.user_id=? AND en.status="active" WHERE e.id=?');$st->execute([$u['id'],$examId]);$exam=$st->fetch();
    $targets=$exam?array_map('intval',json_decode($exam['target_student_ids']??'[]',true)?:[]):[];
    if (!$exam || empty($exam['active']) || empty($exam['is_enabled']) || (($exam['audience']??'all')==='selected'&&!in_array((int)$u['id'],$targets,true))) exit('This exam is unavailable.');
    $now=time();$retakeAfterClose=\CmuLms\Exam::retakeAllowedAfterClose($examId,(int)$u['id']); if ((!empty($exam['starts_at'])&&strtotime($exam['starts_at'])>$now)||(!empty($exam['ends_at'])&&strtotime($exam['ends_at'])<$now&&!$retakeAfterClose)) exit('This exam is unavailable at this time.');
    if (!empty($exam['camera_required']) && empty($_POST['camera_confirmed'])) { $_SESSION['error']='Camera access is required before this exam can start.'; \CmuLms\Http::redirect('?route=exam-start&exam_id='.$examId); }
    $active=$db->prepare('SELECT id FROM exam_attempts WHERE exam_id=? AND student_id=? AND status="in_progress" ORDER BY id DESC LIMIT 1');$active->execute([$examId,$u['id']]);if($id=$active->fetchColumn())\CmuLms\Http::redirect('?route=exam&attempt='.$id);
    $usage=\CmuLms\Exam::attemptUsage($examId,(int)$u['id'],(int)$exam['attempts_allowed']);$number=$usage['next_number'];if($usage['used']>=$usage['allowed'])exit('No attempts remaining.');
    try {$selected=\CmuLms\Exam::questionsForAttempt((int)$exam['question_pool_id'],(int)$exam['question_count']);} catch (\InvalidArgumentException $e) {$_SESSION['error']=$e->getMessage();\CmuLms\Http::redirect('?route=dashboard');}$ids=[];$optionOrder=[];foreach($selected as $question){$qid=(int)$question['id'];$ids[]=$qid;$keys=array_keys(json_decode($question['options']??'{}',true)?:[]);if(count($keys)>1)shuffle($keys);$optionOrder[(string)$qid]=$keys;}
    $start=time();$end=date('Y-m-d H:i:s',$start+(int)$exam['duration_minutes']*60);$db->prepare('INSERT INTO exam_attempts(exam_id,student_id,attempt_number,started_at,ends_at,consented_at,question_ids,option_order,status,ip_start,user_agent_start,camera_required,created_at,updated_at) VALUES(?,?,?,?,?,NOW(),?, ?,"in_progress",?,?,?,NOW(),NOW())')->execute([$examId,$u['id'],$number,date('Y-m-d H:i:s',$start),$end,json_encode($ids),json_encode($optionOrder),!empty($exam['log_ip'])?($_SERVER['REMOTE_ADDR']??null):null,!empty($exam['log_ip'])?($_SERVER['HTTP_USER_AGENT']??''):null,!empty($exam['camera_required'])?1:0]);
    \CmuLms\Http::redirect('?route=exam&attempt='.$db->lastInsertId());
}

if ($route === 'exam-begin' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'student') {
    \CmuLms\View::checkCsrf();$examId=(int)($_POST['exam_id']??0);if(empty($_POST['disclosure'])||empty($_POST['honesty'])){$_SESSION['error']='Accept the disclosure and honesty statement before starting.';\CmuLms\Http::redirect('?route=exam-start&exam_id='.$examId);} $st=$db->prepare('SELECT e.*,c.active FROM exams e JOIN courses c ON c.id=e.course_id JOIN enrollments en ON en.course_id=e.course_id AND en.user_id=? AND en.status="active" WHERE e.id=?');$st->execute([$u['id'],$examId]);$exam=$st->fetch();if(!$exam||empty($exam['active'])||empty($exam['is_enabled']))exit('Exam unavailable.');if(!empty($exam['camera_required'])&&empty($_POST['camera_confirmed'])){$_SESSION['error']='Camera access is required before this exam can start.';\CmuLms\Http::redirect('?route=exam-start&exam_id='.$examId);} $active=$db->prepare('SELECT * FROM exam_attempts WHERE exam_id=? AND student_id=? AND status="in_progress" ORDER BY id DESC LIMIT 1');$active->execute([$examId,$u['id']]);if($attempt=$active->fetch())\CmuLms\Http::redirect('?route=exam&attempt='.$attempt['id']);$usage=\CmuLms\Exam::attemptUsage($examId,(int)$u['id']);$number=$usage['next_number'];if($usage['used']>=(int)$exam['attempts_allowed'])exit('No attempts remaining.');$qs=$db->prepare('SELECT id FROM questions WHERE pool_id=? AND is_active=1 ORDER BY RAND() LIMIT '.max(1,(int)$exam['question_count']));$qs->execute([$exam['question_pool_id']]);$ids=array_column($qs->fetchAll(),'id');$start=time();$end=date('Y-m-d H:i:s',$start+(int)$exam['duration_minutes']*60);$db->prepare('INSERT INTO exam_attempts(exam_id,student_id,attempt_number,started_at,ends_at,consented_at,question_ids,option_order,status,ip_start,user_agent_start,created_at,updated_at) VALUES(?,?,?,?,?,NOW(),?,?,"in_progress",?,?,NOW(),NOW())')->execute([$examId,$u['id'],$number,date('Y-m-d H:i:s',$start),$end,json_encode($ids),json_encode([]),$_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??'']);\CmuLms\Http::redirect('?route=exam&attempt='.$db->lastInsertId());
}

if ($route === 'admin-user-create' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'admin') {
    \CmuLms\View::checkCsrf(); $id=trim($_POST['id_number']??'');$name=trim($_POST['name']??'');$role=in_array($_POST['role']??'student',['student','teacher','admin'],true)?$_POST['role']:'student';if($id===''||$name==='')exit('Name and school ID are required.');$st=$db->prepare('SELECT 1 FROM users WHERE id_number=?');$st->execute([$id]);if($st->fetchColumn())exit('School ID already exists.');$db->prepare('INSERT INTO users(name,first_name,middle_initial,last_name,id_number,email,password,role,section_code,year_level,course,must_change_password,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())')->execute([$name,trim($_POST['first_name']??''),trim($_POST['middle_initial']??''),trim($_POST['last_name']??''),$id,trim($_POST['email']??''),password_hash('ChangeMe!',PASSWORD_DEFAULT),$role,trim($_POST['section_code']??''),trim($_POST['year_level']??''),trim($_POST['course']??'')]);$_SESSION['flash']='User created. Default password: ChangeMe!';\CmuLms\Http::redirect('?route=admin-users');
}

if ($route === 'admin-student-import' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'admin') {
    \CmuLms\View::checkCsrf();
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) exit('Choose a CSV file.');
    $fh=fopen($_FILES['file']['tmp_name'],'rb');$header=fgetcsv($fh);if(!$header)exit('The CSV file is empty.');$header=array_map(static fn($v)=>strtolower(str_replace([' ','-'],'_',trim((string)$v))),$header);
    if(!in_array('id_number',$header,true)&&!in_array('school_id',$header,true))exit('CSV must contain id_number or school_id.');if(!in_array('full_name',$header,true)&&!(in_array('first_name',$header,true)&&in_array('last_name',$header,true)))exit('CSV must contain full_name or first_name and last_name.');
    $courseId=(int)($_POST['course_id']??0);if($courseId){$st=$db->prepare('SELECT id FROM courses WHERE id=? AND active=1');$st->execute([$courseId]);if(!$st->fetchColumn())exit('Course not found.');}
    $hash=password_hash('ChangeMe!',PASSWORD_DEFAULT);$created=0;$enrolled=0;$skipped=0;
    while(($row=fgetcsv($fh))!==false){$row=array_pad($row,count($header),'');$r=array_combine($header,$row);$id=trim($r['id_number']??$r['school_id']??'');$first=trim($r['first_name']??$r['firstname']??'');$middle=trim($r['middle_initial']??$r['middle']??'');$last=trim($r['last_name']??$r['lastname']??'');$name=trim($r['full_name']??trim($first.' '.($middle?$middle.' ':'').$last));if($id===''||$name===''){$skipped++;continue;}$st=$db->prepare('SELECT id FROM users WHERE id_number=?');$st->execute([$id]);$uid=$st->fetchColumn();if(!$uid){$db->prepare('INSERT INTO users(name,first_name,middle_initial,last_name,id_number,role,password,must_change_password,section_code,year_level,course,created_at,updated_at) VALUES(?,?,?,?,?,"student",?,1,?,?,?,?,NOW(),NOW())')->execute([$name,$first,$middle,$last,$id,$hash,trim($r['section_code']??''),trim($r['year_level']??''),trim($r['course']??'')]);$uid=(int)$db->lastInsertId();$created++;}elseif($courseId){$st=$db->prepare('SELECT role FROM users WHERE id=?');$st->execute([$uid]);if($st->fetchColumn()!=='student'){$skipped++;continue;}}if($courseId){$ins=$db->prepare("INSERT IGNORE INTO enrollments(course_id,user_id,status,created_at,updated_at) VALUES(?,?,'active',NOW(),NOW())");$ins->execute([$courseId,$uid]);$enrolled+=(int)$ins->rowCount();}}
    fclose($fh);$_SESSION['flash']="Imported $created new students; enrolled $enrolled. Skipped $skipped rows.";\CmuLms\Http::redirect('?route=admin-users');
}

if ($route === 'admin-unlock' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'admin') {
    \CmuLms\View::checkCsrf();$id=(int)($_POST['user_id']??0);$db->prepare('UPDATE users SET locked_until=NULL,updated_at=NOW() WHERE id=?')->execute([$id]);$_SESSION['flash']='Account unlocked.';\CmuLms\Http::redirect('?route=admin-users');
}

if ($route === 'term-toggle' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'admin') {
    \CmuLms\View::checkCsrf();$id=(int)($_POST['term_id']??0);$active=!empty($_POST['active']);$db->prepare('UPDATE academic_terms SET active=?,updated_at=NOW() WHERE id=?')->execute([$active?1:0,$id]);$_SESSION['flash']='Academic term status updated.';\CmuLms\Http::redirect('?route=admin-courses');
}

if ($route === 'admin-courses' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && $u['role'] === 'admin') {
    $courses=$db->query('SELECT c.*,u.name teacher_name,COUNT(e.user_id) students FROM courses c JOIN users u ON u.id=c.teacher_id LEFT JOIN enrollments e ON e.course_id=c.id AND e.status="active" GROUP BY c.id ORDER BY c.code')->fetchAll();$terms=$db->query('SELECT * FROM academic_terms ORDER BY academic_year DESC,semester')->fetchAll();view('admin/courses',['user'=>$u,'courses'=>$courses,'terms'=>$terms,'teachers'=>\CmuLms\User::teachers()]);exit;
}

if ($route === 'admin-users' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && $u['role'] === 'admin') {
    $q=trim($_GET['q']??'');$role=$_GET['role']??'all';if(!in_array($role,['all','student','teacher','admin'],true))$role='all';$requestedPer=(int)($_GET['per_page']??20);$per=in_array($requestedPer,[10,20,50,100],true)?$requestedPer:20;$page=max(1,(int)($_GET['page']??1));$total=\CmuLms\User::userCount($q,$role);$courses=$db->query('SELECT id,code,title FROM courses WHERE active=1 ORDER BY code')->fetchAll();view('admin/users',['user'=>$u,'students'=>\CmuLms\User::users($q,$role,$per,($page-1)*$per),'total'=>$total,'page'=>$page,'per_page'=>$per,'pages'=>max(1,(int)ceil($total/$per)),'role_filter'=>$role,'courses'=>$courses]);exit;
}

if ($route === 'admin-toggle' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'],['teacher','admin'],true)) {
    \CmuLms\View::checkCsrf();$targetId=(int)($_POST['user_id']??0);$state=($_POST['state']??'')==='disable';$st=$db->prepare('SELECT id,role FROM users WHERE id=?');$st->execute([$targetId]);$target=$st->fetch();if(!$target)exit('User not found.');
    if($u['role']==='teacher'){if($target['role']!=='student')exit('Teachers can only manage student accounts.');$courseId=(int)($_POST['course_id']??0);$st=$db->prepare('SELECT 1 FROM courses c JOIN enrollments e ON e.course_id=c.id AND e.user_id=? AND e.status="active" WHERE c.id=? AND c.teacher_id=? AND c.active=1');$st->execute([$targetId,$courseId,$u['id']]);if(!$st->fetchColumn())exit('Student enrollment unavailable.');}
    $db->prepare('UPDATE users SET disabled_at=?,updated_at=NOW() WHERE id=?')->execute([$state?date('Y-m-d H:i:s'):null,$targetId]);$_SESSION['flash']='Account status updated.';if(!empty($_POST['course_id']))\CmuLms\Http::redirect('?route=course&id='.(int)$_POST['course_id'].'&tab=students');\CmuLms\Http::redirect('?route=admin-users');
}

if ($route === 'exam-preview' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare('SELECT e.*, c.code, c.title course_title, c.teacher_id, p.title pool_title FROM exams e JOIN courses c ON c.id=e.course_id JOIN question_pools p ON p.id=e.question_pool_id WHERE e.id=? AND (c.teacher_id=? OR ?="admin")');
    $st->execute([$id, $u['id'], $u['role']]);
    $exam = $st->fetch();
    if (!$exam) exit('Exam not found.');
    $st = $db->prepare('SELECT * FROM questions WHERE pool_id=? AND is_active=1 ORDER BY id');
    $st->execute([(int)$exam['question_pool_id']]);
    view('exams/preview', ['user'=>$u, 'exam'=>$exam, 'questions'=>$st->fetchAll()]);
    exit;
}

if ($route === 'pool-update' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    \CmuLms\View::checkCsrf(); $poolId=(int)($_POST['pool_id']??0); $st=$db->prepare('SELECT p.id,p.course_id,c.teacher_id FROM question_pools p JOIN courses c ON c.id=p.course_id WHERE p.id=? AND (c.teacher_id=? OR ?="admin")');$st->execute([$poolId,$u['id'],$u['role']]);$pool=$st->fetch();if(!$pool)exit('Question bank unavailable.');
    $db->prepare('UPDATE question_pools SET title=?,description=?,academic_period=?,topic=?,updated_at=NOW() WHERE id=?')->execute([trim($_POST['title']??''),trim($_POST['description']??''),trim($_POST['academic_period']??''),trim($_POST['topic']??''),$poolId]);$_SESSION['flash']='Question bank details saved.';\CmuLms\Http::redirect('?route=pool-view&id='.$poolId);
}

/* Editing a question already used by an attempt creates a new active revision.
   Historical attempts keep their original prompt, choices, and answer key. */
if ($route === 'question-update' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    \CmuLms\View::checkCsrf();
    $questionId=(int)($_POST['question_id']??0);
    $st=$db->prepare('SELECT q.*,p.course_id,c.teacher_id FROM questions q JOIN question_pools p ON p.id=q.pool_id JOIN courses c ON c.id=p.course_id WHERE q.id=? AND (c.teacher_id=? OR ?="admin")');
    $st->execute([$questionId,$u['id'],$u['role']]);
    $question=$st->fetch();
    if(!$question) exit('Question not found.');
    $options=[];
    foreach(['A','B','C','D'] as $key) if(trim((string)($_POST['option_'.$key]??''))!=='') $options[$key]=trim((string)$_POST['option_'.$key]);
    $answer=trim((string)($_POST['answer']??''));
    $used=false;
    $attempts=$db->query('SELECT question_ids FROM exam_attempts')->fetchAll();
    foreach($attempts as $attempt){$ids=array_map('intval',json_decode($attempt['question_ids']??'[]',true)?:[]);if(in_array($questionId,$ids,true)){$used=true;break;}}
    if($used){
        $db->prepare('UPDATE questions SET is_active=0,updated_at=NOW() WHERE id=?')->execute([$questionId]);
        $db->prepare('INSERT INTO questions(pool_id,type,prompt,options,answer,points,is_active,created_at,updated_at) VALUES(?,?,?,?,?,?,1,NOW(),NOW())')->execute([(int)$question['pool_id'],$question['type'],trim($_POST['prompt']??''),json_encode($options),json_encode([$answer]),(int)$question['points']]);
        $_SESSION['flash']='Question revision saved. Existing attempts kept the original version.';
    } else {
        $db->prepare('UPDATE questions SET prompt=?,options=?,answer=?,updated_at=NOW() WHERE id=?')->execute([trim($_POST['prompt']??''),json_encode($options),json_encode([$answer]),$questionId]);
        $_SESSION['flash']='Question updated.';
    }
    \CmuLms\Http::redirect('?route=pool-view&id='.(int)($_POST['pool_id']??$question['pool_id']));
}

if ($route === 'material-update' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    \CmuLms\View::checkCsrf(); $id=(int)($_POST['material_id']??0);$st=$db->prepare('SELECT m.*,c.teacher_id FROM course_materials m JOIN courses c ON c.id=m.course_id WHERE m.id=? AND (c.teacher_id=? OR ?="admin")');$st->execute([$id,$u['id'],$u['role']]);$m=$st->fetch();if(!$m)exit('Material unavailable.');$db->prepare('UPDATE course_materials SET title=?,description=?,academic_period=?,opens_at=?,closes_at=?,is_enabled=?,updated_at=NOW() WHERE id=?')->execute([trim($_POST['title']??''),trim($_POST['description']??''),trim($_POST['academic_period']??'midterm'),$_POST['opens_at']?:null,$_POST['closes_at']?:null,!empty($_POST['is_enabled']),$id]);$_SESSION['flash']='Material updated.';\CmuLms\Http::redirect('?route=material-open&id='.$id);
}

if ($route === 'material-delete' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    \CmuLms\View::checkCsrf();$id=(int)($_POST['material_id']??0);$st=$db->prepare('SELECT m.*,c.teacher_id FROM course_materials m JOIN courses c ON c.id=m.course_id WHERE m.id=? AND (c.teacher_id=? OR ?="admin")');$st->execute([$id,$u['id'],$u['role']]);$m=$st->fetch();if(!$m)exit('Material unavailable.');$db->prepare('DELETE FROM course_materials WHERE id=?')->execute([$id]);$file=__DIR__.'/storage/materials/'.basename((string)$m['file_path']);if(is_file($file))@unlink($file);$_SESSION['flash']='Material deleted.';\CmuLms\Http::redirect('?route=course&id='.(int)$m['course_id']);
}

if ($route === 'exam-delete' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    \CmuLms\View::checkCsrf();
    $id = (int)($_POST['exam_id'] ?? 0);
    $st = $db->prepare('SELECT e.course_id FROM exams e JOIN courses c ON c.id=e.course_id WHERE e.id=? AND (c.teacher_id=? OR ?="admin")');
    $st->execute([$id, $u['id'], $u['role']]);
    $exam = $st->fetch();
    if (!$exam) exit('Exam not found.');
    $db->prepare('DELETE FROM exams WHERE id=?')->execute([$id]);
    $_SESSION['flash'] = 'Exam deleted.';
    \CmuLms\Http::redirect('?route=course&id='.(int)$exam['course_id'].'&tab=activities');
}

if ($route === 'attempt-review' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    $attemptId=(int)($_GET['id']??0); $st=$db->prepare('SELECT a.*,e.title exam_title,e.question_count,c.code,c.teacher_id,u.name,u.id_number FROM exam_attempts a JOIN exams e ON e.id=a.exam_id JOIN courses c ON c.id=e.course_id JOIN users u ON u.id=a.student_id WHERE a.id=? AND (c.teacher_id=? OR ?="admin")'); $st->execute([$attemptId,$u['id'],$u['role']]); $attempt=$st->fetch(); if(!$attempt) exit('Attempt review unavailable.');
    $ids=array_map('intval',json_decode($attempt['question_ids']??'[]',true)?:[]); $questions=[]; if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$q=$db->prepare("SELECT * FROM questions WHERE id IN ($marks)");$q->execute($ids);$by=[];foreach($q as $row)$by[$row['id']]=$row;foreach($ids as $id)if(isset($by[$id]))$questions[]=$by[$id];} $ans=$db->prepare('SELECT question_id,answer FROM exam_answers WHERE attempt_id=?');$ans->execute([$attemptId]);$answers=[];foreach($ans as $row)$answers[$row['question_id']]=json_decode($row['answer'],true)[0]??'';
    view('exams/review',['user'=>$u,'attempt'=>$attempt,'questions'=>$questions,'answers'=>$answers]); exit;
}

if ($route === 'exam-risk' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    $examId=(int)($_GET['id']??0); $st=$db->prepare('SELECT e.*,c.code,c.teacher_id FROM exams e JOIN courses c ON c.id=e.course_id WHERE e.id=? AND (c.teacher_id=? OR ?="admin")');$st->execute([$examId,$u['id'],$u['role']]);$exam=$st->fetch();if(!$exam)exit('Risk report unavailable.');
    $st=$db->prepare('SELECT a.id,a.attempt_number,a.status,a.violation_count,a.started_at,a.submitted_at,u.name,u.id_number,COALESCE(SUM(ev.kind IN ("tab_blur","visibility_hidden","fullscreen_exit","camera_lost","copy_attempt")),0) violations,COUNT(ev.id) event_count FROM exam_attempts a JOIN users u ON u.id=a.student_id LEFT JOIN exam_events ev ON ev.attempt_id=a.id WHERE a.exam_id=? GROUP BY a.id ORDER BY violations DESC,u.last_name,u.name');$st->execute([$examId]);view('exams/risk',['user'=>$u,'exam'=>$exam,'attempts'=>$st->fetchAll()]);exit;
}

if ($route === 'exam-item-analysis' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    $examId=(int)($_GET['id']??0);$st=$db->prepare('SELECT e.*,c.code,c.teacher_id FROM exams e JOIN courses c ON c.id=e.course_id WHERE e.id=? AND (c.teacher_id=? OR ?="admin")');$st->execute([$examId,$u['id'],$u['role']]);$exam=$st->fetch();if(!$exam)exit('Item analysis unavailable.');
    $st=$db->prepare('SELECT a.question_ids,ea.question_id,ea.answer,q.prompt,q.answer correct FROM exam_attempts a JOIN exam_answers ea ON ea.attempt_id=a.id JOIN questions q ON q.id=ea.question_id WHERE a.exam_id=? AND a.status IN ("submitted","auto_submitted")');$st->execute([$examId]);$items=[];foreach($st as $row){$id=(int)$row['question_id'];if(!isset($items[$id]))$items[$id]=['prompt'=>$row['prompt'],'answered'=>0,'correct'=>0];$items[$id]['answered']++;$given=json_decode($row['answer'],true)[0]??'';$correct=json_decode($row['correct'],true)?:[];if(cmuAnswerMatches($given,$correct))$items[$id]['correct']++;}view('exams/item-analysis',['user'=>$u,'exam'=>$exam,'items'=>$items]);exit;
}

if ($route === 'exam-position' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'student') {
    \CmuLms\View::checkCsrf();
    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    $position = max(0, (int)($_POST['position'] ?? 0));
    $st = $db->prepare('SELECT a.question_ids,a.current_question_index,e.allow_back_navigation,e.allow_question_jump FROM exam_attempts a JOIN exams e ON e.id=a.exam_id WHERE a.id=? AND a.student_id=? AND a.status="in_progress"');
    $st->execute([$attemptId, $u['id']]);
    $attempt = $st->fetch();
    if (!$attempt) { if(\CmuLms\Http::wantsJson())\CmuLms\Http::json(['ok'=>false,'message'=>'This attempt has ended or is unavailable.','attempt_closed'=>true],409);http_response_code(422); exit('Invalid exam position.'); }
    $ids = array_map('intval', json_decode($attempt['question_ids'] ?? '[]', true) ?: []);
    if ($position >= count($ids)) { http_response_code(422); exit('Invalid exam position.'); }
    $current=(int)($attempt['current_question_index']??0);if(empty($attempt['allow_back_navigation'])&&$position<$current){http_response_code(422);exit('Previous-question navigation is disabled.');}if(empty($attempt['allow_question_jump'])&&$position>$current+1){http_response_code(422);exit('Numbered-question navigation is disabled.');}
    $db->prepare('UPDATE exam_attempts SET current_question_index=?,updated_at=NOW() WHERE id=?')->execute([$position,$attemptId]);
    $state=$db->prepare('SELECT status,violation_count FROM exam_attempts WHERE id=?');$state->execute([$attemptId]);$state=$state->fetch()?:['status'=>'in_progress','violation_count'=>0];header('Content-Type: application/json');echo json_encode(['ok'=>true,'status'=>$state['status'],'violation_count'=>(int)$state['violation_count'],'current_question_index'=>$position]);exit;
}

if ($route === 'exam-submit' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'student') {
    \CmuLms\View::checkCsrf();
    $attemptId=(int)($_POST['attempt_id']??0);
    $st=$db->prepare('SELECT a.*,e.course_id,e.title,e.ends_at exam_closes_at FROM exam_attempts a JOIN exams e ON e.id=a.exam_id WHERE a.id=? AND a.student_id=? AND a.status="in_progress"');
    $st->execute([$attemptId,$u['id']]); $attempt=$st->fetch();
    if(!$attempt) { $closed=$db->prepare('SELECT status FROM exam_attempts WHERE id=? AND student_id=?');$closed->execute([$attemptId,$u['id']]);$closedStatus=$closed->fetchColumn();$_SESSION['error']=in_array($closedStatus,['auto_submitted','submitted'],true)?'The exam deadline has passed or this attempt has already been submitted.':'This attempt is no longer available for submission.'; \CmuLms\Http::redirect('?route=dashboard'); }
    if(empty($_POST['honesty'])) { $_SESSION['error']='Please confirm the honesty statement before submitting.'; \CmuLms\Http::redirect('?route=exam&attempt='.$attemptId); }
    if(strtotime($attempt['ends_at'])<=time()) { cmuExpireStudentAttempts($db,(int)$u['id']); $_SESSION['error']='The exam deadline has passed and the attempt was auto-submitted.'; \CmuLms\Http::redirect('?route=dashboard'); }
    cmuFinalizeAttempt($db,$attempt);
    $db->prepare('UPDATE exam_attempts SET honesty_confirmed=1,final_honesty_confirmed_at=NOW(),ip_submit=?,user_agent_submit=?,updated_at=NOW() WHERE id=?')->execute([!empty($attempt['log_ip'])?($_SERVER['REMOTE_ADDR']??null):null,!empty($attempt['log_ip'])?($_SERVER['HTTP_USER_AGENT']??''):null,$attemptId]);
    $_SESSION['flash']='Exam submitted successfully.'; \CmuLms\Http::redirect('?route=dashboard');
}

if ($route === 'exam-reset-student' && $_SERVER['REQUEST_METHOD']==='POST' && $u && in_array($u['role'],['teacher','admin'],true)) {
    \CmuLms\View::checkCsrf();$examId=(int)($_POST['exam_id']??0);$studentId=(int)($_POST['student_id']??0);
    try {
        $attemptLimit=(int)($_POST['attempt_limit']??0);
        $count=\CmuLms\Exam::resetStudentAttempts($examId,$studentId,$u,$attemptLimit);
        $afterClose=$count&&\CmuLms\Exam::retakeAllowedAfterClose($examId,$studentId);
        $_SESSION['flash']=$count?($afterClose?"Attempts reset. The student can take this exam up to {$attemptLimit} time(s), including after the closing time. Previous answer history is preserved.":"Attempts reset. The student can take this exam up to {$attemptLimit} time(s) while it is open. Previous answer history is preserved."):'There are no used attempts to reset for this student.';
    }catch(\InvalidArgumentException $e){http_response_code(403);exit(\CmuLms\View::e($e->getMessage()));}
    \CmuLms\Http::redirect('?route=exam-stats&id='.$examId.'#student-results');
}

if ($route === 'exam-stats' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare('SELECT e.*,c.code,c.title course_title,c.teacher_id FROM exams e JOIN courses c ON c.id=e.course_id WHERE e.id=? AND (c.teacher_id=? OR ?="admin")');
    $st->execute([$id, $u['id'], $u['role']]);
    $exam = $st->fetch();
    if (!$exam) exit('Exam not found');
    $st = $db->prepare('SELECT a.*,u.name,u.id_number,COUNT(DISTINCT ans.id) answered_count,COUNT(DISTINCT ev.id) event_count FROM exam_attempts a JOIN users u ON u.id=a.student_id LEFT JOIN exam_answers ans ON ans.attempt_id=a.id LEFT JOIN exam_events ev ON ev.attempt_id=a.id WHERE a.exam_id=? GROUP BY a.id ORDER BY a.started_at DESC');
    $st->execute([$id]);
    $attempts=$st->fetchAll();$byStudent=[];foreach($attempts as $attempt){$sid=(int)$attempt['student_id'];$byStudent[$sid][]=$attempt;}
    $st=$db->prepare('SELECT u.id,u.name,u.id_number FROM users u JOIN enrollments en ON en.user_id=u.id AND en.status="active" WHERE en.course_id=? AND u.role="student" ORDER BY u.last_name,u.name');$st->execute([(int)$exam['course_id']]);$learners=$st->fetchAll();$targets=array_map('intval',json_decode($exam['target_student_ids']??'[]',true)?:[]);$results=[];$now=time();
    foreach($learners as $learner){$sid=(int)$learner['id'];if(($exam['audience']??'all')==='selected'&&!in_array($sid,$targets,true))continue;$rows=array_values(array_filter($byStudent[$sid]??[],static fn($row)=>$row['status']!=='reset'));$submitted=array_values(array_filter($rows,fn($row)=>in_array($row['status'],['submitted','auto_submitted'],true)));$active=array_values(array_filter($rows,fn($row)=>$row['status']==='in_progress'));$best=null;foreach($submitted as $row){$percent=((int)($row['max_score']??0)>0?((float)($row['score']??0)/(float)$row['max_score']*100):null);if($best===null||($percent??-1)>($best['percentage']??-1))$best=['score'=>$row['score'],'max_score'=>$row['max_score'],'percentage'=>$percent,'submitted_at'=>$row['submitted_at']];}$status=$active?'taking':($submitted?'took':((!empty($exam['ends_at'])&&strtotime($exam['ends_at'])<$now)?'missed':'not_started'));$current=$active?end($active):null;$results[]=$learner+['status'=>$status,'attempts_taken'=>count($rows),'attempts_allowed'=>\CmuLms\Exam::allowedAttempts($id,$sid,(int)$exam['attempts_allowed']),'best_score'=>$best['score']??null,'best_max_score'=>$best['max_score']??null,'best_percentage'=>$best['percentage']??null,'submitted_at'=>$best['submitted_at']??null,'answers_saved'=>$current['answered_count']??null,'time_remaining'=>$current?max(0,strtotime($current['ends_at'])-$now):0,'progress_percent'=>$current&&((int)$exam['question_count']>0)?round(((int)$current['answered_count']/(int)$exam['question_count'])*100,1):null];}
    $summary=['assigned'=>count($results),'took_count'=>count(array_filter($results,fn($row)=>$row['status']==='took')),'taking_count'=>count(array_filter($results,fn($row)=>$row['status']==='taking')),'missed_count'=>count(array_filter($results,fn($row)=>$row['status']==='missed')),'not_started_count'=>count(array_filter($results,fn($row)=>$row['status']==='not_started'))];
    view('exams/stats', ['user'=>$u,'exam'=>$exam,'attempts'=>$attempts,'results'=>$results,'summary'=>$summary]);
    exit;
}

if ($route === 'exam-event' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'student') {
    \CmuLms\View::checkCsrf();
    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    $kind = trim($_POST['kind'] ?? '');
    $allowedKinds = ['tab_blur','visibility_hidden','fullscreen_exit','focus_return','camera_ready','camera_lost','camera_restored','copy_attempt','no_face_detected','multiple_faces_detected'];
    $st = $db->prepare('SELECT a.*,e.violation_policy,e.auto_submit_after_violations,e.course_id,e.title FROM exam_attempts a JOIN exams e ON e.id=a.exam_id WHERE a.id=? AND a.student_id=? AND a.status="in_progress"');
    $st->execute([$attemptId, $u['id']]);
    $attempt = $st->fetch();
    if (!$attempt || !in_array($kind, $allowedKinds, true)) { http_response_code(422); exit('Invalid exam event.'); }
    $metadata = trim($_POST['metadata'] ?? '{}');
    if (strlen($metadata) > 2000) $metadata = '{}';
    $db->prepare('INSERT INTO exam_events(attempt_id,kind,metadata,occurred_at) VALUES(?,?,?,NOW())')->execute([$attemptId,$kind,$metadata]);
    if (in_array($kind, ['tab_blur','visibility_hidden','fullscreen_exit','camera_lost','copy_attempt'], true)) {
        $db->prepare('UPDATE exam_attempts SET violation_count=violation_count+1,updated_at=NOW() WHERE id=?')->execute([$attemptId]);
        $policy=(string)($attempt['violation_policy']??'log');$threshold=(int)($attempt['auto_submit_after_violations']??0);
        if($policy==='auto_submit'&&$threshold>0){$fresh=$db->prepare('SELECT a.*,e.course_id,e.title FROM exam_attempts a JOIN exams e ON e.id=a.exam_id WHERE a.id=? AND a.status="in_progress"');$fresh->execute([$attemptId]);if($current=$fresh->fetch()){ $count=(int)$current['violation_count']; if($count>=$threshold) cmuFinalizeAttempt($db,$current,true); }}
    }
    $state=$db->prepare('SELECT status,violation_count FROM exam_attempts WHERE id=?');$state->execute([$attemptId]);$state=$state->fetch()?:['status'=>'in_progress','violation_count'=>0];header('Content-Type: application/json'); echo json_encode(['ok'=>true,'status'=>$state['status'],'violation_count'=>(int)$state['violation_count'],'warning'=>($attempt['violation_policy']==='warn')]); exit;
}

if ($route === 'exam-flag' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'student') {
    $exists=$db->prepare('SELECT 1 FROM exam_attempts WHERE id=? AND student_id=? AND status="in_progress"');$exists->execute([(int)($_POST['attempt_id']??0),$u['id']]);if(!$exists->fetchColumn()){http_response_code(422);exit('Invalid question.');}
    \CmuLms\View::checkCsrf();$attemptId=(int)($_POST['attempt_id']??0);$questionId=(int)($_POST['question_id']??0);$st=$db->prepare('SELECT flagged_questions,question_ids FROM exam_attempts WHERE id=? AND student_id=? AND status="in_progress"');$st->execute([$attemptId,$u['id']]);$attempt=$st->fetch();$ids=array_map('intval',json_decode($attempt['question_ids']??'[]',true)?:[]);if(!$attempt||!in_array($questionId,$ids,true)){http_response_code(422);exit('Invalid question.');}$flagged=array_values(array_map('intval',json_decode($attempt['flagged_questions']??'[]',true)?:[]));if(in_array($questionId,$flagged,true))$flagged=array_values(array_diff($flagged,[$questionId]));else$flagged[]=$questionId;$db->prepare('UPDATE exam_attempts SET flagged_questions=?,updated_at=NOW() WHERE id=?')->execute([json_encode($flagged),$attemptId]);header('Content-Type: application/json');echo json_encode(['ok'=>true,'flagged'=>in_array($questionId,$flagged,true)]);exit;
}

if (in_array($route, ['material-open','material-file'], true) && $u) {
    $st = $db->prepare('SELECT m.id,m.course_id,m.is_enabled,m.opens_at,m.closes_at,c.teacher_id,c.active FROM course_materials m JOIN courses c ON c.id=m.course_id WHERE m.id=?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $material = $st->fetch();
    $allowed = $material && (int)$material['active'] === 1;
    if ($allowed && $u['role'] === 'student') {
        $allowed = !empty($material['is_enabled']) && (empty($material['opens_at']) || strtotime($material['opens_at']) <= time()) && (empty($material['closes_at']) || strtotime($material['closes_at']) >= time());
        $en = $db->prepare('SELECT 1 FROM enrollments WHERE course_id=? AND user_id=? AND status="active"');
        if ($allowed) { $en->execute([$material['course_id'], $u['id']]); $allowed = (bool)$en->fetchColumn(); }
    } elseif ($allowed && $u['role'] === 'teacher') {
        $allowed = (int)$material['teacher_id'] === (int)$u['id'];
    } elseif ($allowed && $u['role'] !== 'admin') {
        $allowed = false;
    }
    if (!$allowed) exit('Material unavailable.');
}

if ($route === 'material-open' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u) {
    $material=\CmuLms\Material::find((int)($_GET['id'] ?? 0));
    if (!$material) { http_response_code(404); exit('Material not found.'); }
    if ($u['role']==='student') {
        $db->prepare('INSERT INTO course_material_reads(material_id,student_id,open_count,created_at,updated_at) VALUES(?,?,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE open_count=open_count+1,updated_at=NOW()')->execute([(int)$material['id'],(int)$u['id']]);
    }
    $readState=['open_count'=>0,'read_at'=>null];
    if ($u['role']==='student') {
        $read=$db->prepare('SELECT open_count,read_at FROM course_material_reads WHERE material_id=? AND student_id=?');
        $read->execute([(int)$material['id'],(int)$u['id']]); $readState=$read->fetch()?:$readState;
    }
    view('materials/show',['user'=>$u,'material'=>$material,'read_state'=>$readState]);
    exit;
}

function cmuMaterialPages(string $path, string $extension): array {
    if ($extension === 'pptx' && class_exists('ZipArchive')) {
        $zip=new \ZipArchive();$pages=[];
        if($zip->open($path)===true){$names=[];for($i=0;$i<$zip->numFiles;$i++){$name=$zip->getNameIndex($i);if(preg_match('#^ppt/slides/slide(\d+)\.xml$#',$name,$m))$names[(int)$m[1]]=$name;}ksort($names);foreach($names as $name){$xml=$zip->getFromName($name)?:'';$text=[];if(preg_match_all('#<a:t(?:\s[^>]*)?>(.*?)</a:t>#s',$xml,$matches))foreach($matches[1] as $value)$text[]=html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_XML1,'UTF-8');$pages[]=trim(implode(' ',$text));}$zip->close();}
        return $pages;
    }
    if ($extension === 'pdf') {
        try {
            if (class_exists('Smalot\\PdfParser\\Parser')) {
                $config=new \Smalot\PdfParser\Config();
                $config->setDecodeMemoryLimit(32 * 1024 * 1024);
                $document=(new \Smalot\PdfParser\Parser([], $config))->parseFile($path);
                return array_map(static fn($page)=>trim($page->getText()),$document->getPages());
            }
            if (function_exists('shell_exec') && function_exists('escapeshellarg')) {
                $null=PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null';$command='pdftotext -layout '.escapeshellarg($path).' - 2>'.$null;$text=trim((string)@shell_exec($command));
                if($text!=='')return array_values(array_filter(array_map('trim',preg_split("/\f/",$text)),static fn($page)=>$page!==''));
            }
        } catch (\Throwable $e) { error_log('Material text extraction failed: '.$e->getMessage()); }
    }
    return [];
}

if ($route === 'material-upload' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'teacher') {
    \CmuLms\View::checkCsrf();$courseId=(int)($_POST['course_id']??0);if(!cmuTeacherOwnsCourse($db,$courseId,(int)$u['id']))exit('Course unavailable.');
    if(empty($_FILES['file']['tmp_name'])||!is_uploaded_file($_FILES['file']['tmp_name']))exit('Choose a PDF or PPTX file.');$ext=strtolower(pathinfo($_FILES['file']['name'],PATHINFO_EXTENSION));if(!in_array($ext,['pdf','pptx'],true))exit('Only PDF and PPTX files are accepted.');
    $dir=__DIR__.'/storage/materials';if(!is_dir($dir))mkdir($dir,0775,true);$name=bin2hex(random_bytes(16)).'.'.$ext;$path=$dir.'/'.$name;if(!move_uploaded_file($_FILES['file']['tmp_name'],$path))exit('Upload failed.');$pages=cmuMaterialPages($path,$ext);$pageCount=count($pages);if($pageCount===0)$pageCount=1;
    $period=in_array($_POST['academic_period']??'midterm',['midterm','final'],true)?$_POST['academic_period']:'midterm';$db->prepare('INSERT INTO course_materials(course_id,teacher_id,title,description,academic_period,file_path,file_type,page_count,pages,is_enabled,opens_at,closes_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,1,?,?,NOW(),NOW())')->execute([$courseId, $u['id'],trim($_POST['title']??''),trim($_POST['description']??''),$period,$name,$ext,$pageCount,json_encode($pages,JSON_UNESCAPED_UNICODE),$_POST['opens_at']?:null,$_POST['closes_at']?:null]);
    $_SESSION['flash']='Material uploaded successfully.';\CmuLms\Http::redirect('?route=course&id='.$courseId);
}

/* Student submissions must belong to an active enrollment in the activity's course. */
if ($route === 'task-submit' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'student') {
    $st=$db->prepare('SELECT t.id,t.course_id,c.active FROM tasks t JOIN courses c ON c.id=t.course_id WHERE t.id=?');
    $st->execute([(int)($_POST['task_id']??0)]);$task=$st->fetch();
    $enrolled=false;if($task&&(int)$task['active']===1){$en=$db->prepare('SELECT 1 FROM enrollments WHERE course_id=? AND user_id=? AND status="active"');$en->execute([$task['course_id'],$u['id']]);$enrolled=(bool)$en->fetchColumn();}
    if(!$task||!$enrolled){http_response_code(403);exit('This activity is unavailable.');}
}

if ($route === 'attendance-submit' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'student') {
    $st=$db->prepare('SELECT a.id,a.course_id,c.active FROM attendance_sessions a JOIN courses c ON c.id=a.course_id WHERE a.id=?');
    $st->execute([(int)($_POST['session_id']??0)]);$session=$st->fetch();
    $enrolled=false;if($session&&(int)$session['active']===1){$en=$db->prepare('SELECT 1 FROM enrollments WHERE course_id=? AND user_id=? AND status="active"');$en->execute([$session['course_id'],$u['id']]);$enrolled=(bool)$en->fetchColumn();}
    if(!$session||!$enrolled){http_response_code(403);exit('This attendance session is unavailable.');}
}

if ($route === 'attendance-stats' && $u) {
    $st = $db->prepare('SELECT a.course_id,c.teacher_id FROM attendance_sessions a JOIN courses c ON c.id=a.course_id WHERE a.id=?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $attendance = $st->fetch();
    if (!$attendance || ($u['role'] === 'teacher' && (int)$attendance['teacher_id'] !== (int)$u['id']) || !in_array($u['role'], ['teacher','admin'], true)) exit('Attendance report unavailable.');
}

if ($route === 'attendance-stats' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && in_array($u['role'],['teacher','admin'],true)) {
    $id=(int)($_GET['id']??0);$st=$db->prepare('SELECT a.*,c.code,c.title course_title FROM attendance_sessions a JOIN courses c ON c.id=a.course_id WHERE a.id=?');$st->execute([$id]);$session=$st->fetch();if(!$session)exit('Attendance session not found.');$roster=\CmuLms\Attendance::roster($id);view('attendance/stats',['user'=>$u,'session'=>$session,'roster'=>$roster]);exit;
}

if ($route === 'attendance-roster' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && in_array($u['role'],['teacher','admin'],true)) {
    $id=(int)($_GET['id']??$_GET['session_id']??0);
    $st=$db->prepare('SELECT a.id,a.course_id,c.teacher_id FROM attendance_sessions a JOIN courses c ON c.id=a.course_id WHERE a.id=?');
    $st->execute([$id]); $session=$st->fetch();
    if(!$session || ($u['role']==='teacher' && (int)$session['teacher_id']!==(int)$u['id'])){http_response_code(404);exit('Attendance report unavailable.');}
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['session_id'=>$id,'students'=>\CmuLms\Attendance::roster($id)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}

if ($route === 'message-send' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u) {
    \CmuLms\View::checkCsrf();
    $recipient = (int)($_POST['recipient_id'] ?? 0); $body=trim($_POST['body']??'');
    $st = $db->prepare('SELECT id FROM users WHERE id=? AND id<>? AND disabled_at IS NULL'); $st->execute([$recipient, $u['id']]);
    if (!$st->fetchColumn() || $body === '') exit('Recipient or message unavailable.');
    $db->prepare('INSERT INTO messages(sender_id,recipient_id,body,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())')->execute([$u['id'],$recipient,$body]);
    $db->prepare('INSERT INTO notifications(user_id,title,body,type,target_url,created_at,updated_at) VALUES(?,?,?,"message","?route=messages",NOW(),NOW())')->execute([$recipient,'New message from '.$u['name'],$body]);
    $_SESSION['flash']='Message sent.'; \CmuLms\Http::redirect('?route=messages');
}

if ($route === 'announcement-comments' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u) {
    $announcementId=(int)($_GET['announcement_id']??$_GET['id']??0);
    $st=$db->prepare('SELECT a.id,a.course_id,c.teacher_id FROM announcements a JOIN courses c ON c.id=a.course_id WHERE a.id=?');
    $st->execute([$announcementId]); $announcement=$st->fetch();
    if(!$announcement) { http_response_code(404); exit('Announcement not found.'); }
    $allowed=$u['role']==='admin'||($u['role']==='teacher'&&(int)$announcement['teacher_id']===(int)$u['id']);
    if(!$allowed){$en=$db->prepare('SELECT 1 FROM enrollments WHERE course_id=? AND user_id=? AND status="active"');$en->execute([$announcement['course_id'],$u['id']]);$allowed=(bool)$en->fetchColumn();}
    if(!$allowed) { http_response_code(403); exit('Announcement unavailable.'); }
    header('Content-Type: application/json; charset=utf-8'); echo json_encode(['announcement_id'=>$announcementId,'comments'=>\CmuLms\Announcement::comments($announcementId)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}

if ($route === 'announcement-comment' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u) {
    \CmuLms\View::checkCsrf(); $announcementId=(int)($_POST['announcement_id']??0);$body=trim($_POST['body']??'');$st=$db->prepare('SELECT a.id,a.course_id,a.comments_enabled,c.teacher_id FROM announcements a JOIN courses c ON c.id=a.course_id WHERE a.id=?');$st->execute([$announcementId]);$announcement=$st->fetch();if(!$announcement||empty($announcement['comments_enabled'])||$body==='')exit('Comments are unavailable.');
    $allowed=(($u['role']??'')==='admin'||(($u['role']??'')==='teacher'&&(int)$announcement['teacher_id']===(int)$u['id']));if(!$allowed){$en=$db->prepare('SELECT 1 FROM enrollments WHERE course_id=? AND user_id=? AND status="active"');$en->execute([$announcement['course_id'],$u['id']]);$allowed=(bool)$en->fetchColumn();}if(!$allowed)exit('You cannot comment on this announcement.');
    $db->prepare('INSERT INTO announcement_comments(announcement_id,user_id,body,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())')->execute([$announcementId,$u['id'],$body]);$_SESSION['flash']='Comment posted.';\CmuLms\Http::redirect('?route=course&id='.(int)$announcement['course_id']);
}

if ($route === 'attendance-submit' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'student') {
    $sessionId=(int)($_POST['session_id']??0);$st=$db->prepare('SELECT id FROM attendance_submissions WHERE session_id=? AND student_id=?');$st->execute([$sessionId,$u['id']]);if($st->fetchColumn()){$_SESSION['error']='Attendance has already been submitted and cannot be changed.';\CmuLms\Http::redirect('?route=course&id='.(int)($_POST['course_id']??0));}
}

if ($route === 'attendance-submit' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'student') {
    $attendanceId=(int)($_POST['session_id']??0);$access=$db->prepare('SELECT a.id FROM attendance_sessions a JOIN courses c ON c.id=a.course_id JOIN enrollments e ON e.course_id=c.id AND e.user_id=? AND e.status="active" WHERE a.id=? AND c.active=1');$access->execute([$u['id'],$attendanceId]);if(!$access->fetchColumn())exit('Attendance unavailable.');
}

if ($route === 'announcement-attachment' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'],['teacher','admin'],true)) {
    \CmuLms\View::checkCsrf();$announcementId=(int)($_POST['announcement_id']??0);$st=$db->prepare('SELECT a.*,c.teacher_id FROM announcements a JOIN courses c ON c.id=a.course_id WHERE a.id=? AND (c.teacher_id=? OR ?="admin")');$st->execute([$announcementId,$u['id'],$u['role']]);$announcement=$st->fetch();if(!$announcement||empty($_FILES['file']['tmp_name']))exit('Announcement or file unavailable.');$ext=strtolower(pathinfo($_FILES['file']['name'],PATHINFO_EXTENSION));if(!in_array($ext,['pdf','pptx','doc','docx','png','jpg','jpeg','txt'],true))exit('Unsupported attachment type.');$dir=__DIR__.'/storage/announcements';if(!is_dir($dir))mkdir($dir,0775,true);$name=bin2hex(random_bytes(16)).'.'.$ext;if(!move_uploaded_file($_FILES['file']['tmp_name'],$dir.'/'.$name))exit('Attachment upload failed.');$db->prepare('INSERT INTO announcement_attachments(announcement_id,file_path,original_name,mime_type,file_size,created_at,updated_at) VALUES(?,?,?,?,?,NOW(),NOW())')->execute([$announcementId,$name,basename($_FILES['file']['name']),$_FILES['file']['type']??'application/octet-stream',(int)$_FILES['file']['size']]);$_SESSION['flash']='Announcement attachment uploaded.';\CmuLms\Http::redirect('?route=course&id='.(int)$announcement['course_id']);
}

if ($route === 'announcement-attachment-file' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u) {
    $id=(int)($_GET['id']??0);$st=$db->prepare('SELECT x.*,a.course_id,c.teacher_id FROM announcement_attachments x JOIN announcements a ON a.id=x.announcement_id JOIN courses c ON c.id=a.course_id WHERE x.id=?');$st->execute([$id]);$attachment=$st->fetch();if(!$attachment)exit('Attachment not found.');$allowed=$u['role']==='admin'||($u['role']==='teacher'&&(int)$attachment['teacher_id']===(int)$u['id']);if(!$allowed){$en=$db->prepare('SELECT 1 FROM enrollments WHERE course_id=? AND user_id=? AND status="active"');$en->execute([$attachment['course_id'],$u['id']]);$allowed=(bool)$en->fetchColumn();}if(!$allowed)exit('Attachment unavailable.');$file=__DIR__.'/storage/announcements/'.basename($attachment['file_path']);if(!is_file($file))exit('Attachment file not found.');header('Content-Type: '.($attachment['mime_type']?:'application/octet-stream'));header('Content-Disposition: attachment; filename="'.str_replace('"','',basename($attachment['original_name'])).'"');readfile($file);exit;
}

if ($route === 'announcement-comment-delete' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u) {
    \CmuLms\View::checkCsrf();$commentId=(int)($_POST['comment_id']??0);$st=$db->prepare('SELECT c.id,c.user_id,a.course_id,cx.teacher_id FROM announcement_comments c JOIN announcements a ON a.id=c.announcement_id JOIN courses cx ON cx.id=a.course_id WHERE c.id=?');$st->execute([$commentId]);$comment=$st->fetch();if(!$comment)exit('Comment not found.');if((int)$comment['user_id']!==(int)$u['id']&&$u['role']!=='admin'&&!($u['role']==='teacher'&&(int)$comment['teacher_id']===(int)$u['id']))exit('You cannot delete this comment.');$db->prepare('DELETE FROM announcement_comments WHERE id=?')->execute([$commentId]);$_SESSION['flash']='Comment deleted.';\CmuLms\Http::redirect('?route=course&id='.(int)$comment['course_id']);
}

if ($route === 'messages' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u) {
    $db->prepare('UPDATE messages SET read_at=NOW(),updated_at=NOW() WHERE recipient_id=? AND read_at IS NULL')->execute([$u['id']]);
    view('messages/index', ['user'=>$u,'items'=>\CmuLms\Message::forUser($u['id']),'users'=>\CmuLms\User::messageable($u['id'])]);
    exit;
}

/* JSON read endpoints retained for integrations that previously used the Laravel API. */
if ($route === 'auth-me' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u) {
    $safe=$u; unset($safe['password'],$safe['grok_api_key']); header('Content-Type: application/json'); echo json_encode($safe); exit;
}
if ($route === 'courses' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u) {
    header('Content-Type: application/json'); echo json_encode(\CmuLms\Course::forUser($u)); exit;
}
if ($route === 'academic-terms' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u) {
    $terms=$db->query('SELECT id,academic_year,semester,active FROM academic_terms WHERE active=1 ORDER BY academic_year DESC,semester')->fetchAll(); header('Content-Type: application/json'); echo json_encode($terms); exit;
}
if ($route === 'admin-dashboard' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && $u['role']==='admin') {
    \CmuLms\Http::redirect('?route=dashboard');
}

if ($route === 'student-import' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    \CmuLms\View::checkCsrf();
    require_once __DIR__.'/services/StudentImporter.php';
    $courseId=(int)($_POST['course_id']??0);$destination=\CmuLms\Course::find($courseId);
    if(!$destination||empty($destination['active'])||($u['role']==='teacher'&&(int)$destination['teacher_id']!==(int)$u['id'])){http_response_code(403);exit('Import destination unavailable.');}
    if(isset($_GET['id'])&&(int)$_GET['id']!==$courseId){http_response_code(422);exit('The import destination does not match the open class. Refresh the course page.');}
    $importer=new \CmuLms\StudentImporter($db);$token=trim((string)($_POST['import_token']??''));
    try {
        if($token!==''){
            $pending=$_SESSION['pending_student_imports'][$token]??null;
            if(!$pending||(int)$pending['course_id']!==$courseId||(int)$pending['user_id']!==(int)$u['id']||time()-(int)$pending['created_at']>1800)throw new \InvalidArgumentException('This preview expired or belongs to another class. Upload the CSV again.');
            $stored=basename($pending['file']);$parsed=$importer->parse(__DIR__.'/storage/imports/'.$stored);$plan=$importer->plan($parsed,$courseId);
            if(!$plan['errors']){
                $db->beginTransaction();
                try {
                    $result=$importer->import($parsed,$destination);
                    $db->prepare('INSERT INTO student_imports(user_id,course_id,section_code,file_path,status,imported,enrolled,errors,ip_address,created_at,updated_at) VALUES(?,?,?,?,"completed",?,?,"[]",?,NOW(),NOW())')->execute([$u['id'],$courseId,$destination['section_code'],$stored,$result['imported'],$result['enrolled'],$_SERVER['REMOTE_ADDR']??null]);
                    $importId=(int)$db->lastInsertId();$db->commit();
                }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
                unset($_SESSION['pending_student_imports'][$token]);
                $_SESSION['flash']=$result['enrolled'].' students enrolled in '.$destination['code'].'. '.$result['imported'].' new accounts created; '.$result['already'].' were already enrolled. Other classes were unchanged.';
                \CmuLms\Http::redirect('?route=course&id='.$courseId.'&tab=students&import_id='.$importId.'#students-activity');
            }
        }else{
            if(empty($_FILES['file']['tmp_name'])||!is_uploaded_file($_FILES['file']['tmp_name']))throw new \InvalidArgumentException('Choose a CSV file.');
            if(strtolower(pathinfo($_FILES['file']['name'],PATHINFO_EXTENSION))!=='csv'||(int)$_FILES['file']['size']>5*1024*1024)throw new \InvalidArgumentException('Upload a CSV file no larger than 5 MB.');
            $parsed=$importer->parse($_FILES['file']['tmp_name']);$plan=$importer->plan($parsed,$courseId);
            if(!empty($_POST['preview'])){header('Content-Type: application/json');echo json_encode(['destination'=>['id'=>$courseId,'code'=>$destination['code'],'section'=>$destination['section_code']]]+$plan);exit;}
            $dir=__DIR__.'/storage/imports';if(!is_dir($dir))mkdir($dir,0775,true);$stored=bin2hex(random_bytes(16)).'.csv';
            if(!move_uploaded_file($_FILES['file']['tmp_name'],$dir.'/'.$stored))throw new \RuntimeException('Could not store the CSV file.');
            $token=bin2hex(random_bytes(24));$_SESSION['pending_student_imports'][$token]=['course_id'=>$courseId,'user_id'=>(int)$u['id'],'file'=>$stored,'created_at'=>time()];
        }
        view('courses/import-preview',['user'=>$u,'course'=>$destination,'plan'=>$plan,'import_token'=>$token]);exit;
    }catch(\InvalidArgumentException $e){$_SESSION['error']=$e->getMessage();\CmuLms\Http::redirect('?route=course&id='.$courseId.'&tab=students#student-import');}
}

if (in_array($route,['course-enrollment-candidates','course-enroll-from-subject'],true) && $u) {
    $json=$route==='course-enrollment-candidates';
    $input=$json?$_GET:$_POST;
    $destinationId=(int)($input['course_id']??0);$sourceId=(int)($input['source_course_id']??0);
    $fail=static function(string $message,int $status=422) use($json,$destinationId):never {
        if($json){http_response_code($status);header('Content-Type: application/json');echo json_encode(['error'=>$message]);exit;}
        $_SESSION['error']=$message;\CmuLms\Http::redirect('?route=course&id='.$destinationId.'&tab=students#enroll-from-subject');
    };
    if(!in_array($u['role'],['teacher','admin'],true))$fail('Teacher access is required.',403);
    if(($json&&$_SERVER['REQUEST_METHOD']!=='GET')||(!$json&&$_SERVER['REQUEST_METHOD']!=='POST'))$fail('Invalid request method.',405);
    if(!$json)\CmuLms\View::checkCsrf();
    $destination=\CmuLms\Course::find($destinationId);$source=\CmuLms\Course::find($sourceId);
    if(!$destination||!$source||empty($destination['active'])||empty($source['active'])||$sourceId===$destinationId)$fail('Choose a different active source subject.');
    if($u['role']==='teacher'&&((int)$destination['teacher_id']!==(int)$u['id']||(int)$source['teacher_id']!==(int)$u['id']))$fail('You can enroll students only between your own subjects.',403);
    if($json){header('Content-Type: application/json');echo json_encode(\CmuLms\Course::enrollmentCandidates($sourceId,$destinationId,trim((string)($_GET['q']??'')),max(1,(int)($_GET['page']??1))));exit;}
    $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['user_ids']??[])),static fn($id)=>$id>0)));
    if(!$ids||count($ids)>500)$fail('Select between 1 and 500 students.');
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $db->beginTransaction();
    try {
        $st=$db->prepare('SELECT u.id FROM users u JOIN enrollments e ON e.user_id=u.id WHERE e.course_id=? AND e.status="active" AND u.role="student" AND u.disabled_at IS NULL AND u.id IN ('.$marks.') FOR UPDATE');
        $st->execute(array_merge([$sourceId],$ids));$valid=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
        if(count($valid)!==count($ids)){ $db->rollBack();$fail('Some selected students are no longer active in the source subject. Reload the list and try again.'); }
        $insert=$db->prepare('INSERT INTO enrollments(course_id,user_id,status,created_at,updated_at) VALUES(?,?,"active",NOW(),NOW()) ON DUPLICATE KEY UPDATE status="active",updated_at=NOW()');
        $existing=$db->prepare('SELECT status FROM enrollments WHERE course_id=? AND user_id=?');$count=0;
        foreach($valid as $studentId){$existing->execute([$destinationId,$studentId]);if($existing->fetchColumn()==='active')continue;$insert->execute([$destinationId,$studentId]);$count++;}
        $db->commit();
    } catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    $_SESSION['flash']=$count.' student(s) enrolled from '.$source['code'].'. Existing accounts and source enrollments were retained.';
    \CmuLms\Http::redirect('?route=course&id='.$destinationId.'&tab=students#enroll-from-subject');
}

if ($route === 'course-enroll' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'],['teacher','admin'],true)) {
    \CmuLms\View::checkCsrf(); $courseId=(int)($_POST['course_id']??0);if($u['role']==='teacher'&&!cmuTeacherOwnsCourse($db,$courseId,(int)$u['id']))exit('Course unavailable.');
    $ids=array_values(array_filter(array_map('intval',(array)($_POST['user_ids']??[]))));if(!$ids&&trim($_POST['section_code']??'')!==''){$st=$db->prepare('SELECT id FROM users WHERE role="student" AND disabled_at IS NULL AND section_code=?');$st->execute([trim($_POST['section_code'])]);$ids=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));}
    if(!$ids)exit('Select at least one student.');$marks=implode(',',array_fill(0,count($ids),'?'));$st=$db->prepare("SELECT id FROM users WHERE id IN ($marks) AND role='student' AND disabled_at IS NULL");$st->execute($ids);$valid=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));if(count($valid)!==count($ids))exit('Only active student accounts can be enrolled.');$ins=$db->prepare('INSERT IGNORE INTO enrollments(course_id,user_id,status,created_at,updated_at) VALUES(?,?,"active",NOW(),NOW())');$count=0;foreach($valid as $id){$ins->execute([$courseId,$id]);$count+=(int)$ins->rowCount();}$_SESSION['flash']="$count students enrolled.";\CmuLms\Http::redirect('?route=course&id='.$courseId.'&tab=students');
}

if ($route === 'grade-submit' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'],['teacher','admin'],true)) {
    \CmuLms\View::checkCsrf();$submissionId=(int)($_POST['submission_id']??0);$st=$db->prepare('SELECT s.*,t.title task_title,t.max_score task_max,c.id course_id,c.teacher_id FROM task_submissions s JOIN tasks t ON t.id=s.task_id JOIN courses c ON c.id=t.course_id WHERE s.id=? AND (c.teacher_id=? OR ?="admin")');$st->execute([$submissionId,$u['id'],$u['role']]);$submission=$st->fetch();if(!$submission)exit('Submission unavailable.');$max=(float)($submission['task_max']??100);if($max<=0)$max=100;$score=(float)($_POST['score']??0);if($score<0||$score>$max)exit('Score must be between 0 and '.$max.'.');$db->prepare('UPDATE task_submissions SET score=?,feedback=?,graded_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$score,trim($_POST['feedback']??''),$submissionId]);$st=$db->prepare('SELECT id FROM grades WHERE course_id=? AND student_id=? AND title=? AND category="activity" ORDER BY id DESC LIMIT 1');$st->execute([$submission['course_id'],$submission['student_id'],$submission['task_title']]);if($gradeId=$st->fetchColumn())$db->prepare('UPDATE grades SET score=?,max_score=?,released=1,graded_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$score,$max,$gradeId]);else $db->prepare('INSERT INTO grades(course_id,student_id,title,category,score,max_score,released,graded_at,created_at,updated_at) VALUES(?,?,?,?,?,?,1,NOW(),NOW(),NOW())')->execute([$submission['course_id'],$submission['student_id'],$submission['task_title'],'activity',$score,$max]);$_SESSION['flash']='Grade saved and added to the gradebook.';\CmuLms\Http::redirect('?route=course&id='.(int)($_POST['course_id']??$submission['course_id']));
}

if ($route === 'student-import-status' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && in_array($u['role'],['teacher','admin'],true)) {
    $id=(int)($_GET['id']??0);$st=$db->prepare('SELECT i.*,c.code FROM student_imports i LEFT JOIN courses c ON c.id=i.course_id WHERE i.id=? AND (i.user_id=? OR ?="admin" OR c.teacher_id=?)');$st->execute([$id,$u['id'],$u['role'],$u['id']]);$row=$st->fetch();if(!$row){http_response_code(404);exit('Import not found.');}header('Content-Type: application/json');echo json_encode($row);exit;
}

if ($route === 'material-read' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role']==='student') {
    \CmuLms\View::checkCsrf(); $materialId=(int)($_POST['material_id']??0);
    $st=$db->prepare('SELECT m.course_id,r.open_count FROM course_materials m JOIN enrollments e ON e.course_id=m.course_id AND e.user_id=? AND e.status="active" LEFT JOIN course_material_reads r ON r.material_id=m.id AND r.student_id=? WHERE m.id=? AND m.is_enabled=1 AND (m.opens_at IS NULL OR m.opens_at<=NOW()) AND (m.closes_at IS NULL OR m.closes_at>=NOW())');
    $st->execute([$u['id'],$u['id'],$materialId]); $row=$st->fetch();
    if(!$row) exit('Material unavailable.'); if((int)($row['open_count']??0)<2){$_SESSION['error']='Open this material a second time before marking it as read.';\CmuLms\Http::redirect('?route=material-open&id='.$materialId);}
    $db->prepare('UPDATE course_material_reads SET read_at=NOW(),updated_at=NOW() WHERE material_id=? AND student_id=?')->execute([$materialId,$u['id']]); $_SESSION['flash']='Material marked as read.'; \CmuLms\Http::redirect('?route=material-open&id='.$materialId);
}

if ($route === 'task-submit' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role']==='student') {
    $st=$db->prepare('SELECT submission_type FROM tasks WHERE id=?'); $st->execute([(int)($_POST['task_id']??0)]);
    if($st->fetchColumn()==='none'){$_SESSION['error']='This activity does not accept submissions.';\CmuLms\Http::redirect('?route=course&id='.(int)($_POST['course_id']??0));}
    if(trim((string)($_POST['body']??''))==='' && empty($_FILES['file']['tmp_name'])){$_SESSION['error']='Add written work or a file before submitting.';\CmuLms\Http::redirect('?route=course&id='.(int)($_POST['course_id']??0));}
}

if ($route === 'task-submissions' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && in_array($u['role'],['teacher','admin'],true)) {
    $taskId=(int)($_GET['task_id']??$_GET['id']??0);$st=$db->prepare('SELECT s.*,u.name,u.id_number,t.title task_title,c.teacher_id FROM task_submissions s JOIN users u ON u.id=s.student_id JOIN tasks t ON t.id=s.task_id JOIN courses c ON c.id=t.course_id WHERE s.task_id=? AND (c.teacher_id=? OR ?="admin") ORDER BY s.submitted_at DESC');$st->execute([$taskId,$u['id'],$u['role']]);header('Content-Type: application/json');echo json_encode($st->fetchAll());exit;
}

if ($route === 'submission-file' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u) {
    $id=(int)($_GET['id']??0);$st=$db->prepare('SELECT s.file_path,s.student_id,c.teacher_id FROM task_submissions s JOIN tasks t ON t.id=s.task_id JOIN courses c ON c.id=t.course_id WHERE s.id=?');$st->execute([$id]);$row=$st->fetch();$allowed=$row&&($u['role']==='admin'||($u['role']==='student'&&(int)$row['student_id']===(int)$u['id'])||($u['role']==='teacher'&&(int)$row['teacher_id']===(int)$u['id']));if(!$allowed)exit('File unavailable.');$file=__DIR__.'/storage/submissions/'.basename((string)$row['file_path']);if(!$row['file_path']||!is_file($file))exit('File not found.');header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.basename($row['file_path']).'"');readfile($file);exit;
}

function cmuTeacherOwnsCourse(PDO $db, int $courseId, int $teacherId): bool {
    $st = $db->prepare('SELECT 1 FROM courses WHERE id=? AND teacher_id=? AND active=1');
    $st->execute([$courseId, $teacherId]);
    return (bool)$st->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'teacher') {
    $courseId = (int)($_POST['course_id'] ?? 0);
    if (in_array($route, ['course-post','student-import','student-manual','material-upload'], true) && !cmuTeacherOwnsCourse($db, $courseId, (int)$u['id'])) exit('Course unavailable.');
    if ($route === 'pool-create' && !cmuTeacherOwnsCourse($db, $courseId, (int)$u['id'])) exit('Course unavailable.');
    if (in_array($route, ['question-create','aiken-import'], true)) {
        $st = $db->prepare('SELECT 1 FROM question_pools p JOIN courses c ON c.id=p.course_id WHERE p.id=? AND c.teacher_id=? AND c.active=1');
        $st->execute([(int)($_POST['pool_id'] ?? 0), $u['id']]);
        if (!$st->fetchColumn()) exit('Question bank unavailable.');
    }
    if ($route === 'exam-create') {
        $st = $db->prepare('SELECT 1 FROM question_pools p JOIN courses source ON source.id=p.course_id JOIN courses c ON c.id=? WHERE p.id=? AND c.teacher_id=? AND source.teacher_id=? AND c.active=1');
        $st->execute([$courseId,(int)($_POST['pool_id'] ?? 0), $u['id'], $u['id']]);
        if (!$st->fetchColumn()) exit('Question bank or course unavailable.');
    }
    if ($route === 'grade-submit') {
        $st = $db->prepare('SELECT 1 FROM task_submissions s JOIN tasks t ON t.id=s.task_id JOIN courses c ON c.id=t.course_id WHERE s.id=? AND c.teacher_id=?');
        $st->execute([(int)($_POST['submission_id'] ?? 0), $u['id']]);
        if (!$st->fetchColumn()) exit('Submission unavailable.');
    }
}

/* All question entry points are maintained here so they share the same
   ownership rules as question-bank review and exam publishing. */
if ($route === 'question-create' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'teacher') {
    \CmuLms\View::checkCsrf();
    $poolId = (int)($_POST['pool_id'] ?? 0);
    $st = $db->prepare('SELECT p.*,c.id course_id FROM question_pools p JOIN courses c ON c.id=p.course_id WHERE p.id=? AND c.teacher_id=? AND c.active=1');
    $st->execute([$poolId, $u['id']]);
    $pool = $st->fetch();
    $prompt = trim((string)($_POST['prompt'] ?? ''));
    if (!$pool || $prompt === '') exit('Question bank or question prompt unavailable.');
    $type = in_array($_POST['type'] ?? 'multiple_choice', ['multiple_choice','true_false','identification'], true) ? $_POST['type'] : 'multiple_choice';
    $options = [];
    foreach (['A','B','C','D'] as $key) {
        $value = trim((string)($_POST['option_'.$key] ?? ''));
        if ($value !== '') $options[$key] = $value;
    }
    if ($type === 'multiple_choice' && count($options) < 2) exit('Add at least two answer choices.');
    if ($type === 'true_false') $options = ['A'=>'True','B'=>'False'];
    $answer = trim((string)($_POST['answer'] ?? ''));
    if ($answer === '' || ($type !== 'identification' && !array_key_exists($answer, $options))) exit('Choose a valid correct answer.');
    $db->prepare('INSERT INTO questions(pool_id,type,prompt,options,answer,points,is_active,created_at,updated_at) VALUES(?,?,?,?,?,?,1,NOW(),NOW())')
        ->execute([$poolId,$type,$prompt,json_encode($options, JSON_UNESCAPED_UNICODE),json_encode([$answer], JSON_UNESCAPED_UNICODE),max(1,(int)($_POST['points'] ?? 1))]);
    $_SESSION['flash'] = 'Question added to the question bank.';
    \CmuLms\Http::redirect('?route=course&id='.(int)$pool['course_id'].'&tab=question-banks');
}

if ($route === 'aiken-import' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'teacher') {
    \CmuLms\View::checkCsrf();
    $poolId = (int)($_POST['pool_id'] ?? 0);
    $st = $db->prepare('SELECT p.*,c.id course_id FROM question_pools p JOIN courses c ON c.id=p.course_id WHERE p.id=? AND c.teacher_id=? AND c.active=1');
    $st->execute([$poolId, $u['id']]);
    $pool = $st->fetch();
    if (!$pool) exit('Question bank unavailable.');
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) exit('Choose an Aiken text file.');
    $extension = strtolower(pathinfo((string)($_FILES['file']['name'] ?? ''), PATHINFO_EXTENSION));
    if ($extension !== 'txt') exit('Only .txt Aiken files are accepted.');
    $text = file_get_contents($_FILES['file']['tmp_name']);
    if ($text === false || trim($text) === '') exit('The Aiken file is empty.');
    $lines = preg_split('/\R/u', str_replace(["\r\n", "\r"], "\n", $text));
    $items = [];
    $prompt = '';
    $options = [];
    $answer = '';
    $flush = static function () use (&$items, &$prompt, &$options, &$answer): void {
        if ($prompt !== '' && count($options) >= 2 && $answer !== '' && array_key_exists($answer, $options)) {
            $items[] = [$prompt, $options, $answer];
        }
        $prompt = ''; $options = []; $answer = '';
    };
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') { if ($prompt !== '' && $answer !== '') $flush(); continue; }
        if (preg_match('/^ANSWER\s*:\s*([A-D])\s*$/i', $line, $m)) { $answer = strtoupper($m[1]); continue; }
        if (preg_match('/^([A-D])[\.)]\s*(.+)$/u', $line, $m)) { $options[strtoupper($m[1])] = trim($m[2]); continue; }
        if ($options && $answer !== '') { $flush(); }
        $prompt = $prompt === '' ? $line : $prompt.' '. $line;
    }
    $flush();
    if (!$items) exit('No valid Aiken questions were found. Each item needs a prompt, A-D choices, and ANSWER: A-D.');
    $insert = $db->prepare('INSERT INTO questions(pool_id,type,prompt,options,answer,points,is_active,created_at,updated_at) VALUES(?,?,?,?,?,?,1,NOW(),NOW())');
    foreach ($items as [$itemPrompt,$itemOptions,$itemAnswer]) $insert->execute([$poolId,'multiple_choice',$itemPrompt,json_encode($itemOptions, JSON_UNESCAPED_UNICODE),json_encode([$itemAnswer]),1]);
    $_SESSION['flash'] = count($items).' Aiken questions imported into '. $pool['title'].'.';
    \CmuLms\Http::redirect('?route=course&id='.(int)$pool['course_id'].'&tab=question-banks');
}

if ($route === 'student-manual' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    \CmuLms\View::checkCsrf();
    $courseId = (int)($_POST['course_id'] ?? 0);
    if ($u['role'] === 'teacher' && !cmuTeacherOwnsCourse($db, $courseId, (int)$u['id'])) exit('Course unavailable.');
    $first = trim((string)($_POST['first_name'] ?? ''));
    $middle = trim((string)($_POST['middle_initial'] ?? ''));
    $last = trim((string)($_POST['last_name'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $idNumber = trim((string)($_POST['id_number'] ?? ''));
    if ($name === '') $name = trim($first.' '.($middle !== '' ? $middle.' ' : '').$last);
    if ($name === '' || $idNumber === '') exit('Student name and school ID are required.');
    $st = $db->prepare('SELECT id,role FROM users WHERE id_number=?'); $st->execute([$idNumber]); $existing = $st->fetch();
    if ($existing && $existing['role'] !== 'student') exit('That school ID already belongs to a non-student account.');
    $studentId = $existing ? (int)$existing['id'] : 0;
    if (!$studentId) {
        $db->prepare('INSERT INTO users(name,first_name,middle_initial,last_name,id_number,role,password,must_change_password,section_code,year_level,course,created_at,updated_at) VALUES(?,?,?,?,?,"student",?,1,?,?,?,?,NOW(),NOW())')
            ->execute([$name,$first,$middle,$last,$idNumber,password_hash('ChangeMe!', PASSWORD_DEFAULT),trim((string)($_POST['section_code'] ?? '')),trim((string)($_POST['year_level'] ?? '')),trim((string)($_POST['course_name'] ?? ''))]);
        $studentId = (int)$db->lastInsertId();
    }
    $db->prepare('INSERT INTO enrollments(course_id,user_id,status,created_at,updated_at) VALUES(?,? ,"active",NOW(),NOW()) ON DUPLICATE KEY UPDATE status="active",updated_at=NOW()')->execute([$courseId,$studentId]);
    $_SESSION['flash'] = 'Student added and enrolled. New accounts use the default password ChangeMe! and must change it at first login.';
    \CmuLms\Http::redirect('?route=course&id='.$courseId.'&tab=students');
}

if ($route === 'save-settings' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    \CmuLms\View::checkCsrf();
    $key = trim((string)($_POST['grok_api_key'] ?? ''));
    if ($key !== '' && strlen($key) < 20) exit('The Groq API key appears too short.');
    if ($key === '' && empty($_POST['remove_key'])) { $st=$db->prepare('SELECT grok_api_key FROM users WHERE id=?'); $st->execute([$u['id']]); $key=(string)($st->fetchColumn()??''); }
    $db->prepare('UPDATE users SET grok_api_key=?,updated_at=NOW() WHERE id=?')->execute([$key !== '' ? $key : null, $u['id']]);
    $_SESSION['flash'] = $key === '' ? 'AI provider key removed.' : 'AI provider settings saved.';
    \CmuLms\Http::redirect('?route=settings');
}

if ($route === 'task-submit' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'student') {
    \CmuLms\View::checkCsrf();
    $taskId = (int)($_POST['task_id'] ?? 0);
    $st = $db->prepare('SELECT t.*,c.active course_active FROM tasks t JOIN courses c ON c.id=t.course_id JOIN enrollments e ON e.course_id=t.course_id AND e.user_id=? AND e.status="active" WHERE t.id=?');
    $st->execute([$u['id'], $taskId]);
    $task = $st->fetch();
    $courseId = (int)($task['course_id'] ?? ($_POST['course_id'] ?? 0));
    if (!$task || (int)$task['course_active'] !== 1) exit('Activity unavailable.');
    if (($task['submission_type'] ?? 'none') === 'none') { $_SESSION['error']='This activity does not accept submissions.'; \CmuLms\Http::redirect('?route=course&id='.$courseId); }
    if (empty($task['submissions_open'])) { $_SESSION['error']='Submissions for this activity are closed by the teacher.'; \CmuLms\Http::redirect('?route=course&id='.$courseId); }
    if (!empty($task['due_at']) && strtotime($task['due_at']) <= time() && empty($task['allow_late'])) { $_SESSION['error']='The submission deadline has passed. This activity is closed.'; \CmuLms\Http::redirect('?route=course&id='.$courseId); }
    $st = $db->prepare('SELECT id FROM task_submissions WHERE task_id=? AND student_id=?'); $st->execute([$taskId,$u['id']]);
    if ($st->fetchColumn()) { $_SESSION['error']='This submission is already final and cannot be changed.'; \CmuLms\Http::redirect('?route=course&id='.$courseId); }
    $body = trim((string)($_POST['body'] ?? '')); $fileName = null;
    if (!empty($_FILES['file']['tmp_name'])) {
        if (!is_uploaded_file($_FILES['file']['tmp_name'])) exit('Invalid upload.');
        $ext = strtolower(pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf','doc','docx','txt','jpg','jpeg','png','zip'], true)) exit('Unsupported submission file type.');
        if ((int)($_FILES['file']['size'] ?? 0) > 10*1024*1024) exit('Submission files must be 10 MB or smaller.');
        $dir = __DIR__.'/storage/submissions'; if (!is_dir($dir)) mkdir($dir, 0775, true);
        $fileName = bin2hex(random_bytes(16)).'.'.$ext;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir.'/'.$fileName)) exit('Submission upload failed.');
    }
    if ($body === '' && $fileName === null) { $_SESSION['error']='Add written work or a file before submitting.'; \CmuLms\Http::redirect('?route=course&id='.$courseId); }
    $isLate = !empty($task['due_at']) && strtotime($task['due_at']) < time();
    $db->prepare('INSERT INTO task_submissions(task_id,student_id,body,file_path,submitted_at,is_late,created_at,updated_at) VALUES(?,?,?,?,NOW(),?,NOW(),NOW())')->execute([$taskId,$u['id'],$body,$fileName,$isLate?1:0]);
    $_SESSION['flash'] = $isLate ? 'Submission saved and marked late.' : 'Submission saved. It is now final and cannot be changed.';
    \CmuLms\Http::redirect('?route=course&id='.$courseId.'&tab=activities');
}

if ($route === 'admin-toggle' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    \CmuLms\View::checkCsrf();
    $studentId = (int)($_POST['user_id'] ?? 0); $courseId = (int)($_POST['course_id'] ?? 0);
    $state = ($_POST['state'] ?? '') === 'disable' ? 'disable' : 'enable';
    $sql = 'SELECT u.id FROM users u JOIN enrollments e ON e.user_id=u.id AND e.course_id=? AND e.status="active" JOIN courses c ON c.id=e.course_id WHERE u.id=? AND u.role="student" AND (c.teacher_id=? OR ?="admin")';
    $st = $db->prepare($sql); $st->execute([$courseId,$studentId,$u['id'],$u['role']]);
    if (!$st->fetchColumn()) exit('Student is not enrolled in a course you manage.');
    $db->prepare('UPDATE users SET disabled_at=?,updated_at=NOW() WHERE id=? AND role="student"')->execute([$state==='disable'?date('Y-m-d H:i:s'):null,$studentId]);
    $_SESSION['flash'] = $state==='disable' ? 'Student account disabled.' : 'Student account enabled.';
    \CmuLms\Http::redirect('?route=course&id='.$courseId.'&tab=students');
}

if ($route === 'pool-create' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'teacher') {
    \CmuLms\View::checkCsrf();
    $courseId=(int)($_POST['course_id']??0);
    if(!cmuTeacherOwnsCourse($db,$courseId,(int)$u['id'])) exit('Course unavailable.');
    $title=trim((string)($_POST['title']??''));
    if($title==='') exit('A question bank title is required.');
    $period=in_array($_POST['academic_period']??'midterm',['midterm','final'],true)?$_POST['academic_period']:'midterm';
    $db->prepare('INSERT INTO question_pools(course_id,title,description,academic_period,topic,created_at,updated_at) VALUES(?,?,?,?,?,NOW(),NOW())')->execute([$courseId,$title,trim((string)($_POST['description']??'')),$period,trim((string)($_POST['topic']??''))]);
    $_SESSION['flash']='Question bank created.';
    \CmuLms\Http::redirect('?route=course&id='.$courseId.'&tab=question-banks');
}

if ($route === 'course-post' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'teacher') {
    \CmuLms\View::checkCsrf();
    $courseId=(int)($_POST['course_id']??0); $type=$_POST['content_type']??'';
    if(!cmuTeacherOwnsCourse($db,$courseId,(int)$u['id'])) exit('Course unavailable.');
    $title=trim($_POST['title']??''); if($title==='') exit('A title is required.');
    $period=in_array($_POST['academic_period']??'midterm',['midterm','final'],true)?$_POST['academic_period']:'midterm';
    if($type==='announcement'){
        $db->prepare('INSERT INTO announcements(course_id,author_id,title,body,publish_at,pinned,comments_enabled,academic_period,is_visible,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,1,NOW(),NOW())')->execute([$courseId,$u['id'],$title,trim($_POST['body']??''),$_POST['publish_at']?:null,!empty($_POST['pinned']),isset($_POST['comments_enabled'])?(int)!empty($_POST['comments_enabled']):1,$period]);
    } elseif($type==='task') {
        $db->prepare('INSERT INTO tasks(course_id,title,type,description,due_at,submission_type,max_score,submissions_open,academic_period,created_at,updated_at) VALUES(?, ?, "task", ?, ?, ?, ?, ?, ?, NOW(), NOW())')->execute([$courseId,$title,trim($_POST['body']??''),$_POST['due_at']?:null,$_POST['submission_type']??'text',($_POST['max_score']??'')!==''?(float)$_POST['max_score']:null,isset($_POST['submissions_open'])?(int)!empty($_POST['submissions_open']):1,$period]);
    } elseif($type==='attendance') {
        $code=trim($_POST['access_code']??'');$db->prepare('INSERT INTO attendance_sessions(course_id,title,code_hash,code_required,opens_at,closes_at,created_at,updated_at) VALUES(?,?,?,?,?,?,NOW(),NOW())')->execute([$courseId,$title,$code?password_hash($code,PASSWORD_DEFAULT):null,!empty($_POST['code_required']),$_POST['opens_at']??null,$_POST['closes_at']??null]);
    } else exit('Unsupported course content type.');
    notifyCourseStudents($courseId,$type==='announcement'?'New announcement':($type==='task'?'New activity':'New attendance session'),$title,'?route=course&id='.$courseId);
    $_SESSION['flash']='Content saved successfully.'; \CmuLms\Http::redirect('?route=course&id='.$courseId);
}

if ($route === 'exam-create' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'teacher') {
    $courseId = (int)($_POST['course_id'] ?? 0);
    $poolId = (int)($_POST['pool_id'] ?? 0);
    $st = $db->prepare('SELECT p.academic_period,p.course_id source_course_id,c.semester FROM question_pools p JOIN courses source ON source.id=p.course_id JOIN courses c ON c.id=? WHERE p.id=? AND c.teacher_id=? AND source.teacher_id=? AND c.active=1');
    $st->execute([$courseId, $poolId, $u['id'], $u['id']]);
    $term=$st->fetch(); if (!$term) exit('Question bank or course unavailable.');
    $questionCount=max(1,(int)($_POST['question_count'] ?? 10));$available=$db->prepare('SELECT COUNT(*) FROM questions WHERE pool_id=? AND is_active=1');$available->execute([$poolId]);if($questionCount>(int)$available->fetchColumn())exit('The requested question count exceeds the active questions in this bank.');
    $starts=$_POST['starts_at']?:null;$ends=$_POST['ends_at']?:null;if($ends&&strtotime($ends)<=time())exit('The exam closing time must be in the future.');if($starts&&$ends&&strtotime($ends)<=strtotime($starts))exit('The closing time must be after the opening time.');
    $audience = ($_POST['audience'] ?? 'all') === 'selected' ? 'selected' : 'all';
    $targets = array_values(array_filter(array_map('intval', $_POST['target_student_ids'] ?? [])));
    $period=in_array($_POST['academic_period']??'', ['midterm','final'],true)?$_POST['academic_period']:(($term['academic_period']??'')?:((($term['semester']??'')==='2nd')?'final':'midterm'));
    $policy=in_array($_POST['violation_policy']??'log',['log','warn','auto_submit'],true)?$_POST['violation_policy']:'log';$threshold=max(1,(int)($_POST['auto_submit_after_violations']??3));
    $db->beginTransaction();
    try {
    if((int)$term['source_course_id']!==$courseId){$copiedBank=\CmuLms\QuestionPool::copyIntoCourse($poolId,$courseId,(int)$u['id']);$poolId=$copiedBank['id'];}
    $db->prepare('INSERT INTO exams(course_id,question_pool_id,title,duration_minutes,attempts_allowed,question_count,academic_period,starts_at,ends_at,camera_required,allow_back_navigation,allow_question_jump,log_ip,violation_policy,auto_submit_after_violations,is_enabled,audience,target_student_ids,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
        ->execute([$courseId,$poolId,trim($_POST['title'] ?? ''),max(1,(int)($_POST['duration_minutes'] ?? 30)),max(1,(int)($_POST['attempts_allowed'] ?? 1)),$questionCount,$period,$starts,$ends,!empty($_POST['camera_required']),!empty($_POST['allow_back_navigation']),!empty($_POST['allow_question_jump']),!empty($_POST['log_ip']),$policy,$threshold,!empty($_POST['is_enabled']),$audience,json_encode($audience==='selected'?$targets:[])]);
    $db->commit();
    } catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    notifyCourseStudents($courseId, 'New quiz or exam', trim($_POST['title'] ?? 'A new assessment was posted.'), '?route=course&id='.$courseId);
    $_SESSION['flash'] = 'Exam posted successfully.';
    \CmuLms\Http::redirect('?route=course&id='.$courseId.'&tab=activities');
}

if ($route === 'exam-edit' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'],['teacher','admin'],true)) {
    \CmuLms\View::checkCsrf(); $id=(int)($_POST['exam_id']??0);
    $st=$db->prepare('SELECT e.*,c.teacher_id FROM exams e JOIN courses c ON c.id=e.course_id WHERE e.id=? AND (c.teacher_id=? OR ?="admin")');$st->execute([$id,$u['id'],$u['role']]);$exam=$st->fetch();if(!$exam)exit('Exam not found.');
    $audience=($_POST['audience']??'all')==='selected'?'selected':'all';$targets=array_values(array_filter(array_map('intval',$_POST['target_student_ids']??[])));$period=in_array($_POST['academic_period']??'midterm',['midterm','final'],true)?$_POST['academic_period']:'midterm';
    $questionCount=filter_var($_POST['question_count']??$exam['question_count'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $available=$db->prepare('SELECT COUNT(*) FROM questions WHERE pool_id=? AND is_active=1');$available->execute([(int)$exam['question_pool_id']]);$availableCount=(int)$available->fetchColumn();
    if($questionCount===false||$questionCount>$availableCount){$_SESSION['error']='Number of items must be a whole number from 1 to '.$availableCount.' (the active questions in this bank).';\CmuLms\Http::redirect('?route=exam-edit&id='.$id);} 
    $policy=in_array($_POST['violation_policy']??'log',['log','warn','auto_submit'],true)?$_POST['violation_policy']:'log';$threshold=max(1,(int)($_POST['auto_submit_after_violations']??3));
    $db->prepare('UPDATE exams SET title=?,academic_period=?,duration_minutes=?,attempts_allowed=?,question_count=?,starts_at=?,ends_at=?,camera_required=?,allow_back_navigation=?,allow_question_jump=?,log_ip=?,violation_policy=?,auto_submit_after_violations=?,is_enabled=?,audience=?,target_student_ids=?,updated_at=NOW() WHERE id=?')->execute([trim($_POST['title']??''),$period,max(1,(int)($_POST['duration_minutes']??1)),max(1,(int)($_POST['attempts_allowed']??1)),$questionCount,$_POST['starts_at']?:null,$_POST['ends_at']?:null,!empty($_POST['camera_required']),!empty($_POST['allow_back_navigation']),!empty($_POST['allow_question_jump']),!empty($_POST['log_ip']),$policy,$threshold,!empty($_POST['is_enabled']),$audience,json_encode($audience==='selected'?$targets:[]),$id]);if(trim($_POST['title']??'')!==($exam['title']??''))$db->prepare('UPDATE grades SET title=?,updated_at=NOW() WHERE course_id=? AND category="quiz" AND title=?')->execute([trim($_POST['title']??''),(int)$exam['course_id'],$exam['title']]);
    $_SESSION['flash']='Exam settings saved.';\CmuLms\Http::redirect('?route=exam-edit&id='.$id);
}

if ($route === 'exam-score-visibility' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'],['teacher','admin'],true)) {
    \CmuLms\View::checkCsrf();$id=(int)($_POST['exam_id']??0);$st=$db->prepare('SELECT e.course_id,c.teacher_id FROM exams e JOIN courses c ON c.id=e.course_id WHERE e.id=? AND (c.teacher_id=? OR ?="admin")');$st->execute([$id,$u['id'],$u['role']]);$exam=$st->fetch();if(!$exam)exit('Exam not found.');$enabled=!empty($_POST['show_score_to_students']);$action=$enabled?'exam_scores_shown':'exam_scores_hidden';$metadata=json_encode(['show_score_to_students'=>$enabled],JSON_UNESCAPED_SLASHES);$db->prepare('INSERT INTO audit_logs(user_id,action,auditable_type,auditable_id,metadata,ip_address,user_agent,created_at,updated_at) VALUES(?,?,"exams",?,?,?,?,NOW(),NOW())')->execute([$u['id'],$action,$id,$metadata,$_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??'']);$_SESSION['flash']='Student score visibility saved.';\CmuLms\Http::redirect('?route=exam-edit&id='.$id);
}

if ($route === 'exam-edit' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && in_array($u['role'],['teacher','admin'],true)) {
    $id=(int)($_GET['id']??0);$st=$db->prepare('SELECT e.*,c.code,c.title course_title,c.teacher_id FROM exams e JOIN courses c ON c.id=e.course_id WHERE e.id=? AND (c.teacher_id=? OR ?="admin")');$st->execute([$id,$u['id'],$u['role']]);$exam=$st->fetch();if(!$exam)exit('Exam not found.');$visibility=$db->prepare('SELECT action FROM audit_logs WHERE auditable_type="exams" AND auditable_id=? AND action IN ("exam_scores_shown","exam_scores_hidden") ORDER BY id DESC LIMIT 1');$visibility->execute([$id]);$exam['show_score_to_students']=$visibility->fetchColumn()==='exam_scores_shown';$available=$db->prepare('SELECT COUNT(*) FROM questions WHERE pool_id=? AND is_active=1');$available->execute([(int)$exam['question_pool_id']]);$exam['available_questions']=(int)$available->fetchColumn();$students=\CmuLms\Course::students((int)$exam['course_id']);view('exams/edit',['user'=>$u,'exam'=>$exam,'students'=>$students,'selected'=>json_decode($exam['target_student_ids']??'[]',true)?:[]]);exit;
}

if ($route === 'exam-start' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'student') {
    $st = $db->prepare('SELECT c.active FROM exams e JOIN courses c ON c.id=e.course_id JOIN enrollments en ON en.course_id=c.id AND en.user_id=? AND en.status="active" WHERE e.id=?');
    $st->execute([$u['id'], (int)($_POST['exam_id'] ?? 0)]);
    if ((int)$st->fetchColumn() !== 1) exit('This course or exam is unavailable.');
}

if ($route === 'gradebook' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    if ($u['role'] === 'teacher') {
        $st = $db->prepare('SELECT g.*,c.code course_code,u.name,u.id_number FROM grades g JOIN courses c ON c.id=g.course_id JOIN users u ON u.id=g.student_id WHERE c.teacher_id=? ORDER BY c.code,u.last_name,u.name,g.title');
        $st->execute([$u['id']]);
    } else {
        $st = $db->query('SELECT g.*,c.code course_code,u.name,u.id_number FROM grades g JOIN courses c ON c.id=g.course_id JOIN users u ON u.id=g.student_id ORDER BY c.code,u.last_name,u.name,g.title');
    }
    view('gradebook/teacher', ['user'=>$u,'grades'=>$st->fetchAll()]);
    exit;
}

if ($route === 'course-gradebook' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && $u['role'] === 'student') {
    $courseId = (int) ($_GET['id'] ?? 0);
    $st = $db->prepare('SELECT c.* FROM courses c JOIN enrollments e ON e.course_id=c.id AND e.user_id=? AND e.status="active" WHERE c.id=? AND c.active=1');
    $st->execute([(int) $u['id'], $courseId]);
    $course = $st->fetch();
    if (!$course) {
        http_response_code(404);
        exit('Course gradebook unavailable.');
    }
    $grades = array_values(array_filter(\CmuLms\Grade::forStudent((int) $u['id']), static function (array $grade) use ($courseId): bool {
        return (int) ($grade['course_id'] ?? 0) === $courseId && !empty($grade['released']);
    }));
    view('gradebook/student-course', [
        'user' => $u,
        'course' => $course,
        'grades' => $grades,
        'attendance' => \CmuLms\Attendance::studentSummary($courseId, (int) $u['id']),
    ]);
    exit;
}

if ($route === 'course-gradebook' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    $courseId=(int)($_GET['id']??0); $st=$db->prepare('SELECT c.* FROM courses c WHERE c.id=? AND (c.teacher_id=? OR ?="admin")'); $st->execute([$courseId,$u['id'],$u['role']]); $course=$st->fetch(); if(!$course) exit('Course gradebook unavailable.');
    $weights=$db->prepare('SELECT * FROM course_grade_weights WHERE course_id=? ORDER BY category'); $weights->execute([$courseId]); $weights=$weights->fetchAll();
    $grades=\CmuLms\Grade::forCourse($courseId);
    $st=$db->prepare('SELECT u.id,u.name,u.id_number FROM users u JOIN enrollments en ON en.user_id=u.id AND en.status="active" WHERE en.course_id=? AND u.role="student" ORDER BY u.last_name,u.name'); $st->execute([$courseId]); $learners=$st->fetchAll();
    $byStudent=[]; foreach($grades as $grade){$byStudent[(int)$grade['student_id']][]=$grade;}
    $st=$db->prepare('SELECT id,title,ends_at,audience,target_student_ids FROM exams WHERE course_id=? ORDER BY starts_at IS NULL,starts_at');$st->execute([$courseId]);$courseExams=$st->fetchAll();$attemptSt=$db->prepare('SELECT * FROM exam_attempts WHERE exam_id=? AND student_id=? ORDER BY id');$examResults=[];foreach($learners as $learner){foreach($courseExams as $exam){$targets=array_map('intval',json_decode($exam['target_student_ids']??'[]',true)?:[]);if(($exam['audience']??'all')==='selected'&&!in_array((int)$learner['id'],$targets,true))continue;$attemptSt->execute([(int)$exam['id'],(int)$learner['id']]);$attemptRows=$attemptSt->fetchAll();$submitted=array_values(array_filter($attemptRows,static fn($row)=>in_array($row['status'],['submitted','auto_submitted'],true)));$best=null;foreach($submitted as $row){$pct=(float)($row['max_score']??0)>0?(float)$row['score']/(float)$row['max_score']*100:null;if($best===null||($pct??-1)>($best['percentage']??-1))$best=['score'=>$row['score'],'max_score'=>$row['max_score'],'percentage'=>$pct];}$status=$attemptRows&&array_filter($attemptRows,static fn($row)=>$row['status']==='in_progress')?'Taking':($submitted?'Took':(!empty($exam['ends_at'])&&strtotime($exam['ends_at'])<time()?'Missed':'Not started'));$examResults[]=['student_name'=>$learner['name'],'id_number'=>$learner['id_number'],'exam_title'=>$exam['title'],'status'=>$status,'score'=>$best['score']??null,'max_score'=>$best['max_score']??null,'percentage'=>$best['percentage']??null];}}
    $summaries=[]; foreach($learners as $learner){$rows=$byStudent[(int)$learner['id']]??[];$category=[];foreach($rows as $grade){if(empty($grade['released'])||(float)$grade['max_score']<=0)continue;$category[$grade['category']]['earned']=($category[$grade['category']]['earned']??0)+(float)$grade['score'];$category[$grade['category']]['possible']=($category[$grade['category']]['possible']??0)+(float)$grade['max_score'];}$weighted=0;$weightTotal=0;foreach($weights as $weight){$cat=$weight['category'];if(isset($category[$cat])&&$category[$cat]['possible']>0){$weighted+=($category[$cat]['earned']/$category[$cat]['possible'])*((float)$weight['weight']);$weightTotal+=(float)$weight['weight'];}}$simplePossible=0;$simpleEarned=0;foreach($category as $cat){$simpleEarned+=$cat['earned'];$simplePossible+=$cat['possible'];}$summaries[(int)$learner['id']]=['weighted_average'=>$weightTotal>0?$weighted/$weightTotal*100:($simplePossible>0?$simpleEarned/$simplePossible*100:null),'graded_items'=>count($rows)];}
    view('gradebook/course',['user'=>$u,'course'=>$course,'grades'=>$grades,'weights'=>$weights,'learners'=>$learners,'summaries'=>$summaries,'exam_results'=>$examResults]); exit;
}

if ($route === 'course-gradebook-export' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    $courseId=(int)($_GET['id']??0); $st=$db->prepare('SELECT c.code,c.title FROM courses c WHERE c.id=? AND (c.teacher_id=? OR ?="admin")'); $st->execute([$courseId,$u['id'],$u['role']]); $course=$st->fetch(); if(!$course) exit('Course gradebook unavailable.');
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9_-]+/','_',$course['code']).'-gradebook.csv"'); $out=fopen('php://output','w'); fputcsv($out,['Course','Student','School ID','Assessment','Category','Score','Maximum','Released','Graded at']); foreach(\CmuLms\Grade::forCourse($courseId) as $row) fputcsv($out,[$course['code'],$row['name'],$row['id_number'],$row['title'],$row['category'],$row['score'],$row['max_score'],!empty($row['released'])?'Yes':'No',$row['graded_at']]); fclose($out); exit;
}

if ($route === 'grade-weights' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'], ['teacher','admin'], true)) {
    \CmuLms\View::checkCsrf(); $courseId=(int)($_POST['course_id']??0); $st=$db->prepare('SELECT id FROM courses WHERE id=? AND (teacher_id=? OR ?="admin")'); $st->execute([$courseId,$u['id'],$u['role']]); if(!$st->fetchColumn()) exit('Course gradebook unavailable.'); $db->prepare('DELETE FROM course_grade_weights WHERE course_id=?')->execute([$courseId]);
    foreach((array)($_POST['category']??[]) as $i=>$category){$category=trim((string)$category);$weight=(float)($_POST['weight'][$i]??0);if($category!==''&&$weight>=0)$db->prepare('INSERT INTO course_grade_weights(course_id,category,weight,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())')->execute([$courseId,$category,$weight]);} $_SESSION['flash']='Grade weights saved.'; \CmuLms\Http::redirect('?route=course-gradebook&id='.$courseId);
}

if ($route === 'exam-display-order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor=\CmuLms\Auth::role('teacher');\CmuLms\View::checkCsrf();$courseId=(int)($_POST['course_id']??0);
    require_once __DIR__.'/services/ExamDisplayOrder.php';
    try {\CmuLms\ExamDisplayOrder::save($db,$actor,$courseId,(string)($_POST['sort_mode']??''),(array)($_POST['exam_ids']??[]));$_SESSION['flash']='Exam arrangement saved. Your score table and Excel exports use the same order.';}
    catch (\InvalidArgumentException $e) {$_SESSION['error']=$e->getMessage();}
    catch (\RuntimeException $e) {http_response_code(403);exit('Course unavailable.');}
    \CmuLms\Http::redirect('?route=course&id='.$courseId.'#quizzes-and-exams');
}

if ($route === 'teacher-course-archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = \CmuLms\Auth::role('teacher');
    $id = (int)($_POST['course_id'] ?? 0);
    $st = $db->prepare('UPDATE courses SET active=1-active,updated_at=NOW() WHERE id=? AND teacher_id=?');
    $st->execute([$id, $actor['id']]);
    $_SESSION['flash'] = 'Course archive status updated.';
    \CmuLms\Http::redirect('?route=dashboard&archived=1');
}

if ($route === 'course' && $u && $u['role'] === 'student') {
    $st = $db->prepare('SELECT active FROM courses WHERE id=?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    if ((int)$st->fetchColumn() !== 1) exit('This course is archived and is not available to students.');
}

/* Course pages are rendered by the maintained MVC router. This keeps course
   access, enrollment filtering, paging, and all related data in one place. */
if ($route === 'course' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u) {
    $courseId=(int)($_GET['id']??0); $course=\CmuLms\Course::find($courseId);
    if (!$course) { http_response_code(404); exit('Course not found.'); }
    if ($u['role']==='student') {
        $st=$db->prepare('SELECT 1 FROM enrollments WHERE course_id=? AND user_id=? AND status="active" AND EXISTS(SELECT 1 FROM courses WHERE id=? AND active=1)');
        $st->execute([$courseId,$u['id'],$courseId]); if(!$st->fetchColumn()) exit('Course unavailable.');
    } elseif ($u['role']==='teacher' && (int)$course['teacher_id']!==(int)$u['id']) exit('Course unavailable.');
    $attendancePage=max(1,(int)($_GET['attendance_page']??1)); $attendancePer=12; $includeClosed=$u['role']!=='student';
    $attendanceTotal=\CmuLms\Attendance::countForCourse($courseId,$includeClosed);
    $submissions=$u['role']==='student'?\CmuLms\Submission::byStudent((int)$u['id']):\CmuLms\Submission::forCourse($courseId);
    require_once __DIR__.'/services/ExamDisplayOrder.php';
    $examOrder=\CmuLms\ExamDisplayOrder::preference($db,(int)$u['id'],$courseId);
    view('courses/show',[
        'examOrder'=>$examOrder,
        'user'=>$u,'course'=>$course,'tab'=>$_GET['tab']??'home',
        'exams'=>$u['role']==='student'?array_values(array_filter(\CmuLms\Exam::forStudent((int)$u['id']),static fn($exam)=>(int)$exam['course_id']===$courseId)):\CmuLms\ExamDisplayOrder::arrange(\CmuLms\Exam::forCourse($courseId),$examOrder),
        'tasks'=>\CmuLms\Task::forCourse($courseId),
        'announcements'=>\CmuLms\Announcement::forCourse($courseId,$u['role']!=='student'),
        'attendance'=>\CmuLms\Attendance::forCourse($courseId,$includeClosed,$attendancePer,($attendancePage-1)*$attendancePer),
        'attendance_page'=>$attendancePage,'attendance_per_page'=>$attendancePer,
        'attendance_pages'=>max(1,(int)ceil($attendanceTotal/$attendancePer)),
        'students'=>\CmuLms\Course::students($courseId),
        'student_activity'=>$u['role']==='teacher'?\CmuLms\Course::studentActivity($courseId,$_GET):null,
        'grades'=>\CmuLms\Grade::forCourse($courseId),
        'pools'=>\CmuLms\QuestionPool::forCourse($courseId),
        'materials'=>\CmuLms\Material::forCourse($courseId),
        'submissions'=>$submissions
    ]); exit;
}

/* Shared pages that used to be rendered by the legacy front controller. Keep
   these in the maintained router so every role uses the same authorization
   and layout path. */
if ($route === 'calendar' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u) {
    if ($u['role']==='student') {
        $courseQuery=$db->prepare('SELECT c.id,c.code,c.title FROM courses c JOIN enrollments e ON e.course_id=c.id AND e.user_id=? AND e.status="active" WHERE c.active=1 ORDER BY c.code');
        $courseQuery->execute([(int)$u['id']]);
    } elseif ($u['role']==='teacher') {
        $courseQuery=$db->prepare('SELECT id,code,title FROM courses WHERE teacher_id=? AND active=1 ORDER BY code');
        $courseQuery->execute([(int)$u['id']]);
    } else {
        $courseQuery=$db->query('SELECT id,code,title FROM courses WHERE active=1 ORDER BY code');
    }
    $calendarCourses=$courseQuery->fetchAll();
    $events=[];
    if ($calendarCourses) {
        $courseIds=array_map('intval',array_column($calendarCourses,'id')); $marks=implode(',',array_fill(0,count($courseIds),'?')); $courseById=[];
        foreach ($calendarCourses as $calendarCourse) {
            $courseById[(int) $calendarCourse['id']] = $calendarCourse;
        }
        $addEvent=function(array $row,string $type,string $date,string $url)use(&$events,$courseById):void{
            $when=$row[$date]??null; if(!$when)return; $course=$courseById[(int)$row['course_id']]??['code'=>'','title'=>''];
            $events[]=['date'=>$when,'type'=>$type,'title'=>$row['title'],'course_code'=>$course['code'],'course_title'=>$course['title'],'url'=>$url,'status'=>strtotime($when)<time()?'Past':'Scheduled'];
        };
        $st=$db->prepare("SELECT id,course_id,title,starts_at,ends_at,created_at FROM exams WHERE course_id IN ($marks) AND is_enabled=1");$st->execute($courseIds);foreach($st as $row){$dateKey=!empty($row['starts_at'])?'starts_at':(!empty($row['ends_at'])?'ends_at':'created_at');$addEvent($row,'Quiz / exam',$dateKey,($u['role']==='student'?'?route=exam-start&exam_id='.(int)$row['id']:'?route=exam-stats&id='.(int)$row['id']));}
        $st=$db->prepare("SELECT id,course_id,title,due_at,created_at FROM tasks WHERE course_id IN ($marks)");$st->execute($courseIds);foreach($st as $row){$addEvent($row,'Activity',!empty($row['due_at'])?'due_at':'created_at','?route=course&id='.(int)$row['course_id'].'&tab=activities');}
        $st=$db->prepare("SELECT id,course_id,title,opens_at,created_at FROM attendance_sessions WHERE course_id IN ($marks)");$st->execute($courseIds);foreach($st as $row){$addEvent($row,'Attendance',!empty($row['opens_at'])?'opens_at':'created_at',($u['role']==='student'?'?route=course&id=':'?route=attendance-stats&id=').(int)($u['role']==='student'?$row['course_id']:$row['id']));}
        $st=$db->prepare("SELECT id,course_id,title,publish_at,created_at FROM announcements WHERE course_id IN ($marks)");$st->execute($courseIds);foreach($st as $row){$addEvent($row,'Announcement',!empty($row['publish_at'])?'publish_at':'created_at','?route=course&id='.(int)$row['course_id'].'&tab=announcements');}
        $st=$db->prepare("SELECT id,course_id,title,opens_at,created_at FROM course_materials WHERE course_id IN ($marks)");$st->execute($courseIds);foreach($st as $row){$addEvent($row,'Material',!empty($row['opens_at'])?'opens_at':'created_at','?route=material-open&id='.(int)$row['id']);}
    }
    usort($events,static fn($a,$b)=>strcmp((string)$a['date'],(string)$b['date']));
    view('calendar/index',['user'=>$u,'events'=>$events,'calendar_courses'=>$calendarCourses]);
    exit;
}

if ($route === 'classes' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u) {
    $classes = \CmuLms\Course::forUser($u);
    view('courses/index', ['user' => $u, 'courses' => $classes, 'exams' => $u['role']==='student'?\CmuLms\Exam::forStudent((int)$u['id']):[]]);
    exit;
}

if ($route === 'settings' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u) {
    view('settings/index', ['user'=>$u]);
    exit;
}

if ($route === 'notifications' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u) {
    view('notifications/index', ['user'=>$u, 'items'=>\CmuLms\Notification::forUser((int)$u['id'])]);
    exit;
}

if ($route === 'notification-read' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u) {
    \CmuLms\View::checkCsrf();
    $db->prepare('UPDATE notifications SET read_at=NOW(),updated_at=NOW() WHERE id=? AND user_id=?')
        ->execute([(int)($_POST['notification_id'] ?? 0), (int)$u['id']]);
    \CmuLms\Http::redirect('?route=notifications');
}

if ($route === 'gradebook' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && $u['role'] === 'student') {
    view('gradebook/index', ['user'=>$u, 'grades'=>\CmuLms\Grade::forStudent((int)$u['id'])]);
    exit;
}

if ($route === 'term-create' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'admin') {
    \CmuLms\View::checkCsrf();
    $year=trim((string)($_POST['academic_year'] ?? ''));
    $semester=(string)($_POST['semester'] ?? '1st');
    if (!preg_match('/^\d{4}-\d{4}$/', $year) || !in_array($semester, ['1st','2nd'], true)) exit('Enter a valid academic year and semester.');
    $exists=$db->prepare('SELECT id FROM academic_terms WHERE academic_year=? AND semester=?');
    $exists->execute([$year,$semester]);
    if ($exists->fetchColumn()) { $_SESSION['error']='That academic term already exists.'; \CmuLms\Http::redirect('?route=admin-courses'); }
    $db->prepare('INSERT INTO academic_terms(academic_year,semester,active,created_at,updated_at) VALUES(?,?,1,NOW(),NOW())')->execute([$year,$semester]);
    $_SESSION['flash']='Academic term added.';
    \CmuLms\Http::redirect('?route=admin-courses');
}

if ($route === 'course-create' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && $u['role'] === 'admin') {
    \CmuLms\View::checkCsrf();
    $code=strtoupper(trim((string)($_POST['code'] ?? '')));
    $title=trim((string)($_POST['title'] ?? ''));
    $teacherId=(int)($_POST['teacher_id'] ?? 0);
    $year=trim((string)($_POST['academic_year'] ?? ''));
    $semester=(string)($_POST['semester'] ?? '1st');
    if ($code==='' || $title==='' || !$teacherId || !preg_match('/^\d{4}-\d{4}$/', $year) || !in_array($semester,['1st','2nd'],true)) exit('Complete the course details.');
    $term=$db->prepare('SELECT id FROM academic_terms WHERE academic_year=? AND semester=? AND active=1');
    $term->execute([$year,$semester]);
    if (!$term->fetchColumn()) exit('Academic term is not configured by the administrator.');
    $teacher=$db->prepare('SELECT id FROM users WHERE id=? AND role="teacher" AND disabled_at IS NULL');
    $teacher->execute([$teacherId]);
    if (!$teacher->fetchColumn()) exit('Choose an active teacher.');
    $dup=$db->prepare('SELECT id FROM courses WHERE code=?');
    $dup->execute([$code]);
    if ($dup->fetchColumn()) { $_SESSION['error']='That course code is already in use.'; \CmuLms\Http::redirect('?route=admin-courses'); }
    $db->prepare('INSERT INTO courses(code,title,description,teacher_id,section_code,academic_year,semester,active,created_at,updated_at) VALUES(?,?,?,?,?,?,?,1,NOW(),NOW())')
        ->execute([$code,$title,trim((string)($_POST['description'] ?? '')),$teacherId,trim((string)($_POST['section_code'] ?? '')),$year,$semester]);
    $_SESSION['flash']='Course created successfully.';
    \CmuLms\Http::redirect('?route=admin-courses');
}

/* Protected material files. The browser may open the file only when the
   viewer is the course teacher/admin or an enrolled student inside the
   material's availability window. */
if ($route === 'material-file' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u) {
    $id=(int)($_GET['id'] ?? 0);
    $st=$db->prepare('SELECT m.*,c.teacher_id,c.active FROM course_materials m JOIN courses c ON c.id=m.course_id WHERE m.id=?');
    $st->execute([$id]); $material=$st->fetch();
    if (!$material) { http_response_code(404); exit('Material not found.'); }
    $allowed=$u['role']==='admin'||($u['role']==='teacher'&&(int)$material['teacher_id']===(int)$u['id']);
    if (!$allowed && $u['role']==='student') {
        $en=$db->prepare('SELECT 1 FROM enrollments WHERE course_id=? AND user_id=? AND status="active"');
        $en->execute([(int)$material['course_id'],(int)$u['id']]);
        $now=time();
        $allowed=(bool)$en->fetchColumn() && (int)$material['active']===1 && (int)$material['is_enabled']===1
            && (empty($material['opens_at']) || strtotime($material['opens_at']) <= $now)
            && (empty($material['closes_at']) || strtotime($material['closes_at']) >= $now);
    }
    if (!$allowed) { http_response_code(403); exit('Material unavailable.'); }
    $file=__DIR__.'/storage/materials/'.basename((string)$material['file_path']);
    if (!is_file($file)) { http_response_code(404); exit('Material file not found.'); }
    $mime=($material['file_type']??'')==='pdf'?'application/pdf':'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    header('Content-Type: '.$mime); header('Content-Length: '.filesize($file)); readfile($file); exit;
}

if ($route === 'attendance-photo' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && in_array($u['role'],['teacher','admin'],true)) {
    $id=(int)($_GET['id'] ?? 0);
    $st=$db->prepare('SELECT s.photo_path,c.teacher_id FROM attendance_submissions s JOIN attendance_sessions a ON a.id=s.session_id JOIN courses c ON c.id=a.course_id WHERE s.id=? AND (c.teacher_id=? OR ?="admin")');
    $st->execute([$id,$u['id'],$u['role']]); $photo=$st->fetch();
    if (!$photo || !$photo['photo_path']) { http_response_code(404); exit('Photo not found.'); }
    $file=__DIR__.'/storage/attendance/'.basename((string)$photo['photo_path']);
    if (!is_file($file)) { http_response_code(404); exit('Photo file not found.'); }
    $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION)); $mime=in_array($ext,['png','webp'],true)?'image/'.$ext:'image/jpeg';
    header('Content-Type: '.$mime); header('Content-Length: '.filesize($file)); readfile($file); exit;
}

if ($route === 'teacher-course-update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = \CmuLms\Auth::role('teacher');
    $id = (int)($_POST['course_id'] ?? 0);
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $title = trim($_POST['title'] ?? '');
    $section = trim($_POST['section_code'] ?? '');
    $st = $db->prepare('SELECT id FROM courses WHERE id=? AND teacher_id=?');
    $st->execute([$id, $actor['id']]);
    if (!$st->fetchColumn() || $code === '' || $title === '') exit('Course not found or incomplete.');
    $st = $db->prepare('SELECT id FROM courses WHERE code=? AND id<>?');
    $st->execute([$code, $id]);
    if ($st->fetchColumn()) exit('That course code is already in use.');
    $db->prepare('UPDATE courses SET code=?,title=?,description=?,section_code=?,updated_at=NOW() WHERE id=? AND teacher_id=?')
        ->execute([$code, $title, trim($_POST['description'] ?? ''), $section, $id, $actor['id']]);
    $_SESSION['flash'] = 'Course details updated.';
    \CmuLms\Http::redirect('?route=course&id='.$id);
}

if ($route === 'pool-copy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = \CmuLms\Auth::role('teacher');
    \CmuLms\View::checkCsrf();
    $source = (int)($_POST['pool_id'] ?? 0);
    $targetCourse = (int)($_POST['target_course_id'] ?? 0);
    try {
        $copy=\CmuLms\QuestionPool::copyIntoCourse($source,$targetCourse,(int)$actor['id']);
    } catch (\InvalidArgumentException $e) { $_SESSION['error']=$e->getMessage();\CmuLms\Http::redirect('?route=classes'); }
    $_SESSION['flash'] = $copy['count'].' questions copied. The bank is now available for exam publishing in this class.';
    if(!empty($_POST['return_to_course']))\CmuLms\Http::redirect('?route=course&id='.$targetCourse.'&selected_pool='.$copy['id'].'#exam-publishing');
    \CmuLms\Http::redirect('?route=pool-view&id='.$copy['id']);
}

if ($route === 'question-copy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor=\CmuLms\Auth::role('teacher','admin');\CmuLms\View::checkCsrf();$qid=(int)($_POST['question_id']??0);$target=(int)($_POST['target_pool_id']??0);$st=$db->prepare('SELECT q.*,c.teacher_id FROM questions q JOIN question_pools p ON p.id=q.pool_id JOIN courses c ON c.id=p.course_id WHERE q.id=? AND (c.teacher_id=? OR ?="admin")');$st->execute([$qid,$actor['id'],$actor['role']]);$question=$st->fetch();$st=$db->prepare('SELECT p.id FROM question_pools p JOIN courses c ON c.id=p.course_id WHERE p.id=? AND (c.teacher_id=? OR ?="admin")');$st->execute([$target,$actor['id'],$actor['role']]);if(!$question||!$st->fetchColumn())exit('Question bank unavailable.');$db->prepare('INSERT INTO questions(pool_id,type,prompt,options,answer,points,is_active,created_at,updated_at) VALUES(?,?,?,?,?,?,?,NOW(),NOW())')->execute([$target,$question['type'],$question['prompt'],$question['options'],$question['answer'],$question['points'],$question['is_active']]);$_SESSION['flash']='Question copied.';\CmuLms\Http::redirect('?route=pool-view&id='.$target);
}

if ($route === 'pool-view' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $actor = \CmuLms\Auth::role('teacher', 'admin');
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare('SELECT p.*,c.code course_code,c.title course_title,c.teacher_id FROM question_pools p JOIN courses c ON c.id=p.course_id WHERE p.id=? AND (c.teacher_id=? OR ?="admin")');
    $st->execute([$id, $actor['id'], $actor['role']]);
    $pool = $st->fetch();
    if (!$pool) exit('Question bank not found');
    $courses = $actor['role'] === 'teacher' ? \CmuLms\Course::forUser($actor) : [];
    $targetPools = $db->prepare('SELECT p.id,p.title,c.code FROM question_pools p JOIN courses c ON c.id=p.course_id WHERE c.teacher_id=? AND p.id<>? ORDER BY c.code,p.title');
    $targetPools->execute([$actor['id'],$id]);
    $requestedPer=(int)($_GET['per_page']??10); $perPage = in_array($requestedPer, [10,20,50], true) ? $requestedPer : 10;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $total = \CmuLms\QuestionPool::questionCount($id);
    view('question-banks/show', ['user'=>$actor,'pool'=>$pool,'questions'=>\CmuLms\QuestionPool::questions($id,$perPage,($page-1)*$perPage),'courses'=>$courses,'target_pools'=>$targetPools->fetchAll(),'page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>max(1,(int)ceil($total/$perPage))]);
    exit;
}

if ($route === 'teacher-course-create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = \CmuLms\Auth::role('teacher');
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $title = trim($_POST['title'] ?? '');
    $year = trim($_POST['academic_year'] ?? '');
    $semester = $_POST['semester'] ?? '';
    if ($code === '' || $title === '' || !preg_match('/^\d{4}-\d{4}$/', $year) || !in_array($semester, ['1st', '2nd'], true)) exit('Complete the course details.');
    $st = $db->prepare('SELECT 1 FROM academic_terms WHERE academic_year=? AND semester=? AND active=1');
    $st->execute([$year, $semester]);
    if (!$st->fetchColumn()) exit('That academic term is not configured by the administrator.');
    $db->prepare('INSERT INTO courses(code,title,description,teacher_id,section_code,academic_year,semester,active,created_at,updated_at) VALUES(?,?,?,?,?,?,?,1,NOW(),NOW())')
        ->execute([$code, $title, trim($_POST['description'] ?? ''), $actor['id'], trim($_POST['section_code'] ?? ''), $year, $semester]);
    $_SESSION['flash'] = 'Course created successfully.';
    \CmuLms\Http::redirect('?route=dashboard');
}

if ($route === 'dashboard' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && $u['role'] === 'student') {
    $courses=\CmuLms\Course::forUser($u); $exams=\CmuLms\Exam::forStudent((int)$u['id']); $grades=\CmuLms\Grade::forStudent((int)$u['id']);
    $st=$db->prepare('SELECT t.*,c.code course_code,c.title course_title FROM tasks t JOIN courses c ON c.id=t.course_id JOIN enrollments e ON e.course_id=c.id AND e.user_id=? AND e.status="active" WHERE c.active=1 ORDER BY t.due_at IS NULL,t.due_at LIMIT 30'); $st->execute([$u['id']]); $dashboardTasks=$st->fetchAll();
    view('dashboard/index',['user'=>$u,'courses'=>$courses,'exams'=>$exams,'grades'=>$grades,'dashboard_tasks'=>$dashboardTasks]); exit;
}

if ($route === 'teacher-scores-export' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$u || !in_array($u['role'], ['teacher','admin'], true)) {http_response_code(403);exit('Forbidden');}
    require_once __DIR__.'/services/ScoreReport.php';
    require_once __DIR__.'/services/ScoreWorkbook.php';
    try {$report=\CmuLms\ScoreReport::build($db,$u,(int)($_GET['course_id']??0),(string)($_GET['score_period']??'all'),(string)($_GET['score_search']??''));}
    catch (\RuntimeException $e) {http_response_code(403);exit('Course unavailable.');}
    $path=tempnam(sys_get_temp_dir(),'cmu_scores_');
    try {
        \CmuLms\ScoreWorkbook::write($path,\CmuLms\ScoreReport::sheets($report));
        $filename=preg_replace('/[^A-Za-z0-9_-]+/','_',$report['course']['code']).'-attempt-scores-'.date('Y-m-d').'.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');header('Cache-Control: private, no-store');
        header('Content-Length: '.filesize($path));readfile($path);
    } finally {if(is_file($path))unlink($path);}
    exit;
}

if ($route === 'dashboard' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && $u['role'] === 'teacher') {
    $showArchived = !empty($_GET['archived']);
    $st = $db->prepare('SELECT c.*,COUNT(e.user_id) students FROM courses c LEFT JOIN enrollments e ON e.course_id=c.id AND e.status="active" WHERE c.teacher_id=? AND c.active=? GROUP BY c.id ORDER BY c.code');
    $st->execute([$u['id'], $showArchived ? 0 : 1]);
    $courses = $st->fetchAll();
    $active = $db->prepare('SELECT COUNT(DISTINCT e.user_id) FROM enrollments e JOIN courses c ON c.id=e.course_id JOIN users u ON u.id=e.user_id WHERE c.teacher_id=? AND e.status="active" AND u.last_activity_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE)');
    $active->execute([$u['id']]);
    $open = $db->prepare('SELECT COUNT(*) FROM tasks t JOIN courses c ON c.id=t.course_id WHERE c.teacher_id=? AND (t.due_at IS NULL OR t.due_at>=NOW())');
    $open->execute([$u['id']]);
    $overdue = $db->prepare('SELECT COUNT(*) FROM tasks t JOIN courses c ON c.id=t.course_id WHERE c.teacher_id=? AND t.due_at<NOW()');
    $overdue->execute([$u['id']]);
    $stats = ['active_users'=>(int)$active->fetchColumn(),'open_tasks'=>(int)$open->fetchColumn(),'overdue'=>(int)$overdue->fetchColumn()];
    $terms = $db->query('SELECT academic_year,semester FROM academic_terms WHERE active=1 ORDER BY academic_year DESC,semester')->fetchAll();
    require_once __DIR__.'/services/ScoreReport.php';
    $scoreCourse=(int)($_GET['score_course']??($courses[0]['id']??0));
    if(!in_array($scoreCourse,array_map('intval',array_column($courses,'id')),true))$scoreCourse=(int)($courses[0]['id']??0);
    $scoreReport=$scoreCourse?\CmuLms\ScoreReport::build($db,$u,$scoreCourse,(string)($_GET['score_period']??'all'),(string)($_GET['score_search']??'')):null;
    view('dashboard/index', ['user'=>$u,'courses'=>$courses,'exams'=>[],'grades'=>[],'stats'=>$stats,'terms'=>$terms,'show_archived'=>$showArchived,'scoreReport'=>$scoreReport]);
    exit;
}

if ($route === 'dashboard' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && $u['role'] === 'admin') {
    $stats = [
        'active_users' => (int)$db->query('SELECT COUNT(*) FROM users WHERE last_activity_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE)')->fetchColumn(),
        'open_tasks' => (int)$db->query('SELECT COUNT(*) FROM tasks WHERE due_at IS NULL OR due_at>=NOW()')->fetchColumn(),
        'overdue' => (int)$db->query('SELECT COUNT(*) FROM tasks WHERE due_at<NOW()')->fetchColumn(),
        'users_total' => (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'students_total' => (int)$db->query('SELECT COUNT(*) FROM users WHERE role="student"')->fetchColumn(),
        'teachers_total' => (int)$db->query('SELECT COUNT(*) FROM users WHERE role="teacher"')->fetchColumn(),
        'courses_total' => (int)$db->query('SELECT COUNT(*) FROM courses')->fetchColumn(),
    ];
    view('dashboard/index', ['user'=>$u,'courses'=>\CmuLms\Course::forUser($u),'exams'=>[],'grades'=>[],'stats'=>$stats]);
    exit;
}

if ($route === 'task-update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = \CmuLms\Auth::role('teacher', 'admin');
    \CmuLms\View::checkCsrf();
    $id = (int)($_POST['task_id'] ?? 0);
    $st = $db->prepare('SELECT t.*, c.teacher_id FROM tasks t JOIN courses c ON c.id=t.course_id WHERE t.id=?');
    $st->execute([$id]);
    $task = $st->fetch();
    if (!$task || ($actor['role'] === 'teacher' && (int)$task['teacher_id'] !== (int)$actor['id'])) exit('Activity not found');
    $db->prepare('UPDATE tasks SET title=?, description=?, due_at=?, submissions_open=?, updated_at=NOW() WHERE id=?')
        ->execute([trim($_POST['title'] ?? ''), trim($_POST['description'] ?? ''), $_POST['due_at'] ?: null, !empty($_POST['submissions_open']), $id]);
    $_SESSION['flash'] = 'Activity updated.';
    \CmuLms\Http::redirect('?route=course&id='.(int)$task['course_id']);
}

/* Laravel-compatible aliases for activity controls and direct course links. */
if ($route === 'task-submissions-status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = \CmuLms\Auth::role('teacher', 'admin');
    \CmuLms\View::checkCsrf();
    $id = (int)($_POST['task_id'] ?? 0);
    $st = $db->prepare('SELECT t.*,c.teacher_id FROM tasks t JOIN courses c ON c.id=t.course_id WHERE t.id=?');
    $st->execute([$id]); $task = $st->fetch();
    if (!$task || ($actor['role']==='teacher' && (int)$task['teacher_id'] !== (int)$actor['id'])) exit('Activity not found.');
    $open = !empty($_POST['submissions_open']) ? 1 : 0;
    $db->prepare('UPDATE tasks SET submissions_open=?,updated_at=NOW() WHERE id=?')->execute([$open,$id]);
    $_SESSION['flash'] = $open ? 'Submissions reopened.' : 'Submissions closed.';
    \CmuLms\Http::redirect('?route=course&id='.(int)$task['course_id']);
}

if ($route === 'course-activity' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && in_array($u['role'],['teacher','admin'],true)) {
    $courseId=(int)($_GET['course_id']??$_GET['id']??0); $st=$db->prepare('SELECT id,code,title,teacher_id FROM courses WHERE id=? AND (teacher_id=? OR ?="admin")');$st->execute([$courseId,$u['id'],$u['role']]);$course=$st->fetch();if(!$course){http_response_code(404);exit('Course unavailable.');}
    $st=$db->prepare('SELECT u.id,u.name,u.id_number,u.section_code,u.last_login_at,u.last_activity_at,u.last_logout_at,u.disabled_at FROM users u JOIN enrollments e ON e.user_id=u.id WHERE e.course_id=? AND e.status="active" AND u.role="student" ORDER BY u.last_name,u.name');$st->execute([$courseId]);$students=$st->fetchAll();$cutoff=time()-300;foreach($students as &$student)$student['online']=!$student['disabled_at']&&!empty($student['last_activity_at'])&&strtotime($student['last_activity_at'])>=$cutoff;unset($student);
    $st=$db->prepare('SELECT a.id,a.status,a.attempt_number,a.started_at,a.ends_at,a.submitted_at,a.violation_count,u.id student_id,u.name student_name,u.id_number,e.title exam_title,e.question_count,COUNT(DISTINCT ans.id) answers_saved FROM exam_attempts a JOIN exams e ON e.id=a.exam_id JOIN users u ON u.id=a.student_id LEFT JOIN exam_answers ans ON ans.attempt_id=a.id WHERE e.course_id=? GROUP BY a.id ORDER BY a.started_at DESC');$st->execute([$courseId]);$progress=[];foreach($st as $attempt){$total=max(1,(int)$attempt['question_count']);$end=$attempt['submitted_at']?strtotime($attempt['submitted_at']):min(time(),strtotime($attempt['ends_at']));$progress[]=$attempt+['progress_percent'=>round(((int)$attempt['answers_saved']/$total)*100,1),'minutes_taken'=>round(max(0,$end-strtotime($attempt['started_at']))/60,1),'time_remaining'=>$attempt['status']==='in_progress'?max(0,strtotime($attempt['ends_at'])-time()):0];}
    if(($_GET['format']??'')==='html'){view('courses/activity',['user'=>$u,'course'=>$course,'students'=>$students,'progress'=>$progress]);exit;}
    header('Content-Type: application/json');echo json_encode(['course'=>$course,'students'=>$students,'quiz_progress'=>$progress]);exit;
}

if ($route === 'exam-resume' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && $u['role']==='student') {
    $examId=(int)($_GET['exam_id'] ?? $_GET['id'] ?? 0);
    $st=$db->prepare('SELECT id FROM exam_attempts WHERE exam_id=? AND student_id=? AND status="in_progress" ORDER BY id DESC LIMIT 1');
    $st->execute([$examId,$u['id']]);
    if ($attempt=$st->fetchColumn()) \CmuLms\Http::redirect('?route=exam&attempt='.(int)$attempt);
    \CmuLms\Http::redirect('?route=exam-start&exam_id='.$examId);
}

if ($route === 'announcement-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST['content_type']='announcement'; $_POST['content_id']=(int)($_POST['announcement_id'] ?? 0);
    $route='content-delete';
}

if ($route === 'content-delete' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'],['teacher','admin'],true)) {
    \CmuLms\View::checkCsrf();
    $type=(string)($_POST['content_type'] ?? '');
    $id=(int)($_POST['content_id'] ?? 0);
    if (!in_array($type,['announcement','task','attendance'],true)) exit('Unsupported content type.');
    $table=['announcement'=>'announcements','task'=>'tasks','attendance'=>'attendance_sessions'][$type];
    $st=$db->prepare("SELECT x.*,c.teacher_id FROM {$table} x JOIN courses c ON c.id=x.course_id WHERE x.id=? AND (c.teacher_id=? OR ?=\"admin\")");
    $st->execute([$id,$u['id'],$u['role']]); $item=$st->fetch();
    if (!$item) exit(ucfirst($type).' not found.');
    if ($type==='attendance') {
        $count=$db->prepare('SELECT COUNT(*) FROM attendance_submissions WHERE session_id=?'); $count->execute([$id]);
        if ((int)$count->fetchColumn()>0) { $_SESSION['error']='This attendance session has check-ins and cannot be deleted. Disable or close it instead.'; \CmuLms\Http::redirect('?route=course&id='.(int)$item['course_id']); }
    }
    $db->beginTransaction();
    try {
        if ($type==='announcement') {
            $files=$db->prepare('SELECT file_path FROM announcement_attachments WHERE announcement_id=?'); $files->execute([$id]);
            foreach ($files as $file) { $path=__DIR__.'/storage/announcements/'.basename((string)$file['file_path']); if (is_file($path)) @unlink($path); }
            $db->prepare('DELETE FROM announcement_comments WHERE announcement_id=?')->execute([$id]);
            $db->prepare('DELETE FROM announcement_attachments WHERE announcement_id=?')->execute([$id]);
        } elseif ($type==='task') {
            $files=$db->prepare('SELECT file_path FROM task_submissions WHERE task_id=?'); $files->execute([$id]);
            foreach ($files as $file) { if (!empty($file['file_path'])) { $path=__DIR__.'/storage/submissions/'.basename((string)$file['file_path']); if (is_file($path)) @unlink($path); } }
            $db->prepare('DELETE FROM task_submissions WHERE task_id=?')->execute([$id]);
        }
        $db->prepare("DELETE FROM {$table} WHERE id=?")->execute([$id]);
        $db->commit();
    } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    $_SESSION['flash']=ucfirst($type).' deleted.';
    \CmuLms\Http::redirect('?route=course&id='.(int)$item['course_id']);
}

if ($route === 'attendance-delete' && $_SERVER['REQUEST_METHOD'] === 'POST' && $u && in_array($u['role'],['teacher','admin'],true)) {
    \CmuLms\View::checkCsrf();$id=(int)($_POST['session_id']??0);$st=$db->prepare('SELECT a.*,c.teacher_id FROM attendance_sessions a JOIN courses c ON c.id=a.course_id WHERE a.id=? AND (c.teacher_id=? OR ?="admin")');$st->execute([$id,$u['id'],$u['role']]);$session=$st->fetch();if(!$session)exit('Attendance session not found.');$st=$db->prepare('SELECT COUNT(*) FROM attendance_submissions WHERE session_id=?');$st->execute([$id]);if((int)$st->fetchColumn()>0){$_SESSION['error']='This attendance session has check-ins and cannot be deleted. Disable or close it instead.';\CmuLms\Http::redirect('?route=course&id='.(int)$session['course_id']);}$db->prepare('DELETE FROM attendance_sessions WHERE id=?')->execute([$id]);$_SESSION['flash']='Attendance session deleted.';\CmuLms\Http::redirect('?route=course&id='.(int)$session['course_id']);
}

if ($route === 'announcement-visibility' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = \CmuLms\Auth::role('teacher', 'admin');
    \CmuLms\View::checkCsrf();
    $id=(int)($_POST['announcement_id']??0);$st=$db->prepare('SELECT a.course_id,c.teacher_id FROM announcements a JOIN courses c ON c.id=a.course_id WHERE a.id=? AND (c.teacher_id=? OR ?="admin")');$st->execute([$id,$actor['id'],$actor['role']]);$announcement=$st->fetch();if(!$announcement)exit('Announcement not found.');
    $db->prepare('UPDATE announcements SET is_visible=?,updated_at=NOW() WHERE id=?')->execute([!empty($_POST['is_visible'])?1:0,$id]);$_SESSION['flash']=!empty($_POST['is_visible'])?'Announcement shown to students.':'Announcement hidden from students.';\CmuLms\Http::redirect('?route=course&id='.(int)$announcement['course_id']);
}

if ($route === 'announcement-update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = \CmuLms\Auth::role('teacher', 'admin');
    \CmuLms\View::checkCsrf();
    $id = (int)($_POST['announcement_id'] ?? 0);
    $st = $db->prepare('SELECT a.*, c.teacher_id FROM announcements a JOIN courses c ON c.id=a.course_id WHERE a.id=?');
    $st->execute([$id]);
    $announcement = $st->fetch();
    if (!$announcement || ($actor['role'] === 'teacher' && (int)$announcement['teacher_id'] !== (int)$actor['id'])) exit('Announcement not found');
    $db->prepare('UPDATE announcements SET title=?, body=?, publish_at=?, is_visible=?, updated_at=NOW() WHERE id=?')
        ->execute([trim($_POST['title'] ?? ''), trim($_POST['body'] ?? ''), $_POST['publish_at'] ?: null, !empty($_POST['is_visible']), $id]);
    $_SESSION['flash'] = 'Announcement updated.';
    \CmuLms\Http::redirect('?route=course&id='.(int)$announcement['course_id']);
}

if ($route === 'attendance-update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $actor = \CmuLms\Auth::role('teacher', 'admin');
    \CmuLms\View::checkCsrf();
    $id = (int)($_POST['session_id'] ?? 0);
    $st = $db->prepare('SELECT a.*, c.teacher_id FROM attendance_sessions a JOIN courses c ON c.id=a.course_id WHERE a.id=?');
    $st->execute([$id]);
    $session = $st->fetch();
    if (!$session || ($actor['role'] === 'teacher' && (int)$session['teacher_id'] !== (int)$actor['id'])) exit('Attendance session not found');
    $code = trim($_POST['access_code'] ?? '');
    $hash = $code !== '' ? password_hash($code, PASSWORD_DEFAULT) : $session['code_hash'];
    $db->prepare('UPDATE attendance_sessions SET title=?, opens_at=?, closes_at=?, code_required=?, code_hash=?, is_enabled=?, updated_at=NOW() WHERE id=?')
        ->execute([trim($_POST['title'] ?? ''), $_POST['opens_at'], $_POST['closes_at'], !empty($_POST['code_required']), $hash, !empty($_POST['is_enabled']), $id]);
    $_SESSION['flash'] = 'Attendance settings updated.';
    \CmuLms\Http::redirect('?route=course&id='.(int)$session['course_id']);
}

/* Timed attempts are graded once when the expired attempt is first revisited. */
if ($route === 'exam' && $_SERVER['REQUEST_METHOD'] === 'GET' && $u && $u['role'] === 'student') {
    $id = (int)($_GET['attempt'] ?? 0);
    $st = $db->prepare('SELECT a.*, e.course_id, e.title FROM exam_attempts a JOIN exams e ON e.id=a.exam_id WHERE a.id=? AND a.student_id=? AND a.status="in_progress" AND a.ends_at<=NOW()');
    $st->execute([$id, $u['id']]);
    $expired = $st->fetch();
    if ($expired) {
        $ids = array_map('intval', json_decode($expired['question_ids'] ?? '[]', true) ?: []);
        $score = 0; $max = 0;
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $qst = $db->prepare("SELECT id, answer, points FROM questions WHERE id IN ($marks)");
            $qst->execute($ids);
            $ansSt = $db->prepare('SELECT question_id, answer FROM exam_answers WHERE attempt_id=?');
            $ansSt->execute([$id]);
            $saved = [];
            foreach ($ansSt as $answer) $saved[$answer['question_id']] = json_decode($answer['answer'], true)[0] ?? null;
            foreach ($qst as $question) {
                $correct = json_decode($question['answer'], true) ?: [];
                $points = (int)$question['points']; $max += $points;
                if (isset($saved[$question['id']]) && in_array($saved[$question['id']], $correct, true)) $score += $points;
            }
        }
        $db->prepare('UPDATE exam_attempts SET status="submitted", submitted_at=NOW(), updated_at=NOW() WHERE id=? AND status="in_progress"')->execute([$id]);
        $db->prepare('INSERT INTO grades(course_id,student_id,title,category,score,max_score,released,graded_at,created_at,updated_at) VALUES(?,?,?,?,?,?,1,NOW(),NOW(),NOW())')
            ->execute([$expired['course_id'], $u['id'], $expired['title'], 'quiz', $score, $max]);
    }
}
