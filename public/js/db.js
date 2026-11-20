/* Shared PostgreSQL-backed data adapter. It retains the small IndexedDB-shaped
 * interface used by the existing screens, while all writes are committed to
 * the Laravel server for every signed-in user. */
let db = null;
window.dbReady = false;

const apiClient = {
    async request(url, options = {}) {
        const expectedCsrf = window.sessionCsrfToken;
        const response = await fetch(url, { credentials: "same-origin", cache: "no-store", ...options, headers: { "Content-Type": "application/json", "X-CSRF-Token": window.sessionCsrfToken || "", ...(options.headers || {}) } });
        const payload = await response.json().catch(() => ({}));
        if (response.status === 401) {
            if (typeof handleTrackerAuthenticationFailure === "function") handleTrackerAuthenticationFailure(payload, expectedCsrf);
            else if (typeof showLogin === "function") showLogin(payload.message || "Your session has expired. Please sign in again.");
        }
        if (!response.ok || !payload.ok) throw new Error(payload.message || "The server could not process the request.");
        return payload;
    },
    getBootstrap() { return this.request("api/data.php"); },
    listProjects(filters = {}) {
        const query = new URLSearchParams({ action: "listProjects", ...filters });
        return this.request(`api/data.php?${query.toString()}`);
    },
    getProjectHistory(projectId, page = 1, pageSize = 25) {
        const query = new URLSearchParams({ action: "projectHistory", projectId, page, pageSize });
        return this.request(`api/data.php?${query.toString()}`);
    },
    getDashboard(filters = {}) {
        const query = new URLSearchParams({ action: "dashboard", ...filters });
        return this.request(`api/data.php?${query.toString()}`);
    },
    getNotifications() { return this.request("api/data.php?action=notifications"); },
    getProjectAlerts(projectId) { return this.request(`api/data.php?action=projectAlerts&projectId=${encodeURIComponent(projectId)}`); },
    getProjectVersion() { return this.request("api/data.php?action=projectVersion"); },
    getNextProjectNumber() { return this.request("api/data.php?action=nextProjectNumber"); },
    post(action, data = {}) { return this.request("api/data.php", { method: "POST", body: JSON.stringify({ action, ...data }) }); }
};
window.apiClient = apiClient;

function createServerDatabase(seed) {
    const clone = value => value === undefined ? undefined : JSON.parse(JSON.stringify(value));
    const records = {
        projects: new Map((seed.projects || []).map(item => [String(item.id), item])),
        history: new Map((seed.history || []).map(item => [String(item.id), item]))
    };
    let refreshSequence = 0, revision = 0;
    let projectVersion = seed.version ?? null;
    return {
        get projectVersion() { return projectVersion; },
        acceptRecord(name, record) {
            if (!records[name] || !record || record.id === undefined) return;
            records[name].set(String(record.id), clone(record));
            revision++;
        },
        async refresh() {
            const sequence = ++refreshSequence, startedRevision = revision;
            const data = await apiClient.getBootstrap();
            // A late read must not replace a newer read or a committed local write.
            if (sequence !== refreshSequence || startedRevision !== revision) return false;
            window.projectHolidayCalendar = data.holidayCalendar || window.projectHolidayCalendar;
            window.timelineDaySettings = data.timelineSettings || window.timelineDaySettings || {};
            ["projects", "history"].forEach(name => {
                records[name].clear();
                (data[name] || []).forEach(item => records[name].set(String(item.id), item));
            });
            projectVersion = data.version ?? null;
            return true;
        },
        transaction() {
            let pending = 0, completed = false, failed = false;
            const tx = { oncomplete: null, onerror: null };
            const begin = () => { pending++; };
            const fail = error => { failed = true; console.error("Database request failed:", error); if (typeof tx.onerror === "function") tx.onerror({ target: { error } }); };
            const completeIfIdle = () => {
                if (!completed && pending === 0) { completed = true; if (!failed && typeof tx.oncomplete === "function") tx.oncomplete(); }
            };
            const end = () => {
                pending--;
                setTimeout(completeIfIdle, 0);
            };
            setTimeout(completeIfIdle, 0);
            const localRequest = value => {
                begin();
                const request = { result: value, onsuccess: null, onerror: null };
                setTimeout(() => { try { if (typeof request.onsuccess === "function") request.onsuccess({ target: request }); } catch (error) { if (typeof request.onerror === "function") request.onerror({ target: { error } }); fail(error); } end(); }, 0);
                return request;
            };
            const mutation = (action, data, apply) => {
                begin();
                const requestData = action === "saveProject" ? { ...data, isImport: window.importInProgress === true } : data;
                apiClient.post(action, requestData).then(result => { apply(result); revision++; end(); }).catch(error => { fail(error); end(); });
            };
            const store = name => ({
                get(id) { return localRequest(clone(records[name].get(String(id)))); },
                getAll() { return localRequest([...records[name].values()].map(clone)); },
                add(value) {
                    const payload = clone(value), action = name === "projects" ? "saveProject" : "saveHistory";
                    mutation(action, { record: payload }, result => { Object.assign(value, result.record); records[name].set(String(result.record.id), result.record); });
                },
                put(value) {
                    const payload = clone(value), action = name === "projects" ? "saveProject" : "saveHistory";
                    mutation(action, { record: payload }, result => { Object.assign(value, result.record); records[name].set(String(result.record.id), result.record); });
                },
                putTimeline(value, history) {
                    const payload = clone(value);
                    mutation('saveTimelineRecord', { record: payload, history: clone(history) }, result => { Object.assign(value, result.record); records.projects.set(String(result.record.id), result.record); });
                },
                delete(id, reason) { mutation(name === "projects" ? "deleteProject" : "deleteHistory", { id, reason }, result => { if (result.pending) return; records[name].delete(String(id)); if (name === "projects") for (const [key, item] of records.history) if (String(item.projectId) === String(id)) records.history.delete(key); }); },
                clear(reason) { mutation("clearStore", { store: name, reason }, result => { if (result.pending) return; records[name].clear(); if (name === "projects") records.history.clear(); }); },
                index(field) {
                    return { openCursor(keyRange) {
                        const wanted = keyRange && (keyRange.__value ?? keyRange.lower), matches = [...records[name].values()].filter(item => String(item[field]) === String(wanted));
                        let index = 0; begin(); const request = { onsuccess: null, onerror: null };
                        const next = () => setTimeout(() => { const item = matches[index++]; const cursor = item ? { value: clone(item), delete: () => store(name).delete(item.id), continue: next } : null; try { if (typeof request.onsuccess === "function") request.onsuccess({ target: { result: cursor } }); if (!item) end(); } catch (error) { if (typeof request.onerror === "function") request.onerror({ target: { error } }); fail(error); end(); } }, 0);
                        next(); return request;
                    }};
                }
            });
            tx.objectStore = store;
            return tx;
        }
    };
}

window.IDBKeyRange = window.IDBKeyRange || { only: value => ({ __value: value }) };
window.initializeDatabase = async function () {
    db = null; window.db = null; window.dbReady = false;
    try {
        const data = await apiClient.getBootstrap();
        window.projectHolidayCalendar = data.holidayCalendar || window.projectHolidayCalendar;
            window.timelineDaySettings = data.timelineSettings || {};
        db = createServerDatabase(data); window.db = db; window.dbReady = true;
        window.dispatchEvent(new Event("sharedDatabaseReady"));
    } catch (error) {
        console.error("Database initialization failed:", error);
        if (typeof showLogin === "function") showLogin(error.message || "Unable to load project data. Please try signing in again.");
    }
};
