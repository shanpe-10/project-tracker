/* Shared form, details and import rules for the seven project sections. */
function projectLdNotApplicable(record) { return /^N\/A$/i.test(String(record.ldAmount ?? '').trim()); }
function normalizeProjectRevisionPayload(record) {
    if (record.physicalAccomplishment != null) {
        const raw = String(record.physicalAccomplishment).trim().replace(/%$/, '').trim();
        record.physicalAccomplishment = /^NYS$/i.test(raw) ? '0' : raw;
    }
    if (record.statusMode === 'manual') record.manualStatus = record.status;
    else if (record.statusMode === 'automatic') record.manualStatus = null;
    record.ldNotApplicable = projectLdNotApplicable(record);
    if (record.ldNotApplicable) {
        record.ldAmount = record.ldNoOfDays = record.ldRemarks = 'N/A';
        record.ldNewCompletionDate = record.ldActualDuration = null;
    }
    return record;
}

function validateProjectRevisionFields(record, previous = typeof projectEditSnapshot === 'undefined' ? {} : (projectEditSnapshot || {})) {
    const errors = [];
    const normalized = normalizeProjectRevisionPayload({...record});
    for (const field of projectFieldDefinitions) {
        const value = normalized[field.key];
        if (value == null || value === '' || String(value) === String(previous[field.key] ?? '')) continue;
        if (String(value).length > field.max) errors.push(`${field.key}: ${field.label} is too long.`);
        if (field.type === 'digits' && !/^\d+$/.test(String(value))) errors.push(`${field.key}: ${field.label} must contain digits only.`);
        if (field.type === 'percentage' && (!/^\d+(?:\.\d+)?$/.test(String(value)) || Number(value) < 0 || Number(value) > 100)) errors.push(`${field.key}: Physical Accomplishment must be from 0 to 100%.`);
    }
    return errors;
}

function loadProjectRevisionRecord(record = {}) {
    const mode = document.getElementById('statusMode');
    const legacyManual = (!record.statusMode && ((record.status === 'Completed' && !record.completion) || (record.status === 'Released' && !record.swa)));
    if (mode) mode.value = record.statusMode || (legacyManual ? 'manual' : 'automatic');
    const status = document.getElementById('status');
    if (status) status.value = record.manualStatus || record.status || 'On-going Bidding';
    const physical = document.getElementById('physicalAccomplishment');
    if (physical) physical.value = normalizeProjectRevisionPayload({...record}).physicalAccomplishment ?? '';
    for (const key of ['ldNoOfDays','ldNewCompletionDate','ldActualDuration','ldRemarks']) {
        const input = document.getElementById(key);
        if (input) { delete input.dataset.ldPrevious; input.disabled = false; }
    }
    syncProjectRevisionForm();
}

function syncProjectRevisionForm() {
    const mode = document.getElementById('statusMode');
    if (mode && !mode.value) mode.value = 'automatic';
    const status = document.getElementById('status');
    if (status) status.disabled = mode?.value !== 'manual';
    const isNa = projectLdNotApplicable({ldAmount:document.getElementById('ldAmount')?.value});
    for (const key of ['ldNoOfDays','ldNewCompletionDate','ldActualDuration','ldRemarks']) {
        const input = document.getElementById(key);
        if (!input) continue;
        if (isNa && !input.disabled) {
            input.dataset.ldPrevious = input.value;
            input.value = ['ldNoOfDays','ldRemarks'].includes(key) ? 'N/A' : '';
        } else if (!isNa && input.disabled) {
            input.value = input.dataset.ldPrevious || '';
            delete input.dataset.ldPrevious;
        }
        input.disabled = isNa;
        input.placeholder = isNa ? 'N/A' : '';
        const picker = input.closest('.project-workday-input')?.querySelector('button');
        if (picker) picker.disabled = isNa;
    }
    document.getElementById('ldNotApplicableHint')?.classList.toggle('d-none', !isNa);
    const physical = document.getElementById('physicalAccomplishment');
    const display = document.getElementById('physicalAccomplishmentDisplay');
    if (display && physical) {
        const raw = physical.value.trim().replace(/%$/, '').trim();
        display.textContent = raw === '' ? 'Not specified. Zero displays as NYS.' : /^NYS$/i.test(raw) || (Number.isFinite(Number(raw)) && Number(raw) === 0) ? 'NYS' : `${raw}%`;
    }
}

