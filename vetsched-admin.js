/* ---------------- PostgreSQL API ---------------- */
const API_URL = (() => {
  if (window.location.protocol === 'file:') {
    return 'http://localhost/vetsched/api.php';
  }
	return new URL('./api.php', window.location.href).toString();
})();
async function loadList(key){
	const response = await fetch(`${API_URL}?resource=${encodeURIComponent(key)}&_=${Date.now()}`, {cache:'no-store'});
	if(!response.ok) throw new Error(`API request failed (${response.status})`);
	return await response.json();
}
async function saveList(key,list){
	try{
		for(const item of list){
			const response = await fetch(`${API_URL}?resource=${encodeURIComponent(key)}`, {
				method:'POST',
				headers:{'Content-Type':'application/json'},
				body:JSON.stringify(item)
			});
			const result = await response.json();
			if(!response.ok || result.success === false) throw new Error(result.message || 'API save failed');
			if(result.id) item.id = result.id;
		}
		const saved = await loadList(key);
		list.splice(0, list.length, ...saved);
		return saved;
	}catch(error){
		console.error('API save error', error);
		showToast(error.message || "Couldn't save — try again");
		return null;
	}
}
async function deleteRecord(key,id){
	try{
		const response=await fetch(`${API_URL}?resource=${encodeURIComponent(key)}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id})});
		const result=await response.json();
		if(!response.ok||result.success===false)throw new Error(result.message||'Delete failed');
		return true;
	}catch(error){
		console.error('API delete error',error);
		showToast(error.message||"Couldn't delete — try again");
		return false;
	}
}

/* ---------------- auth ---------------- */
let currentAdmin=null;
let currentAdminUsername='';
const REMEMBERED_ADMIN_KEY='vetschedRememberedAdmin';
async function attemptLogin(){
  const user=document.getElementById('loginUser').value.trim();
  const pass=document.getElementById('loginPass').value;
  const remember=document.getElementById('rememberMe').checked;
  const err=document.getElementById('loginError');

  if(!user || !pass){
    err.textContent='Enter both username and password.';
    err.classList.add('show');
    return;
  }

  try {
    const response=await fetch(`${API_URL}?resource=admin`, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action:'login', username:user, password:pass})
    });
    const result=await response.json();

    if(!response.ok || result.success !== true) {
      throw new Error(result.message || 'Invalid admin username or password.');
    }

    err.classList.remove('show');
    if(remember) {
      localStorage.setItem(REMEMBERED_ADMIN_KEY, JSON.stringify({user: result.admin?.username || user, pass}));
    } else {
      localStorage.removeItem(REMEMBERED_ADMIN_KEY);
    }

    currentAdminUsername = result.admin?.username || user;
    currentAdmin = result.admin?.display_name || currentAdminUsername;
    document.getElementById('loginScreen').style.display='none';
    document.getElementById('appRoot').style.display='flex';
    document.getElementById('loggedInAs').textContent=`Signed in as ${currentAdmin}`;
    init();
  } catch (error) {
    err.textContent = error.message || 'Invalid admin username or password.';
    err.classList.add('show');
  }
}
function logout(){currentAdmin=null;currentAdminUsername='';document.getElementById('loginUser').value='';document.getElementById('loginPass').value='';document.getElementById('rememberMe').checked=Boolean(localStorage.getItem(REMEMBERED_ADMIN_KEY));document.getElementById('loginError').classList.remove('show');document.getElementById('appRoot').style.display='none';document.getElementById('loginScreen').style.display='flex';document.getElementById('loginUser').focus();}
function restoreRememberedLogin(){
  try {
    const saved=JSON.parse(localStorage.getItem(REMEMBERED_ADMIN_KEY)||'null');
    if(!saved?.user) return;
    document.getElementById('loginUser').value=saved.user;
    document.getElementById('loginPass').value=saved.pass || '';
    document.getElementById('rememberMe').checked=true;
    if(saved.pass) {
      attemptLogin();
    }
  } catch (error) {
    localStorage.removeItem(REMEMBERED_ADMIN_KEY);
  }
}
document.getElementById('loginSubmit').addEventListener('click',attemptLogin);
document.getElementById('loginPass').addEventListener('keydown',e=>{if(e.key==='Enter')attemptLogin();});
document.getElementById('loginUser').addEventListener('keydown',e=>{if(e.key==='Enter')document.getElementById('loginPass').focus();});
document.getElementById('loginPasswordToggle').addEventListener('click',()=>{
  const input=document.getElementById('loginPass');
  const toggle=document.getElementById('loginPasswordToggle');
  const isHidden=input.type==='password';
  input.type=isHidden?'text':'password';
  toggle.textContent=isHidden?'Hide':'Show';
  toggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
  input.focus();
  input.setSelectionRange(input.value.length, input.value.length);
});
document.getElementById('menuLogoutBtn').addEventListener('click',()=>{toggleNavMenu(false);logout();});

/* ---------------- state ---------------- */
let subjects=[],sections=[],students=[],instructors=[],currentView='subjects',activeYear=1,activeSemester=1,activeSubjectId=null,sectionFilterId='',editingSubjectId=null,editingSectionId=null,selectedDays=[],studentSearch='',viewingStudentScheduleId=null,studentSchedules={};
const uid=()=>Math.random().toString(36).slice(2,10)+Date.now().toString(36).slice(-4);
function fmtTime(t){if(!t)return'';const[h,m]=t.split(':').map(Number),period=h>=12?'PM':'AM',h12=h%12===0?12:h%12;return`${h12}:${String(m).padStart(2,'0')} ${period}`;}
function formatDays(days){const order=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];return(days||[]).slice().sort((a,b)=>order.indexOf(a)-order.indexOf(b));}
function initials(name){return name.split(/\s+/).filter(Boolean).slice(0,2).map(w=>w[0].toUpperCase()).join('');}
function showToast(msg,duration=2200){const t=document.getElementById('toast');t.textContent=msg;t.classList.add('show');clearTimeout(showToast._tm);showToast._tm=duration>0?setTimeout(()=>t.classList.remove('show'),duration):null;}
function hideToast(){const t=document.getElementById('toast');clearTimeout(showToast._tm);showToast._tm=null;t.classList.remove('show');}
function escapeHtml(str){const d=document.createElement('div');d.textContent=str??'';return d.innerHTML;}
function renderInstructorOptions(selectEl, selectedName = '') {
  if(!selectEl) return;
  const value = String(selectedName || '');
  const options = ['<option value="">Select instructor</option>'];
  instructors.forEach(instructor => {
    const name = String(instructor.name || instructor.instructor_name || '');
    options.push(`<option value="${escapeHtml(name)}" ${value && name.toLowerCase() === value.toLowerCase() ? 'selected' : ''}>${escapeHtml(name)}</option>`);
  });
  options.push('<option value="__new__">+ Add new instructor</option>');
  selectEl.innerHTML = options.join('');
  if (value && ![...selectEl.options].some(option => option.value.toLowerCase() === value.toLowerCase())) {
    const fallback = document.createElement('option');
    fallback.value = value;
    fallback.textContent = value;
    fallback.selected = true;
    selectEl.appendChild(fallback);
  }
  if (!value) selectEl.value = '';
}
async function createInstructor(name){
  const instructorName = String(name || '').trim();
  if (!instructorName) return null;
  try {
    const response = await fetch(`${API_URL}?resource=instructors`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: instructorName })
    });
    const result = await response.json();
    if (!response.ok || result.success === false) throw new Error(result.message || 'Could not save instructor');
    const saved = { id: result.id, name: result.name || instructorName };
    const exists = instructors.some(item => String(item.id) === String(saved.id) || String(item.name || item.instructor_name || '').toLowerCase() === saved.name.toLowerCase());
    if (!exists) instructors.push(saved);
    return saved;
  } catch (error) {
    console.error('Instructor save error', error);
    showToast(error.message || 'Could not save instructor');
    return null;
  }
}
async function promptForInstructor(selectEl, fallbackValue = '') {
  const nextName = window.prompt('Enter instructor name:');
  if (nextName === null) {
    if (fallbackValue) selectEl.value = fallbackValue;
    return;
  }
  const trimmed = nextName.trim();
  if (!trimmed) {
    showToast('Instructor name is required');
    if (fallbackValue) selectEl.value = fallbackValue;
    return;
  }
  const created = await createInstructor(trimmed);
  if (!created) {
    if (fallbackValue) selectEl.value = fallbackValue;
    return;
  }
  renderInstructorOptions(selectEl, created.name);
  selectEl.value = created.name;
}
/* ---------------- navigation ---------------- */
function toggleNavMenu(force){const menu=document.getElementById('navMenu'),open=force!==undefined?force:!menu.classList.contains('open');menu.classList.toggle('open',open);}
function switchView(view){currentView=view;toggleNavMenu(false);renderAll();}
function subjectsForActiveTerm(){
  return subjects.filter(subject => Number(subject.yearLevel || subject.year_level || 1) === activeYear && Number(subject.semester || 1) === activeSemester);
}
function selectActiveSubject(){
  const visible = subjectsForActiveTerm();
  if (!visible.some(subject => String(subject.id) === String(activeSubjectId))) activeSubjectId = visible[0]?.id || null;
}
function renderSidebar(){
  document.getElementById('navSubjects').classList.toggle('active', currentView === 'subjects');
  document.getElementById('navStudents').classList.toggle('active', currentView === 'students');
  const body = document.getElementById('sidebarBody');
  if (currentView === 'students') {
    body.innerHTML = '<div class="sidebar-note">View each student\'s submitted schedule here. Student accounts are managed through registration.</div>';
    return;
  }
  body.innerHTML = '<label class="year-select-label" for="yearSelect">Year level</label><select class="year-select" id="yearSelect"><option value="1">Year 1</option><option value="2">Year 2</option><option value="3">Year 3</option><option value="4">Year 4</option><option value="5">Year 5</option></select><label class="year-select-label" for="semesterSelect">Semester</label><select class="year-select" id="semesterSelect"><option value="1">Semester 1</option><option value="2">Semester 2</option></select><button class="add-subject-btn" id="openAddSubject">+ Add subject</button><div class="sidebar-label">SUBJECTS</div><ul class="subject-list" id="subjectList"></ul>';
  const yearSelect = document.getElementById('yearSelect');
  const semesterSelect = document.getElementById('semesterSelect');
  yearSelect.value = String(activeYear);
  semesterSelect.value = String(activeSemester);
  const updateTerm = () => { activeYear = Number(yearSelect.value); activeSemester = Number(semesterSelect.value); sectionFilterId = ''; selectActiveSubject(); renderAll(); };
  yearSelect.addEventListener('change', updateTerm);
  semesterSelect.addEventListener('change', updateTerm);
  document.getElementById('openAddSubject').addEventListener('click', openAddSubjectModal);
  const list = document.getElementById('subjectList');
  const visibleSubjects = subjectsForActiveTerm();
  if (!visibleSubjects.length) { list.innerHTML = '<div class="empty-sidebar">No subjects for this year and semester yet. Add one above.</div>'; return; }
  list.innerHTML = visibleSubjects.map(s => { const count = sections.filter(sec => sec.subjectId === s.id).length; return `<li class="subject-item ${s.id === activeSubjectId ? 'active' : ''}" data-id="${s.id}"><span class="sname">${escapeHtml(s.name)}</span><span class="scount">${count}</span></li>`; }).join('');
  list.querySelectorAll('.subject-item').forEach(el => el.addEventListener('click', () => { activeSubjectId = subjects.find(subject => String(subject.id) === el.dataset.id)?.id || null; sectionFilterId = ''; renderAll(); }));
}

/* ---------------- main views ---------------- */
function renderSubjectsMain(){const el=document.getElementById('mainInner');if(!activeSubjectId){if(!subjects.length){el.innerHTML='<div class="no-subject-selected"><svg viewBox="0 0 24 24" fill="none"><path d="M12 14.2c-2.6 0-5.4 2-5.4 4.6 0 1.4 1.1 2.4 2.5 2.4.9 0 1.6-.4 2.3-.8.4-.3.9-.5 1.4-.5s1 .2 1.4.5c.7.4 1.4.8 2.3.8 1.4 0 2.5-1 2.5-2.4 0-2.6-2.8-4.6-5.4-4.6z" fill="#3f5638"/><ellipse cx="6" cy="10.5" rx="1.6" ry="2.1" fill="#3f5638"/><ellipse cx="18" cy="10.5" rx="1.6" ry="2.1" fill="#3f5638"/><ellipse cx="9" cy="6.8" rx="1.4" ry="1.9" fill="#3f5638"/><ellipse cx="15" cy="6.8" rx="1.4" ry="1.9" fill="#3f5638"/></svg><h2>Welcome to Vetsched Admin</h2><p>Add a subject to start building out its sections and schedules.</p><button class="btn-primary" id="emptyAddSubject">+ Add subject</button></div>';document.getElementById('emptyAddSubject').addEventListener('click',openAddSubjectModal);return;}el.innerHTML='<div class="no-subject-selected"><h2>Pick a subject</h2><p>Select a subject from the left to view or add its sections.</p></div>';return;}const subject=subjects.find(s=>s.id===activeSubjectId);if(!subject){activeSubjectId=null;renderSubjectsMain();return;}const allSubjSections=sections.filter(sec=>sec.subjectId===subject.id),sectionOptions=allSubjSections.map(sec=>`<option value="${escapeHtml(sec.id)}" ${String(sec.id)===String(sectionFilterId)?'selected':''}>${escapeHtml(sec.type)}</option>`).join(''),subjSections=sectionFilterId?allSubjSections.filter(sec=>String(sec.id)===String(sectionFilterId)):allSubjSections;el.innerHTML=`<div class="topbar"><div class="subject-heading"><h1>${escapeHtml(subject.name)}</h1><div class="subject-meta"><span class="code-pill">${escapeHtml(subject.code)}</span><span class="year-pill">Year ${Number(subject.yearLevel||subject.year_level||1)}</span><span class="year-pill">Semester ${Number(subject.semester||1)}</span></div></div><div class="topbar-actions"><button class="icon-btn" id="editSubjectBtn" title="Edit subject"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></button><button class="icon-btn danger" id="deleteSubjectBtn" title="Delete subject"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button></div></div><div class="section-head"><h2>Sections</h2><div class="section-controls"><select id="sectionFilter" aria-label="Choose section"><option value="">All sections</option>${sectionOptions}</select><button class="add-section-btn" id="openAddSection">+ Add section</button></div></div>${subjSections.length===0?'<div class="empty-state"><h3>No sections yet</h3><p>Add a section to set its time frame and assign an instructor.</p><button id="emptyAddSection">+ Add section</button></div>':`<div class="sections-list">${subjSections.map(sec=>`<div class="section-card" data-id="${sec.id}"><div class="section-main"><div class="section-title-row"><span class="section-type">${escapeHtml(sec.type)}</span><div class="section-days">${sec.days.map(d=>`<span class="day-chip">${d}</span>`).join('')}</div></div><div class="section-sub"><span>${fmtTime(sec.start)} – ${fmtTime(sec.end)}</span><span>${escapeHtml(sec.instructor)}</span>${sec.room?`<span>${escapeHtml(sec.room)}</span>`:''}</div></div><div class="section-actions"><button class="icon-btn edit-section-btn" title="Edit section">Edit</button><button class="icon-btn danger delete-section-btn" title="Delete section">Delete</button></div></div>`).join('')}</div>`}`;document.getElementById('editSubjectBtn').addEventListener('click',()=>openEditSubjectModal(subject.id));document.getElementById('deleteSubjectBtn').addEventListener('click',()=>deleteSubject(subject.id));document.getElementById('sectionFilter')?.addEventListener('change',e=>{sectionFilterId=e.target.value;renderSubjectsMain();});document.getElementById('openAddSection')?.addEventListener('click',openAddSectionModal);document.getElementById('emptyAddSection')?.addEventListener('click',openAddSectionModal);el.querySelectorAll('.edit-section-btn').forEach(btn=>btn.addEventListener('click',e=>openEditSectionModal(e.target.closest('.section-card').dataset.id)));el.querySelectorAll('.delete-section-btn').forEach(btn=>btn.addEventListener('click',e=>deleteSection(e.target.closest('.section-card').dataset.id)));}
function scheduleMarkup(schedule){
  if(!schedule.length) return '<div class="student-schedule-empty">No submitted schedule yet.</div>';
  return `<div class="student-schedule"><div class="student-schedule-title">Submitted schedule</div>${schedule.map(course=>`<div class="student-schedule-row"><div><strong>${escapeHtml(course.courseCode)}</strong> ${escapeHtml(course.courseName)}</div><div>${escapeHtml(course.type)} · ${escapeHtml(course.section)}</div><div>${escapeHtml(course.day)}, ${escapeHtml(course.timeRange)} · ${escapeHtml(course.room)} · ${escapeHtml(course.instructor)}</div></div>`).join('')}</div>`;
}
async function toggleStudentSchedule(studentId){
  viewingStudentScheduleId=String(viewingStudentScheduleId)===String(studentId)?null:String(studentId);
  if(viewingStudentScheduleId && !studentSchedules[studentId]){
    try {
      const response=await fetch(`${API_URL}?resource=enrolled_courses&studentId=${encodeURIComponent(studentId)}`, {cache:'no-store'});
      if(!response.ok) throw new Error('Could not load schedule');
      studentSchedules[studentId]=await response.json();
    } catch { studentSchedules[studentId]=[]; }
  }
  renderStudentsMain();
}
function renderStudentsMain(){
  const el=document.getElementById('mainInner');
  const q=studentSearch.trim().toLowerCase();
  const filtered=q?students.filter(s=>s.studentId.toLowerCase().includes(q)||s.name.toLowerCase().includes(q)):students;
  const rows=filtered.map(s=>{
    const expanded=String(viewingStudentScheduleId)===String(s.id);
    return `<div class="student-entry"><div class="student-row" data-id="${s.id}"><div class="student-info"><div class="student-avatar">${escapeHtml(initials(s.name||'?'))}</div><div><div class="student-name">${escapeHtml(s.name)}</div><div class="student-id">ID: ${escapeHtml(s.studentId)}${s.yearLevel?` · Year ${escapeHtml(s.yearLevel)}`:''}${s.semester?` · Semester ${escapeHtml(s.semester)}`:''}</div></div></div><div class="student-actions"><button class="view-schedule-btn" data-id="${s.id}">${expanded?'Hide schedule':'View schedule'}</button></div></div>${expanded?scheduleMarkup(studentSchedules[s.id]||[]):''}</div>`;
  }).join('');
  el.innerHTML=`<div class="students-header"><div><h1>Students</h1><p>View student accounts and their submitted schedules.</p></div></div><div class="search-row"><div class="search-box"><input type="text" id="studentSearchInput" placeholder="Search by student ID or name" value="${escapeHtml(studentSearch)}"></div></div>${!students.length?'<div class="no-results"><h3>No students yet</h3><p>Students appear here after they register.</p></div>':!filtered.length?`<div class="no-results"><h3>No matches</h3><p>No student found for "${escapeHtml(studentSearch)}".</p></div>`:`<div class="students-table">${rows}</div>`}`;
  const input=document.getElementById('studentSearchInput');
  input.addEventListener('input',e=>{studentSearch=e.target.value;renderStudentsMain();const next=document.getElementById('studentSearchInput');next.focus();next.setSelectionRange(next.value.length,next.value.length);});
  el.querySelectorAll('.view-schedule-btn').forEach(btn=>btn.addEventListener('click',()=>toggleStudentSchedule(btn.dataset.id)));
}
const renderSidebarBase=renderSidebar;
renderSidebar=function(){renderSidebarBase();document.getElementById('navSettings').classList.toggle('active',currentView==='settings');if(currentView==='settings')document.getElementById('sidebarBody').innerHTML='<div class="sidebar-note">Manage semester-wide admin actions here.</div>';};
function renderSettingsMain(){
  document.getElementById('mainInner').innerHTML = `
    <div class="settings-page">
      <div class="settings-header">
        <h1>Settings</h1>
        <p>Manage actions that affect the entire student body.</p>
      </div>

      <section class="settings-card">
        <div>
          <h2>Change password</h2>
          <p>Update the password for ${escapeHtml(currentAdminUsername || 'this admin account')}.</p>
        </div>
        <div class="settings-password-form">
          <div class="field">
            <label for="currentAdminPassword">Current password</label>
            <input type="password" id="currentAdminPassword" autocomplete="current-password">
          </div>
          <div class="field">
            <label for="newAdminPassword">New password</label>
            <input type="password" id="newAdminPassword" autocomplete="new-password">
          </div>
          <div class="field">
            <label for="confirmAdminPassword">Confirm new password</label>
            <input type="password" id="confirmAdminPassword" autocomplete="new-password">
          </div>
          <div class="settings-form-actions">
            <button class="btn-primary" id="changePasswordBtn">Update password</button>
          </div>
          <div class="field-error" id="adminChangePasswordError" style="display:none;"></div>
          <div class="settings-success" id="adminChangePasswordSuccess"></div>
        </div>
      </section>

      <section class="settings-danger">
        <div>
          <h2>Set semester</h2>
          <p>Set the semester for every student. This clears all submitted schedules so students can enroll for the selected semester.</p>
        </div>
        <button class="danger-action-btn" id="setSemesterBtn">Set semester</button>
      </section>
    </div>
  `;
  document.getElementById('changePasswordBtn').addEventListener('click', changeAdminPassword);
  document.getElementById('setSemesterBtn').addEventListener('click', setSemester);
}
async function changeAdminPassword(){
  const currentPassword=document.getElementById('currentAdminPassword').value;
  const newPassword=document.getElementById('newAdminPassword').value;
  const confirmPassword=document.getElementById('confirmAdminPassword').value;
  const error=document.getElementById('adminChangePasswordError');
  const success=document.getElementById('adminChangePasswordSuccess');

  error.style.display='none';
  success.textContent='';

  if(!currentPassword || !newPassword || !confirmPassword){
    error.textContent='Complete all password fields.';
    error.style.display='block';
    return;
  }

  if(newPassword.length < 10){
    error.textContent='New password must be at least 10 characters.';
    error.style.display='block';
    return;
  }

  if(newPassword !== confirmPassword){
    error.textContent='New password and confirmation do not match.';
    error.style.display='block';
    return;
  }

  try {
    const response=await fetch(`${API_URL}?resource=admin`, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        action:'changePassword',
        username: currentAdminUsername || document.getElementById('loginUser').value.trim(),
        currentPassword,
        newPassword
      })
    });
    const result=await response.json();

    if(!response.ok || result.success !== true){
      throw new Error(result.message || 'Could not update password.');
    }

    document.getElementById('currentAdminPassword').value='';
    document.getElementById('newAdminPassword').value='';
    document.getElementById('confirmAdminPassword').value='';
    success.textContent = result.message || 'Password updated successfully.';
  } catch (error) {
    error.textContent = error.message || 'Could not update password.';
    error.style.display='block';
  }
}
function renderMain(){currentView==='students'?renderStudentsMain():currentView==='settings'?renderSettingsMain():renderSubjectsMain();}
function addSectionDayLabels(){document.querySelectorAll('.section-card').forEach(card=>{const section=sections.find(item=>String(item.id)===String(card.dataset.id)),summary=card.querySelector('.section-sub');if(!section||!summary||summary.querySelector('.section-day-label'))return;const typeLabel=document.createElement('span');typeLabel.className='section-day-label';typeLabel.textContent=`Class: ${(section.classTypes||[section.classType||'Lecture']).join(' / ')}`;summary.prepend(typeLabel);const label=document.createElement('span');label.className='section-day-label';label.textContent=`Days: ${formatDays(section.days).join(', ')||'Not set'}`;summary.prepend(label);});}
function renderAll(){renderSidebar();renderMain();addSectionDayLabels();}

/* ---------------- modal helpers ---------------- */
function clearErrors(ids){ids.forEach(id=>document.getElementById(id).style.display='none');}
function openAddSubjectModal(){
  editingSubjectId=null;
  document.getElementById('subjectModalTitle').textContent=`Add Year ${activeYear}, Semester ${activeSemester} subject`;
  document.getElementById('subjectCode').value='';
  document.getElementById('subjectName').value='';
  document.getElementById('subjectYear').value=String(activeYear);
  document.getElementById('subjectSemester').value=String(activeSemester);
  clearErrors(['subjectCodeError','subjectNameError']);
  document.getElementById('subjectOverlay').classList.add('open');
  document.getElementById('subjectCode').focus();
}
function openEditSubjectModal(id){
  const s=subjects.find(x=>x.id===id); if(!s)return;
  editingSubjectId=id;
  document.getElementById('subjectModalTitle').textContent='Edit subject';
  document.getElementById('subjectCode').value=s.code||'';
  document.getElementById('subjectName').value=s.name;
  document.getElementById('subjectYear').value=String(s.yearLevel||s.year_level||1);
  document.getElementById('subjectSemester').value=String(s.semester||1);
  clearErrors(['subjectCodeError','subjectNameError']);
  document.getElementById('subjectOverlay').classList.add('open');
}
function closeSubjectModal(){document.getElementById('subjectOverlay').classList.remove('open');}
async function saveSubject(){
  const code=document.getElementById('subjectCode').value.trim();
  const name=document.getElementById('subjectName').value.trim();
  const yearLevel=Number(document.getElementById('subjectYear').value);
  const semester=Number(document.getElementById('subjectSemester').value);
  let valid=true;
  document.getElementById('subjectCodeError').style.display=code?'none':(valid=false,'block');
  document.getElementById('subjectNameError').style.display=name?'none':(valid=false,'block');
  if(!valid)return;
  const subject={id:editingSubjectId||undefined,code,name,yearLevel,semester};
  const saved=await saveList('subjects',[subject]); if(!saved)return;
  subjects=saved;
  activeYear=yearLevel;
  activeSemester=semester;
  activeSubjectId=saved.find(item=>String(item.code)===String(code)&&String(item.name)===String(name)&&Number(item.semester||1)===semester)?.id||activeSubjectId;
  showToast(editingSubjectId?'Subject updated':'Subject added');
  closeSubjectModal(); renderAll();
}
async function deleteSubject(id){const s=subjects.find(x=>x.id===id);if(!s||!confirm(`Delete "${s.name}" and all its sections? This can't be undone.`))return;if(!await deleteRecord('subjects',id))return;subjects=subjects.filter(x=>x.id!==id);sections=sections.filter(x=>x.subjectId!==id);if(activeSubjectId===id)activeSubjectId=null;showToast('Subject deleted');renderAll();}
function setDayPickerUI(){document.querySelectorAll('.day-toggle').forEach(btn=>{const active=selectedDays.includes(btn.dataset.day);btn.classList.toggle('on',active);btn.setAttribute('aria-pressed',String(active));});}
function openAddSectionModal(){editingSectionId=null;selectedDays=[];document.getElementById('sectionModalTitle').textContent='Add section';['sectionType','startTime','endTime','instructor','room','maxCapacity'].forEach(id=>document.getElementById(id).value='');document.getElementById('classType').value='Lecture';clearErrors(['sectionTypeError','dayError','timeError','instructorError','maxCapacityError']);setDayPickerUI();document.getElementById('sectionOverlay').classList.add('open');document.getElementById('sectionType').focus();}
function openEditSectionModal(id){const sec=sections.find(x=>x.id===id);if(!sec)return;editingSectionId=id;selectedDays=[...(sec.days||[])];document.getElementById('sectionModalTitle').textContent='Edit section';document.getElementById('sectionType').value=sec.type;document.getElementById('classType').value=sec.classType||'Lecture';document.getElementById('startTime').value=sec.start;document.getElementById('endTime').value=sec.end;document.getElementById('instructor').value=sec.instructor;document.getElementById('room').value=sec.room||'';document.getElementById('maxCapacity').value=sec.maxCapacity||'';clearErrors(['sectionTypeError','dayError','timeError','instructorError','maxCapacityError']);setDayPickerUI();document.getElementById('sectionOverlay').classList.add('open');}
function closeSectionModal(){document.getElementById('sectionOverlay').classList.remove('open');}
async function toggleSection(id){const section=sections.find(item=>String(item.id)===String(id));if(!section)return;const opening=!section.isOpen;if(opening&&Number(section.enrollmentCount||0)>=Number(section.maxCapacity||0)){if(!confirm('Max capacity has been reach are u sure u want to re open section'))return;}try{const response=await fetch(`${API_URL}?resource=sections`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'toggle',id:section.id,isOpen:opening})});const result=await response.json();if(!response.ok||result.success===false)throw new Error(result.message||'Could not change section status');section.isOpen=opening;renderAll();showToast(opening?'Section opened':'Section closed');}catch(error){console.error('Section status error',error);showToast(error.message||'Could not change section status');}}
async function saveSection(){const type=document.getElementById('sectionType').value.trim(),start=document.getElementById('startTime').value,end=document.getElementById('endTime').value,instructor=document.getElementById('instructor').value.trim(),room=document.getElementById('room').value.trim(),maxCapacity=Number(document.getElementById('maxCapacity').value),subjectId=activeSubjectId;let valid=true;document.getElementById('sectionTypeError').style.display=type?'none':(valid=false,'block');document.getElementById('dayError').style.display=selectedDays.length?'none':(valid=false,'block');document.getElementById('instructorError').style.display=instructor?'none':(valid=false,'block');document.getElementById('maxCapacityError').style.display=maxCapacity>0?'none':(valid=false,'block');const timeOk=!start||!end||start<end;document.getElementById('timeError').style.display=timeOk?'none':(valid=false,'block');if(!valid)return;if(!subjectId){showToast('Select a subject before adding a section');return;}const section=editingSectionId?sections.find(x=>String(x.id)===String(editingSectionId)):null;const payload={id:section?.id||uid(),subjectId,type,classType:document.getElementById('classType').value,days:[...selectedDays],start,end,instructor,room,maxCapacity,isOpen:section?.isOpen??false};const saved=await saveList('sections',[payload]);if(!saved)return;if(section)Object.assign(section,payload);else sections.push(saved.find(item=>String(item.id)===String(payload.id))||payload);closeSectionModal();await refreshFromDatabase(true);showToast(editingSectionId?'Section updated':'Section added');}
async function deleteSection(id){const sec=sections.find(x=>x.id===id);if(!sec||!confirm(`Delete "${sec.type}"? This can't be undone.`))return;if(!await deleteRecord('sections',id))return;sections=sections.filter(x=>x.id!==id);showToast('Section deleted');renderAll();}
function openAddStudentModal(){editingStudentId=null;document.getElementById('studentModalTitle').textContent='Add student';document.getElementById('studentId').value='';document.getElementById('studentName').value='';clearErrors(['studentIdError','studentNameError']);document.getElementById('studentOverlay').classList.add('open');document.getElementById('studentId').focus();}
function openEditStudentModal(id){const s=students.find(x=>x.id===id);if(!s)return;editingStudentId=id;document.getElementById('studentModalTitle').textContent='Edit student';document.getElementById('studentId').value=s.studentId;document.getElementById('studentName').value=s.name;clearErrors(['studentIdError','studentNameError']);document.getElementById('studentOverlay').classList.add('open');}
function closeStudentModal(){document.getElementById('studentOverlay').classList.remove('open');}
async function saveStudent(){const studentId=document.getElementById('studentId').value.trim(),name=document.getElementById('studentName').value.trim();let valid=true;document.getElementById('studentIdError').style.display=studentId?'none':(valid=false,'block');document.getElementById('studentNameError').style.display=name?'none':(valid=false,'block');if(!valid)return;const dupe=students.find(s=>s.studentId.toLowerCase()===studentId.toLowerCase()&&s.id!==editingStudentId);if(dupe){document.getElementById('studentIdError').textContent='That student ID is already in use.';document.getElementById('studentIdError').style.display='block';return;}document.getElementById('studentIdError').textContent='Give the student an ID.';if(editingStudentId){const s=students.find(x=>x.id===editingStudentId);s.studentId=studentId;s.name=name;showToast('Student updated');}else{students.push({id:uid(),studentId,name});showToast('Student added');}await saveList('students',students);closeStudentModal();renderAll();}
async function deleteStudent(id){const s=students.find(x=>x.id===id);if(!s||!confirm(`Delete "${s.name}"? This can't be undone.`))return;students=students.filter(x=>x.id!==id);await saveList('students',students);showToast('Student deleted');renderAll();}
async function setSemester(){
  const selection=prompt('Set the semester for all students. Enter 1 for Semester 1 or 2 for Semester 2.');
  if(selection===null)return;
  const semester=Number(selection.trim());
  if(![1,2].includes(semester)){showToast('Enter 1 for Semester 1 or 2 for Semester 2');return;}
  if(!confirm(`Set every student to Semester ${semester}? This clears all submitted schedules.`))return;
  try{
    const response=await fetch(`${API_URL}?resource=students`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'setSemester',semester})});
    const result=await response.json();
    if(!response.ok||result.success===false)throw new Error(result.message||'Could not set semester');
    students=students.map(student=>({...student,semester}));
    studentSchedules={};
    viewingStudentScheduleId=null;
    showToast(`Semester ${semester} set for all students; submitted schedules cleared`);
    renderAll();
  }catch(error){console.error('Set semester error',error);showToast(error.message||'Could not set semester');}
}

