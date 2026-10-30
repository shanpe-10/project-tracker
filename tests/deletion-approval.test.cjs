const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function adapter() {
    const requests = [];
    const context = { window: { sessionCsrfToken: 'fixture' }, console, setTimeout, URLSearchParams,
        fetch: async (url, options) => {
            requests.push(JSON.parse(options.body));
            return { ok: true, status: 202, json: async () => ({ ok: true, pending: true, requestId: 10 }) };
        } };
    vm.createContext(context);
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/js/db.js'), 'utf8'), context);
    return { db: context.createServerDatabase({ projects: [{ id: 1, title: 'Keep until confirmed' }], history: [{ id: 5, projectId: 1 }] }), requests };
}
function complete(transaction) { return new Promise((resolve, reject) => { transaction.oncomplete = resolve; transaction.onerror = event => reject(event.target.error); }); }
function read(db, name) { return new Promise(resolve => { const request = db.transaction([name], 'readonly').objectStore(name).getAll(); request.onsuccess = event => resolve(event.target.result); }); }

test('legacy project delete submits its reason and retains project/history in the browser while pending', async () => {
    const { db, requests } = adapter();
    const transaction = db.transaction(['projects', 'history'], 'readwrite');
    const done = complete(transaction);
    transaction.objectStore('projects').delete(1, 'Duplicate record');
    await done;
    assert.deepEqual(requests[0], { action: 'deleteProject', id: 1, reason: 'Duplicate record' });
    assert.equal((await read(db, 'projects')).length, 1);
    assert.equal((await read(db, 'history')).length, 1);
});

test('legacy bulk delete retains all cached records when the server creates an approval request', async () => {
    const { db, requests } = adapter();
    const transaction = db.transaction(['projects', 'history'], 'readwrite');
    const done = complete(transaction);
    transaction.objectStore('projects').clear('Old records');
    await done;
    assert.deepEqual(requests[0], { action: 'clearStore', store: 'projects', reason: 'Old records' });
    assert.equal((await read(db, 'projects')).length, 1);
    assert.equal((await read(db, 'history')).length, 1);
});

test('legacy history deletion keeps its history entry while pending', async () => {
    const { db } = adapter();
    const transaction = db.transaction(['history'], 'readwrite');
    const done = complete(transaction);
    transaction.objectStore('history').delete(5, 'Incorrect log');
    await done;
    assert.equal((await read(db, 'history')).length, 1);
    assert.equal((await read(db, 'projects')).length, 1);
});
