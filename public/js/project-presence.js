/* Advisory project presence. This does not lock a project or send unsaved fields. */
(() => {
    const endpoint = "api/project-presence.php";
    const editors = new Map();
    let active = null;
    let accountKey = "";
    let generation = 0;
    let sequence = 0;
    let lastApplied = 0;
    let polling = false;
    let unavailable = false;
    let heartbeatUnavailable = false;
    let leaving = false;

    function userKey() {
        const user = window.currentUser;
        return user && user.role !== "superadmin" ? `${user.id}:${window.sessionCsrfToken}` : "";
    }

    async function request(method, payload, csrf = window.sessionCsrfToken, keepalive = false) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        let url = endpoint;
        if (method === "GET") {
            const query = new URLSearchParams();
            payload.projectIds.forEach(id => query.append("projectIds[]", id));
            url += `?${query}`;
        }
        try {
            const response = await fetch(url, {
                method, credentials: "same-origin", cache: "no-store", keepalive,
                signal: controller.signal,
                headers: method === "POST" ? { "Content-Type": "application/json", "X-CSRF-Token": csrf || "" } : {},
                ...(method === "POST" ? { body: JSON.stringify(payload) } : {})
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error("Presence unavailable");
            return result;
        } finally {
            clearTimeout(timeout);
        }
    }

    function checkAccount() {
        const key = userKey();
        if (key !== accountKey) {
            window.stopProjectEditingPresence();
            editors.clear();
            unavailable = false;
            heartbeatUnavailable = false;
            accountKey = key;
            generation++;
            render();
        }
        return key;
    }

    function apply(result, order) {
        if (order < lastApplied) return;
        lastApplied = order;
        unavailable = false;
        const serverNow = Date.parse(result.serverTime) || Date.now();
        Object.entries(result.editorsByProject || {}).forEach(([id, people]) => {
            editors.set(Number(id), people.map(person => ({ ...person,
                expiresLocallyAt: Date.now() + Math.max(0, Date.parse(person.expiresAt) - serverNow)
            })));
        });
        render();
    }

    function render() {
        document.querySelectorAll("[data-project-editors]").forEach(element => {
            const id = Number(element.dataset.projectEditors);
            const people = accountKey ? (editors.get(id) || []).filter(person => person.expiresLocallyAt > Date.now()) : [];
            const header = Boolean(accountKey) && Number.isSafeInteger(id) && id > 0 && ["projectEditorPresence", "projectDetailsPresence"].includes(element.id);
            const editingHere = active?.projectId === id;
            const status = header ? (unavailable || (editingHere && heartbeatUnavailable) ? "unavailable" : people.length ? "editing" : editingHere || !editors.has(id) ? "checking" : "idle") : "";
            const signature = JSON.stringify([id, people.map(person => [person.id, person.fullName, person.username]), status, window.currentUser?.id]);
            if (element.dataset.presenceSignature === signature) return;
            element.dataset.presenceSignature = signature;
            element.replaceChildren();
            element.hidden = !people.length && !header;
            element.title = "";
            element.classList.add("project-editing-presence");
            element.classList.toggle("is-idle", Boolean(status) && status !== "editing");
            if (status === "unavailable") {
                element.textContent = "Editing status unavailable";
                return;
            }
            if (!people.length) {
                if (header) element.textContent = status === "checking" ? "Checking editors…" : "No one is editing";
                return;
            }
            const avatars = document.createElement("span");
            avatars.className = "project-editor-avatars";
            avatars.setAttribute("aria-hidden", "true");
            people.slice(0, 4).forEach(person => {
                const avatar = document.createElement("span");
                const name = (person.fullName || person.username || "User").trim();
                avatar.className = "project-editor-avatar";
                avatar.style.setProperty("--editor-hue", String((Number(person.id) * 67) % 360));
                avatar.textContent = name.split(/\s+/).slice(0, 2).map(word => word.charAt(0)).join("").toUpperCase();
                avatar.title = name;
                avatars.append(avatar);
            });
            const names = people.map(person => `${person.fullName || person.username || "User"}${Number(person.id) === Number(window.currentUser?.id) ? " (you)" : ""}`).join(", ");
            const label = document.createElement("span");
            label.className = "project-editor-names";
            label.textContent = `Editing: ${names}`;
            element.title = `${label.textContent}. Edit form open; changes are not shared until saved.`;
            element.append(avatars, label);
        });
    }

    async function heartbeat() {
        if (!checkAccount() || !active || active.busy || leaving) return;
        const entry = active;
        const revision = generation;
        const order = ++sequence;
        entry.busy = true;
        try {
            const result = await request("POST", { action: "heartbeat", projectId: entry.projectId, editorToken: entry.token }, entry.csrf);
            if (active === entry && generation === revision && userKey() === accountKey) {
                heartbeatUnavailable = false;
                apply(result, order);
                render();
            }
        } catch (_) {
            // A successful observer read does not mean our editor was registered.
            if (active === entry && generation === revision) {
                heartbeatUnavailable = true;
                render();
            }
        } finally {
            entry.busy = false;
        }
    }

    window.refreshProjectEditingPresence = async function () {
        if (!checkAccount() || polling || leaving || document.hidden) return;
        const ids = [...new Set([...document.querySelectorAll("[data-project-editors]")].map(element => Number(element.dataset.projectEditors)).filter(id => Number.isSafeInteger(id) && id > 0))].slice(0, 100);
        if (!ids.length) return;
        const revision = generation;
        const key = accountKey;
        const order = ++sequence;
        polling = true;
        try {
            const result = await request("GET", { projectIds: ids });
            if (generation === revision && userKey() === key) apply(result, order);
        } catch (_) {
            if (generation === revision && order >= lastApplied) {
                lastApplied = order;
                // Do not keep showing stale names when presence cannot be checked.
                ids.forEach(id => editors.delete(id));
                unavailable = true;
                render();
            }
        } finally {
            polling = false;
        }
    };

    window.startProjectEditingPresence = function (projectId) {
        checkAccount();
        window.stopProjectEditingPresence();
        if (!accountKey || !window.currentUser?.canEdit || !Number.isSafeInteger(Number(projectId))) return;
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        active = { projectId: Number(projectId), token: [...bytes].map(byte => byte.toString(16).padStart(2, "0")).join(""), csrf: window.sessionCsrfToken, busy: false };
        const indicator = document.getElementById("projectEditorPresence");
        if (indicator) indicator.dataset.projectEditors = String(projectId);
        generation++;
        unavailable = false;
        heartbeatUnavailable = false;
        render();
        void heartbeat();
    };

    window.stopProjectEditingPresence = function ({ keepalive = false, clear = false } = {}) {
        const entry = active;
        active = null;
        heartbeatUnavailable = false;
        generation++;
        const indicator = document.getElementById("projectEditorPresence");
        if (indicator) indicator.dataset.projectEditors = "";
        if (clear) {
            editors.clear();
            accountKey = "";
            unavailable = false;
        }
        render();
        if (!entry) return Promise.resolve();
        return request("POST", { action: "stop", projectId: entry.projectId, editorToken: entry.token }, entry.csrf, keepalive)
            .catch(() => {})
            .then(() => { if (!leaving && !clear) void window.refreshProjectEditingPresence(); });
    };

    window.renderProjectEditingIndicators = render;
    const catchUp = () => { checkAccount(); void heartbeat(); void window.refreshProjectEditingPresence(); };
    document.addEventListener("DOMContentLoaded", catchUp);
    window.addEventListener("sharedDatabaseReady", catchUp);
    document.addEventListener("visibilitychange", () => { if (!document.hidden) catchUp(); });
    window.addEventListener("focus", catchUp);
    window.addEventListener("online", catchUp);
    window.addEventListener("pagehide", () => { leaving = true; void window.stopProjectEditingPresence({ keepalive: true }); });
    window.addEventListener("pageshow", () => {
        leaving = false;
        if (typeof editingId !== "undefined" && editingId !== null) window.startProjectEditingPresence(editingId);
        catchUp();
    });
    setInterval(() => { render(); void window.refreshProjectEditingPresence(); }, 3000);
    setInterval(() => { void heartbeat(); }, 15000);
})();
