/* Session gate for the shared LGU tracker. */
window.sessionCsrfToken = "";
window.currentUser = null;
function setPermissionControlState(id, allowed, unavailableTitle) {
    const control = document.getElementById(id);
    if (!control) return;
    control.disabled = !allowed;
    control.classList.toggle("opacity-50", !allowed);
    control.classList.toggle("pe-none", !allowed);
    control.title = allowed ? "" : unavailableTitle;
    control.setAttribute("aria-disabled", String(!allowed));
}
async function authRequest(action, payload = {}) {
    const response = await fetch("api/auth.php", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": window.sessionCsrfToken || "" },
        body: JSON.stringify({ action, ...payload })
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok && !result.message) result.message = "The login service is unavailable.";
    return result;
}
function applyAuthenticatedUser(user, csrfToken) {
    window.currentUser = user;
    window.sessionCsrfToken = csrfToken;
    document.getElementById("currentUserName").textContent = user.fullName || user.username;
    const initial = (user.fullName || user.username || "U").trim().charAt(0).toUpperCase();
    const avatar = document.getElementById("currentUserInitial");
    if (avatar) avatar.textContent = initial || "U";
    const photo = document.getElementById("currentUserPhoto");
    const photoUrl = user.profilePhoto || `api/profile.php?action=photo&v=${Date.now()}`;
    if (avatar) {
        avatar.style.backgroundImage = `url("${photoUrl}")`;
        avatar.classList.toggle("has-photo", Boolean(user.profilePhoto));
    }
    if (photo) {
        photo.classList.add("d-none");
    }
    document.getElementById("accountRequestsTabBtn")?.classList.toggle("d-none", !["admin", "superadmin"].includes(user.role));
    const isSuperadmin = user.role === "superadmin";
    document.body.classList.toggle("account-management-only", isSuperadmin);
    document.querySelectorAll("[data-project-navigation]").forEach(button => button.hidden = isSuperadmin);
    const eyebrow = document.getElementById("administrationPageEyebrow");
    if (eyebrow) eyebrow.textContent = isSuperadmin ? "Account management" : "Administration";
    if (isSuperadmin) { window.projectNotificationGroups = []; window.notificationAlertTotal = 0; }
    setPermissionControlState("addProjectButton", user.canCreate, "Ask Superadmin for Add projects permission.");
    setPermissionControlState("exportExcelButton", user.canExport, "Ask Superadmin for Export Excel permission.");
    setPermissionControlState("importExcelButton", user.canImport, "Ask Superadmin for Import Excel permission.");
    setPermissionControlState("deleteAllRecordsButton", user.canDeleteAll, "Ask Superadmin for Delete all records permission.");
    setPermissionControlState("dashboardBulkDoneBtn", user.canResolveAlerts, "Ask Superadmin for Mark/restore alerts permission.");
    clearInterval(window.accountNotificationTimer);
    window.pendingAccountRequests = [];
    const management = document.getElementById("accountManagementCard");
    if (management) management.hidden = user.role !== "superadmin";
    document.querySelectorAll("[data-admin-section]").forEach(section => section.hidden = user.role !== "admin");
    const title = document.getElementById("administrationPageTitle");
    const description = document.getElementById("administrationPageDescription");
    if (title) title.textContent = user.role === "superadmin" ? "Accounts" : "Administration";
    if (description) description.textContent = user.role === "superadmin" ? "Create, edit, delete and approve accounts; manage Staff permissions." : "Manage timeline reminders, recent activity and automatic backups.";
    const tab = document.querySelector("#accountRequestsTabBtn span:last-child");
    if (tab) tab.textContent = user.role === "superadmin" ? "Accounts" : "Admin";
    document.body.classList.remove("authentication-required");
    document.getElementById("loginOverlay")?.classList.add("d-none");
    if (user.role === "superadmin" && typeof refreshPendingAccountNotifications === "function") {
        refreshPendingAccountNotifications();
        clearInterval(window.accountNotificationTimer);
        window.accountNotificationTimer = setInterval(refreshPendingAccountNotifications, 30000);
    }
}
function showLogin(message = "") {
    if (typeof resetFormAccess === "function") resetFormAccess({ silent: true, navigate: false, preserveDraft: true });
    window.currentUser = null;
    db = null; window.db = null; window.dbReady = false;
    window.pendingAccountRequests = [];
    window.projectNotificationGroups = [];
    window.notificationAlertTotal = 0;
    if (typeof renderServerDashboardNotifications === "function") renderServerDashboardNotifications();
    window.sessionCsrfToken = "";
    clearInterval(window.accountNotificationTimer);
    document.body.classList.add("authentication-required");
    document.getElementById("loginOverlay")?.classList.remove("d-none");
    const error = document.getElementById("loginError"); if (error) error.textContent = message;
}
function connectionHelpMessage() {
    if (window.location.protocol === "file:") {
        return "Open the tracker through Apache, not by double-clicking a file. Ask IT for the Project Tracker link.";
    }
    return "Cannot reach the login service. Confirm Apache and PostgreSQL are running, then refresh the page.";
}
async function signIn(event) {
    event.preventDefault();
    const username = document.getElementById("loginUsername").value.trim(), password = document.getElementById("loginPassword").value;
    const submitButton = event.currentTarget.querySelector("button[type='submit']");
    submitButton.disabled = true;
    try {
        const result = await authRequest("login", { username, password });
        if (!result.ok) {
            const message = result.message || "Sign-in failed.";
            showLogin(message);
            if (message.includes("awaiting Superadmin approval")) {
                // Keep the approval message in the sign-in card.  A Bootstrap modal
                // would sit behind the login overlay and trap focus, which prevents
                // people from editing their username or password to try another account.
                document.getElementById("loginPassword")?.focus();
            }
            return;
        }
        applyAuthenticatedUser(result.user, result.csrfToken);
        await initializeDatabase();
    } catch (_) {
        showLogin(connectionHelpMessage());
    } finally {
        submitButton.disabled = false;
    }
}
async function signOut() {
    clearInterval(window.accountNotificationTimer);
    sessionStorage.removeItem("lguProjectTrackerWorkspace");
    await authRequest("logout");
    // A clean page removes cached records and restarts the session gate.
    window.location.reload();
}