function renderRevisedProjectDetails(project) {
    const dates = getProjectMilestoneDates(project);
    const na = projectLdNotApplicable(project);
    const display = field => {
        const raw = na && ['ldAmount','ldNoOfDays','ldNewCompletionDate','ldActualDuration','ldRemarks'].includes(field.key) ? 'N/A' : project[field.key];
        if (raw == null || raw === '') return '<span class="project-detail-empty">Not specified</span>';
        if (field.type === 'percentage' && Number.isFinite(Number(raw))) return Number(raw) === 0 ? 'NYS' : `${escapeHtml(String(Number(raw)))}%`;
        if (field.type === 'money' && /^\d+(?:\.\d+)?$/.test(String(raw).replace(/[,₱$\s]/g,''))) return escapeHtml(new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(Number(String(raw).replace(/[,₱$\s]/g,''))));
        return escapeHtml(String(raw)).replace(/\n/g,'<br>');
    };
    const fieldHtml = field => `<div class="project-detail-field"><div class="project-detail-label">${escapeHtml(field.label)}</div><div class="project-detail-value">${display(field)}</div></div>`;
    return `<div class="project-details-summary"><div><div class="project-details-project-number">Project Number ${escapeHtml(String(project.projectNo ?? ''))}</div><h3 class="project-details-project-title">${escapeHtml(project.title || '')}</h3><div class="project-details-contractor">${escapeHtml(project.winningBidder || '')}</div></div><span class="project-details-status ${getStatusClass(project.status)}">${escapeHtml(project.status || '')}</span></div>` + projectSections.map(section => {
        const fields = projectFieldDefinitions.filter(field => field.section === section.id && !field.hidden);
        let html = `<section class="project-detail-section"><h4 class="project-detail-section-title">${escapeHtml(section.label)}</h4><div class="project-detail-grid">${fields.filter(f=>!f.legacy).map(fieldHtml).join('')}</div>`;
        if (section.id === 'contract') html += `<div class="project-detail-grid project-detail-due-grid">${Object.entries({preInspectionDueDate:'Pre-Inspection Due Date',ntpDueDate:'Notice to Proceed Due Date',materialTestingDueDate:'Material Testing Due Date',finalInspectionDueDate:'Final Inspection Due Date',contractEndReminderDate:'Contract End Follow-up Date'}).map(([key,label])=>fieldHtml({key,label,type:'date',...{}}).replace('Not specified',escapeHtml(dates[key] || 'Not specified'))).join('')}</div>`;
        if (section.id === 'completion') {
            html += `<div class="project-detail-grid">${fieldHtml({key:'swaDueDate',label:'SWA Due Date',type:'date'}).replace('Not specified',escapeHtml(getSwaDueDateValue(project.completion)||'Not specified'))}</div>`;
            html += `<h5 class="mt-3">Attachments</h5>` + ((project.attachments || []).length ? `<div class="list-group">${project.attachments.map(file=>{
                const url = `api/attachments.php?projectId=${encodeURIComponent(project.id)}&attachmentId=${encodeURIComponent(file.id)}`;
                return `<div class="list-group-item"><a href="${url}" data-preview-name="${escapeHtml(file.name)}" data-preview-mime="${escapeHtml(file.mime || '')}" onclick="openAttachmentPreview(event)">${escapeHtml(file.name)}</a><a class="btn btn-sm btn-outline-secondary ms-2" href="${url}&download=1">Download</a></div>`;
            }).join('')}</div>` : '<span class="project-detail-empty">No attachments</span>');
        }
        const legacy = fields.filter(f=>f.legacy && project[f.key] != null && project[f.key] !== '');
        if (legacy.length) html += `<details open class="mt-3"><summary>Additional details</summary><div class="project-detail-grid">${legacy.map(fieldHtml).join('')}</div></details>`;
        return html+'</section>';
    }).join('');
}

document.addEventListener('DOMContentLoaded',()=>{
    for (const id of ['statusMode','status','ldAmount','physicalAccomplishment']) {
        document.getElementById(id)?.addEventListener('input',()=>{
            syncProjectRevisionForm();
            if (typeof updateCompletionDateHint === 'function') updateCompletionDateHint();
        });
    }
    syncProjectRevisionForm();
});
