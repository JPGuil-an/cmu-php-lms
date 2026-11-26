<?php
use CmuLms\View;
$title=$title??'CMU LMS';
$u=$user??null;
$currentRoute=$_GET['route']??'dashboard';
$navCourses=($u && in_array($u['role']??'', ['student','teacher'], true)) ? \CmuLms\Course::forUser($u) : [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=View::e($title)?> · CMU LMS</title>
<style>
:root{--ink:#143f35;--deep:#124b3f;--green:#236b59;--mint:#e4f1e6;--line:#d5e3dc;--paper:#f4f7f5;--sidebar:250px}*{box-sizing:border-box}html{overflow-x:hidden}body{margin:0;background:var(--paper);color:var(--ink);font:15px Arial,sans-serif;overflow-x:hidden}.site-sidebar{position:fixed;inset:0 auto 0 0;width:var(--sidebar);z-index:30;background:var(--deep);color:#fff;padding:25px 20px;display:flex;flex-direction:column;gap:7px}.site-brand{color:#fff;font-size:27px;font-weight:bold;text-decoration:none;letter-spacing:-.04em;margin-bottom:8px}.site-context{color:#b8d8cb;font-size:12px;line-height:1.45;margin:0 0 26px}.site-sidebar nav{display:flex;flex-direction:column;gap:5px}.site-sidebar nav a{color:#e5f4ec;text-decoration:none;padding:12px 14px;border-radius:9px;white-space:nowrap}.site-sidebar nav a:hover,.site-sidebar nav a.active{background:#2d715e;color:#fff}.site-sidebar .sidebar-footer{margin-top:auto;border-top:1px solid rgba(255,255,255,.18);padding-top:16px;color:#b8d8cb;font-size:12px}.site-topbar{position:fixed;top:0;right:0;left:var(--sidebar);height:68px;z-index:20;background:var(--deep);color:#fff;padding:0 clamp(16px,4vw,54px);display:flex;align-items:center;justify-content:flex-end;gap:10px}.topbar-menu{display:none;margin-right:auto;background:transparent;border:1px solid rgba(255,255,255,.4);color:#fff;padding:8px 11px;min-height:38px;width:auto}.topbar-actions{display:flex;align-items:center;gap:9px}.topbar-actions a{color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.22);border-radius:8px;padding:9px 11px;white-space:nowrap}.topbar-user{color:#d5ebe1;white-space:nowrap}.topbar-logout{display:inline-flex;margin:0}.topbar-logout button{background:#fff;color:var(--deep);min-height:38px;width:auto}.sidebar-backdrop{display:none}main{width:100%;min-height:calc(100vh - 68px);margin:0;padding:94px clamp(18px,4vw,58px) 36px max(28px,calc(var(--sidebar) + 4vw));}main>section,main>.grid,main>h1,main>h2,main>h3,main>div:not(.exam-page){width:min(1120px,100%);margin-left:auto;margin-right:auto}.hero,.card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:22px;margin-bottom:17px;overflow-wrap:anywhere;box-shadow:0 2px 6px rgba(20,63,53,.035)}.hero{background:linear-gradient(135deg,#185a49,#2c775f);color:#fff;border:0}.hero h1{overflow-wrap:anywhere}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr));gap:16px}.btn,button{background:var(--green);color:#fff;border:0;border-radius:7px;padding:10px 14px;min-height:42px;font-weight:bold;cursor:pointer;text-decoration:none;max-width:100%}.field{display:grid;gap:6px;margin:12px 0}.field input,.field textarea,input,textarea,select{width:100%;min-width:0;padding:11px;border:1px solid #c9d9d1;border-radius:8px;font:inherit;max-width:100%}textarea{min-height:80px;resize:vertical}.badge{background:#e0f1e6;color:#1a6754;border-radius:99px;padding:5px 9px;font-size:12px;font-weight:bold;display:inline-block}.error,.flash{width:min(1120px,100%);margin:0 auto 16px;padding:12px;border-radius:8px}.error{background:#ffe7e4}.flash{background:#ddf2e4}.options label{display:block;border:1px solid #d2e0d9;border-radius:8px;padding:12px;margin:8px 0}table{width:100%;border-collapse:collapse;background:#fff}th,td{text-align:left;border-bottom:1px solid var(--line);padding:10px;vertical-align:top}form{display:grid;gap:10px}.muted{color:#62766e}footer{margin-left:var(--sidebar)}
@media(max-width:760px){:root{--sidebar:250px}.site-sidebar{transform:translateX(-102%);transition:transform .2s ease;box-shadow:8px 0 24px rgba(0,0,0,.18)}body.sidebar-open .site-sidebar{transform:translateX(0)}.sidebar-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:25}.site-sidebar~.sidebar-backdrop{display:none}body.sidebar-open .site-sidebar~.sidebar-backdrop{display:block}.site-topbar{left:0;height:62px;padding:0 12px;justify-content:space-between}.topbar-menu{display:block}.topbar-actions{margin-left:auto;min-width:0}.topbar-actions a{font-size:0;padding:9px 10px}.topbar-actions a:first-child:before{content:'🔔';font-size:16px}.topbar-actions a:nth-child(2):before{content:'✉';font-size:16px}.topbar-user{display:none}.topbar-logout button{font-size:0;padding:9px 10px}.topbar-logout button:before{content:'↪';font-size:18px}main{min-height:calc(100vh - 62px);padding:78px 10px 25px;margin:0}main>section,main>.grid,main>h1,main>h2,main>h3,main>div:not(.exam-page),.error,.flash{width:100%}.hero,.card{padding:16px}.hero h1{font-size:25px}.grid{grid-template-columns:1fr}table{font-size:13px;display:block;overflow-x:auto;white-space:nowrap}th,td{padding:9px}.btn,button{width:100%}.topbar-logout button{width:auto}footer{margin-left:0;font-size:12px;padding:20px 10px!important}}
@media(max-width:420px){body{font-size:14px}.hero h1{font-size:22px}h1{font-size:24px}h2{font-size:20px}h3{font-size:17px}.card{border-radius:11px;padding:13px;margin-bottom:12px}.topbar-actions{gap:5px}}
</style>
<style>
.nav-class-heading{display:flex;align-items:center;gap:6px}.nav-class-heading>a{flex:1}.nav-class-heading>span{color:#b8d8cb;padding-right:8px}.nav-subjects{display:grid;gap:2px;margin:0 0 7px 12px;padding-left:10px;border-left:1px solid rgba(255,255,255,.25)}.nav-subjects a{display:grid;padding:7px 9px;border-radius:7px;color:#d3eae0;text-decoration:none}.nav-subjects a:hover,.nav-subjects a.active{background:#2d715e;color:#fff}.nav-subjects strong{font-size:11px;color:#f1c47c}.nav-subjects small{font-size:10px;color:#b8d8cb;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.site-topbar.guest{left:0}.role-guest main{padding-left:clamp(18px,4vw,58px)}
form.card{display:flex;flex-direction:column;align-items:stretch;min-width:0}form.card>input,form.card>textarea,form.card>select{flex:none;align-self:stretch}form.card>textarea{min-height:88px}form.card button,.card form button{width:auto;align-self:flex-start;min-height:38px;padding:9px 13px}.card form{min-width:0}.card form input,.card form textarea,.card form select{font-size:14px}.grid>form.card{height:100%}.grid>form.card button{margin-top:auto}.quiz-tools-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px;margin-bottom:17px}.quiz-tool-card{margin-bottom:0}.quiz-tool-card h2{font-size:22px;margin-top:0}.quiz-tool-card form{gap:10px}.quiz-tool-card form button{margin-top:4px}.question-bank-picker{border:1px solid var(--line);background:#f8fbf8;border-radius:11px;padding:14px}.question-bank-picker label{display:block;font-size:13px;font-weight:bold;margin-bottom:7px}.question-bank-picker-row{display:flex;align-items:center;gap:10px}.question-bank-picker-row select{flex:1;min-width:0}.question-bank-review{white-space:nowrap}.question-bank-review.disabled{opacity:.55;pointer-events:none}@media(max-width:820px){.quiz-tools-grid{grid-template-columns:1fr}.quiz-tool-card{margin-bottom:0}}@media(max-width:520px){.question-bank-picker-row{align-items:stretch;flex-direction:column}.question-bank-review{width:100%;text-align:center}}
</style>
<style>.quiz-description-php{display:grid;gap:4px;margin:10px 0}.quiz-description-php span{font-size:13px;color:#62766e}.quiz-score-php{color:#1c654f;font-weight:bold}.quiz-retake-approved-php{display:block;color:#17634e;font-size:13px;font-weight:700;margin-top:5px}</style>
</head>
<body class="role-<?=View::e($u['role']??'guest')?>">
<?php if($u): ?>
<aside class="site-sidebar" id="siteSidebar">
  <a class="site-brand" href="?route=dashboard">CMU·LMS</a>
  <p class="site-context">Central Mindanao University<br>CISC · JP Guil-an</p>
  <nav aria-label="Sidebar navigation">
    <a class="<?=$currentRoute==='dashboard'?'active':''?>" href="?route=dashboard">⌂ &nbsp; Overview</a>
    <div class="nav-class-heading"><a class="<?=$currentRoute==='classes'||$currentRoute==='course'?'active':''?>" href="?route=classes">▦ &nbsp; Classes</a><span>−</span></div>
    <?php if($navCourses): ?><div class="nav-subjects"><?php foreach($navCourses as $navCourse): ?><a class="<?=($currentRoute==='course' && (int)($_GET['id']??0)===(int)$navCourse['id'])?'active':''?>" href="?route=course&amp;id=<?=View::e($navCourse['id'])?>"><strong><?=View::e($navCourse['code'])?></strong><small><?=View::e($navCourse['title'])?></small></a><?php endforeach; ?></div><?php endif; ?>
    <a class="<?=$currentRoute==='calendar'?'active':''?>" href="?route=calendar">◷ &nbsp; Calendar</a>
    <a class="<?=$currentRoute==='gradebook'?'active':''?>" href="?route=gradebook">▤ &nbsp; Gradebook</a>
    <a class="<?=$currentRoute==='settings'?'active':''?>" href="?route=settings">⚙ &nbsp; Settings</a>
  </nav>
  <div class="sidebar-footer"><?=View::e(ucfirst($u['role']))?><br><?=View::e($u['name']??'')?></div>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<?php endif; ?>
<header class="site-topbar<?=$u ? '' : ' guest'?>">
<?php if($u): ?><button type="button" class="topbar-menu" id="menuToggle" aria-label="Toggle navigation">☰</button><?php endif; ?>
<?php if($u):
    $unreadNotifications=CmuLms\Notification::unread((int)$u['id']);
    $unreadMessages=CmuLms\Message::unread((int)$u['id']);
?>
<div class="topbar-actions">
  <a href="?route=notifications" aria-label="Notifications">Notifications<?= $unreadNotifications?' ('.$unreadNotifications.')':'' ?></a>
  <a href="?route=messages" aria-label="Messages">Messages<?= $unreadMessages?' ('.$unreadMessages.')':'' ?></a>
  <a class="topbar-user" href="?route=dashboard"><?=View::e($u['name']??'')?></a>
  <form method="post" class="topbar-logout" onsubmit="return confirm('Are you sure you want to sign out?')"><?=View::csrfField()?><input type="hidden" name="_action" value="logout"><button type="submit">Sign out</button></form>
</div>
<?php endif; ?>
</header>
<main>
<?php if(!empty($_SESSION['error'])):?><div class="error"><?=View::e($_SESSION['error']);unset($_SESSION['error']);?></div><?php endif; ?>
<?php if(!empty($_SESSION['flash'])):?><div class="flash"><?=View::e($_SESSION['flash']);unset($_SESSION['flash']);?></div><?php endif; ?>
<script>(function(){const t=document.getElementById('menuToggle'),b=document.getElementById('sidebarBackdrop');if(!t)return;function close(){document.body.classList.remove('sidebar-open')}t.addEventListener('click',()=>document.body.classList.toggle('sidebar-open'));b?.addEventListener('click',close);document.querySelectorAll('.site-sidebar a').forEach(a=>a.addEventListener('click',close));})();</script>
