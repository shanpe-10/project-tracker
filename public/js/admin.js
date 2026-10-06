/* Superadmin manages accounts; Admin has all project and operational permissions. */
window.pendingAccountRequests = [];

function renderPendingAccountNotification() {
    const pending = window.pendingAccountRequests;
    if (typeof renderServerDashboardNotifications === "function") {
        renderServerDashboardNotifications();
        return;
    }
    const count = document.getElementById("notificationCount");
    const timelineAlertCount = Number(window.dashboardAlertTotal) || (typeof activeDashboardAlertsData === "undefined" ? 0 : activeDashboardAlertsData.length);
    if (count) {
        const total = timelineAlertCount + pending.length;
        count.textContent = total;
        count.classList.toggle("d-none", total === 0);
    }
}

function updatePendingAccountRequests(users) {
    window.pendingAccountRequests = users.filter(account => account.role === "staff" && !account.active);
    renderPendingAccountNotification();
}

async function refreshPendingAccountNotifications() {
    if (window.currentUser?.role !== "superadmin") return;
    try {
        const result = await authRequest("listManagedUsers");
        if (result.ok) updatePendingAccountRequests(result.users);
    } catch (_) {
        // Keep the most recently displayed notification count during a transient outage.
    }
}

function openPendingAccountRequests() {
    document.getElementById("notificationAlertPanel")?.classList.add("d-none");
    switchWorkspace("account-requests");
}

function setAccountRequestsMessage(message) {
    const messageBox = document.getElementById("accountRequestsMessage");
    if (!messageBox) return;
    messageBox.textContent = message;
    messageBox.hidden = !message;
}

function addAccountAction(container, label, style, callback, disabled = false) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = `btn btn-sm ${style}`;
    button.textContent = label;
    button.disabled = disabled;
    button.onclick = callback;
    container.append(button);
}

let activePermissionButton = null;

function addPermissionDropdown(container, account) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "permission-dropdown-button btn btn-sm btn-outline-primary";
    button.textContent = "Permissions";
    button.setAttribute("aria-haspopup", "dialog");
    button.setAttribute("aria-controls", `accountPermissions${Number(account.id)}`);
    button.setAttribute("aria-expanded", "false");
    button.dataset.accountId = String(account.id);
    button.onclick = event => {
        event.stopPropagation();
        const wasOpen = document.querySelector(`.permission-popover[data-account-id="${account.id}"]`) !== null;
        closePermissionPopovers();
        if (wasOpen) return;
        const panel = buildPermissionPopover(account);
        document.body.append(panel);
        activePermissionButton = button;
        button.setAttribute("aria-expanded", "true");
        positionPermissionPopover();
        panel.querySelector("input")?.focus({ preventScroll: true });
    };
    container.append(button);
}

function positionPermissionPopover() {
    const button = activePermissionButton;
    const panel = document.querySelector(".permission-popover");
    if (!button || !panel) return;
    if (!button.isConnected || !button.getClientRects().length) { closePermissionPopovers(); return; }
    const padding = 12, gap = 6;
    const bounds = button.getBoundingClientRect();
    const viewportWidth = window.innerWidth, viewportHeight = window.innerHeight;
    if (bounds.bottom <= padding || bounds.top >= viewportHeight - padding) { closePermissionPopovers(); return; }
    const width = Math.min(260, viewportWidth - padding * 2);
    panel.style.width = `${width}px`;
    panel.style.maxHeight = `${Math.max(1, viewportHeight - padding * 2)}px`;
    const naturalHeight = panel.getBoundingClientRect().height;
    const below = Math.max(0, viewportHeight - padding - bounds.bottom - gap);
    const above = Math.max(0, bounds.top - padding - gap);
    const openAbove = below < naturalHeight && above > below;
    const available = openAbove ? above : below;
    panel.style.maxHeight = `${Math.max(1, available)}px`;
    const height = panel.getBoundingClientRect().height;
    const top = openAbove ? bounds.top - gap - height : bounds.bottom + gap;
    panel.style.top = `${Math.max(padding, Math.min(top, viewportHeight - padding - height))}px`;
    panel.style.left = `${Math.max(padding, Math.min(bounds.right - width, viewportWidth - padding - width))}px`;
}

