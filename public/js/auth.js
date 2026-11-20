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
    const expectedCsrf = window.sessionCsrfToken;
    const response = await fetch("api/auth.php", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": window.sessionCsrfToken || "" },
        body: JSON.stringify({ action, ...payload })
    });
    const result = await response.json().catch(() => ({}));
    if (response.status === 401 && action !== "login" && typeof handleTrackerAuthenticationFailure === "function") handleTrackerAuthenticationFailure(result, expectedCsrf);
    if (!response.ok && !result.message) result.message = "The login service is unavailable.";
    return result;
}
function applyAuthenticatedUser(user, csrfToken, sessionTiming = null, refreshOnly = false) {
    const lostAdminAccess = window.currentUser?.role === "admin" && user.role !== "admin";
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
    const menuAvatar = document.getElementById("accountMenuAvatar");
    if (menuAvatar && avatar) {
        menuAvatar.textContent = initial || "U";
        menuAvatar.style.backgroundImage = avatar.style.backgroundImage;
        menuAvatar.classList.toggle("has-photo", Boolean(user.profilePhoto));
    }
    const menuRole = document.getElementById("accountMenuRole");
    if (menuRole) menuRole.textContent = ({admin: "Administrator", staff: "Staff"})[user.role] || "Account";
    const isAdmin = user.role === "admin";
    const administrationNavigation = document.getElementById("administrationNavigation");
    const staffDeletionNavigation = document.getElementById("staffDeletionNavigation");
    const deletionRequestsNav = document.getElementById("deletionRequestsNav");
    const deletionLinks = isAdmin ? document.getElementById("administrationLinks") : staffDeletionNavigation;
    if (deletionRequestsNav.parentElement !== deletionLinks) deletionLinks.append(deletionRequestsNav);
    administrationNavigation.classList.toggle("d-none", !isAdmin);
    staffDeletionNavigation.classList.toggle("d-none", user.role !== "staff");
    if (lostAdminAccess && isAdminWorkspace(activeWorkspace)) switchWorkspace("dashboard");
    if (!refreshOnly && typeof resetDeletionRequests === "function") resetDeletionRequests();
    if (!refreshOnly && typeof startDeletionRequestUpdates === "function") startDeletionRequestUpdates();
    if (typeof window.refreshRoleGuide === 'function') window.refreshRoleGuide();
    setPermissionControlState("addProjectButton", user.canCreate, "Ask Admin for Add projects permission.");
    setPermissionControlState("exportExcelButton", user.canExport, "Ask Admin for Export Excel permission.");
    setPermissionControlState("importExcelButton", user.canImport, "Ask Admin for Import Excel permission.");
    setPermissionControlState("deleteAllRecordsButton", isAdmin, "Only Admin can delete all records.");
    const deleteAllZone = document.getElementById("deleteAllRecordsZone");
    if (deleteAllZone) { deleteAllZone.hidden = !isAdmin; deleteAllZone.classList.toggle("d-none", !isAdmin); }
    const deleteAllLabel = document.getElementById("deleteAllRecordsLabel");
    if (deleteAllLabel) deleteAllLabel.textContent = "Delete all records";
    if (!refreshOnly) clearInterval(window.accountNotificationTimer);
    if (!refreshOnly) window.pendingAccountRequests = [];
    const management = document.getElementById("accountManagementCard");
    const holidayCard=document.getElementById('holidayCalendarCard');
    if (holidayCard) { holidayCard.hidden=!isAdmin; holidayCard.classList.toggle('d-none',!isAdmin); }
    if (management) management.hidden = !isAdmin;
    document.querySelectorAll("[data-admin-section]").forEach(section => section.hidden = user.role !== "admin");
    document.body.classList.remove("authentication-required");
    document.getElementById("loginOverlay")?.classList.add("d-none");
    if (!refreshOnly && isAdmin && typeof refreshPendingAccountNotifications === "function") {
        refreshPendingAccountNotifications();
        clearInterval(window.accountNotificationTimer);
        window.accountNotificationTimer = setInterval(refreshPendingAccountNotifications, 30000);
    }
    if (!refreshOnly && typeof startTrackerSession === "function") startTrackerSession(sessionTiming);
    if (refreshOnly) {
        if (typeof renderTablePage === 'function') renderTablePage();
        if (typeof renderProjectAttachments === 'function') renderProjectAttachments();
        document.getElementById('projectDetailsEditButton')?.classList.toggle('d-none', !user.canEdit);
        if (typeof syncProjectDetailsDeletionRequest === "function") syncProjectDetailsDeletionRequest();
        const save = document.getElementById('saveBtn');
        if (save && typeof editingId !== 'undefined') {
            const allowed = editingId === null ? user.canCreate : user.canEdit;
            save.disabled = !allowed || (typeof projectSaveInProgress !== 'undefined' && projectSaveInProgress);
            save.title = allowed ? '' : 'Permission changed. Your draft is kept; contact Admin for access.';
        }
    }
}
function showLogin(message = "") {
    if (typeof preserveSuspendedProjectDraft === "function" && preserveSuspendedProjectDraft()) message += " Your project draft is kept in this tab. Sign in with the same account to continue; do not refresh or close this tab.";
    if (typeof stopTrackerSession === "function") stopTrackerSession();
    document.querySelectorAll(".project-action-dialog[open]").forEach(dialog => dialog.close());
    if (typeof bootstrap !== "undefined") document.querySelectorAll(".modal.show").forEach(modal => bootstrap.Modal.getInstance(modal)?.hide());
    if (typeof resetFormAccess === "function") resetFormAccess({ silent: true, navigate: false, preserveDraft: true });
    if (typeof stopProjectEditingPresence === "function") void stopProjectEditingPresence({ clear: true });
    if (typeof resetDeletionRequests === "function") resetDeletionRequests();
    window.currentUser = null;
    document.getElementById("administrationNavigation")?.classList.add("d-none");
    document.getElementById("staffDeletionNavigation")?.classList.add("d-none");
    if (typeof window.refreshRoleGuide === 'function') window.refreshRoleGuide();
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
    const usernameField = document.getElementById("loginUsername");
    const passwordField = document.getElementById("loginPassword");
    const username = usernameField.value.trim(), password = passwordField.value;
    const submitButton = event.currentTarget.querySelector("button[type='submit']");
    const setup = document.getElementById("initialAdminSetup");
    const setupFields = Array.from(setup.querySelectorAll("input"));
    const isSettingUp = !setup.classList.contains("d-none");
    const payload = { username, password };
    if (isSettingUp) {
        [payload.setup_code, payload.new_password, payload.new_password_confirmation] = setupFields.map(input => input.value);
    }
    submitButton.disabled = true;
    try {
        const result = await authRequest("login", payload);
        if (!result.ok) {
            const message = result.message || "Sign-in failed.";
            showLogin(message);
            if (result.requiresPasswordSetup && !isSettingUp) {
                setup.classList.remove("d-none");
                setupFields.forEach(input => { input.required = true; });
                submitButton.textContent = "Set password and sign in";
                setupFields[0].focus();
            }
            if (message.includes("Your account is inactive")) {
                // Keep the approval message in the sign-in card.  A Bootstrap modal
                // would sit behind the login overlay and trap focus, which prevents
                // people from editing their username or password to try another account.
                document.getElementById("loginPassword")?.focus();
            }
            return;
        }
        applyAuthenticatedUser(result.user, result.csrfToken, result.sessionTiming);
        passwordField.value = "";
        setupFields.forEach(input => { input.value = ""; input.required = false; });
        setup.classList.add("d-none");
        submitButton.textContent = "Sign in";
        sessionStorage.removeItem("lguProjectTrackerWorkspace");
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
    if (typeof stopProjectEditingPresence === "function") await stopProjectEditingPresence({ clear: true });
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
        if (result.ok && result.authenticated) { applyAuthenticatedUser(result.user, result.csrfToken, result.sessionTiming); await initializeDatabase(); }
        else showLogin(result.message || "");
    } catch (_) { showLogin(connectionHelpMessage()); }
});
