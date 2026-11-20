/* Admin account directory and office settings. */
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
    if (window.currentUser?.role !== "admin") return;
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

function addAccountAction(container, label, style, callback, disabled = false, disabledTitle = "") {
    const button = document.createElement("button");
    button.type = "button";
    button.className = `btn btn-sm ${style}`;
    button.textContent = label;
    button.disabled = disabled;
    if (disabled) button.title = disabledTitle;
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
        ["canDelete", "Request project deletion"],
        ["canResolveAlerts", "Restore historical alerts"],
        ["canArchive", "Archive projects"],
    ].forEach(([permission, label]) => {
        const option = document.createElement("label");
        option.className = "form-check d-block small mb-1";
        const toggle = document.createElement("input");
        toggle.type = "checkbox";
        toggle.className = "form-check-input";
        toggle.checked = Boolean(account[permission]);
        toggle.onchange = async () => {
            const allowed = toggle.checked;
            const toggles = panel.querySelectorAll('input[type="checkbox"]');
            toggles.forEach(input => { input.disabled = true; });
            try {
                const saved = await setUserPermission(account.id, permission, allowed, account.username);
                if (saved) account[permission] = allowed;
                else toggle.checked = !allowed;
            } finally {
                toggles.forEach(input => { input.disabled = false; });
            }
        };
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

function accountIcon(name) {
    const paths = {
        key: '<circle cx="8" cy="8" r="4"/><path d="m11 11 9 9m-4-4 3-3m-6 0 3-3"/>',
        edit: '<path d="m16 3 5 5M4 20l4-1L21 6a2 2 0 0 0-5-3L3 16l-1 5Z"/>',
        shield: '<path d="M12 3 3 7v5c0 5 9 9 9 9s9-4 9-9V7Z"/><path d="m8 12 3 3 5-6"/>',
        trash: '<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/>',
        lock: '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        search: '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    };
    return `<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.key}</svg>`;
}

function filterAccountDirectory() {
    const query = document.getElementById('accountDirectorySearch')?.value.trim().toLocaleLowerCase() || '';
    const rows = [...document.querySelectorAll('#accountRequestsList tr[data-account-search]')];
    let shown = 0;
    rows.forEach(row => { row.hidden = !row.dataset.accountSearch.includes(query); if (!row.hidden) shown++; });
    const empty = document.getElementById('accountDirectoryEmpty');
    if (empty) empty.hidden = shown !== 0;
    const summary = document.getElementById('accountDirectorySummary');
    if (summary) summary.textContent = query ? `${shown} of ${rows.length} accounts` : `${rows.length} accounts · ${rows.filter(row => row.dataset.active === 'true').length} active`;
}

async function loadAccountRequests() {
    if (window.currentUser?.role !== "admin") return;
    const list = document.getElementById("accountRequestsList");
    if (!list) return;
    setAccountRequestsMessage("Loading accounts…");
    try {
        const result = await authRequest("listManagedUsers");
        if (!result.ok) throw new Error(result.message || "Unable to load accounts.");
        updatePendingAccountRequests(result.users);
        list.replaceChildren();
        setAccountRequestsMessage("");
        result.users.forEach(account => {
            const row = document.createElement("tr");
            row.dataset.accountSearch = `${account.fullName} ${account.username} ${account.role}`.toLocaleLowerCase();
            row.dataset.active = String(account.active);
            const cell = (label, className = "") => {
                const td = document.createElement("td"); td.dataset.label = label; td.className = className; row.append(td); return td;
            };
            const initial = (account.fullName || account.username || "U").trim().charAt(0).toUpperCase();
            const created = new Date(account.createdAt);
            const date = Number.isNaN(created.getTime()) ? '' : created.toLocaleDateString('en-US', {timeZone:'Asia/Taipei',month:'short',day:'numeric',year:'numeric'});
            const protectedLabel = account.isOriginalAdmin ? `<span class="account-protected" title="The original Admin account cannot be deleted">${accountIcon('lock')}<span>Protected</span></span>` : '';
            cell('Account', 'account-user-cell').innerHTML = `<span class="account-user-content"><span class="account-user-avatar">${account.profilePhoto ? `<img src="${escapeHtml(account.profilePhoto)}" alt="">` : escapeHtml(initial)}</span><span class="account-identity"><strong>${escapeHtml(account.fullName)}${protectedLabel}</strong><span class="account-username">@${escapeHtml(account.username)}</span><small class="account-added">Added ${escapeHtml(date)}</small></span></span>`;
            const role = ({admin:'Admin',staff:'Staff'})[account.role];
            const permissionCount = ['canCreate','canEdit','canImport','canExport','canDelete','canResolveAlerts','canArchive'].filter(key => account[key]).length;
            const access = account.role === 'admin' ? 'Full project access' : permissionCount ? `${permissionCount} permissions enabled` : 'View only';
            cell('Access', 'account-access-cell').innerHTML = `<div class="account-access-top"><span class="account-role-badge is-${account.role}">${role}</span><span class="account-state ${account.active ? 'is-active':'is-pending'}">${account.active ? 'Active':'Pending'}</span></div><span class="account-access-summary">${escapeHtml(access)}</span>`;
            const password = cell('Password', 'account-password-cell');
            addAccountAction(password, 'Set new password', 'btn-outline-secondary', () => openAccountEditor(account, {passwordOnly: true}));
            const actions = document.createElement('div'); actions.className='account-actions'; cell('Actions','account-actions-cell').append(actions);
            addAccountAction(actions,'Edit','account-edit-action',()=>openAccountEditor(account));
            actions.lastElementChild.innerHTML=`${accountIcon('edit')}<span>Edit</span>`;
            if (!account.active) {
                addAccountAction(actions,'Approve','btn-success',()=>reviewAccountRequest(account.id,'approve',account.username));
                if (!account.isOriginalAdmin) addAccountAction(actions,'Reject','btn-outline-danger',()=>reviewAccountRequest(account.id,'reject',account.username));
            } else {
                if (account.role==='staff') {
                    addPermissionDropdown(actions,account);
                    const button=actions.lastElementChild; button.innerHTML=accountIcon('shield'); button.className='btn btn-sm account-icon-action permission-dropdown-button';
                    button.title='Manage permissions'; button.setAttribute('aria-label',`Permissions for ${account.username}`);
                }
                if (!account.isOriginalAdmin && account.id!==window.currentUser.id) {
                    addAccountAction(actions,'Delete','account-icon-action account-delete-action',()=>deleteStaffAccount(account.id,account.username));
                    const button=actions.lastElementChild; button.innerHTML=accountIcon('trash'); button.title='Delete account'; button.setAttribute('aria-label',`Delete ${account.username}`);
                }
            }
            list.append(row);
        });
        const empty=document.createElement('tr'); empty.id='accountDirectoryEmpty'; empty.hidden=true;
        const message=document.createElement('td'); message.colSpan=4; message.className='account-directory-empty'; message.textContent='No matching accounts. Try a different name or username.'; empty.append(message); list.append(empty);
        filterAccountDirectory();
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
    if (destination) destination.readOnly = result.cloud === true;
    const download = document.getElementById('downloadLatestBackup');
    if (download) download.hidden = !(result.cloud && result.latest);
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
        if (activeWorkspace === "accountability") loadAuditLog();
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

let auditLogDates = { fromDate: null, toDate: null };
let auditLogPage = 1;
let auditLogRequestSequence = 0;

function applyAuditLogDates(event) {
    event?.preventDefault();
    const fromDate = document.getElementById("auditFromDate").value || null;
    const toDate = document.getElementById("auditToDate").value || null;
    const error = document.getElementById("auditDateError");
    if (fromDate && toDate && fromDate > toDate) {
        error.textContent = "The From date must be on or before the To date.";
        error.hidden = false;
        return;
    }
    error.hidden = true;
    auditLogDates = { fromDate, toDate };
    void loadAuditLog(1);
}

function resetAuditLogDates() {
    document.getElementById("auditFromDate").value = "";
    document.getElementById("auditToDate").value = "";
    applyAuditLogDates();
}

function changeAuditLogPage(direction) {
    void loadAuditLog(Math.max(1, auditLogPage + direction));
}

function showAuditLogMessage(list, text, style = "text-muted") {
    const item = document.createElement("li");
    item.className = `${style} p-3`;
    item.textContent = text;
    list.replaceChildren(item);
}

async function loadAuditLog(page = auditLogPage) {
    if (window.currentUser?.role !== "admin") return;
    const list = document.getElementById("auditLogList");
    if (!list) return;
    const pagination = document.getElementById("auditLogPagination");
    const sequence = ++auditLogRequestSequence;
    const userId = window.currentUser.id;
    const stillCurrent = () => sequence === auditLogRequestSequence
        && window.currentUser?.id === userId && window.currentUser?.role === "admin";
    const dates = auditLogDates;
    pagination.hidden = true;
    showAuditLogMessage(list, "Loading activity…");
    try {
        const result = await authRequest("listAuditLog", { ...dates, page });
        if (!stillCurrent()) return;
        if (!result.ok) throw new Error(result.message || "Unable to load activity.");
        auditLogPage = result.page;
        if (!result.entries.length) {
            if (page > 1) {
                void loadAuditLog(1);
                return;
            }
            showAuditLogMessage(list, dates.fromDate || dates.toDate
                ? "No activity matches these dates."
                : "No activity has been recorded yet.");
            return;
        }
        list.replaceChildren();
        result.entries.forEach(entry => {
            const item = document.createElement("li");
            item.className = "account-audit-entry";
            item.dataset.logId = String(entry.id);
            const details = document.createElement("div");
            const heading = document.createElement("div");
            heading.className = "account-audit-heading";
            const number = document.createElement("span");
            number.className = "account-audit-number";
            number.textContent = `Log #${entry.id}`;
            const summary = document.createElement("div");
            summary.className = "account-audit-summary";
            summary.textContent = entry.summary;
            const meta = document.createElement("div");
            meta.className = "account-audit-meta";
            meta.textContent = `${entry.actorUsername} · ${new Date(entry.createdAt).toLocaleString(undefined, { timeZone: "Asia/Manila" })}`;
            heading.append(number, summary);
            details.append(heading, meta);
            const action = document.createElement("span");
            action.className = "account-audit-action";
            action.textContent = entry.action.replaceAll(".", " ");
            item.append(details, action);
            list.append(item);
        });
        list.scrollTop = 0;
        document.getElementById("auditLogPage").textContent = `Page ${auditLogPage}`;
        document.getElementById("auditLogPrevious").disabled = auditLogPage <= 1;
        document.getElementById("auditLogNext").disabled = !result.hasMorePages;
        pagination.hidden = auditLogPage <= 1 && !result.hasMorePages;
    } catch (error) {
        if (stillCurrent()) showAuditLogMessage(list, error.message || "Unable to load activity.", "text-danger");
    }
}

async function clearAuditHistory() {
    if (!window.confirm("Clear the accountability view for all administrators across every date?")) return;
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
        loadAuditLog(1);
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
        // Keep this panel and its checkboxes alive while refreshing the directory.
        const panel = document.querySelector(`.permission-popover[data-account-id="${id}"]`);
        if (panel) activePermissionButton = null;
        await loadAccountRequests();
        if (panel?.isConnected && document.querySelector(".permission-popover") === panel) {
            activePermissionButton = document.querySelector(`.permission-dropdown-button[data-account-id="${id}"]`);
            activePermissionButton?.setAttribute("aria-expanded", "true");
            positionPermissionPopover();
        }
        return true;
    } catch (error) {
        showSystemNotification(error.message);
        return false;
    }
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

function openAccountEditor(account=null,options={}) {
    if(window.currentUser?.role!=='admin')return;
    const passwordOnly=!!account&&options.passwordOnly===true;
    const roles=['staff','admin'];
    const passwordInput = `<input id="accountEditorPassword" class="form-control" name="password" type="password" minlength="12" autocomplete="new-password" aria-describedby="accountPasswordHint" ${!account || passwordOnly ? 'required' : ''}>`;
    const passwordHint = `<span id="accountPasswordHint" class="account-dialog-note">${account && !passwordOnly ? 'Leave blank to keep the current password.' : 'Use at least 12 characters.'}</span>`;
    const passwordField = account
        ? `<label>${passwordOnly ? 'New password' : 'Password <span class="account-optional">(optional)</span>'}${passwordInput}${passwordHint}</label>`
        : `<div>
            <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                <label for="accountEditorPassword" class="form-label mb-0">Password</label>
                <button type="button" class="btn btn-sm btn-link p-0" data-create-password-toggle aria-controls="accountEditorPassword">Show password</button>
            </div>
            ${passwordInput}${passwordHint}
        </div>`;
    const container=document.createElement('div');container.className='modal fade account-dialog';container.tabIndex=-1;
    container.setAttribute('aria-labelledby','accountEditorTitle');
    container.innerHTML=`<div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="accountEditorForm">
        <div class="modal-header"><div><span class="account-dialog-eyebrow">Account management</span><h5 class="modal-title" id="accountEditorTitle">${passwordOnly?'Set new password':account?'Edit account':'Create account'}</h5></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">${passwordOnly?'<div class="account-dialog-person"><span class="account-dialog-symbol">'+accountIcon('key')+'</span><div><strong data-editor-name></strong><span data-editor-username></span></div></div><p class="account-dialog-description">The user will use this new password the next time they sign in.</p>':''}<div class="account-editor-grid">
        ${passwordOnly?'':`<label>Full name<input class="form-control" name="fullName" required maxlength="160" autocomplete="name"></label><label>Username<input class="form-control" name="username" required pattern="[A-Za-z0-9._-]{3,80}" maxlength="80" autocomplete="off"></label><label>Role<select class="form-select" name="role">${roles.map(role=>`<option value="${role}">${({staff:'Staff',admin:'Admin'})[role]}</option>`).join('')}</select></label>`}
        ${passwordField}
        ${passwordOnly?'<label>Confirm new password<input class="form-control" name="passwordConfirmation" type="password" minlength="12" autocomplete="new-password" required></label>':'<label class="account-active-toggle"><input class="form-check-input" name="active" type="checkbox"><span>Active account<small>Allow this user to sign in.</small></span></label>'}
        ${account?`<label ${passwordOnly?'':'hidden'}>Your Admin password<input class="form-control" name="current_password" type="password" autocomplete="current-password" maxlength="4096" ${passwordOnly?'required':'disabled'}></label>`:''}
        </div><p class="account-dialog-error" role="alert" data-account-error hidden></p></div>
        <div class="modal-footer"><button type="button" class="btn account-cancel-button" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">${passwordOnly?'Save new password':'Save account'}</button></div>
    </form></div>`;
    document.body.append(container);
    const form=container.querySelector('form'),modal=new bootstrap.Modal(container);
    const adminPassword = form.elements.current_password;
    const passwordToggle = form.querySelector('[data-create-password-toggle]');
    const setPasswordVisible = visible => {
        if (!passwordToggle) return;
        form.elements.password.type = visible ? 'text' : 'password';
        passwordToggle.textContent = visible ? 'Hide password' : 'Show password';
    };
    passwordToggle?.addEventListener('click', () => setPasswordVisible(form.elements.password.type === 'password'));
    if(passwordOnly){container.querySelector('[data-editor-name]').textContent=account.fullName;container.querySelector('[data-editor-username]').textContent='@'+account.username;}
    else {for(const key of ['fullName','username','role'])form.elements[key].value=account?.[key]||(key==='role'?'staff':'');form.elements.active.checked=account?account.active:true;}
    if (adminPassword && !passwordOnly) {
        form.elements.password.addEventListener('input', () => {
            const resetting = form.elements.password.value !== '';
            adminPassword.closest('label').hidden = !resetting;
            adminPassword.disabled = !resetting;
            adminPassword.required = resetting;
            if (!resetting) adminPassword.value = '';
        });
    }
    form.addEventListener('submit',async event=>{
        event.preventDefault();const error=container.querySelector('[data-account-error]');error.hidden=true;
        setPasswordVisible(false);
        if(passwordOnly&&form.elements.password.value!==form.elements.passwordConfirmation.value){error.textContent='The passwords do not match.';error.hidden=false;return;}
        const button=form.querySelector('[type=submit]');button.disabled=true;
        const body=passwordOnly?{id:account.id,fullName:account.fullName,username:account.username,role:account.role,active:account.active,password:form.elements.password.value,current_password:adminPassword.value}:Object.fromEntries(new FormData(form));
        if(!passwordOnly)body.active=form.elements.active.checked;
        if(account)body.id=account.id;
        if(!body.password)delete body.password;
        if (adminPassword) adminPassword.value = '';
        try {
            const result=await authRequest(account?'updateUser':'createUser',body);
            if(!result.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Unable to save account.');
            if (result.signInRequired) { modal.hide(); showLogin("Your password was changed. Sign in with your new password."); return; }
            modal.hide();void loadAccountRequests();void refreshPendingAccountNotifications();showSystemNotification(passwordOnly?'New password saved.':result.message);
        }catch(failure){error.textContent=failure.message;error.hidden=false;}
        finally{button.disabled=false;}
    });
    container.addEventListener('hide.bs.modal',()=>{form.elements.password.value='';if(form.elements.passwordConfirmation)form.elements.passwordConfirmation.value='';if(adminPassword)adminPassword.value='';});
    container.addEventListener('hidden.bs.modal',()=>{modal.dispose();container.remove();},{once:true});
    container.addEventListener('shown.bs.modal',()=>{form.elements[passwordOnly?'password':'fullName'].focus();},{once:true});
    modal.show();
}