window.addEventListener("resize", positionPermissionPopover);
document.addEventListener("scroll", event => {
    if (!event.target.closest?.(".permission-popover")) positionPermissionPopover();
}, true);
document.addEventListener("keydown", event => {
    if (event.key === "Escape" && activePermissionButton) {
        event.preventDefault();
        event.stopPropagation();
        closePermissionPopovers(true);
    }
}, true);

function closePermissionPopovers(restoreFocus = false) {
    const anchor = activePermissionButton;
    activePermissionButton = null;
    document.querySelectorAll(".permission-popover").forEach(popover => popover.remove());
    document.querySelectorAll(".permission-dropdown-button").forEach(button => button.setAttribute("aria-expanded", "false"));
    if (restoreFocus && anchor?.isConnected) anchor.focus({ preventScroll: true });
}

function buildPermissionPopover(account) {
    const panel = document.createElement("div");
    panel.className = "permission-popover border rounded bg-white shadow-sm p-2 position-fixed text-start";
    panel.id = `accountPermissions${Number(account.id)}`;
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-label", `Permissions for ${account.fullName || account.username}`);
    panel.dataset.accountId = String(account.id);
    [
        ["canCreate", "Add projects"],
        ["canEdit", "Edit projects"],
        ["canImport", "Import Excel"],
        ["canExport", "Export Excel"],
        ["canDelete", "Delete projects"],
        ["canDeleteAll", "Delete all records"],
        ["canResolveAlerts", "Mark/restore alerts"],
        ["canArchive", "Archive projects"],
    ].forEach(([permission, label]) => {
        const option = document.createElement("label");
        option.className = "form-check d-block small mb-1";
        const toggle = document.createElement("input");
        toggle.type = "checkbox";
        toggle.className = "form-check-input";
        toggle.checked = Boolean(account[permission]);
        toggle.onchange = () => setUserPermission(account.id, permission, toggle.checked, account.username);
        option.append(toggle, document.createTextNode(` ${label}`));
        panel.append(option);
    });
    return panel;
}

document.addEventListener("click", event => {
    if (!event.target.closest(".permission-popover, .permission-dropdown-button")) {
        closePermissionPopovers();
    }
});

