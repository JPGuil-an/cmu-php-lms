<?php use CmuLms\View;$title='Reset password';include __DIR__.'/../layout/header.php';?>
<section class="card" style="max-width:460px;margin:40px auto">
<p class="muted" style="font-size:12px;letter-spacing:.08em">ACCOUNT RECOVERY · STEP 2 OF 2</p>
<h1>Set a new password</h1><p>Your school ID and last name matched. Complete this step within 5 minutes. After saving, sign in again with your new password.</p>
<form method="post" action="?route=reset-password"><?=View::csrfField()?>
<div class="field"><label for="recoveryPassword">New password</label><input id="recoveryPassword" type="password" name="new_password" autocomplete="new-password" minlength="8" maxlength="72" aria-describedby="passwordHint" required><small id="passwordHint" class="muted">At least 8 characters. Choose a password different from your current password.</small></div>
<div class="field"><label for="recoveryConfirm">Confirm new password</label><input id="recoveryConfirm" type="password" name="new_password_confirmation" autocomplete="new-password" minlength="8" maxlength="72" required></div>
<button type="submit">Reset password</button>
</form><p class="muted" style="font-size:13px">Resetting your password signs your account out of other sessions. It does not change enrollments, exam attempts, or grades.</p>
<p style="margin:20px 0 0;text-align:center"><a href="?route=forgot-password" style="color:var(--green)">Start again</a> · <a href="?route=login" style="color:var(--green)">Back to sign in</a></p>
</section><?php include __DIR__.'/../layout/footer.php';?>
