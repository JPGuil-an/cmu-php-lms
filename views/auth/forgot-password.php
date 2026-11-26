<?php use CmuLms\View;$title='Forgot password';include __DIR__.'/../layout/header.php';$old=$_SESSION['recovery_old']??[];unset($_SESSION['recovery_old']);$retry=max(0,(int)($_SESSION['recovery_retry_until']??0)-time());?>
<section class="card" style="max-width:460px;margin:40px auto">
<p class="muted" style="font-size:12px;letter-spacing:.08em">ACCOUNT RECOVERY · STEP 1 OF 2</p>
<h1>Forgot your password?</h1><p>Enter your school ID number and last name exactly as registered. This works for students, teachers, and administrators.</p>
<?php if($retry):?><p role="status" class="muted">Please wait <?=View::e((int)ceil($retry/60))?> minute(s) before trying again.</p><?php endif;?>
<form method="post" action="?route=forgot-password"><?=View::csrfField()?>
<div class="field"><label for="recoveryId">School ID number</label><input id="recoveryId" name="id_number" value="<?=View::e($old['id_number']??'')?>" autocomplete="username" maxlength="64" required></div>
<div class="field"><label for="recoveryLastName">Last name</label><input id="recoveryLastName" name="last_name" value="<?=View::e($old['last_name']??'')?>" autocomplete="family-name" maxlength="120" required></div>
<button type="submit">Verify account</button>
</form><p style="margin:20px 0 0;text-align:center"><a href="?route=login" style="color:var(--green)">Back to sign in</a></p>
</section><?php include __DIR__.'/../layout/footer.php';?>