async function loadAccountRequests() {
    if (window.currentUser?.role !== "superadmin") return;
    const list = document.getElementById("accountRequestsList");
    if (!list) return;
    list.replaceChildren();
    setAccountRequestsMessage("Loading accounts…");
    try {
        const result = await authRequest("listManagedUsers");
        if (!result.ok) throw new Error(result.message || "Unable to load staff accounts.");
        updatePendingAccountRequests(result.users);
        if (!result.users.length) { setAccountRequestsMessage("There are no accounts."); loadAuditLog(); loadBackupSettings(); return; }
        setAccountRequestsMessage("");
        const summary = document.getElementById("accountDirectorySummary");
        if (summary) summary.textContent = `${result.users.length} account${result.users.length === 1 ? "" : "s"} · ${window.pendingAccountRequests.length} pending approval`;
        result.users.forEach(account => {
            const row = document.createElement("tr");
            const role = account.role === "superadmin" ? "Superadmin" : account.role === "admin" ? "Admin" : "Staff";
            const enabledPermissions = [account.canCreate && "Add", account.canEdit && "Edit", account.canImport && "Import", account.canExport && "Export", account.canDelete && "Delete", account.canDeleteAll && "Delete all", account.canResolveAlerts && "Resolve alerts", account.canArchive && "Archive"].filter(Boolean);
            const access = account.role === "superadmin" ? "Account management" : account.role === "admin" ? "All project operations, timeline, activity and backups" : !account.active ? "Awaiting review" : enabledPermissions.length ? enabledPermissions.join(", ") : "View only";
            const cell = (label, className = "") => {
                const td = document.createElement("td");
                td.dataset.label = label;
                td.className = className;
                row.append(td);
                return td;
            };
            const initial = (account.fullName || account.username || "U").trim().charAt(0).toUpperCase();
            cell("Full name", "account-user-cell").innerHTML = `<span class="account-user-content"><span class="account-user-avatar">${account.profilePhoto ? `<img src="${account.profilePhoto}" alt="">` : escapeHtml(initial)}</span><span>${escapeHtml(account.fullName)}</span></span>`;
            cell("Username", "account-username").textContent = account.username;
            cell("Role").innerHTML = `<span class="account-role-badge">${escapeHtml(role)}</span>`;
            cell("Status / access", "account-access-cell").innerHTML = `<span class="account-state ${account.active ? "is-active" : "is-pending"}">${account.active ? "Active" : "Pending approval"}</span><span class="account-access-summary">${escapeHtml(access)}</span>`;
            const created = new Date(account.createdAt);
            cell("Created", "account-created-cell").innerHTML = Number.isNaN(created.getTime()) ? "—" : `<span>${escapeHtml(created.toLocaleDateString("en-US", {timeZone:"Asia/Taipei",month:"short",day:"2-digit",year:"numeric"}))}</span><small>${escapeHtml(created.toLocaleTimeString("en-US", {timeZone:"Asia/Taipei",hour:"numeric",minute:"2-digit"}))}</small>`;
            const actions = document.createElement("div");
            actions.className = "account-actions";
            cell("Actions", "account-actions-cell").append(actions);
            addAccountAction(actions, "Edit", "btn-outline-primary", () => openAccountEditor(account));
            if (!account.active) {
                addAccountAction(actions, "Approve", "btn-success", () => reviewAccountRequest(account.id, "approve", account.username));
                addAccountAction(actions, "Reject", "btn-outline-danger", () => reviewAccountRequest(account.id, "reject", account.username));
            } else {
                if (account.role === "staff") addPermissionDropdown(actions, account);
                addAccountAction(actions, "Delete", "btn-outline-danger", () => deleteStaffAccount(account.id, account.username), account.id === window.currentUser.id);
            }
            list.append(row);
        });

    } catch (error) { setAccountRequestsMessage(error.message); }
}

let defaultBackupDestination = "";

function updateBackupToggleLabel() {
    const enabled = document.getElementById("automaticBackupEnabled")?.checked === true;
    const label = document.getElementById("automaticBackupLabel");
    if (label) label.textContent = enabled ? "On" : "Off";
}

function formatBackupSize(bytes) {
    if (!Number.isFinite(Number(bytes))) return "";
    return Number(bytes) < 1048576 ? `${Math.max(1, Math.round(Number(bytes) / 1024))} KB` : `${(Number(bytes) / 1048576).toFixed(1)} MB`;
}

async function backupSettingsRequest(options = {}) {
    const response = await fetch("api/backups.php", { credentials: "same-origin", headers: { "Content-Type": "application/json", "X-CSRF-Token": window.sessionCsrfToken || "" }, ...options });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result.ok) throw new Error(result.message || "Unable to manage backup settings.");
    return result;
}

function renderBackupSettings(result) {
    defaultBackupDestination = result.defaultDestination || "";
    const toggle = document.getElementById("automaticBackupEnabled");
    const destination = document.getElementById("backupDestinationPath");
    const status = document.getElementById("backupSettingsStatus");
    if (toggle) toggle.checked = result.enabled === true;
    if (destination) destination.value = result.destination || defaultBackupDestination;
    updateBackupToggleLabel();
    if (status) status.textContent = result.latest ? `Latest: ${new Date(result.latest.createdAt).toLocaleString()} · ${formatBackupSize(result.latest.size)} · ${result.latest.name}` : "No backup has been created in this destination yet.";
}

async function loadBackupSettings() {
    if (window.currentUser?.role !== "admin" || !document.getElementById("backupDestinationPath")) return;
    try { renderBackupSettings(await backupSettingsRequest()); }
    catch (error) { document.getElementById("backupSettingsStatus").textContent = error.message; }
}

