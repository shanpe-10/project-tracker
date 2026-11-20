const projectWorkdayInputIds = ['preProcurementDate','preBidDate','biddingDate','prDate','obre','resolutionDate','noticePostQual','noa','noaDueDate','performanceBond','contractRef','ntpDate','dole','contractEndDate','preInspection','materialTestingDate','materialRequestDate','finalInspection','swa','ldNewCompletionDate','completionDate'];
let projectDatePickerInput = null;
let projectDatePickerDialog = null;

function projectDateIsSelectable(value) {
    const date = projectCalendarDate(value);
    return Boolean(date && isProjectWorkday(date));
}

function setupProjectWorkdayPickers() {
    for (const id of projectWorkdayInputIds) {
        const input = document.getElementById(id);
        if (!input || input.dataset.workdayPicker) continue;
        input.dataset.workdayPicker='true';
        input.type='text';
        input.placeholder='YYYY-MM-DD';
        input.setAttribute('pattern','[0-9]{4}-[0-9]{2}-[0-9]{2}');
        input.setAttribute('aria-haspopup','dialog');
        input.addEventListener('click',()=>openProjectWorkdayPicker(input));
        input.addEventListener('keydown',event=>{ if (event.key==='ArrowDown' && !input.readOnly) { event.preventDefault(); openProjectWorkdayPicker(input); } });
        input.addEventListener('change',()=>{
            if (!input.value || input.readOnly || projectDateIsSelectable(input.value)) return;
            showSystemNotification('Choose a working date. Non-working weekdays and excluded holidays are unavailable.');
            input.value='';
            input.dispatchEvent(new Event('input',{bubbles:true}));
        });
        const wrapper=document.createElement('div'); wrapper.className='project-workday-input';
        input.parentNode.insertBefore(wrapper,input); wrapper.append(input);
        const button=document.createElement('button'); button.type='button'; button.className='btn btn-outline-secondary'; button.textContent='▦';
        button.disabled=input.disabled || input.readOnly;
        button.setAttribute('aria-label',`Choose date for ${document.querySelector(`label[for="${id}"]`)?.textContent.trim() || id}`);
        button.addEventListener('click',()=>openProjectWorkdayPicker(input)); wrapper.append(button);
    }
}

function openProjectWorkdayPicker(input) {
    if (input.disabled || input.readOnly) return;
    projectDatePickerInput=input;
    if (!projectDatePickerDialog) {
        const dialog=document.createElement('dialog'); dialog.className='project-workday-dialog';
        dialog.innerHTML='<h3 id="projectWorkdayPickerTitle" class="fs-5">Choose a working date</h3><div class="project-workday-month"><button type="button" data-step="-1" aria-label="Previous month">‹</button><select aria-label="Month"></select><input type="number" min="1" max="9999" aria-label="Year"><button type="button" data-step="1" aria-label="Next month">›</button></div><div class="project-workday-grid" role="group" aria-label="Calendar dates"></div><p class="small text-muted mt-3">Shaded dates are non-working weekdays or excluded holidays.</p><div class="d-flex justify-content-between"><button type="button" data-clear>Clear date</button><button type="button" data-close>Close</button></div>';
        dialog.setAttribute('aria-labelledby','projectWorkdayPickerTitle'); document.body.append(dialog); projectDatePickerDialog=dialog;
        const month=dialog.querySelector('select');
        for (let i=0;i<12;i++) month.add(new Option(new Date(2026,i,1).toLocaleString('en',{month:'long'}),i));
        month.addEventListener('change',renderProjectWorkdayPicker); dialog.querySelector('input').addEventListener('change',renderProjectWorkdayPicker);
        dialog.querySelectorAll('[data-step]').forEach(button=>button.addEventListener('click',()=>{
            const year=dialog.querySelector('input'); const next=new Date(Date.UTC(Number(year.value),Number(month.value)+Number(button.dataset.step),1));
            if (next.getUTCFullYear()<1 || next.getUTCFullYear()>9999) return;
            year.value=next.getUTCFullYear(); month.value=next.getUTCMonth(); renderProjectWorkdayPicker();
        }));
        dialog.querySelector('[data-close]').addEventListener('click',()=>dialog.close());
        dialog.querySelector('[data-clear]').addEventListener('click',()=>chooseProjectWorkingDate(''));
        dialog.addEventListener('close',()=>projectDatePickerInput?.focus());
    }
    const date=projectCalendarDate(input.value) || new Date();
    projectDatePickerDialog.querySelector('select').value=date.getUTCMonth(); projectDatePickerDialog.querySelector('input').value=date.getUTCFullYear();
    renderProjectWorkdayPicker(); projectDatePickerDialog.showModal();
}

function renderProjectWorkdayPicker() {
    const dialog=projectDatePickerDialog; const grid=dialog.querySelector('.project-workday-grid'); grid.replaceChildren();
    const year=Number(dialog.querySelector('input').value),month=Number(dialog.querySelector('select').value);
    if (!Number.isInteger(year) || year<1 || year>9999) return;
    for (const day of ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']) { const heading=document.createElement('span'); heading.textContent=day; grid.append(heading); }
    const first=projectCalendarDate(`${String(year).padStart(4,'0')}-${String(month+1).padStart(2,'0')}-01`);
    for (let i=0;i<first.getUTCDay();i++) grid.append(document.createElement('span'));
    const last=new Date(first); last.setUTCMonth(month+1); last.setUTCDate(0);
    for (let day=1;day<=last.getUTCDate();day++) {
        const value=`${String(year).padStart(4,'0')}-${String(month+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
        const button=document.createElement('button'); button.type='button'; button.textContent=day; button.disabled=!projectDateIsSelectable(value);
        const calendar=window.projectHolidayCalendar;
        const excluded=calendar?.dateOverrides?.[value] ?? (calendar?.excludeHolidays !== false);
        const holiday=excluded && calendar?.years?.[year]?.[value];
        button.title=button.disabled ? (holiday || 'Non-working weekday') : value;
        button.setAttribute('aria-label',`${value}${button.disabled ? ': '+button.title : ''}`);
        if (value===projectDatePickerInput.value) button.classList.add('selected');
        button.addEventListener('click',()=>chooseProjectWorkingDate(value)); grid.append(button);
    }
}

function chooseProjectWorkingDate(value) {
    if (!projectDatePickerInput || projectDatePickerInput.disabled || projectDatePickerInput.readOnly) return;
    if (value && !projectDateIsSelectable(value)) return;
    projectDatePickerInput.value=value;
    projectDatePickerInput.dispatchEvent(new Event('input',{bubbles:true}));
    projectDatePickerInput.dispatchEvent(new Event('change',{bubbles:true})); projectDatePickerDialog.close();
}

document.addEventListener('DOMContentLoaded',setupProjectWorkdayPickers);
window.addEventListener('sharedDatabaseReady',setupProjectWorkdayPickers);
