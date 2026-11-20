const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = process.env.TRACKER_SOURCE_ROOT || path.resolve(__dirname, '..');
function deferred() { let resolve; const promise = new Promise(r => resolve = r); return { promise, resolve }; }
function setup() {
    const listeners = {}, elements = {projectFormTabs:{addEventListener(){}},documentForm:{addEventListener(){}}}, classes = new Set();
    const ctx = vm.createContext({ console: { error() {}, warn() {} }, setTimeout, clearTimeout, URLSearchParams,
        Event: class {}, Map, Set, Date, FormData,
        document: { hidden: false, body: { classList: { contains: name => classes.has(name) } },
            addEventListener(name, fn) { (listeners[name] ||= []).push(fn); },
            getElementById: id => elements[id] || null, querySelector: () => null, querySelectorAll: () => [] },
        localStorage: { getItem: () => null, setItem() {} },
        activeWorkspace: 'documents', showSystemNotification() {},
        listRenders: 0, loadServerProjectPage: async () => { ctx.listRenders++; },
    });
    ctx.window = ctx; ctx.addEventListener = (name, fn) => (listeners[name] ||= []).push(fn);
    ctx.setInterval = (fn, delay) => { ctx.pollDelay = delay; };
    for (const name of ['db', 'state', 'projects']) vm.runInContext(fs.readFileSync(path.join(root, 'public/js', name + '.js'), 'utf8'), ctx);
    ctx.currentUser = { id: 2, role: 'staff', canEdit: true, canCreate: true };
    const buttonClasses = new Set(['d-none']);
    elements.sharedUpdatesButton = { classList: { toggle(name, enabled) { if (enabled) buttonClasses.add(name); else buttonClasses.delete(name); } } };
    ctx.loadServerProjectPage = async () => { ctx.listRenders++; };
    ctx.refreshGlobalNotifications = () => {};
    const init = seed => { ctx.seed = seed; vm.runInContext('db=createServerDatabase(seed); sharedProjectVersion=db.projectVersion; activeWorkspace="documents";', ctx); };
    return { ctx, init, classes, listeners, elements, buttonClasses };
}
async function run() {
    let t = setup(), c = t.ctx;
    t.init({ version: 'initial', projects: [] });
    c.apiClient.getProjectVersion = async () => ({ version: 'new' });
    c.apiClient.getBootstrap = async () => ({ version: 'new', projects: [{ id: 1, title: 'Saved by other account' }] });
    await c.checkForSharedProjectUpdates();
    assert.equal(c.listRenders, 1);
    assert.equal(vm.runInContext('sharedProjectVersion', c), 'new');
    for (const listener of t.listeners.DOMContentLoaded) listener();
    assert.equal(c.pollDelay, 3000);
    for (const event of ['focus', 'online', 'visibilitychange', 'hidden.bs.modal', 'sharedDatabaseReady']) assert(t.listeners[event]?.length);

    // No bootstrap baseline must trigger loading, rather than accepting unseen changes.
    t = setup(); c = t.ctx; t.init({ projects: [] });
    c.apiClient.getProjectVersion = async () => ({ version: 'saved-before-first-poll' });
    c.apiClient.getBootstrap = async () => ({ version: 'saved-before-first-poll', projects: [] });
    await c.checkForSharedProjectUpdates(); assert.equal(c.listRenders, 1);

    // An open draft is preserved, including when the update button is clicked.
    t = setup(); c = t.ctx; t.init({ version: 'v1', projects: [] });
    vm.runInContext('editingId=1;', c);
    c.apiClient.getProjectVersion = async () => ({ version: 'v2' });
    let reads = 0;
    c.apiClient.getBootstrap = async () => { reads++; return { version: 'v2', projects: [] }; };
    await c.checkForSharedProjectUpdates(); await c.refreshSharedProjectData();
    assert.equal(reads, 0); assert(!t.buttonClasses.has('d-none'));
    vm.runInContext('editingId=null;', c); await c.checkForSharedProjectUpdates();
    assert.equal(reads, 1); assert(t.buttonClasses.has('d-none'));

    // A commit during refresh is picked up next time: do not reread its marker after the snapshot.
    t = setup(); c = t.ctx; t.init({ version: 'v1', projects: [] });
    let latest = 'v2'; c.apiClient.getProjectVersion = async () => ({ version: latest });
    c.apiClient.getBootstrap = async () => { latest = 'v3'; return { version: 'v2', projects: [] }; };
    await c.checkForSharedProjectUpdates(); assert.equal(vm.runInContext('sharedProjectVersion', c), 'v2');
    c.apiClient.getBootstrap = async () => ({ version: 'v3', projects: [] });
    await c.checkForSharedProjectUpdates(); assert.equal(c.listRenders, 2);

    // A failed list render is retried even after its bootstrap data loaded.
    t = setup(); c = t.ctx; t.init({ version: 'v1', projects: [] });
    c.apiClient.getProjectVersion = async () => ({ version: 'v2' });
    c.apiClient.getBootstrap = async () => ({ version: 'v2', projects: [] });
    c.loadServerProjectPage = async () => false;
    await c.checkForSharedProjectUpdates(); assert.equal(vm.runInContext('sharedProjectVersion', c), null);
    assert(!t.buttonClasses.has('d-none'));
    c.loadServerProjectPage = async () => true;
    await c.checkForSharedProjectUpdates(); assert.equal(vm.runInContext('sharedProjectVersion', c), 'v2');
    assert(t.buttonClasses.has('d-none'));

    // A discarded stale response must not clear a pending update or advance its marker.
    t = setup(); c = t.ctx; t.init({ version: 'v1', projects: [] });
    const pending = deferred(); c.apiClient.getBootstrap = () => pending.promise;
    const refreshing = c.refreshSharedProjectData();
    vm.runInContext('db.acceptRecord("projects", {id:1,title:"Local commit"});', c);
    pending.resolve({ version: 'old', projects: [] }); await refreshing;
    assert.equal(vm.runInContext('sharedProjectVersion', c), 'v1'); assert.equal(c.listRenders, 0);

    // Hidden tabs do no polling; concurrent checks issue a single request.
    t = setup(); c = t.ctx; t.init({ version: 'v1', projects: [] });
    let checks = 0; const poll = deferred(); c.apiClient.getProjectVersion = () => { checks++; return poll.promise; };
    c.document.hidden = true; await c.checkForSharedProjectUpdates(); assert.equal(checks, 0);
    c.document.hidden = false; const checking = c.checkForSharedProjectUpdates();
    await c.checkForSharedProjectUpdates(); assert.equal(checks, 1); poll.resolve({ version: 'v1' }); await checking;

    // Save-state feedback is visible and disables repeat submission.
    t = setup(); c = t.ctx;
    const classList = { remove() {}, add() {} };
    t.elements.saveBtn = { setAttribute() {} }; t.elements.projectSaveStatus = { classList };
    c.setProjectSaveState(true, 'Saving…'); assert(t.elements.saveBtn.disabled);
    c.setProjectSaveState(false, 'Save failed: conflict', true); assert(!t.elements.saveBtn.disabled);
    assert.equal(t.elements.projectSaveStatus.textContent, 'Save failed: conflict');

    // Real save conflict handling retains the editor and original version token.
    t = setup(); c = t.ctx;
    const original = { id: 1, title: 'Original', updatedAt: 'old-version', projectNo: '1' };
    t.init({ version: 'v1', projects: [original] });
    c.original = original; vm.runInContext('editingId=1; projectEditSnapshot=original;', c);
    let sentRecord, resets = 0; const notices = [];
    c.resetFormAccess = () => { resets++; };
    c.showSystemNotification = message => notices.push(message);
    c.fetch = async (url, options) => {
        sentRecord = JSON.parse(options.body.get('record'));
        return { status: 409, ok: false, json: async () => ({ ok: false, message: 'Version conflict' }) };
    };
    c.apiClient.getProjectVersion = async () => ({ version: 'v2' });
    await c.updateDocument(1, { title: 'My unsaved draft' });
    await new Promise(resolve => setTimeout(resolve, 30));
    assert.equal(sentRecord.updatedAt, 'old-version'); assert.equal(resets, 0);
    assert.equal(vm.runInContext('editingId', c), 1);
    assert(notices.some(message => message.includes('Your draft is still open')));
    assert.equal(vm.runInContext('projectSaveInProgress', c), false);
    console.log('Shared-update frontend regressions passed: automatic refresh, baseline race, draft protection, stale response, visibility, polling overlap and save feedback.');
}
run().catch(error => { console.error(error); process.exitCode = 1; });