async function useDefaultBackupDestination() {
    const field = document.getElementById("backupDestinationPath");
    if (!field) return;
    field.value = defaultBackupDestination;
    await saveBackupSettings("Default backup destination saved.");
}

async function browseBackupDestination() {
    const button = document.getElementById("browseBackupDestinationButton");
    const status = document.getElementById("backupSettingsStatus");
    if (button) button.disabled = true;
    const container = document.createElement("div");
    container.className = "modal fade";
    container.tabIndex = -1;
    container.setAttribute("aria-labelledby", "backupFolderPickerTitle");
    container.innerHTML = `<div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="backupFolderPickerTitle">Choose backup folder</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><p class="small text-muted">Folders on the computer hosting the tracker.</p>
            <label class="form-label" for="backupFolderPickerPath">Folder path</label>
            <div class="input-group mb-2"><input id="backupFolderPickerPath" class="form-control" type="text" spellcheck="false"><button type="button" class="btn btn-outline-secondary" data-picker="open">Open</button></div>
            <div class="d-flex flex-wrap gap-2 mb-2" data-picker="drives"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary mb-2" data-picker="parent">Up one folder</button>
            <p class="small mb-2" role="status" aria-live="polite" data-picker="status"></p>
            <div class="list-group" data-picker="folders"></div>
        </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" data-picker="choose" disabled>Use this folder</button></div>
    </div></div>`;
    document.body.appendChild(container);
    const modal = new bootstrap.Modal(container);
    const field = container.querySelector("#backupFolderPickerPath");
    const pickerStatus = container.querySelector('[data-picker="status"]');
    const folders = container.querySelector('[data-picker="folders"]');
    const choose = container.querySelector('[data-picker="choose"]');
    const parent = container.querySelector('[data-picker="parent"]');
    let selectedPath = "", parentPath = null, busy = false;
    const folderButton = (label, path) => {
        const entry = document.createElement("button");
        entry.type = "button";
        entry.className = "list-group-item list-group-item-action";
        entry.textContent = label;
        entry.addEventListener("click", () => loadFolder(path));
        return entry;
    };
    const loadFolder = async (path) => {
        if (busy) return;
        busy = true;
        choose.disabled = true;
        selectedPath = "";
        pickerStatus.textContent = "Loading folders…";
        folders.replaceChildren();
        try {
            const result = await backupSettingsRequest({ method: "POST", body: JSON.stringify({ action: "browseDestination", path }) });
            field.value = selectedPath = result.path;
            parentPath = result.parent;
            parent.disabled = !parentPath;
            const drives = container.querySelector('[data-picker="drives"]');
            drives.replaceChildren();
            for (const drive of result.drives || []) {
                const entry = folderButton(drive, drive);
                entry.className = "btn btn-sm btn-outline-secondary";
                drives.appendChild(entry);
            }
            for (const directory of result.directories || []) folders.appendChild(folderButton(directory.name, directory.path));
            pickerStatus.textContent = result.truncated ? "Showing the first 1,000 folders. Enter a path to open another folder." : folders.childElementCount ? "Open a subfolder or use the current folder." : "This folder has no subfolders.";
            choose.disabled = false;
        } catch (error) { pickerStatus.textContent = error.message; }
        finally { busy = false; }
    };
    container.querySelector('[data-picker="open"]').addEventListener("click", () => loadFolder(field.value));
    field.addEventListener("input", () => { choose.disabled = field.value !== selectedPath; });
    field.addEventListener("keydown", (event) => { if (event.key === "Enter") { event.preventDefault(); loadFolder(field.value); } });
    parent.addEventListener("click", () => { if (parentPath) loadFolder(parentPath); });
    choose.addEventListener("click", async () => {
        if (!selectedPath || busy) return;
        document.getElementById("backupDestinationPath").value = selectedPath;
        choose.disabled = true;
        if (await saveBackupSettings("Backup destination saved.")) modal.hide();
        else { pickerStatus.textContent = status?.textContent || "The backup destination could not be saved."; choose.disabled = false; }
    });
    container.addEventListener("hidden.bs.modal", () => { modal.dispose(); container.remove(); if (button) button.disabled = false; }, { once: true });
    modal.show();
    const initial = document.getElementById("backupDestinationPath")?.value || defaultBackupDestination || "D:\\";
    field.value = initial;
    await loadFolder(initial);
}