const sectionToggleObserver=new MutationObserver(()=>{document.querySelectorAll('.section-card').forEach(card=>{const sectionId=card.dataset.id;const section=sections.find(item=>String(item.id)===String(sectionId));if(!section)return;const actions=card.querySelector('.section-actions');if(actions&&!actions.querySelector('.toggle-section-btn')){const button=document.createElement('button');button.className=`icon-btn toggle-section-btn ${section.isOpen?'section-toggle-close':'section-toggle-open'}`;button.title=`${section.isOpen?'Close':'Open'} section`;button.textContent=section.isOpen?'Close':'Open';button.addEventListener('click',()=>toggleSection(sectionId));actions.insertBefore(button,actions.firstChild);}const toggleButton=actions?.querySelector('.toggle-section-btn');if(toggleButton&&!section.isOpen){const hasClasses=Array.isArray(section.classes)&&section.classes.length>0;toggleButton.disabled=!hasClasses;toggleButton.title=hasClasses?'Open section':'Add a class before opening this section';}const summary=card.querySelector('.section-sub');if(summary&&!summary.querySelector('.section-capacity-label')&&section.maxCapacity!=null){const label=document.createElement('span');label.className='section-capacity-label';label.textContent=`Remaining Slots: ${section.remainingSeats??Math.max(Number(section.maxCapacity)-Number(section.enrollmentCount||0),0)}/${section.maxCapacity}`;summary.appendChild(label);}});});
sectionToggleObserver.observe(document.getElementById('mainInner'),{childList:true,subtree:true});

