const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const table = { dataset: {}, innerHTML: '', closest() { return { scrollLeft: 0, scrollTop: 0 }; } };
const summary = { innerHTML: '' };
const button = { disabled: false };
const elements = { importPreviewTable: table, importPreviewSummary: summary, continueImportPreviewButton: button, importPreviewModal: {} };
const context = vm.createContext({ Date, console, setTimeout, clearTimeout, URLSearchParams });
context.window = context;
vm.runInContext(fs.readFileSync(path.join(__dirname,'../public/js/utils.js'),'utf8'),context);
context.document = { getElementById(id) { return elements[id] || null; }, addEventListener() {} };
context.XLSX = { SSF: { parse_date_code() { return null; } } };
context.escapeHtml = value => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
context.getNextProjectNumber = projects => Math.max(0, ...projects.map(project => Number.parseInt(project.projectNo, 10) || 0)) + 1;
context.bootstrap = { Modal: { getInstance() { return { hide() {} }; } } };
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

assert.match(table.innerHTML, /Duplicate/);
assert.match(table.innerHTML, /Overwrite existing project/);
assert.match(table.innerHTML, /Add as a new project/);
assert.match(table.innerHTML, /Existing road/);
assert.match(table.innerHTML, /New project no\.<input[^>]+value="15" readonly/);
assert.match(table.innerHTML, /Original spreadsheet no\. 12/);
assert.match(summary.innerHTML, /1.*existing matches/);
assert.match(summary.innerHTML, /1.*invalid/);
assert.equal(button.disabled, true);

vm.runInContext(`importPreviewChoices.set(0, { action: 'overwrite', existingId: 7 }); renderImportPreview();`, context);
assert.match(table.innerHTML, /value="overwrite" selected/);
assert.doesNotMatch(table.innerHTML, /New project no\.<input[^>]+value="15" readonly/);

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
assert.match(table.innerHTML, /Number and title match different existing projects/);
assert.equal(button.disabled, true);

vm.runInContext(`
    importPreviewRows = [{ title: 'Existing road' }];
    importPreviewExistingProjects = [{ id: 7, projectNo: '12', title: 'Existing road' }];
    importPreviewChoices = new Map([[0, { action: 'overwrite', existingId: 7 }]]);
    importPreviewAssignedNumbers = new Map();
    originalProcessImportedRows = processImportedRows;
    processImportedRows = (rows, isolated, choices) => { captured = { rows, isolated, choices }; };
    continueImportPreview();
`, context);
assert.equal(context.captured.choices[0].action, 'overwrite');
assert.equal(context.captured.choices[0].existingId, 7);

vm.runInContext(`
    importPreviewRows = [{ 'Project No.': '12', title: 'Existing road' }];
    importPreviewChoices = new Map([[0, { action: 'new', existingId: 7 }]]);
    importPreviewAssignedNumbers = new Map([[0, '15']]);
    continueImportPreview();
`, context);
assert.equal(context.captured.choices[0].assignedProjectNo, '15');
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
        false,
        { 0: { action: 'new', existingId: 7, assignedProjectNo: '15' } }
    );
`, context);
assert.equal(context.savedImport[0].projectNo, '15');
assert.equal(context.savedImport[0].sourceProjectNo, '12');
assert.match(context.savedImport[0].remarks, /Imported project no\.: 12/);
console.log('Import preview duplicate detection and action selection passed.');