function openProfileEditor() {
    document.getElementById("accountMenu")?.classList.add("d-none");
    const user = window.currentUser || {};
    if (window.profilePhotoEdit?.url) URL.revokeObjectURL(window.profilePhotoEdit.url);
    window.profilePhotoEdit = null;
    window.profilePhotoDragMoved = false;
    document.getElementById("profileFullName").value = user.fullName || user.username || "";
    const initial = (user.fullName || user.username || "U").trim().charAt(0).toUpperCase();
    document.getElementById("profileEditorInitial").textContent = initial;
    const preview = document.getElementById("profileEditorPreview");
    preview.onload = null;
    preview.src = user.profilePhoto || "";
    preview.classList.toggle("d-none", !user.profilePhoto);
    preview.style.width = "";
    preview.style.height = "";
    preview.style.transform = "";
    document.getElementById("profileEditorInitial").classList.toggle("d-none", Boolean(user.profilePhoto));
    document.getElementById("profilePhotoInput").value = ""; document.getElementById("profileEditorError").textContent = "";
    document.getElementById("profilePhotoZoom").value = "1";
    document.getElementById("profileCropArea").classList.remove("is-editing");
    document.getElementById("profilePhotoTools").classList.add("d-none");
    document.getElementById("profileCropHint").classList.add("d-none");
    bootstrap.Modal.getOrCreateInstance(document.getElementById("profileEditorModal")).show();
}
function chooseProfilePhoto(event) {
    if (window.profilePhotoDragMoved || event.target.closest("#profilePhotoTools")) { window.profilePhotoDragMoved = false; return; }
    document.getElementById("profilePhotoInput")?.click();
}
function previewProfilePhoto(input) {
    const file = input.files?.[0]; if (!file) return;
    const error = document.getElementById("profileEditorError");
    if (file.size > 3 * 1024 * 1024) { input.value = ""; error.textContent = "Use an image smaller than 3 MB."; return; }
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { input.value = ""; error.textContent = "Use a JPG, PNG, or WebP image."; return; }
    if (window.profilePhotoEdit?.url) URL.revokeObjectURL(window.profilePhotoEdit.url);
    error.textContent = "";
    window.profilePhotoEdit = { file, url: URL.createObjectURL(file), zoom: 1, rotate: 0, x: 0, y: 0 };
    document.getElementById("profileCropArea").classList.add("is-editing");
    document.getElementById("profilePhotoZoom").value = "1";
    document.getElementById("profilePhotoTools").classList.remove("d-none");
    document.getElementById("profileCropHint").classList.remove("d-none");
    const preview = document.getElementById("profileEditorPreview");
    preview.onload = () => updateProfilePhotoPreview();
    updateProfilePhotoPreview();
}
function adjustProfilePhoto(field, value) {
    const edit = window.profilePhotoEdit;
    if (!edit) return;
    if (field === "rotate") edit.rotate = (edit.rotate + Number(value) + 360) % 360;
    else edit[field] = Number(value);
    updateProfilePhotoPreview();
}
function updateProfilePhotoPreview() {
    const edit = window.profilePhotoEdit;
    if (!edit) return;
    const preview = document.getElementById("profileEditorPreview");
    if (preview.getAttribute("src") !== edit.url) preview.src = edit.url;
    preview.classList.remove("d-none");
    if (preview.naturalWidth && preview.naturalHeight) {
        const areaSize = document.getElementById("profileCropArea")?.clientWidth || 420;
        const quarterTurn = Math.abs(edit.rotate % 180) === 90;
        const rotatedWidth = quarterTurn ? preview.naturalHeight : preview.naturalWidth;
        const rotatedHeight = quarterTurn ? preview.naturalWidth : preview.naturalHeight;
        const coverScale = Math.max(areaSize / rotatedWidth, areaSize / rotatedHeight);
        preview.style.width = `${preview.naturalWidth}px`;
        preview.style.height = `${preview.naturalHeight}px`;
        preview.style.transform = `translate(-50%, -50%) translate(${edit.x}px, ${edit.y}px) rotate(${edit.rotate}deg) scale(${coverScale * edit.zoom})`;
    }
    document.getElementById("profileEditorInitial").classList.add("d-none");
}
function beginProfilePhotoDrag(event) {
    if (!window.profilePhotoEdit || event.target.id !== "profileEditorPreview") return;
    event.preventDefault();
    const area = event.currentTarget, edit = window.profilePhotoEdit;
    const start = { x: event.clientX, y: event.clientY, photoX: edit.x, photoY: edit.y };
    const move = moveEvent => { if (Math.abs(moveEvent.clientX - start.x) + Math.abs(moveEvent.clientY - start.y) > 4) window.profilePhotoDragMoved = true; edit.x = start.photoX + moveEvent.clientX - start.x; edit.y = start.photoY + moveEvent.clientY - start.y; updateProfilePhotoPreview(); };
    const end = () => { document.removeEventListener("pointermove", move); document.removeEventListener("pointerup", end); document.removeEventListener("pointercancel", end); area.classList.remove("is-dragging"); };
    area.classList.add("is-dragging");
    document.addEventListener("pointermove", move);
    document.addEventListener("pointerup", end, { once: true });
    document.addEventListener("pointercancel", end, { once: true });
}
async function saveProfile(event) {
    event.preventDefault();
    const error = document.getElementById("profileEditorError"), button = event.currentTarget.querySelector("button[type='submit']");
    error.textContent = ""; button.disabled = true;
    try {
        const fullName = document.getElementById("profileFullName").value.trim();
        if (!fullName) throw new Error("Enter a display name.");
        const form = new FormData(); form.append("fullName", fullName);
        const photo = document.getElementById("profilePhotoInput").files?.[0];
        if (photo) form.append("photo", await renderEditedProfilePhoto());
        const response = await fetch("api/profile.php", { method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": window.sessionCsrfToken || "" }, body: form });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.ok) throw new Error(result.message || "Unable to update profile. Please sign in again and retry.");
        applyAuthenticatedUser(result.user, window.sessionCsrfToken);
        bootstrap.Modal.getInstance(document.getElementById("profileEditorModal"))?.hide();
        showSystemNotification(result.message);
    } catch (exception) { error.textContent = exception.message || "Unable to save changes."; } finally { button.disabled = false; }
}
async function renderEditedProfilePhoto() {
    const edit = window.profilePhotoEdit;
    const original = document.getElementById("profilePhotoInput").files[0];
    if (!edit || !original) return original;
    try {
        const image = new Image();
        image.src = edit.url;
        await image.decode();
        const canvas = document.createElement("canvas");
        canvas.width = canvas.height = 512;
        const context = canvas.getContext("2d");
        const previewSize = document.getElementById("profileCropArea")?.clientWidth || 420;
        const offsetScale = 512 / previewSize;
        const quarterTurn = Math.abs(edit.rotate % 180) === 90;
        const rotatedWidth = quarterTurn ? image.height : image.width;
        const rotatedHeight = quarterTurn ? image.width : image.height;
        const scale = Math.max(512 / rotatedWidth, 512 / rotatedHeight) * edit.zoom;
        context.fillStyle = "#ffffff";
        context.fillRect(0, 0, 512, 512);
        context.translate(256 + edit.x * offsetScale, 256 + edit.y * offsetScale);
        context.rotate(edit.rotate * Math.PI / 180);
        context.drawImage(image, -image.width * scale / 2, -image.height * scale / 2, image.width * scale, image.height * scale);
        const blob = await new Promise(resolve => canvas.toBlob(resolve, "image/jpeg", .9));
        return blob ? new File([blob], "profile-photo.jpg", { type: "image/jpeg" }) : original;
    } catch (_) {
        return original;
    }
}
document.addEventListener("DOMContentLoaded", async () => {
    try {
        const result = await authRequest("session");
        if (result.ok && result.authenticated) { applyAuthenticatedUser(result.user, result.csrfToken); await initializeDatabase(); }
        else showLogin();
    } catch (_) { showLogin(connectionHelpMessage()); }
});