/* ---------------- grouped section slots ---------------- */
function classSlotMarkup(slot = {}) {
	const days = new Set(slot.days || []);
	const dayButtons = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].map(day => `<button type="button" class="day-toggle slot-day-toggle ${days.has(day) ? 'on' : ''}" data-day="${day}" aria-pressed="${days.has(day)}">${day}</button>`).join('');
	const instructorSelect = `<select class="slot-instructor-select">${instructors.map(instructor => `<option value="${escapeHtml(instructor.name || '')}" ${String(instructor.name || '').toLowerCase() === String(slot.instructor || '').toLowerCase() ? 'selected' : ''}>${escapeHtml(instructor.name || '')}</option>`).join('')}<option value="__new__">+ Add new instructor</option></select>`;
	return `<div class="class-slot">
		<div class="field-row">
			<div class="field"><label>Class type</label><select class="slot-class-type"><option value="Lecture" ${slot.classType === 'Lecture' ? 'selected' : ''}>Lecture</option><option value="Lab" ${slot.classType === 'Lab' ? 'selected' : ''}>Lab</option></select></div>
			<div class="field"><label>Instructor</label>${instructorSelect}</div>
		</div>
		<div class="field"><label>Days</label><div class="day-picker slot-day-picker">${dayButtons}</div></div>
		<div class="field-row"><div class="field"><label>Start time</label><input type="time" class="slot-start" value="${escapeHtml(slot.start || '')}"></div><div class="field"><label>End time</label><input type="time" class="slot-end" value="${escapeHtml(slot.end || '')}"></div></div>
		<div class="field"><label>Room / location</label><input class="slot-room" value="${escapeHtml(slot.room || '')}" placeholder="e.g. Surgery Hall 2"></div>
		<button type="button" class="btn-secondary remove-class-slot">Remove class slot</button>
	</div>`;
}
function renderClassSlots(slots) {
	document.getElementById('classSlots').innerHTML = (slots.length ? slots : [{}]).map(classSlotMarkup).join('');
	document.querySelectorAll('.slot-day-toggle').forEach(button => button.addEventListener('click', () => { button.classList.toggle('on'); button.setAttribute('aria-pressed', String(button.classList.contains('on'))); }));
	document.querySelectorAll('.slot-instructor-select').forEach(select => {
		const currentValue = select.value || '';
		renderInstructorOptions(select, currentValue);
		select.addEventListener('change', async () => {
			if (select.value === '__new__') {
				const previous = currentValue || '';
				select.value = previous;
				await promptForInstructor(select, previous);
			}
		});
	});
	document.querySelectorAll('.remove-class-slot').forEach(button => button.addEventListener('click', () => { button.closest('.class-slot').remove(); }));
}
function readClassSlots() {
	return [...document.querySelectorAll('.class-slot')].map(slot => ({
		classType: slot.querySelector('.slot-class-type').value,
		days: [...slot.querySelectorAll('.slot-day-toggle.on')].map(button => button.dataset.day),
		start: slot.querySelector('.slot-start').value,
		end: slot.querySelector('.slot-end').value,
		instructor: slot.querySelector('.slot-instructor-select').value.trim(),
		room: slot.querySelector('.slot-room').value.trim()
	}));
}
function openAddSectionModal(){editingSectionId=null;document.getElementById('sectionModalTitle').textContent='Add section';document.getElementById('sectionType').value='';document.getElementById('maxCapacity').value='';clearErrors(['sectionTypeError','maxCapacityError','classSlotError']);renderClassSlots([]);document.getElementById('sectionOverlay').classList.add('open');document.getElementById('sectionType').focus();}
function openEditSectionModal(id){const sec=sections.find(item=>String(item.id)===String(id));if(!sec)return;editingSectionId=id;document.getElementById('sectionModalTitle').textContent='Edit section';document.getElementById('sectionType').value=sec.type||'';document.getElementById('maxCapacity').value=sec.maxCapacity||'';clearErrors(['sectionTypeError','maxCapacityError','classSlotError']);renderClassSlots(sec.classes || [{classType:sec.classType||'Lecture',days:sec.days,start:sec.start,end:sec.end,instructor:sec.instructor,room:sec.room}]);document.getElementById('sectionOverlay').classList.add('open');}
async function saveSection(){const type=document.getElementById('sectionType').value.trim(),maxCapacity=Number(document.getElementById('maxCapacity').value),classes=readClassSlots(),subjectId=activeSubjectId;let valid=Boolean(type)&&maxCapacity>0&&classes.length>0;document.getElementById('sectionTypeError').style.display=type?'none':'block';document.getElementById('maxCapacityError').style.display=maxCapacity>0?'none':'block';document.getElementById('classSlotError').style.display=classes.length?'none':'block';classes.forEach(slot=>{if(!slot.days.length||!slot.start||!slot.end||slot.start>=slot.end||!slot.instructor)valid=false;});if(!valid)return;if(!subjectId){showToast('Select a subject before adding a section');return;}const section=editingSectionId?sections.find(item=>String(item.id)===String(editingSectionId)):null;const payload={id:section?.id||uid(),subjectId,type,maxCapacity,classes,isOpen:section?.isOpen??false};const saved=await saveList('sections',[payload]);if(!saved)return;closeSectionModal();await refreshFromDatabase(true);showToast(editingSectionId?'Section updated':'Section added');}

