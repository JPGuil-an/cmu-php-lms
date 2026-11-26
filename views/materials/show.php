<?php
use CmuLms\View;
$title=$material['title'];
$pages=json_decode($material['pages']??'[]',true)?:[];
$readState=$read_state??['open_count'=>0,'read_at'=>null];
include __DIR__.'/../layout/header.php';
?>
<section class="card">
  <h1><?=View::e($material['title'])?></h1>
  <p><?=View::e($material['description']??'')?></p>
  <p class="badge"><?=View::e(strtoupper($material['file_type']))?> material · <?=View::e($material['page_count']?:1)?> page<?=((int)($material['page_count']?:1)===1?'':'s')?></p>
  <?php if($pages): ?>
    <div class="card" id="slideViewer">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><strong id="pageLabel">Page 1 of <?=count($pages)?></strong><span><button type="button" id="prevPage">Back</button> <button type="button" id="nextPage">Next</button></span></div>
      <article id="pageText" tabindex="0" style="white-space:pre-wrap;min-height:260px;max-height:60vh;overflow:auto;padding:20px;border:1px solid #d5e3dc;border-radius:10px;background:#fff;margin-top:12px;user-select:text"></article>
    </div>
    <script>
      const materialPages=<?=json_encode(array_values($pages),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
      let materialPage=0;
      const pageText=document.getElementById('pageText'),pageLabel=document.getElementById('pageLabel'),prevPage=document.getElementById('prevPage'),nextPage=document.getElementById('nextPage');
      function renderMaterialPage(){pageText.textContent=materialPages[materialPage]||'This page contains no extractable text.';pageLabel.textContent='Page '+(materialPage+1)+' of '+materialPages.length;prevPage.disabled=materialPage===0;nextPage.disabled=materialPage===materialPages.length-1;}
      prevPage.onclick=()=>{if(materialPage>0){materialPage--;renderMaterialPage();}};
      nextPage.onclick=()=>{if(materialPage<materialPages.length-1){materialPage++;renderMaterialPage();}};
      renderMaterialPage();
    </script>
  <?php else: ?>
    <iframe title="Material viewer" src="?route=material-file&id=<?=(int)$material['id']?>" style="width:100%;height:70vh;border:1px solid #d5e3dc;border-radius:10px"></iframe>
  <?php endif; ?>
  <p class="muted">Text on extracted pages can be selected and copied. Read tracking is recorded in the database.</p>
  <?php if(($user['role']??'')==='teacher' || ($user['role']??'')==='admin'): ?>
    <details class="card"><summary>Edit material</summary>
      <form method="post" action="?route=material-update" class="grid"><?=View::csrfField()?>
        <input type="hidden" name="material_id" value="<?=View::e($material['id'])?>">
        <label>Title<input name="title" value="<?=View::e($material['title'])?>" required></label>
        <label>Description<input name="description" value="<?=View::e($material['description']??'')?>"></label>
        <label>Term<select name="academic_period"><option value="midterm" <?=($material['academic_period']??'')==='midterm'?'selected':''?>>Midterm</option><option value="final" <?=($material['academic_period']??'')==='final'?'selected':''?>>Final</option></select></label>
        <label>Opens at<input type="datetime-local" name="opens_at" value="<?=!empty($material['opens_at'])?View::e(date('Y-m-d\TH:i',strtotime($material['opens_at']))):''?>"></label>
        <label>Closes at<input type="datetime-local" name="closes_at" value="<?=!empty($material['closes_at'])?View::e(date('Y-m-d\TH:i',strtotime($material['closes_at']))):''?>"></label>
        <label><input type="checkbox" name="is_enabled" value="1" <?=!empty($material['is_enabled'])?'checked':''?>> Visible to students</label>
        <button>Save material</button>
      </form>
      <form method="post" action="?route=material-delete" onsubmit="return confirm('Delete this material?')"><?=View::csrfField()?><input type="hidden" name="material_id" value="<?=View::e($material['id'])?>"><button>Delete material</button></form>
    </details>
  <?php endif; ?>
  <?php if(($user['role']??'')==='student' && empty($readState['read_at']) && (int)($readState['open_count']??0)>=2): ?>
    <form method="post" action="?route=material-read"><?=View::csrfField()?><input type="hidden" name="material_id" value="<?=View::e($material['id'])?>"><label><input type="checkbox" name="confirm" required> I have read this material.</label><button>Mark as read</button></form>
  <?php elseif(($user['role']??'')==='student' && empty($readState['read_at'])): ?>
    <p class="muted">Open this material one more time to unlock the read confirmation.</p>
  <?php endif; ?>
</section>
<?php include __DIR__.'/../layout/footer.php'; ?>
