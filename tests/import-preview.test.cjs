const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const list = { parentElement: { scrollTop: 0 }, innerHTML: '' };
const summary = { innerHTML: '' };
const button = { disabled: false };
const note = {};
const elements = { importPreviewList: list, importPreviewSummary: summary, importPreviewEmpty: {}, importPreviewNote: note, continueImportPreviewButton: button, importPreviewModal: {} };
const context = vm.createContext({ Date, console, setTimeout, clearTimeout, URLSearchParams });
context.window = context;
vm.runInContext(fs.readFileSync(path.join(__dirname,'../public/js/utils.js'),'utf8'),context);
context.document = { getElementById(id) { return elements[id] || null; }, querySelectorAll() { return []; }, addEventListener() {} };
context.XLSX = { SSF: { parse_date_code() { return null; } } };
context.escapeHtml = value => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
context.getNextProjectNumber = projects => Math.max(0, ...projects.map(project => Number.parseInt(project.projectNo, 10) || 0)) + 1;
context.bootstrap = { Modal: { getInstance() { return { hide() {} }; } } };
for (const file of ['project-fields.js','project-revision.js']) vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/js',file),'utf8'),context);
const projectValidation = fs.readFileSync(path.join(__dirname, '../public/js/projects.js'),'utf8');
vm.runInContext(projectValidation.slice(projectValidation.indexOf('function validateProjectNumbersAndActualDates('),projectValidation.indexOf('function saveDocument(')),context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/js/excel.js'), 'utf8'), context);

vm.runInContext(`
    importPreviewRows = [
        { 'Project No.': '12', '2. Project Title': 'Existing road', '13. Notice of Award': '2026-10-01' },
        { 'Project No.': '13', '2. Project Title': 'New bridge' },
        { 'Project No.': '14', '2. Project Title': '' }
    ];
    importPreviewExistingProjects = [{ id: 7, projectNo: '12', title: 'Existing road' }];
    renderImportPreview();
`, context);

assert.match(list.innerHTML, /Existing match/);
assert.match(list.innerHTML, /Update existing project/);
assert.match(list.innerHTML, /Add as a new project/);
assert.match(list.innerHTML, /Existing road/);
assert.match(list.innerHTML, /New project no\. 15/);
assert.match(list.innerHTML, /Project no\. 12/);
assert.match(summary.innerHTML, /3 rows.*2.*can import/);
assert.match(note.textContent, /Fix 1 row/);
assert.equal(button.disabled, true);

vm.runInContext(`importPreviewChoices.set(0, { action: 'overwrite', existingId: 7 }); renderImportPreview();`, context);
assert.match(list.innerHTML, /value="overwrite" selected/);
assert.doesNotMatch(list.innerHTML, /New project no\. 15/);

vm.runInContext(`
    importPreviewRows = [
        { 'Project No.': '12', title: 'Existing road' },
        { 'Project No.': '13', title: 'Existing bridge' }
    ];
    importPreviewExistingProjects = [
        { id: 7, projectNo: '12', title: 'Existing road' },
        { id: 8, projectNo: '13', title: 'Existing bridge' }
    ];
    importPreviewChoices = new Map();
    renderImportPreview();
`, context);
assert.equal(vm.runInContext('importPreviewAssignedNumbers.get(0)', context), '14');
assert.equal(vm.runInContext('importPreviewAssignedNumbers.get(1)', context), '15');

vm.runInContext(`
    importPreviewRows = [{ 'Project No.': '12', '2. Project Title': 'Other title' }];
    importPreviewExistingProjects = [
        { id: 7, projectNo: '12', title: 'Existing road' },
        { id: 8, projectNo: '99', title: 'Other title' }
    ];
    importPreviewChoices = new Map();
    renderImportPreview();
`, context);
assert.match(list.innerHTML, /Project number and title match different saved projects/);
assert.equal(button.disabled, true);

vm.runInContext(`
    importPreviewRows = [{
        title: 'Existing road', preInspection: 'Pre-inspected', bidAmount: '-1',
        winningBidder: 'Updated bidder', losingBidAmount1: 0, prDate: '2/30/2026'
    }];
    Object.defineProperty(importPreviewRows[0], '__rowNum__', { value: 10 });
    importPreviewExistingProjects = [{ id: 7, projectNo: '12', title: 'Existing road' }];
    importPreviewChoices = new Map([[0, { action: 'overwrite', existingId: 7 }]]);
    importPreviewAssignedNumbers = new Map();
    originalProcessImportedRows = processImportedRows;
    processImportedRows = (rows, choices) => { captured = { rows, choices }; };
    renderImportPreview();
`, context);
assert.equal(button.disabled, false);
assert.match(list.innerHTML, /Row 11/);
assert.match(list.innerHTML, /Keep saved fields/);
assert.match(note.textContent, /3 cells have invalid formats/);
vm.runInContext(`
    continueImportPreview();
`, context);
assert.equal(context.captured.choices[0].action, 'overwrite');
assert.equal(context.captured.choices[0].existingId, 7);
assert.deepEqual(Object.keys(context.captured.rows[0]), ['title', 'winningBidder', 'losingBidAmount1', 'ldNotApplicable']);
assert.equal(context.captured.rows[0].ldNotApplicable, false);
assert.equal(context.captured.rows[0].losingBidAmount1, '0');
assert.equal(context.captured.rows[0].__rowNum__, 10);

vm.runInContext(`
    importPreviewRows = [{ 'Project No.': '12', title: 'Existing road' }];
    importPreviewExistingProjects = [{ id: 7, projectNo: '12', title: 'Existing road' }];
    importPreviewChoices = new Map([[0, { action: 'new', existingId: 7 }]]);
    continueImportPreview();
`, context);
assert.equal(context.captured.choices[0].assignedProjectNo, '13');

context.captured = null;
context.showSystemNotification = message => { context.notice = message; };
vm.runInContext(`
    window.projectHolidayCalendar = {
        nonWorkingDays: [0, 6], excludeHolidays: true,
        years: { 2026: { '2026-06-29': 'Local test holiday' } }
    };
    importPreviewRows = [
        { title: 'New project', contractEndDate: '28-Jun-2026', noa: '2026-06-26' },
        { title: 'Saved project', contractEndDate: '2026-06-28' }
    ];
    importPreviewExistingProjects = [{ id: 8, projectNo: '20', title: 'Saved project', contractEndDate: '2026-06-28' }];
    importPreviewChoices = new Map([[1, { action: 'overwrite', existingId: 8 }]]);
    renderImportPreview();
`, context);
assert.equal(button.disabled, false);
assert.match(list.innerHTML, /Contract End Date · 2026-06-28 → 2026-06-30/);
assert.match(note.textContent, /1 date adjusted using the working-day calendar/);
vm.runInContext(`window.projectHolidayCalendar.excludeHolidays = false; continueImportPreview();`, context);
assert.equal(context.captured, null);
assert.match(context.notice, /calendar changed/);
assert.match(list.innerHTML, /2026-06-28 → 2026-06-29/);
vm.runInContext('continueImportPreview()', context);
assert.equal(context.captured.rows[0].contractEndDate, '2026-06-29');
assert.equal(context.captured.rows[0].noa, '2026-06-26');
assert.equal(context.captured.rows[1].contractEndDate, '2026-06-28');

vm.runInContext(`
    importPreviewExistingProjects = [
        {id: 51, title: 'Repeated title', projectNo: '51'},
        {id: 52, title: 'Repeated title', projectNo: '52'}
    ];
    importPreviewRows = [{title: 'Repeated title'}];
    renderImportPreview();
`, context);
assert.match(list.innerHTML, /More than one saved project has this title/);
assert.equal(button.disabled, true);
vm.runInContext(`
    importPreviewRows[0].projectNo = '52';
    importPreviewChoices.set(0, {action:'overwrite',existingId:52});
    renderImportPreview();
`, context);
assert.equal(button.disabled, false);
assert.match(list.innerHTML, /Matches #52/);
vm.runInContext('cancelImportPreview()', context);

vm.runInContext('processImportedRows = originalProcessImportedRows', context);

context.document.querySelector = () => ({ value: 'new' });
context.setTimeout = () => 0;
vm.runInContext(`
    pendingImportPayloads = [];
    bulkImportConflictsQueue = [{
        existingId: 7, existingCreatedAt: '2025-01-01',
        existingTimelineCompletedMarked: true, existingTimelineAlertsCompleted: ['noa-overdue'],
        previewAction: 'overwrite', payload: { projectNo: '12', title: 'Existing road' }
    }];
    executeBulkImportProcessing(true);
`, context);
assert.equal(vm.runInContext('pendingImportPayloads[0].id', context), 7);
assert.equal(vm.runInContext('pendingImportPayloads[0].createdAt', context), '2025-01-01');

context.db = {
    transaction() {
        return { objectStore() {
            return { getAll() {
                return { set onsuccess(handler) {
                    handler({ target: { result: [{ id: 7, projectNo: '12', title: 'Existing road' }] } });
                } };
            } };
        } };
    }
};
vm.runInContext(`
    executeFinalDatabaseWriteTransaction = () => { savedImport = pendingImportPayloads.map(item => ({ ...item })); };
    processImportedRows(
        [{ 'Project No.': '12', '2. Project Title': 'Different new project' }],
        { 0: { action: 'new', existingId: 7, assignedProjectNo: '15' } }
    );
`, context);
assert.equal(context.savedImport[0].projectNo, '15');
assert.equal(context.savedImport[0].sourceProjectNo, '12');
assert.match(context.savedImport[0].remarks, /Imported project no\.: 12/);

context.setTimeout = fn => { fn(); return 0; };
vm.runInContext(`
    processImportedRows([{title:'Existing road',owner:'Updated owner'}], {0:{action:'overwrite',existingId:7}});
`, context);
assert.equal(context.savedImport[0].id, 7);
assert.equal(Object.hasOwn(context.savedImport[0], 'projectNo'), false);
assert.equal(context.savedImport[0].owner, 'Updated owner');
console.log('Import preview duplicate detection and action selection passed.');
