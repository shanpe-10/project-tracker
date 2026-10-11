const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const flush = () => new Promise(resolve => setImmediate(resolve));

function setup(role = 'staff') {
    let clock = 2000000000000, serverActivity = clock, serverStart = clock;
    const events = {}, timers = {}, calls = [], logins = [];
    const elements = { sessionIdleWarning: { hidden: true }, sessionIdleCountdown: { textContent: '' } };
    const ctx = vm.createContext({ Date: class extends Date { static now() { return clock; } },
        document: { hidden: false, getElementById: id => elements[id] || null,
            addEventListener(name, fn) { events[name] = fn; } },
        setInterval(fn, ms) { timers[ms] = fn; },
        addEventListener(name, fn) { events[name] = fn; },
        currentUser: { id: 1, role }, sessionCsrfToken: 'csrf-one',
        showLogin(message) { logins.push(message); ctx.currentUser = null; ctx.stopTrackerSession(); },
        applyAuthenticatedUser(user, csrf) { ctx.currentUser = user; ctx.sessionCsrfToken = csrf; },
    });
    ctx.window = ctx;
    const timing = () => ({ serverNow: clock / 1000, expiresAt: serverStart / 1000 + 28800,
        idleExpiresAt: role === 'superadmin' ? null : serverActivity / 1000 + 1800 });
    ctx.authRequest = async action => {
        calls.push(action);
        if (clock >= serverStart + 28800000 || (role !== 'superadmin' && clock >= serverActivity + 1800000))
            return { ok: true, authenticated: false, reason: 'session_idle', message: 'Session expired' };
        if (action === 'activity') serverActivity = clock;
        return { ok: true, authenticated: true, user: { id: 1, role }, csrfToken: ctx.sessionCsrfToken, sessionTiming: timing() };
    };
    vm.runInContext(fs.readFileSync(path.join(root, 'public/js/session.js'), 'utf8'), ctx);
    ctx.startTrackerSession(timing());
    return { ctx, events, timers, calls, elements, logins, timing,
        advance(ms) { clock += ms; }, get clock() { return clock; } };
}

async function run() {
    let t = setup();
    t.advance(1740000); t.timers[1000](); await flush();
    assert.equal(t.elements.sessionIdleWarning.hidden, false);
    assert.equal(t.elements.sessionIdleCountdown.textContent, '60');
    t.timers[15000](); await flush();
    assert.deepEqual(t.calls, ['session']); // Passive polling never becomes user activity.
    t.events.keydown({ isTrusted: false }); await flush();
    assert.deepEqual(t.calls, ['session']);
    t.events.keydown({ isTrusted: true }); await flush();
    assert.equal(t.calls.at(-1), 'activity');
    assert.equal(t.elements.sessionIdleWarning.hidden, true);
    t.advance(1800000); t.timers[1000](); await flush();
    assert.equal(t.logins.length, 1);

    t = setup('superadmin');
    t.advance(3600000); t.events.pointerdown({ isTrusted: true }); t.timers[1000](); t.timers[15000](); await flush();
    assert.equal(t.elements.sessionIdleWarning.hidden, true);
    assert(!t.calls.includes('activity')); assert.equal(t.logins.length, 0);
    t.advance(25200000); t.timers[1000](); await flush();
    assert.equal(t.logins.length, 1); // The eight-hour maximum still applies.

    t = setup();
    t.ctx.authRequest = async () => ({ ok: true, authenticated: false, message: 'Your account was signed in elsewhere.' });
    t.timers[15000](); await flush();
    assert.equal(t.logins[0], 'Your account was signed in elsewhere.');

    t = setup();
    t.ctx.authRequest = async () => ({ ok: true, authenticated: true, user: { id: 1, role: 'staff' }, csrfToken: 'csrf-two', sessionTiming: t.timing() });
    t.timers[15000](); await flush();
    assert.equal(t.ctx.sessionCsrfToken, 'csrf-two'); assert.equal(t.logins.length, 0);
    t.ctx.handleTrackerAuthenticationFailure({ message: 'Old request failed' }, 'csrf-one');
    assert.equal(t.logins.length, 0);
    t.ctx.handleTrackerAuthenticationFailure({ message: 'New session revoked' }, 'csrf-two');
    assert.equal(t.logins[0], 'New session revoked');

    t = setup();
    let resolve;
    t.ctx.authRequest = () => new Promise(r => { resolve = r; });
    t.timers[15000]();
    t.ctx.sessionCsrfToken = 'new-login'; t.ctx.startTrackerSession(t.timing());
    resolve({ ok: true, authenticated: false, message: 'Stale response' }); await flush();
    assert.equal(t.logins.length, 0);

    t = setup();
    t.advance(1700000); t.ctx.authRequest = async () => { throw new Error('Offline'); };
    t.events.scroll({ isTrusted: true }); await flush();
    t.advance(16000); t.timers[1000](); await flush();
    assert(!t.calls.includes('activity'));
    t.advance(1800000); t.timers[1000](); await flush();
    assert.equal(t.logins.length, 1); // Offline activity cannot keep a session open forever.

    verifyDraftProtection();
    console.log('Account-session frontend checks passed: warning, trusted activity, passive polling, Superadmin exemption, takeover, tab tokens, stale responses, offline timeout, and scoped draft recovery.');
}

function verifyDraftProtection() {
    const fields = {}, bodyClasses = new Set(['project-edit-pane-open']);
    const ctx = vm.createContext({ console, Date, Intl, Map, Set, URLSearchParams, setTimeout, clearTimeout,
        setInterval() {}, alert() {}, localStorage: { getItem() { return null; } },
        document: { body: { classList: { contains: value => bodyClasses.has(value) } },
            getElementById: id => fields[id] || null, querySelectorAll: () => [], addEventListener() {} },
        addEventListener() {}, currentUser: { id: 1, role: 'staff', canEdit: true, canCreate: true },
    });
    ctx.window = ctx;
    for (const name of ['state', 'utils', 'projects']) vm.runInContext(fs.readFileSync(path.join(root, 'public/js', name + '.js'), 'utf8'), ctx);
    fields.title = { value: 'Unsaved title' }; fields.sourceOfFund = { value: 'City Fund' };
    ctx.renderProjectAttachments = () => {}; ctx.initializeLiveValueTracking = () => {}; ctx.showSystemNotification = () => {};
    vm.runInContext('editingId=9; projectEditSnapshot={id:9,updatedAt:"original-version"}; projectEditSource="projects";', ctx);
    assert.equal(ctx.preserveSuspendedProjectDraft(), true);
    vm.runInContext('editingId=null; db={};', ctx); fields.title.value = '';
    ctx.currentUser = { id: 2, role: 'staff', canEdit: true };
    assert.equal(ctx.resumeSuspendedProjectDraft(), false); assert.equal(fields.title.value, '');
    ctx.currentUser.id = 1;
    ctx.editDocument = (id, source, options) => {
        vm.runInContext('editingId=9; projectEditSnapshot={id:9,updatedAt:"new-server-version"};', ctx);
        ctx.applySuspendedProjectDraft(options.suspendedDraft);
    };
    assert.equal(ctx.resumeSuspendedProjectDraft(), true);
    assert.equal(fields.title.value, 'Unsaved title');
    assert.equal(vm.runInContext('projectEditSnapshot.updatedAt', ctx), 'original-version');
}

run().catch(error => { console.error(error); process.exitCode = 1; });
