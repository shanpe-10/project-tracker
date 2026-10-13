const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const element = (value = '') => ({ value, style: {}, disabled: false, hidden: false, listeners: {},
    classList: { toggle() {}, add() {}, remove() {}, contains() { return false; } },
    addEventListener(name, fn) { this.listeners[name] = fn; }, removeEventListener() {}, setAttribute() {} });
const fields = new Map(['status', 'completionDate', 'completionDateHint', 'operationalStatusHelp',
    'swa', 'biddingDate', 'finalInspection', 'contractEndDate'].map(id => [id, element()]));
const ctx = vm.createContext({ console, Date, Intl, Map, Set, URLSearchParams, setTimeout, clearTimeout,
    setInterval() {}, clearInterval() {}, localStorage: { getItem() { return null; } },
    document: { addEventListener() {}, getElementById: id => fields.get(id) || null,
        querySelectorAll() { return []; }, body: { classList: { contains() { return true; } } } },
    addEventListener() {}, showSystemNotification() {} });
ctx.window = ctx;
for (const name of ['state', 'utils', 'projects', 'timeline'])
    vm.runInContext(fs.readFileSync(path.join(root, 'public/js', name + '.js'), 'utf8'), ctx);
fields.get('status').options = [{ value: 'Automatic', textContent: '' }];
fields.get('swa').value = '2026-10-09';
fields.get('completionDate').value = '2026-10-08';
for (const status of ['Pending', 'On-going Bidding', 'Pre-inspection', 'Final-inspection', 'Completed', 'Released']) {
    fields.get('status').value = status;
    ctx.updateCompletionDateHint();
    assert.equal(fields.get('status').value, status);
    assert.equal(fields.get('status').disabled, false);
    assert.match(fields.get('operationalStatusHelp').textContent, /takes priority/);
}
ctx.initializeLiveValueTracking();
fields.get('status').value = 'Automatic';
fields.get('status').listeners.change();
assert.equal(fields.get('status').value, 'Automatic');
assert.equal(fields.get('status').options[0].textContent, 'Automatic (Released)');
assert.equal(fields.get('status').disabled, false);
fields.get('swa').value = '';
fields.get('completionDate').value = '';
ctx.updateCompletionDateHint();
assert.equal(fields.get('status').options[0].textContent, 'Automatic (On-going Bidding)'); // Removing an override cannot latch Released.
fields.get('biddingDate').value = '2026-10-01';
ctx.updateCompletionDateHint();
assert.equal(fields.get('status').options[0].textContent, 'Automatic (Pre-inspection)');
fields.get('status').value = 'Pending';
ctx.currentUser = { id: 1, role: 'admin' };
assert.equal(ctx.preserveSuspendedProjectDraft(), true);
fields.get('status').value = 'Automatic';
const draft = vm.runInContext('suspendedProjectDrafts.get(1)', ctx);
ctx.renderProjectAttachments = () => {};
ctx.applySuspendedProjectDraft(draft);
assert.equal(fields.get('status').value, 'Pending');

// Exercise the actual spreadsheet parser and bulk-import payload for round trips.
const imports = vm.createContext({ console, Date, Uint8Array, ArrayBuffer, setTimeout, clearTimeout,
    document: { getElementById() { return null; }, addEventListener() {} },
    currentUser: { role: 'admin', canImport: true }, alert() {},
    XLSX: { read: () => ({ SheetNames: ['Projects'], Sheets: { Projects: {} } }),
        utils: { sheet_to_json: () => imports.rows } },
    FileReader: class { readAsArrayBuffer() { this.onload({ target: { result: new ArrayBuffer(1) } }); } } });
imports.window = imports;
vm.runInContext(fs.readFileSync(path.join(root, 'public/js/excel.js'), 'utf8'), imports);
vm.runInContext('showImportPreview=(rows)=>{captured=rows}', imports);
imports.rows = [{ 'Project No.': '1', 'Project Title': 'Manual import', 'Operational Status': 'Pending',
    'Manual Operational Status': 'Pending' }];
imports.handleExcelImport({ target: { files: [{}], value: 'manual.csv' } });
assert.equal(imports.captured[0].manualStatus, 'Pending');
assert.equal(imports.validateImportedProject({ title: 'Valid', manualStatus: 'invalid' }, 2).length, 1);
imports.rows[0]['Manual Operational Status'] = '';
imports.handleExcelImport({ target: { files: [{}], value: 'auto.csv' } });
assert.equal(imports.captured[0].manualStatus, '');
delete imports.rows[0]['Manual Operational Status'];
imports.handleExcelImport({ target: { files: [{}], value: 'legacy.csv' } });
assert.equal(Object.hasOwn(imports.captured[0], 'manualStatus'), false);

// Accounts without a photo use initials without requesting a missing profile resource.
const authElements = new Map();
const auth = vm.createContext({ console, Date, clearInterval() {}, setInterval() {},
    document: { body: element(), addEventListener() {}, querySelector() { return null; }, querySelectorAll() { return []; },
        getElementById(id) { if (!authElements.has(id)) authElements.set(id, element()); return authElements.get(id); } } });
auth.window = auth;
vm.runInContext(fs.readFileSync(path.join(root, 'public/js/auth.js'), 'utf8'), auth);
auth.applyAuthenticatedUser({ id: 1, role: 'staff', username: 'fixture', profilePhoto: 'api/profile.php?action=photo&userId=1' }, 'csrf');
assert.match(authElements.get('currentUserInitial').style.backgroundImage, /profile.php/);
auth.applyAuthenticatedUser({ id: 2, role: 'staff', username: 'other', profilePhoto: null }, 'csrf');
assert.equal(authElements.get('currentUserInitial').style.backgroundImage, '');
assert.equal(authElements.get('accountMenuAvatar').style.backgroundImage, '');

// Each background dashboard refresh requests notifications only through loadDocuments.
const timers = {};
const app = vm.createContext({ console, dbReady: true, currentUser: { role: 'admin' }, activeWorkspace: 'dashboard',
    document: { visibilityState: 'visible', addEventListener() {} }, addEventListener() {},
    restoreLastWorkspace() { app.loadDocuments(); }, loads: 0, notifications: 0,
    loadDocuments() { app.loads++; app.notifications++; }, refreshGlobalNotifications() { app.notifications++; },
    setInterval(fn, delay) { timers[delay] = fn; } });
app.window = app;
vm.runInContext(fs.readFileSync(path.join(root, 'public/js/app.js'), 'utf8'), app);
timers[30000]();
assert.equal(app.loads, 2);
assert.equal(app.notifications, 2);
console.log('Manual status priority, automatic reset, suspended drafts, imports, avatars and notification polling passed.');
