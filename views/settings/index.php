<?php use CmuLms\View; $title='Settings'; include __DIR__.'/../layout/header.php'; $hasKey=!empty($user['grok_api_key']); ?>
<section class="card">
<p class="eyebrow">School profile</p><h1>Your account</h1>
<div class="profile-grid-php">
  <div><small>School ID / username</small><strong><?=View::e($user['id_number']??'—')?></strong></div>
  <div><small>Name</small><strong><?=View::e($user['name']??'—')?></strong></div>
  <div><small>Role</small><strong><?=View::e(ucfirst($user['role']??'—'))?></strong></div>
  <div><small>Section</small><strong><?=View::e($user['section_code']??'—')?></strong></div>
  <div><small>Year level</small><strong><?=View::e($user['year_level']??'—')?></strong></div>
  <div><small>Course / program</small><strong><?=View::e($user['course']??'—')?></strong></div>
</div>
</section>
<section class="card"><p class="eyebrow">Account settings</p><h2>Change password</h2><p class="muted">Update your sign-in password from the secure password form.</p><a class="btn" href="?route=password-change">Change password</a></section>
<?php if (($user['role']??'') !== 'teacher' && ($user['role']??'') !== 'admin'): ?>
<style>.profile-grid-php{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.profile-grid-php div{background:#f2f7f2;border:1px solid var(--line);border-radius:9px;padding:12px;display:grid;gap:5px}.profile-grid-php small{color:#718078}.profile-grid-php strong{overflow-wrap:anywhere}@media(max-width:650px){.profile-grid-php{grid-template-columns:1fr 1fr}}@media(max-width:420px){.profile-grid-php{grid-template-columns:1fr}}</style>
<?php endif; ?>
<?php if (($user['role']??'') === 'teacher' || ($user['role']??'') === 'admin'): ?>
<section class="card">
<h1>Teacher settings</h1>
<p>Your provider key is stored for your account and is used only when you request AI quiz generation.</p>
<p><span class="badge"><?= $hasKey ? 'Groq key configured' : 'Groq key not configured' ?></span></p>
<form method="post" action="?route=save-settings">
<?=View::csrfField()?>
<label>Groq API key<input type="password" name="grok_api_key" value="" placeholder="<?=$hasKey?'Enter a new key to replace the saved key':'Paste your Groq API key'?>" autocomplete="new-password"></label>
<button>Save provider key</button>
</form>
<?php if($hasKey): ?><form method="post" action="?route=save-settings"><?=View::csrfField()?><input type="hidden" name="grok_api_key" value=""><input type="hidden" name="remove_key" value="1"><button type="submit">Remove saved key</button></form><?php endif; ?>
</section>
<?php endif; ?>
<?php include __DIR__.'/../layout/footer.php'; ?>
