"use strict";
(() => {
    const form = document.getElementById("auditFilterForm");
    const list = document.getElementById("auditEntries");
    const status = document.getElementById("auditResultsStatus");
    const error = document.getElementById("auditError");
    const api = new URL("api/auth.php", document.baseURI);
    const home = new URL("./", document.baseURI);
    let csrf = "", page = 1, sequence = 0, filters = {}, lastInteraction = Date.now();
    const dateText = value => new Date(value).toLocaleString("en-PH", { timeZone: "Asia/Manila", dateStyle: "medium", timeStyle: "medium" });
    const valueText = value => value === null || value === undefined || value === "" ? "Not set" : typeof value === "object" ? JSON.stringify(value, null, 2) : typeof value === "boolean" ? (value ? "Yes" : "No") : String(value);
    function node(tag, text, className) {
        const element = document.createElement(tag);
        if (text !== undefined) element.textContent = text;
        if (className) element.className = className;
        return element;
    }
    async function request(action, body = {}) {
        const response = await fetch(api, { method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify({ action, ...body }) });
        const result = await response.json();
        if (response.status === 401) { window.location.replace(home.href); throw new Error("Sign in again to continue."); }
        if (!response.ok || !result.ok) throw new Error(result.message || "Unable to load activity.");
        return result;
    }
    function contextRows(container, rows) {
        const details = node("dl", undefined, "detail-context");
        for (const [label, value] of rows) {
            if (value === undefined || value === null || value === "") continue;
            details.append(node("dt", label), node("dd", valueText(value)));
        }
        container.append(details);
    }
    function renderDetails(container, entry) {
        container.replaceChildren();
        const metadata = entry.metadata || {}, audit = metadata.audit;
        contextRows(container, [["Account", entry.actorUsername], ["Action", entry.action], ["Recorded", dateText(entry.createdAt)],
            ["Record", `${entry.entityType}${entry.entityId ? ` #${entry.entityId}` : ""}`],
            ["Role", audit?.actor?.role], ["Project no.", audit?.target?.projectNo], ["Title", audit?.target?.title]]);
        const changes = audit?.changes || [];
        if (changes.length) {
            const wrapper = node("div", undefined, "changes-wrap"), table = node("table", undefined, "changes-table");
            const head = node("thead"), header = node("tr");
            for (const label of ["Changed field", "Previous value", "New value"]) { const cell = node("th", label); cell.scope = "col"; header.append(cell); }
            head.append(header); table.append(head);
            const body = node("tbody");
            for (const change of changes) {
                const row = node("tr");
                row.append(node("td", change.label || change.field), node("td", valueText(change.before)), node("td", valueText(change.after)));
                body.append(row);
            }
            table.append(body); wrapper.append(table); container.append(wrapper);
        } else container.append(node("p", audit ? "No field values changed in this event." : "This older entry contains the details recorded at the time; field-by-field changes were not captured.", "detail-note"));
        const context = { ...(audit?.context || {}) };
        for (const [key, value] of Object.entries(metadata)) if (!["audit", "visibility", "actor"].includes(key)) context[key] = value;
        if (context.credentialReset) { container.append(node("p", "Account password was set or reset. Password values are never included in the log.", "detail-note")); delete context.credentialReset; }
        contextRows(container, Object.entries(context).map(([key, value]) => [key.replace(/([A-Z])/g, " $1").replace(/^./, first => first.toUpperCase()), value]));
    }
    function renderEntry(entry) {
        const item = node("li", undefined, "audit-entry"); item.dataset.logId = String(entry.id);
        const top = node("div", undefined, "entry-top"), title = node("div");
        title.append(node("span", `Log #${entry.id}`, "entry-number"), node("p", entry.summary, "entry-summary"));
        top.append(title, node("span", entry.action.replaceAll(".", " "), "entry-action"));
        item.append(top, node("p", `${entry.actorUsername} · ${dateText(entry.createdAt)}`, "entry-meta"));
        const disclosure = node("details", undefined, "entry-details"), summary = node("summary", "View details"), body = node("div", undefined, "detail-body");
        disclosure.append(summary, body);
        disclosure.addEventListener("toggle", async () => {
            if (!disclosure.open || disclosure.dataset.loaded || disclosure.dataset.loading) return;
            disclosure.dataset.loading = "true"; body.textContent = "Loading details…";
            try { const result = await request("getAuditLogEntry", { id: entry.id }); renderDetails(body, result.entry); disclosure.dataset.loaded = "true"; }
            catch (failure) { body.textContent = failure.message; }
            finally { delete disclosure.dataset.loading; }
        });
        item.append(disclosure); return item;
    }
    async function load(nextPage = 1) {
        const current = ++sequence; error.hidden = true; status.textContent = "Loading activity…";
        list.setAttribute("aria-busy", "true");
        document.getElementById("auditPrevious").disabled = true; document.getElementById("auditNext").disabled = true;
        try {
            const result = await request("listAuditLog", { ...filters, page: nextPage });
            if (current !== sequence) return;
            page = result.page; list.replaceChildren(...result.entries.map(renderEntry));
            status.textContent = result.entries.length ? `${result.entries.length} entries on this page` : "No activity matches these filters.";
            document.getElementById("auditPagination").hidden = !result.entries.length && page === 1;
            document.getElementById("auditPageLabel").textContent = `Page ${page}`;
            document.getElementById("auditPrevious").disabled = page <= 1; document.getElementById("auditNext").disabled = !result.hasMorePages;
        } catch (failure) {
            if (current !== sequence) return;
            error.textContent = failure.message; error.hidden = false; status.textContent = "Activity could not be loaded.";
        } finally { if (current === sequence) list.removeAttribute("aria-busy"); }
    }
    form.addEventListener("submit", event => {
        event.preventDefault(); const next = Object.fromEntries(new FormData(form));
        if (next.fromDate && next.toDate && next.fromDate > next.toDate) { error.textContent = "The From date must be on or before the To date."; error.hidden = false; return; }
        filters = Object.fromEntries(Object.entries(next).filter(([, value]) => value.trim() !== "")); void load(1);
    });
    document.getElementById("auditReset").addEventListener("click", () => { form.reset(); filters = {}; void load(1); });
    document.getElementById("auditRefresh").addEventListener("click", () => void load(page));
    document.getElementById("auditPrevious").addEventListener("click", () => void load(Math.max(1, page - 1)));
    document.getElementById("auditNext").addEventListener("click", () => void load(page + 1));
    document.getElementById("auditSignOut").addEventListener("click", async event => {
        event.currentTarget.disabled = true;
        try { await request("logout"); window.location.replace(home.href); }
        catch (failure) { error.textContent = failure.message; error.hidden = false; event.currentTarget.disabled = false; }
    });
    for (const type of ["click", "keydown", "scroll"]) document.addEventListener(type, () => { lastInteraction = Date.now(); }, { passive: true });
    setInterval(() => { if (!document.hidden && Date.now() - lastInteraction < 60000 && csrf) void request("activity").catch(() => {}); }, 60000);
    void (async () => {
        try {
            const session = await request("session");
            if (!session.authenticated || session.user.role !== "superadmin") { window.location.replace(home.href); return; }
            csrf = session.csrfToken; document.getElementById("auditSignedInUser").textContent = session.user.username; await load(1);
        } catch (failure) { error.textContent = failure.message; error.hidden = false; status.textContent = "Activity could not be loaded."; }
    })();
})();