/* ---------------- wiring ---------------- */
function openAddSectionModal(){editingSectionId=null;document.getElementById('sectionModalTitle').textContent='Add section';document.getElementById('sectionType').value='';document.getElementById('maxCapacity').value='';clearErrors(['sectionTypeError','maxCapacityError']);document.getElementById('sectionOverlay').classList.add('open');document.getElementById('sectionType').focus();}
function openEditSectionModal(id){const section=sections.find(item=>String(item.id)===String(id));if(!section)return;editingSectionId=id;document.getElementById('sectionModalTitle').textContent='Edit section';document.getElementById('sectionType').value=section.type||'';document.getElementById('maxCapacity').value=section.maxCapacity||'';clearErrors(['sectionTypeError','maxCapacityError']);document.getElementById('sectionOverlay').classList.add('open');}
async function saveSection(){const type=document.getElementById('sectionType').value.trim(),maxCapacity=Number(document.getElementById('maxCapacity').value),subjectId=activeSubjectId;document.getElementById('sectionTypeError').style.display=type?'none':'block';document.getElementById('maxCapacityError').style.display=maxCapacity>0?'none':'block';if(!type||maxCapacity<1)return;if(!subjectId){showToast('Select a subject before adding a section');return;}const section=editingSectionId?sections.find(item=>String(item.id)===String(editingSectionId)):null;const payload={id:section?.id||uid(),subjectId,type,maxCapacity,classes:section?.classes||[],isOpen:section?.isOpen??false};if(!await saveList('sections',[payload]))return;closeSectionModal();await refreshFromDatabase(true);showToast(editingSectionId?'Section updated':'Section added');}
let classSectionId=null,editingClassIndex=null;
function setClassDayPicker(days=[]){document.querySelectorAll('.class-day-toggle').forEach(button=>{const active=days.includes(button.dataset.day);button.classList.toggle('on',active);button.setAttribute('aria-pressed',String(active));});}
function readClassForm(){const instructorSelect=document.getElementById('classInstructor');const instructorValue=instructorSelect ? instructorSelect.value : '';return{classType:document.getElementById('classTypeInput').value,days:[...document.querySelectorAll('.class-day-toggle.on')].map(button=>button.dataset.day),start:document.getElementById('classStartTime').value,end:document.getElementById('classEndTime').value,instructor:instructorValue.trim(),room:document.getElementById('classRoom').value.trim()};}
function findScheduleConflict(classes){for(let index=0;index<classes.length;index++){for(let otherIndex=index+1;otherIndex<classes.length;otherIndex++){const first=classes[index],second=classes[otherIndex],sameDay=(first.days||[]).filter(day=>(second.days||[]).includes(day));if(sameDay.length&&first.start<second.end&&second.start<first.end)return{first,second,days:sameDay};}}return null;}
function clearScheduleConflictState(){const modal=document.querySelector('#classOverlay .modal');if(modal)modal.classList.remove('schedule-conflict');}
function refuseScheduleConflict(){const modal=document.querySelector('#classOverlay .modal'),saveButton=document.getElementById('saveClass');if(!modal)return;modal.classList.remove('schedule-conflict');void modal.offsetWidth;modal.classList.add('schedule-conflict');saveButton?.focus();setTimeout(()=>modal.classList.remove('schedule-conflict'),700);}
function openAddClassModal(sectionId){const section=sections.find(item=>String(item.id)===String(sectionId));if(!section)return;classSectionId=section.id;editingClassIndex=null;clearScheduleConflictState();document.getElementById('classModalTitle').textContent=`Add class to ${section.type}`;document.getElementById('classTypeInput').value='Lecture';document.getElementById('classStartTime').value='';document.getElementById('classEndTime').value='';renderInstructorOptions(document.getElementById('classInstructor'), '');document.getElementById('classRoom').value='';clearErrors(['classDayError','classTimeError','classInstructorError']);setClassDayPicker([]);document.getElementById('classOverlay').classList.add('open');}
function openEditClassModal(sectionId,index){const section=sections.find(item=>String(item.id)===String(sectionId)),classEntry=section?.classes?.[index];if(!section||!classEntry)return;classSectionId=section.id;editingClassIndex=index;clearScheduleConflictState();document.getElementById('classModalTitle').textContent=`Edit class in ${section.type}`;document.getElementById('classTypeInput').value=classEntry.classType||'Lecture';document.getElementById('classStartTime').value=classEntry.start||'';document.getElementById('classEndTime').value=classEntry.end||'';renderInstructorOptions(document.getElementById('classInstructor'), classEntry.instructor || '');document.getElementById('classRoom').value=classEntry.room||'';clearErrors(['classDayError','classTimeError','classInstructorError']);setClassDayPicker(classEntry.days||[]);document.getElementById('classOverlay').classList.add('open');}
function closeClassModal(){editingClassIndex=null;clearScheduleConflictState();document.getElementById('classOverlay').classList.remove('open');}
async function saveClass(){const section=sections.find(item=>String(item.id)===String(classSectionId));if(!section)return;const classEntry=readClassForm(),timeOk=classEntry.start&&classEntry.end&&classEntry.start<classEntry.end,oneDayOnly=classEntry.days.length===1;document.getElementById('classDayError').style.display=oneDayOnly?'none':'block';document.getElementById('classTimeError').style.display=timeOk?'none':'block';document.getElementById('classInstructorError').style.display=classEntry.instructor?'none':'block';if(!oneDayOnly||!timeOk||!classEntry.instructor)return;const classes=[...(section.classes||[])];if(editingClassIndex===null)classes.push(classEntry);else classes[editingClassIndex]=classEntry;const conflict=findScheduleConflict(classes);if(conflict){refuseScheduleConflict();showToast(`Schedule conflict on ${conflict.days.join(', ')}: class times overlap`);return;}const payload={id:section.id,subjectId:section.subjectId,type:section.type,maxCapacity:section.maxCapacity,classes,isOpen:section.isOpen};if(!await saveList('sections',[payload]))return;const wasEditing=editingClassIndex!==null;editingClassIndex=null;closeClassModal();await refreshFromDatabase(true);showToast(wasEditing?'Class updated':'Class added');}
async function deleteClass(sectionId,index){const section=sections.find(item=>String(item.id)===String(sectionId)),classEntry=section?.classes?.[index];if(!section||!classEntry)return;const label=`${classEntry.classType||'Class'} on ${formatDays(classEntry.days).join(', ')}`;if(!confirm(`Delete this class (${label})? This cannot be undone.`))return;const classes=(section.classes||[]).filter((item,classIndex)=>classIndex!==index);const payload={id:section.id,subjectId:section.subjectId,type:section.type,maxCapacity:section.maxCapacity,classes,isOpen:section.isOpen};if(!await saveList('sections',[payload]))return;await refreshFromDatabase(true);showToast('Class deleted');}
document.querySelectorAll('.class-day-toggle').forEach(button=>button.addEventListener('click',()=>{document.querySelectorAll('.class-day-toggle').forEach(dayButton=>{const active=dayButton===button;dayButton.classList.toggle('on',active);dayButton.setAttribute('aria-pressed',String(active));});}));
document.getElementById('classInstructor').addEventListener('change', async () => {
  const select = document.getElementById('classInstructor');
  if (select.value === '__new__') {
    const previous = select.dataset.previousValue || '';
    select.value = previous;
    await promptForInstructor(select, previous);
  }
  select.dataset.previousValue = select.value;
});
document.getElementById('cancelClass').addEventListener('click',closeClassModal);document.getElementById('saveClass').addEventListener('click',saveClass);document.getElementById('classOverlay').addEventListener('click',event=>{if(event.target.id==='classOverlay')closeClassModal();});
const classCardObserver=new MutationObserver(()=>{document.querySelectorAll('.section-card').forEach(card=>{if(card.querySelector('.add-class-btn'))return;const button=document.createElement('button');button.className='icon-btn add-class-btn';button.textContent='Add class';button.title='Add class';button.addEventListener('click',()=>openAddClassModal(card.dataset.id));const actions=card.querySelector('.section-actions');if(actions)actions.appendChild(button);});});classCardObserver.observe(document.getElementById('mainInner'),{childList:true,subtree:true});
const classListObserver=new MutationObserver(()=>{document.querySelectorAll('.section-card').forEach(card=>{if(card.querySelector('.section-class-list'))return;const section=sections.find(item=>String(item.id)===String(card.dataset.id));if(!section)return;const summary=card.querySelector('.section-sub');if(summary)summary.style.display='none';const remaining=document.createElement('div');remaining.className='section-remaining';remaining.textContent=`Remaining slots: ${section.remainingSeats ?? Math.max(Number(section.maxCapacity||0)-Number(section.enrollmentCount||0),0)}/${section.maxCapacity||0}`;const list=document.createElement('div');list.className='section-class-list';const classes=section.classes||[];list.innerHTML=classes.length?classes.map((item,index)=>`<div class="section-class-row"><strong>${escapeHtml(item.classType||'Lecture')}</strong><span>${formatDays(item.days).join(', ')} ${fmtTime(item.start)} - ${fmtTime(item.end)}</span><span>${escapeHtml(item.instructor||'')}</span><span>${escapeHtml(item.room||'No room')}</span><button type="button" class="edit-class-btn" data-class-index="${index}">Edit class</button><button type="button" class="delete-class-btn" data-class-index="${index}">Delete class</button></div>`).join(''):'<div class="section-empty-class">If empty, add a class.</div>';list.querySelectorAll('.edit-class-btn').forEach(button=>button.addEventListener('click',()=>openEditClassModal(section.id,Number(button.dataset.classIndex))));list.querySelectorAll('.delete-class-btn').forEach(button=>button.addEventListener('click',()=>deleteClass(section.id,Number(button.dataset.classIndex))));const titleRow=card.querySelector('.section-title-row');if(titleRow)titleRow.after(remaining);if(summary)summary.after(list);else card.querySelector('.section-main')?.append(list);});});classListObserver.observe(document.getElementById('mainInner'),{childList:true,subtree:true});
document.getElementById('hamburgerBtn').addEventListener('click',e=>{e.stopPropagation();toggleNavMenu();});document.getElementById('navSubjects').addEventListener('click',()=>switchView('subjects'));document.getElementById('navStudents').addEventListener('click',()=>switchView('students'));document.addEventListener('click',e=>{const menu=document.getElementById('navMenu');if(menu.classList.contains('open')&&!menu.contains(e.target)&&e.target.id!=='hamburgerBtn')toggleNavMenu(false);});
document.getElementById('cancelSubject').addEventListener('click',closeSubjectModal);document.getElementById('saveSubject').addEventListener('click',saveSubject);document.getElementById('subjectOverlay').addEventListener('click',e=>{if(e.target.id==='subjectOverlay')closeSubjectModal();});document.getElementById('cancelSection').addEventListener('click',closeSectionModal);document.getElementById('saveSection').addEventListener('click',saveSection);document.getElementById('sectionOverlay').addEventListener('click',e=>{if(e.target.id==='sectionOverlay')closeSectionModal();});document.querySelectorAll('.day-toggle').forEach(btn=>btn.addEventListener('click',()=>{const day=btn.dataset.day;selectedDays=selectedDays.includes(day)?selectedDays.filter(item=>item!==day):[...selectedDays,day];setDayPickerUI();}));document.getElementById('cancelStudent').addEventListener('click',closeStudentModal);document.getElementById('saveStudent').addEventListener('click',saveStudent);document.getElementById('studentOverlay').addEventListener('click',e=>{if(e.target.id==='studentOverlay')closeStudentModal();});document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeSubjectModal();closeSectionModal();closeStudentModal();toggleNavMenu(false);}});
document.getElementById('navSettings').addEventListener('click',()=>switchView('settings'));
let isRefreshing=false;
let lastDatabaseSnapshot='';
async function refreshFromDatabase(showLoading=false){
	if(isRefreshing||!currentAdmin)return;
	isRefreshing=true;
	try{
		const [freshSubjects,freshSections,freshStudents,freshInstructors]=await Promise.all([loadList('subjects'),loadList('sections'),loadList('students'),loadList('instructors')]);
		instructors=freshInstructors.map(item=>({id:item.id??item.instructor_id,name:item.name??item.instructor_name??''})).filter(item=>item.name);
		document.getElementById('databaseError').hidden=true;
		const parseDatabaseArray=value=>Array.isArray(value)?value:(typeof value==='string'&&value.startsWith('{')&&value.endsWith('}')?value.slice(1,-1).split(',').filter(Boolean):[]);
		subjects=freshSubjects.map(subject=>({...subject,id:String(subject.id)}));
		sections=freshSections.map(section=>({...section,id:String(section.id),subjectId:section.subjectId==null?null:String(section.subjectId),classTypes:parseDatabaseArray(section.classTypes),days:parseDatabaseArray(section.days),scheduleIds:parseDatabaseArray(section.scheduleIds).map(id=>String(id))}));
		students=freshStudents;
		selectActiveSubject();
		const databaseSnapshot=JSON.stringify({subjects,sections,students});
		if(!showLoading&&databaseSnapshot===lastDatabaseSnapshot)return;
		lastDatabaseSnapshot=databaseSnapshot;
		renderAll();
	}catch(error){
		console.error('API load error',error);
		document.getElementById('databaseError').hidden=false;
		if(showLoading)document.getElementById('mainInner').replaceChildren();
	}finally{
		isRefreshing=false;
	}
}
async function init(){await refreshFromDatabase(true);}
document.getElementById('retryDatabaseBtn').addEventListener('click',()=>refreshFromDatabase(true));
document.getElementById('loginUser').focus();
const sectionRefreshObserver=new MutationObserver(()=>{const controls=document.querySelector('.section-controls');if(!controls||controls.querySelector('#refreshSectionsBtn'))return;const button=document.createElement('button');button.className='icon-btn';button.id='refreshSectionsBtn';button.title='Refresh sections';button.setAttribute('aria-label','Refresh sections');button.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 11a8.1 8.1 0 0 0-14.9-4L3 10"/><path d="M3 4v6h6"/><path d="M4 13a8.1 8.1 0 0 0 14.9 4L21 14"/><path d="M21 20v-6h-6"/></svg>';button.addEventListener('click',()=>refreshFromDatabase(true));controls.insertBefore(button,controls.firstChild);});
sectionRefreshObserver.observe(document.getElementById('mainInner'),{childList:true,subtree:true});
restoreRememberedLogin();
