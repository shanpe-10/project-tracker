let holidaySyncData = null;
let holidaySyncBusy = false;

async function holidayRequest(payload) {
    return apiClient.request('api/holidays.php', payload ? {method:'POST', body:JSON.stringify(payload)} : {});
}

async function loadHolidayCalendar() {
    if (window.currentUser?.role !== "superadmin") return;
    const message = document.getElementById('holidaySyncMessage');
    try { holidaySyncData = await holidayRequest(); renderHolidayCalendar(); }
    catch (error) { if (message) message.textContent = error.message; }
}

function renderHolidayCalendar() {
    if (!holidaySyncData) return;
    const {state,calendar} = holidaySyncData;
    window.projectHolidayCalendar = calendar;
    const canManage = window.currentUser?.role === 'superadmin';
    document.getElementById('holidaySyncControls').hidden = !canManage;
    document.getElementById('holidayOverrideForm').hidden = !canManage;
    document.getElementById('holidaySyncControls').classList.toggle('d-none',!canManage);
    document.getElementById('holidayOverrideForm').classList.toggle('d-none',!canManage);
    document.getElementById('holidaySyncEnabled').checked = state.enabled;
    document.getElementById('holidayExcludeHolidays').checked = calendar.excludeHolidays !== false;
    document.querySelectorAll('[data-non-working-day]').forEach(input=>input.checked=(calendar.nonWorkingDays || [0,6]).includes(Number(input.value)));
    const displayTime = value => value ? new Date(value).toLocaleString('en-PH', {timeZone:'Asia/Manila'}) : 'Never';
    document.getElementById('holidaySyncStatus').textContent = `${state.enabled ? 'Automatic updates enabled' : 'Automatic updates disabled'} · Last successful sync: ${displayTime(state.lastSuccess)} · Last attempt: ${displayTime(state.lastAttempt)}`;
    document.getElementById('holidaySyncMessage').textContent = state.error || '';
    const yearSelect = document.getElementById('holidayCalendarYear');
    const selected = yearSelect.value || String(new Date().getFullYear());
    const years = [...new Set([...Object.keys(calendar.years), ...Object.keys(state.overrides).map(date=>date.slice(0,4))])].sort();
    yearSelect.replaceChildren(...years.map(year=>new Option(year,year)));
    yearSelect.value = years.includes(selected) ? selected : years.at(-1);
    const year = yearSelect.value;
    const entries = {...(calendar.years[year] || {})};
    Object.entries(state.overrides).forEach(([date,entry])=>{if (date.startsWith(year+'-')) entries[date]=entry.name;});
    const rows = Object.entries(entries).sort(([a],[b])=>a.localeCompare(b)).map(([date,name])=>{
        const tr=document.createElement('tr');
        const override=state.overrides[date];
        const values=[date,name,override ? (override.nonWorking ? 'Non-working · manual' : 'Working · manual') : 'Non-working · official calendar'];
        for (const value of values) { const td=document.createElement('td'); td.textContent=value; tr.append(td); }
        const action=document.createElement('td');
        if (canManage) {
            const choose=document.createElement('button'); choose.type='button'; choose.className='btn btn-sm btn-outline-primary me-2'; choose.textContent=override?.nonWorking === false ? 'Exclude date' : 'Count as working';
            choose.addEventListener('click',()=>saveHolidayChange('override',{date,name,nonWorking:override?.nonWorking === false})); action.append(choose);
        }
        if (canManage && override) {
            const button=document.createElement('button'); button.type='button'; button.className='btn btn-sm btn-outline-secondary'; button.textContent='Restore source';
            button.addEventListener('click',()=>saveHolidayChange('restore',{date})); action.append(button);
        }
        tr.append(action); return tr;
    });
    document.getElementById('holidayCalendarRows').replaceChildren(...rows);
    const pending=(calendar.pending?.[year] || []).join(', ');
    document.getElementById('holidayCalendarCoverage').textContent = `Loaded years: ${years.join(', ')}.${pending ? ` Awaiting proclamation: ${pending}.` : ''} Local holidays can be added by Superadmin. Dates outside loaded years need a calendar update.`;
    const review=document.getElementById('holidaySyncReview'); review.replaceChildren();
    for (const item of state.review || []) {
        const li=document.createElement('li'); const link=document.createElement('a');
        if (!/^https:\/\/pco\.gov\.ph\/news_releases\/[a-z0-9-]+\/$/.test(item.url)) continue;
        link.href=item.url; link.target='_blank'; link.rel='noopener'; link.textContent=item.title; li.append(link); review.append(li);
    }
    document.getElementById('holidaySyncReviewPanel').hidden = !(state.review || []).length;
}

async function saveHolidayChange(action, fields = {}) {
    if (holidaySyncBusy || window.currentUser?.role !== 'superadmin' || !holidaySyncData) return;
    holidaySyncBusy=true;
    const controls=document.querySelectorAll('#holidaySyncControls button, #holidaySyncControls input, #holidayOverrideForm button, #holidayOverrideForm input, #holidayOverrideForm select');
    controls.forEach(control=>control.disabled=true);
    const message=document.getElementById('holidaySyncMessage');
    message.textContent=action === 'sync' ? 'Checking official holiday announcements…' : 'Saving holiday settings…';
    try {
        holidaySyncData=await holidayRequest({action,revision:holidaySyncData.state.revision,...fields});
        renderHolidayCalendar();
        message.textContent=action === 'sync' ? 'Official announcements checked. The calendar is up to date for supported declarations.' : 'Holiday settings saved.';
        if (typeof db?.refresh === 'function') await db.refresh();
    } catch (error) { await loadHolidayCalendar(); message.textContent=error.message; }
    finally { holidaySyncBusy=false; controls.forEach(control=>control.disabled=false); }
}

function submitHolidayOverride(event) {
    event.preventDefault();
    const form=event.currentTarget;
    saveHolidayChange('override',{date:form.elements.date.value,name:form.elements.name.value,nonWorking:form.elements.nonWorking.value==='true'});
}

window.addEventListener('sharedDatabaseReady',loadHolidayCalendar);

function saveHolidayOptions() {
    const nonWorkingDays=[...document.querySelectorAll('[data-non-working-day]:checked')].map(input=>Number(input.value));
    if (nonWorkingDays.length === 7) { document.getElementById('holidaySyncMessage').textContent='Keep at least one working weekday.'; return; }
    saveHolidayChange('settings',{enabled:document.getElementById('holidaySyncEnabled').checked,excludeHolidays:document.getElementById('holidayExcludeHolidays').checked,nonWorkingDays});
}
