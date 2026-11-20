const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const context = vm.createContext({ setTimeout, clearTimeout, console: { error() {} }, URLSearchParams, Event: class {} });
context.window = context;
context.document = { addEventListener() {}, getElementById() { return null; } };
context.alert = () => {};
context.showSystemNotification = () => {};
context.FileReader = class {
    readAsArrayBuffer() { this.onload({ target: { result: new ArrayBuffer(1), value: '' } }); }
};
context.XLSX = {
    read() { return { SheetNames: ['Projects'], Sheets: { Projects: {} } }; },
    utils: { decode_range(){return {s:{c:0,r:0},e:{c:-1,r:0}};}, sheet_to_json() { return context.rows; } }
};

vm.runInContext(fs.readFileSync(path.join(root, 'public/js/db.js'), 'utf8'), context);
vm.runInContext(fs.readFileSync(path.join(root, 'public/js/project-fields.js'), 'utf8'), context);
vm.runInContext(fs.readFileSync(path.join(root, 'public/js/project-revision.js'), 'utf8'), context);
vm.runInContext(fs.readFileSync(path.join(root, 'public/js/excel.js'), 'utf8'), context);

async function main() {
    context.currentUser = { role: 'staff', canImport: true };
    context.captured = null;
    vm.runInContext('showImportPreview = rows => { captured = rows; }', context);
    context.rows = [{ 'Project No.': '42', 'Project Title': 'Road repair', 'Operational Status': 'Completed', 'Completion Date': '2025-01-02', 'LD Amount': '50' }];
    vm.runInContext('handleExcelImport({ target: { files: [{}], value: "chosen.csv" } })', context);
    assert.equal(context.captured[0].title, 'Road repair');
    assert.equal(context.captured[0].status, 'Completed');
    assert.equal(context.captured[0].completion, '2025-01-02');
    assert.equal(context.captured[0].ldAmount, '50');
    context.rows = [{ 'Project No.': '43', '2. Project Title': 'Bridge', '23. LD Amount': '75' }];
    vm.runInContext('handleExcelImport({ target: { files: [{}], value: "chosen.xlsx" } })', context);
    assert.equal(context.captured[0].title, 'Bridge');
    assert.equal(context.captured[0].ldAmount, '75');

    context.failedPost = () => Promise.reject(new Error('server rejected write'));
    vm.runInContext('apiClient.post = failedPost', context);
    const db = vm.runInContext('createServerDatabase({ projects: [], history: [] })', context);
    const events = [];
    const tx = db.transaction();
    tx.onerror = () => events.push('error');
    tx.oncomplete = () => events.push('complete');
    tx.objectStore('projects').add({ title: 'Demo' });
    await new Promise(resolve => setTimeout(resolve, 20));
    assert.deepEqual(events, ['error']);
    console.log('High-priority JavaScript regressions passed.');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
