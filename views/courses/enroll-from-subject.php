<?php
use CmuLms\View;
$sourceCourses=array_values(array_filter(\CmuLms\Course::forUser($user),static fn($item)=>(int)$item['id']!==(int)$course['id']&&!empty($item['active'])));
?>
<section class="<?=!empty($enrollmentEmbedded)?'enrollment-embedded':'card'?>" id="enroll-from-subject">
<h2>Enroll from another subject</h2>
<p class="muted">Select existing students from one of your other subjects to enroll in <?=View::e($course['code'])?>.</p>
<?php if(!$sourceCourses): ?><p>No other active subjects are available.</p><?php else: ?>
<form method="post" action="?route=course-enroll-from-subject" id="subjectEnrollmentForm">
<?=View::csrfField()?><input type="hidden" name="course_id" value="<?=(int)$course['id']?>">
<label for="enrollmentSource">Source subject</label>
<select name="source_course_id" id="enrollmentSource" required><option value="">Choose another subject</option><?php foreach($sourceCourses as $sourceCourse): ?><option value="<?=(int)$sourceCourse['id']?>"><?=View::e($sourceCourse['code'].' · '.$sourceCourse['title'].' · '.($sourceCourse['section_code']??''))?></option><?php endforeach; ?></select>
<label for="enrollmentSearch">Find students</label><input type="search" id="enrollmentSearch" placeholder="Search name, school ID, or section" disabled>
<p class="muted" id="enrollmentStatus" role="status" aria-live="polite">Choose a subject to view eligible students.</p>
<div class="enrollment-table-wrap" hidden id="enrollmentTable"><table><thead><tr><th><input type="checkbox" id="enrollmentSelectPage" aria-label="Select all students on this page"></th><th>Student</th><th>School ID</th><th>Section</th></tr></thead><tbody id="enrollmentRows"></tbody></table></div>
<div class="enrollment-actions"><div class="enrollment-paging"><button type="button" id="enrollmentPrevious" disabled>Previous</button><span id="enrollmentPage"></span><button type="button" id="enrollmentNext" disabled>Next</button></div><div id="enrollmentSelectedInputs" hidden></div><button type="submit" id="enrollmentSubmit" disabled>Enroll selected students (0)</button></div>
</form><p class="muted">Already enrolled and disabled students are excluded. Select all selects this page. Selections are kept while paging. Existing accounts and enrollments are retained.</p>
<?php endif; ?>
</section>
<style>
.enrollment-table-wrap{overflow-x:auto}.enrollment-table-wrap input[type="checkbox"]{width:18px;height:18px;margin:0;padding:0;accent-color:var(--green)}.enrollment-table-wrap td:first-child,.enrollment-table-wrap th:first-child{width:42px}.enrollment-actions,.enrollment-paging{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.enrollment-actions{justify-content:space-between}.enrollment-actions button{width:auto;min-height:38px}.enrollment-actions button:disabled{opacity:.5;cursor:not-allowed}#enrollmentSubmit{margin:0}@media(max-width:600px){.enrollment-actions{align-items:stretch;flex-direction:column}.enrollment-actions #enrollmentSubmit{width:100%}}
</style>
<script>
(() => {
const form=document.getElementById('subjectEnrollmentForm');if(!form)return;
const source=document.getElementById('enrollmentSource'),search=document.getElementById('enrollmentSearch'),status=document.getElementById('enrollmentStatus'),rows=document.getElementById('enrollmentRows'),table=document.getElementById('enrollmentTable'),selectPage=document.getElementById('enrollmentSelectPage'),previous=document.getElementById('enrollmentPrevious'),next=document.getElementById('enrollmentNext'),pageText=document.getElementById('enrollmentPage'),submit=document.getElementById('enrollmentSubmit'),hidden=document.getElementById('enrollmentSelectedInputs');
const selected=new Set();let page=1,pages=1,visible=[],pending=null,debounce,busy=false;
function update(){
submit.disabled=busy||selected.size===0;submit.textContent='Enroll selected students ('+selected.size+')';previous.disabled=busy||page<=1;next.disabled=busy||page>=pages;
selectPage.disabled=busy||!visible.length;selectPage.checked=visible.length>0&&visible.every(s=>selected.has(String(s.id)));selectPage.indeterminate=!selectPage.checked&&visible.some(s=>selected.has(String(s.id)));
hidden.replaceChildren();selected.forEach(id=>{const input=document.createElement('input');input.type='hidden';input.name='user_ids[]';input.value=id;hidden.append(input);});
}
async function load(target=1){
pending?.abort();pending=new AbortController();const request=pending;
if(!source.value){rows.replaceChildren();visible=[];table.hidden=true;pages=page=1;busy=false;status.textContent='Choose a subject to view eligible students.';pageText.textContent='';update();return;}
busy=true;table.hidden=true;status.textContent='Loading students…';update();
try{
const query=new URLSearchParams({route:'course-enrollment-candidates',course_id:form.elements.course_id.value,source_course_id:source.value,q:search.value,page:String(target)});
const response=await fetch('?'+query,{credentials:'same-origin',signal:request.signal});
if(!response.headers.get('content-type')?.includes('application/json'))throw new Error('Your session may have expired. Refresh and sign in again.');
const data=await response.json();if(!response.ok)throw new Error(data.error||'Unable to load students.');
page=data.page;pages=data.pages;visible=data.students;rows.replaceChildren();
visible.forEach(student=>{const row=document.createElement('tr'),cell=document.createElement('td'),check=document.createElement('input');check.type='checkbox';check.value=String(student.id);check.checked=selected.has(check.value);check.setAttribute('aria-label','Select '+student.name);check.addEventListener('change',()=>{if(check.checked)selected.add(check.value);else selected.delete(check.value);update();});cell.append(check);row.append(cell);[student.name,student.id_number,student.section_code||'—'].forEach(value=>{const td=document.createElement('td');td.textContent=value;row.append(td);});rows.append(row);});
table.hidden=!visible.length;status.textContent=data.total?data.total+' eligible student(s) found.':'No eligible students found. Students already in this class are excluded.';pageText.textContent='Page '+page+' of '+pages;
}catch(error){if(error.name==='AbortError')return;visible=[];rows.replaceChildren();pages=page=1;pageText.textContent='';status.textContent=error.message;}
finally{if(request===pending){busy=false;update();}}
}
source.addEventListener('change',()=>{clearTimeout(debounce);selected.clear();search.value='';search.disabled=!source.value;load();});search.addEventListener('input',()=>{clearTimeout(debounce);debounce=setTimeout(()=>load(),250);});
selectPage.addEventListener('change',()=>{visible.forEach(s=>{const id=String(s.id);if(selectPage.checked)selected.add(id);else selected.delete(id);});rows.querySelectorAll('input[type="checkbox"]').forEach(check=>check.checked=selected.has(check.value));update();});
previous.addEventListener('click',()=>load(page-1));next.addEventListener('click',()=>load(page+1));form.addEventListener('submit',event=>{if(busy||!selected.size){event.preventDefault();return;}submit.disabled=true;submit.textContent='Enrolling students…';});
})();
</script>
