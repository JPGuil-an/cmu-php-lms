<?php if(($user['role']??'')==='teacher' && !empty($announcements)): ?>
<details class="card">
  <summary><strong>Manage announcement visibility</strong></summary>
  <p class="muted">Hidden announcements remain visible to you but are not shown to students.</p>
  <?php foreach($announcements as $announcement): $visible=!empty($announcement['is_visible']); ?>
    <div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap;border-bottom:1px solid #d5e3dc;padding:10px 0">
      <span><strong><?=View::e($announcement['title'])?></strong> <span class="badge"><?=$visible?'Visible':'Hidden'?></span></span>
      <form method="post" action="?route=announcement-visibility" class="logout-form">
        <?=View::csrfField()?>
        <input type="hidden" name="announcement_id" value="<?=View::e($announcement['id'])?>">
        <input type="hidden" name="is_visible" value="<?=$visible?'0':'1'?>">
        <button type="submit"><?=$visible?'Hide from students':'Show to students'?></button>
      </form>
    </div>
  <?php endforeach; ?>
</details>
<?php endif; ?>