async function saveBackupSettings(successMessage = "Backup settings saved.") {
    const toggle = document.getElementById("automaticBackupEnabled");
    const destination = document.getElementById("backupDestinationPath");
    const status = document.getElementById("backupSettingsStatus");
    if (!toggle || !destination) return false;
    toggle.disabled = true;
    if (status) status.textContent = "Saving backup settings…";
    try {
        const result = await backupSettingsRequest({
            method: "POST",
            body: JSON.stringify({ enabled: toggle.checked, destination: destination.value.trim() })
        });
        renderBackupSettings(result);
        showSystemNotification(successMessage);
        return true;
    } catch (error) {
        await loadBackupSettings();
        if (status) status.textContent = error.message;
        showSystemNotification(error.message || "Unable to save backup settings.");
        return false;
    } finally {
        toggle.disabled = false;
    }
}
async function runManualBackup() {
    const button = document.getElementById("runManualBackupButton");
    const toggle = document.getElementById("automaticBackupEnabled");
    const destination = document.getElementById("backupDestinationPath");
    const status = document.getElementById("backupSettingsStatus");
    if (button) button.disabled = true;
    if (status) status.textContent = "Saving destination…";
    try {
        const settings = await backupSettingsRequest({
            method: "POST",
            body: JSON.stringify({
                enabled: toggle?.checked === true,
                destination: destination?.value.trim() || ""
            })
        });
        renderBackupSettings(settings);
        if (status) status.textContent = "Creating backup… Keep this page open.";
        const result = await backupSettingsRequest({ method: "POST", body: JSON.stringify({ action: "runManual" }) });
        renderBackupSettings(result);
        showSystemNotification(`Manual backup created: ${result.latest.name}`);
        loadAuditLog();
    } catch (error) {
        if (status) status.textContent = error.message;
        showSystemNotification(error.message || "The manual backup failed.");
    } finally {
        if (button) button.disabled = false;
    }
}

document.addEventListener("change", event => {
    if (event.target.id === "automaticBackupEnabled") {
        updateBackupToggleLabel();
        saveBackupSettings(event.target.checked ? "Automatic project backups enabled." : "Automatic project backups disabled.");
    }
});

async function loadAuditLog() {
    if (window.currentUser?.role !== "admin") return;
    const list = document.getElementById("auditLogList");
    if (!list) return;
    list.replaceChildren();
    try {
        const result = await authRequest("listAuditLog");
        if (!result.ok) throw new Error(result.message || "Unable to load activity.");
        if (!result.entries.length) {
            const empty = document.createElement("li");
            empty.className = "text-muted p-3";
            empty.textContent = "No activity has been recorded yet.";
            list.append(empty);
            return;
        }
        result.entries.forEach(entry => {
            const item = document.createElement("li");
            item.className = "account-audit-entry";
            const details = document.createElement("div");
            const summary = document.createElement("div");
            summary.className = "account-audit-summary";
            summary.textContent = entry.summary;
            const meta = document.createElement("div");
            meta.className = "account-audit-meta";
            meta.textContent = `${entry.actorUsername} · ${new Date(entry.createdAt).toLocaleString()}`;
            details.append(summary, meta);
            const action = document.createElement("span");
            action.className = "account-audit-action";
            action.textContent = entry.action.replaceAll(".", " ");
            item.append(details, action);
            list.append(item);
        });
    } catch (error) {
        const failure = document.createElement("li");
        failure.className = "text-danger p-3";
        failure.textContent = error.message;
        list.append(failure);
    }
}

async function clearAuditHistory() {
    if (!window.confirm("Clear all entries currently shown in Recent activity?")) return;
    const confirmation = window.prompt('Administrator approval required. Type CLEAR HISTORY to continue.');
    if (confirmation === null) return;
    if (confirmation !== "CLEAR HISTORY") {
        showSystemNotification("History was not cleared because the approval phrase did not match.");
        return;
    }
    try {
        const result = await authRequest("clearAuditLogView", { confirmation });
        if (!result.ok) throw new Error(result.message || "Unable to clear recent activity.");
        showSystemNotification(result.message);
        loadAuditLog();
    } catch (error) { showSystemNotification(error.message); }
}

