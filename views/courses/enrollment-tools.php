<?php use CmuLms\View; ?>
<details class="card enrollment-tools" id="student-import">
 <summary class="enrollment-tools-heading"><span><strong>Add students</strong><small>Import CSV, add manually, or enroll from another subject</small></span><span aria-hidden="true">＋</span></summary>
 <div class="enrollment-tools-body">
  <p class="muted">Destination: <strong><?=View::e($course['code'])?> · <?=View::e($course['section_code']??'')?></strong></p>
  <label for="enrollmentMethod">Enrollment method</label>
  <select id="enrollmentMethod"><option value="">Choose a method…</option value="csv">Import a CSV file</option><option value="manual">Add one student manually</option><option value="subject">Enroll from another subject</option></select>
  <div data-enrollment-panel="csv" hidden>
   <form method="post" action="?route=student-import&amp;id=<?=(int)$course['id']?>" enctype="multipart/form-data"><?=View::csrfField()?><input type="hidden" name="course_id" value="<?=(int)$course['id']?>"><label>CSV file<input type="file" name="file" accept=".csv" required></label><button>Preview CSV</button></form>
   <details class="csv-help"><summary>CSV format and enrollment details</summary><p class="muted">Columns: first_name, middle_initial, last_name, id_number, section_code, year_level, course. Students enroll in <?=View::e($course['code'])?>. The CSV section does not change the destination, and other class rosters are preserved.</p></details>
  </div>
  <div data-enrollment-panel="manual" hidden>
   <form method="post" action="?route=student-manual"><?=View::csrfField()?><input type="hidden" name="course_id" value="<?=(int)$course['id']?>"><div class="manual-student-grid"><label>Full name<input name="name" autocomplete="off" required></label><label>School ID number<input name="id_number" required></label><label>Section<input name="section_code" placeholder="Optional"></label><label>Year level<input name="year_level" placeholder="Optional"></label><label>Course<input name="course_name" placeholder="Optional"></label></div><button>Add and enroll student</button></form>
  </div>
  <div data-enrollment-panel="subject" hidden><?php $enrollmentEmbedded=true; include __DIR__.'/enroll-from-subject.php'; unset($enrollmentEmbedded); ?></div>
 </div>
</details>
<style>
.enrollment-tools>summary{display:flex;justify-content:space-between;align-items:center;gap:12px;list-style:none;cursor:pointer}.enrollment-tools>summary::-webkit-details-marker{display:none}.enrollment-tools-heading>span:first-child{display:grid;gap:5px}.enrollment-tools-heading strong{font-size:18px}.enrollment-tools-heading small{font-size:12px;color:#6b7d73}.enrollment-tools[open]>.enrollment-tools-heading{padding-bottom:16px;border-bottom:1px solid var(--line)}.enrollment-tools[open]>.enrollment-tools-heading>span:last-child{transform:rotate(45deg)}.enrollment-tools-body>label{display:block;font-size:13px;margin:12px 0 6px}.enrollment-tools-body>select{max-width:440px}.enrollment-tools [data-enrollment-panel]{margin-top:18px}.enrollment-tools [hidden]{display:none!important}.enrollment-tools form label{display:grid;gap:5px;font-size:13px}.enrollment-tools form button{width:auto;justify-self:start}.enrollment-tools .manual-student-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.enrollment-tools .csv-help{margin-top:14px;font-size:12px}.enrollment-tools .enrollment-embedded{margin:0;padding:0;border:0;box-shadow:none}.enrollment-tools .enrollment-embedded>h2,.enrollment-tools .enrollment-embedded>p:first-of-type:not(:only-of-type){display:none}.enrollment-tools .enrollment-embedded>p:last-of-type{font-size:12px}.enrollment-tools .enrollment-table-wrap{max-height:340px;overflow:auto}@media(max-width:600px){.enrollment-tools .manual-student-grid{grid-template-columns:1fr}.enrollment-tools-body>select{max-width:none}}
</style>
<script>
(()=>{const root=document.getElementById('student-import'),method=document.getElementById('enrollmentMethod'),panels=root.querySelectorAll('[data-enrollment-panel]');
 function show(){panels.forEach(panel=>panel.hidden=panel.dataset.enrollmentPanel!==method.value);}
 method.addEventListener('change',show);
 function followLink(){if(location.hash==='#enroll-from-subject'){root.open=true;method.value='subject';show();}else if(location.hash==='#student-import'){root.open=true;if(!method.value)method.value='csv';show();}}
 window.addEventListener('hashchange',followLink);followLink();
})();
</script>
