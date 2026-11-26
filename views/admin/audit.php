<?php use CmuLms\View; $title='Audit log'; include __DIR__.'/../layout/header.php'; ?>
<section class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
    <div><h1>Audit log</h1><p>Read-only security and activity history for the LMS.</p></div>
    <span class="badge"><?=View::e($total??0)?> records</span>
  </div>
  <form method="get" class="grid" style="align-items:end">
    <input type="hidden" name="route" value="admin-audit">
    <label>Search action, user, ID, or IP<input name="q" value="<?=View::e($q??'')?>" placeholder="e.g. login, exam-edit, 192.168..."></label>
    <label>Rows<select name="per_page"><option value="10" <?=($per_page??25)===10?'selected':''?>>10</option><option value="25" <?=($per_page??25)===25?'selected':''?>>25</option><option value="50" <?=($per_page??25)===50?'selected':''?>>50</option><option value="100" <?=($per_page??25)===100?'selected':''?>>100</option></select></label>
    <button>Filter</button>
  </form>
  <div style="overflow-x:auto"><table><thead><tr><th>When</th><th>User</th><th>Action</th><th>Target</th><th>IP</th><th>Details</th></tr></thead><tbody>
  <?php foreach(($logs??[]) as $log): $meta=json_decode($log['metadata']??'',true)?:[]; ?>
    <tr><td><?=View::e($log['created_at'])?></td><td><?=View::e($log['name']??'System')?><?php if(!empty($log['id_number'])): ?><br><small><?=View::e($log['id_number'])?></small><?php endif; ?></td><td><span class="badge"><?=View::e($log['action'])?></span></td><td><?=View::e($log['auditable_type']??'')?><?php if(!empty($log['auditable_id'])): ?> #<?=View::e($log['auditable_id'])?><?php endif; ?></td><td><?=View::e($log['ip_address']??'—')?></td><td><small><?=View::e(json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))?></small></td></tr>
  <?php endforeach; if(empty($logs)): ?><tr><td colspan="6">No audit records found.</td></tr><?php endif; ?>
  </tbody></table></div>
  <p><?php if(($page??1)>1): ?><a class="btn" href="?route=admin-audit&amp;q=<?=urlencode($q??'')?>&amp;per_page=<?=$per_page?>&amp;page=<?=$page-1?>">Previous</a><?php endif; ?> Page <?=View::e($page??1)?> of <?=View::e($pages??1)?> <?php if(($page??1)<($pages??1)): ?><a class="btn" href="?route=admin-audit&amp;q=<?=urlencode($q??'')?>&amp;per_page=<?=$per_page?>&amp;page=<?=$page+1?>">Next</a><?php endif; ?></p>
</section>
<?php include __DIR__.'/../layout/footer.php'; ?>