async function reviewAccountRequest(id, action, username) {
    const verb = action === "approve" ? "approve" : "reject";
    if (!window.confirm(`Are you sure you want to ${verb} the account request for ${username}?`)) return;
    try {
        const result = await authRequest(action === "approve" ? "approveUser" : "rejectUser", { id });
        if (!result.ok) throw new Error(result.message || "Unable to update the account request.");
        showSystemNotification(result.message); loadAccountRequests(); refreshPendingAccountNotifications();
    } catch (error) { showSystemNotification(error.message); }
}

async function setUserPermission(id, permission, allowed, username) {
    try {
        const result = await authRequest("setUserPermission", { id, permission, allowed });
        if (!result.ok) throw new Error(result.message || `Unable to update ${username}'s permission.`);
        showSystemNotification(result.message);
        closePermissionPopovers();
        // Rebuild dropdowns from saved permissions instead of stale account closures.
        await loadAccountRequests();
    } catch (error) { showSystemNotification(error.message); }
}

async function deleteStaffAccount(id, username) {
    if (!window.confirm(`Delete the account for ${username}? This cannot be undone.`)) return;
    try {
        const result = await authRequest("deleteUser", { id });
        if (!result.ok) throw new Error(result.message || "Unable to delete the account.");
        showSystemNotification(result.message);
        loadAccountRequests();
        refreshPendingAccountNotifications();
    } catch (error) { showSystemNotification(error.message); }
}

function openAccountEditor(account = null) {
    if (window.currentUser?.role !== "superadmin") return;
    const container = document.createElement("div");
    container.className = "modal fade";
    container.tabIndex = -1;
    container.innerHTML = `<div class="modal-dialog"><form class="modal-content" id="accountEditorForm">
        <div class="modal-header"><h5 class="modal-title">${account ? "Edit" : "Create"} account</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div class="account-editor-grid">
        <label>Full name<input class="form-control" name="fullName" required maxlength="160"></label>
        <label>Username<input class="form-control" name="username" required pattern="[A-Za-z0-9._-]{3,80}" maxlength="80" autocomplete="off"></label>
        <label>Role<select class="form-select" name="role"><option value="staff">Staff</option><option value="admin">Admin</option><option value="superadmin">Superadmin</option></select></label>
        <label>Password${account ? " (leave blank to keep current)" : ""}<input class="form-control" name="password" type="password" minlength="12" autocomplete="new-password" ${account ? "" : "required"}></label>
        <label class="form-check"><input class="form-check-input" name="active" type="checkbox"> Active account</label>
        </div><p class="text-danger mt-3 mb-0" role="alert" data-account-error></p></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save account</button></div>
    </form></div>`;
    document.body.append(container);
    const form = container.querySelector("form");
    for (const key of ["fullName","username","role"]) form.elements[key].value = account?.[key] || (key === "role" ? "staff" : "");
    form.elements.active.checked = account ? account.active : true;
    const modal = new bootstrap.Modal(container);
    form.addEventListener("submit", async event => {
        event.preventDefault();
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        const body = Object.fromEntries(new FormData(form));
        body.active = form.elements.active.checked;
        if (account) body.id = account.id;
        if (!body.password) delete body.password;
        try {
            const result = await authRequest(account ? "updateUser" : "createUser", body);
            if (!result.ok) throw new Error(Object.values(result.errors || {}).flat().join(" ") || result.message || "Unable to save account.");
            modal.hide(); loadAccountRequests(); refreshPendingAccountNotifications(); showSystemNotification(result.message);
        } catch (error) { container.querySelector('[data-account-error]').textContent = error.message; }
        finally { button.disabled = false; }
    });
    container.addEventListener("hidden.bs.modal", () => { modal.dispose(); container.remove(); }, {once:true});
    modal.show();
}
