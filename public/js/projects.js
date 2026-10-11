function getStatusClass(status) {
        const normalized =
            (status || "Pending").trim().toLowerCase();

        if (normalized === "completed") {
            return "project-status-completed";
        }

        if (normalized === "released") {
            return "project-status-released";
        }

        if (
            normalized === "on-going bidding" ||
            normalized === "on-bidding"
        ) {
            return "project-status-bidding";
        }

        if (normalized.includes("pre-inspection")) {
            return "project-status-preinspection";
        }

        if (
            normalized.includes("final-inspection") ||
            normalized.includes("final inspection")
        ) {
            return "project-status-finalinspection";
        }

        return "project-status-pending";
    }

/* ==========================================================================
   PROJECT MANAGEMENT
   ========================================================================== */


function isProjectAdministrator() {
    return window.currentUser?.role === "admin";
}


function canCreateProjects() {
    return window.currentUser?.canCreate === true;
}

function canEditProjects() {
    return window.currentUser?.canEdit === true;
}

function canEditAlertProjects() {
    return canEditProjects();
}

function canDeleteProjects() {
    return window.currentUser?.canDelete === true;
}

function canArchiveProjects() {
    return window.currentUser?.canArchive === true;
}

let projectNumberSortDirection = localStorage.getItem("lguProjectTrackerProjectNumberSort") === "desc" ? "desc" : "asc";
let projectLifecycle = "active";
let sharedProjectVersion = null;
let sharedProjectSyncBusy = false;
let sharedProjectCheckBusy = false;
let projectEditSnapshot = null;

function showSharedUpdatesIndicator(show) {
    document.getElementById("sharedUpdatesButton")?.classList.toggle("d-none", !show);
}

async function loadNextProjectNumberPreview() {
    const preview = document.getElementById("nextProjectNumberPreview");
    const value = document.getElementById("nextProjectNumberValue");
    if (!preview || !value || editingId !== null) return;
    preview.classList.remove("d-none");
    value.textContent = "Loading…";
    try {
        const result = await apiClient.getNextProjectNumber();
        if (editingId === null) value.textContent = result.nextProjectNumber || "—";
    } catch (error) {
        value.textContent = "Unavailable";
        console.warn("Unable to load the next project number:", error);
    }
}

function hideNextProjectNumberPreview() {
    document.getElementById("nextProjectNumberPreview")?.classList.add("d-none");
}

function projectScreenIsBusy() {
    return projectSaveInProgress || editingId !== null || document.body.classList.contains("project-edit-pane-open")
        || window.importInProgress === true || Boolean(document.querySelector("#importPreviewModal.show, #importConflictModal.show, #importProgressModal.show, #projectDuplicateModal.show"));
}

async function refreshSharedProjectData() {
    if (window.currentUser?.role === "superadmin" || sharedProjectSyncBusy || !db) return;
    if (projectScreenIsBusy()) {
        showSharedUpdatesIndicator(true);
        showSystemNotification("Shared changes are waiting. Save or cancel your current changes to load them safely.");
        return;
    }
    sharedProjectSyncBusy = true;
    const database = db;
    const button = document.getElementById("sharedUpdatesButton");
    if (button) button.disabled = true;
    try {
        const accepted = await database.refresh();
        if (db !== database || accepted === false) return;
        // The marker belongs to this snapshot, not a separate, newer request.
        let rendered = true;
        if (activeWorkspace === "dashboard" && typeof loadDashboardData === "function") rendered = await loadDashboardData(undefined, true);
        else if (["documents", "archive"].includes(activeWorkspace)) rendered = await loadServerProjectPage(true);
        if (rendered === false) throw new Error("The view update is pending.");
        sharedProjectVersion = database.projectVersion;
        showSharedUpdatesIndicator(false);
        if (activeWorkspace === "dashboard-done" && typeof renderMarkedDoneTabContents === "function") renderMarkedDoneTabContents();
        if (typeof refreshGlobalNotifications === "function") refreshGlobalNotifications();
        if (projectDetailsModalShown && projectDetailsEditId !== null) {
            const tx = database.transaction(["projects"], "readonly");
            tx.objectStore("projects").get(projectDetailsEditId).onsuccess = event => {
                if (!projectDetailsModalShown || db !== database) return;
                if (event.target.result) viewProjectDetails(projectDetailsEditId, projectDetailsEditSource === "alerts" ? "needs-attention" : "projects");
                else bootstrap.Modal.getInstance(document.getElementById("projectDetailsModal"))?.hide();
            };
        }
    } catch (error) {
        sharedProjectVersion = null;
        showSharedUpdatesIndicator(true);
        if (button) button.title = "Automatic refresh failed. Select to retry when your connection returns.";
        console.warn("Unable to refresh shared project changes:", error);
    } finally {
        sharedProjectSyncBusy = false;
        if (button) button.disabled = false;
    }
}

async function checkForSharedProjectUpdates() {
    if (window.currentUser?.role === "superadmin" || document.hidden || sharedProjectSyncBusy || sharedProjectCheckBusy || !window.currentUser || !db) return;
    sharedProjectCheckBusy = true;
    const database = db;
    try {
        const result = await apiClient.getProjectVersion();
        if (db !== database) return;
        // A missing baseline triggers a refresh; it must never swallow a change
        // made between initial loading and the first update check.
        if (result.version === sharedProjectVersion && sharedProjectVersion !== null) return;
        if (projectScreenIsBusy()) {
            showSharedUpdatesIndicator(true);
            return;
        }
        await refreshSharedProjectData();
    } catch (error) {
        showSharedUpdatesIndicator(true);
        console.warn("Unable to check for shared project changes:", error);
    } finally {
        sharedProjectCheckBusy = false;
    }
}

window.addEventListener("sharedDatabaseReady", () => {
    sharedProjectVersion = db?.projectVersion ?? null;
    showSharedUpdatesIndicator(false);
    checkForSharedProjectUpdates();
});
document.addEventListener("DOMContentLoaded", () => {
    window.setInterval(checkForSharedProjectUpdates, 3000);
});
document.addEventListener("visibilitychange", () => { if (!document.hidden) checkForSharedProjectUpdates(); });
document.addEventListener("hidden.bs.modal", checkForSharedProjectUpdates);
window.addEventListener("focus", checkForSharedProjectUpdates);
window.addEventListener("online", checkForSharedProjectUpdates);

function updateProjectLifecycleControls() {
    document.querySelectorAll(".projects-lifecycle-tabs button").forEach(button => button.classList.remove("active"));
    const selected = document.getElementById(`projectLifecycle${projectLifecycle.charAt(0).toUpperCase()}${projectLifecycle.slice(1)}`);
    if (selected) selected.classList.add("active");
}

function setProjectLifecycle(lifecycle) {
    if (!["all", "active", "completed", "released", "alerts", "archived"].includes(lifecycle)) return;
    projectLifecycle = lifecycle;
    updateProjectLifecycleControls();
    syncProjectStatusOptions();
    currentPage = 1;
    loadDocuments();
}
function syncProjectStatusOptions() {
    const statusFilter = document.getElementById("filterStatusSelect");
    if (statusFilter) {
        const selectedStatus = statusFilter.value;
        statusFilter.querySelectorAll("option[data-archive-status]").forEach(option => option.remove());
        if (projectLifecycle !== "active") {
            ["Completed", "Released"].forEach(status => {
                const option = new Option(status, status);
                option.dataset.archiveStatus = "true";
                statusFilter.add(option);
            });
        }
        statusFilter.value = [...statusFilter.options].some(option => option.value === selectedStatus) ? selectedStatus : "All";
    }
}
function normalizeProjectSearch(value) {
    return String(value || "")
        .toLowerCase()
        .replace(/\bbarangay\b|\bbrgy\.?\b/g, "brgy")
        .replace(/\bproject\s*(?:no\.?|number)?\b/g, "project")
        .replace(/#/g, " ")
        .replace(/[^a-z0-9]+/g, " ")
        .trim()
        .replace(/\s+/g, " ");
}

function matchesBarangayFilter(project, filter) {
    const value = String(filter || "").trim().toLowerCase()
        .replace(/^(?:brgy|barangay)\.?\s*#?\s*/, "");
    const prefix = "(?:^|[^a-z0-9])(?:brgy\\.?|barangay)\\s*#?\\s*";
    const numeric = value.match(/^0*(\d+)(?:\s*-\s*([a-z]))?$/);
    let pattern;
    if (numeric) {
        const suffix = numeric[2] ? "\\s*-\\s*" + numeric[2] : "";
        pattern = prefix + "0*" + Number(numeric[1]) + suffix + "(?:$|[^a-z0-9_-])";
    } else if (!value) {
        pattern = prefix;
    } else {
        const escaped = value.replace(/[^a-z0-9 ]/g, "\\$&");
        pattern = prefix + "(?:[0-9]+(?:-[a-z])?\\s+)?" + escaped + "(?:$|[^a-z0-9_-])";
    }
    return new RegExp(pattern, "i").test([project.title, project.remarks].filter(Boolean).join(" "));
}

function resetProjectFilters() {
    const defaults = {
        searchInput: "",
        filterStatusSelect: "All",
        filterProjectNo: "",
        filterBrgy: "",
        filterProjectDateField: "",
        filterBiddingMonth: "",
        filterBiddingDay: "",
        filterBiddingYear: ""
    };
    Object.entries(defaults).forEach(([id, value]) => {
        const field = document.getElementById(id);
        if (field) field.value = value;
    });
    currentPage = 1;
}

function prepareProjectListForLoad() {
    const table = document.getElementById("documentTable");
    const info = document.getElementById("paginationInfo");
    const controls = document.getElementById("paginationControls");
    if (table) {
        table.innerHTML = '<tr class="projects-loading-row"><td colspan="8"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Loading projects…</span></td></tr>';
    }
    if (info) info.textContent = "Loading project records…";
    if (controls) controls.replaceChildren();
}

function updateProjectNumberSortControl() {
    const button = document.getElementById("projectNumberSortButton");
    const icon = document.getElementById("projectNumberSortIcon");
    const header = button?.closest("th");
    if (icon) icon.textContent = projectNumberSortDirection === "asc" ? "↑" : "↓";
    if (button) button.setAttribute("aria-label", `Sort project numbers ${projectNumberSortDirection === "asc" ? "descending" : "ascending"}`);
    if (header) header.setAttribute("aria-sort", projectNumberSortDirection === "asc" ? "ascending" : "descending");
}

function toggleProjectNumberSort() {
    projectNumberSortDirection = projectNumberSortDirection === "asc" ? "desc" : "asc";
    localStorage.setItem("lguProjectTrackerProjectNumberSort", projectNumberSortDirection);
    currentPage = 1;
    updateProjectNumberSortControl();
    loadDocuments();
}

function handleDashboardMetricKey(event, action) {
    if (event.key !== "Enter" && event.key !== " ") return;
    event.preventDefault();
    action();
}

function openAllProjects() {
    resetProjectFilters();
    projectLifecycle = "active";
    updateProjectLifecycleControls();
    switchWorkspace("documents");
}

function openPendingProjects() {
    projectLifecycle = "active";
    updateProjectLifecycleControls();
    switchWorkspace("documents");
    const statusFilter = document.getElementById("filterStatusSelect");
    if (statusFilter) statusFilter.value = "Pending";
    currentPage = 1;
    loadDocuments();
}

function openDashboardAlerts() {
    resetProjectFilters();
    projectLifecycle = "alerts";
    updateProjectLifecycleControls();
    switchWorkspace("documents");
}

function escapeAttachmentText(value) {
    const element = document.createElement("span");
    element.textContent = value || "";
    return element.innerHTML;
}

function openAttachmentPreview(event) {
    event.preventDefault();
    const link = event.currentTarget;
    const dialog = document.getElementById("attachmentPreviewDialog");
    const content = document.getElementById("attachmentPreviewContent");
    const title = document.getElementById("attachmentPreviewTitle");
    const download = document.getElementById("attachmentPreviewDownload");
    if (!dialog || !content || !title || !download) return;

    const url = link.getAttribute("href");
    const name = link.dataset.previewName || "Attachment";
    const mime = (link.dataset.previewMime || "").toLowerCase();
    title.textContent = name;
    download.href = `${url}&download=1`;
    content.replaceChildren();

    if (mime.startsWith("image/")) {
        const image = document.createElement("img");
        image.src = url;
        image.alt = name;
        image.className = "attachment-preview-image";
        content.append(image);
    } else if (mime === "application/pdf" || mime === "text/plain") {
        const frame = document.createElement("iframe");
        frame.src = url;
        frame.title = `Preview of ${name}`;
        frame.className = "attachment-preview-frame";
        content.append(frame);
    } else {
        const message = document.createElement("p");
        message.className = "attachment-preview-unavailable";
        message.textContent = "A preview is not available for this file type. You can download the attachment to open it.";
        content.append(message);
    }
    if (!dialog.open) dialog.showModal();
}

function clearAttachmentPreview() {
    document.getElementById("attachmentPreviewContent")?.replaceChildren();
}
function formatAttachmentSize(bytes) {
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

let projectAttachmentRecords = [];
let pendingProjectAttachmentFiles = [];
let pendingProjectAttachmentDeletes = new Set();
const attachmentDraftDatabaseName = "offlineDocManagerAttachmentDrafts";
const attachmentDraftStoreName = "projectAttachmentDrafts";
const attachmentDraftProjectKey = "offlineDocManager.pendingAttachmentProject";

function attachmentDraftIdentity(projectId) {
    return `${window.currentUser?.id || "anonymous"}:${projectId}`;
}

function attachmentDraftLocationKey() {
    return `${attachmentDraftProjectKey}:${window.currentUser?.id || "anonymous"}`;
}

function attachmentDraftDatabase() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(attachmentDraftDatabaseName, 1);
        request.onupgradeneeded = () => request.result.createObjectStore(attachmentDraftStoreName, { keyPath: "projectId" });
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function saveAttachmentDraft() {
    if (editingId === null) return;
    const projectId = editingId, identity = attachmentDraftIdentity(projectId), locationKey = attachmentDraftLocationKey();
    const files = [...pendingProjectAttachmentFiles], deleteIds = [...pendingProjectAttachmentDeletes];
    try {
        const database = await attachmentDraftDatabase();
        const transaction = database.transaction(attachmentDraftStoreName, "readwrite");
        transaction.objectStore(attachmentDraftStoreName).put({ projectId: identity, files, deleteIds });
        localStorage.setItem(locationKey, String(projectId));
    } catch (error) { console.warn("Could not preserve attachment draft:", error); }
}

async function restoreAttachmentDraft(projectId) {
    const userId = window.currentUser?.id, identity = attachmentDraftIdentity(projectId);
    try {
        const database = await attachmentDraftDatabase();
        const transaction = database.transaction(attachmentDraftStoreName, "readonly");
        const request = transaction.objectStore(attachmentDraftStoreName).get(identity);
        const draft = await new Promise((resolve, reject) => { request.onsuccess = () => resolve(request.result); request.onerror = () => reject(request.error); });
        if (!draft || editingId !== projectId || window.currentUser?.id !== userId) return;
        pendingProjectAttachmentFiles = Array.isArray(draft.files) ? draft.files : [];
        pendingProjectAttachmentDeletes = new Set(Array.isArray(draft.deleteIds) ? draft.deleteIds : []);
        renderProjectAttachments(projectAttachmentRecords, projectId);
        showSystemNotification("Unsaved attachment changes were restored. Select Apply Records Change to save them.");
    } catch (error) { console.warn("Could not restore attachment draft:", error); }
}

async function clearAttachmentDraft(projectId) {
    if (projectId === null || projectId === undefined) return;
    const identity = attachmentDraftIdentity(projectId), locationKey = attachmentDraftLocationKey();
    try {
        const database = await attachmentDraftDatabase();
        const transaction = database.transaction(attachmentDraftStoreName, "readwrite");
        transaction.objectStore(attachmentDraftStoreName).delete(identity);
        if (localStorage.getItem(locationKey) === String(projectId)) localStorage.removeItem(locationKey);
    } catch (error) { console.warn("Could not clear attachment draft:", error); }
}

function restoreAttachmentDraftPage() {
    if (resumeSuspendedProjectDraft()) return;
    const projectId = Number.parseInt(localStorage.getItem(attachmentDraftLocationKey()), 10);
    if (Number.isInteger(projectId) && projectId > 0 && editingId === null) editDocument(projectId);
}

window.addEventListener("sharedDatabaseReady", restoreAttachmentDraftPage);

function renderProjectAttachments(attachments = projectAttachmentRecords, projectId = editingId) {
    const editor = document.getElementById("projectAttachmentsEditor");
    const notice = document.getElementById("projectAttachmentsNewNotice");
    const list = document.getElementById("projectAttachmentsList");
    if (!editor || !notice || !list) return;
    const canManage = projectId !== null && canEditProjects();
    editor.classList.toggle("d-none", !canManage);
    notice.classList.toggle("d-none", projectId !== null);
    if (projectId === null) { list.innerHTML = ""; return; }
    projectAttachmentRecords = attachments;
    const visibleAttachments = attachments.filter(file => !pendingProjectAttachmentDeletes.has(file.id));
    const pendingFiles = pendingProjectAttachmentFiles.map((file, index) => ({ id: `pending-${index}`, name: file.name, size: file.size, pending: true }));
    if (!visibleAttachments.length && !pendingFiles.length) { list.innerHTML = '<div class="list-group-item text-muted">No attachments yet.</div>'; return; }
    list.innerHTML = [...visibleAttachments, ...pendingFiles].map(file => {
        if (file.pending) return `<div class="list-group-item d-flex align-items-center justify-content-between gap-3 list-group-item-warning"><div class="text-truncate"><span class="fw-semibold">${escapeAttachmentText(file.name)}</span><div class="small text-muted">${formatAttachmentSize(Number(file.size) || 0)} · Pending upload</div></div><button class="btn btn-outline-danger btn-sm" type="button" onclick="deleteProjectAttachment('${file.id}')">Remove</button></div>`;
        const url = `api/attachments.php?projectId=${encodeURIComponent(projectId)}&attachmentId=${encodeURIComponent(file.id)}`;
        const remove = canManage ? `<button class="btn btn-outline-danger btn-sm" type="button" onclick="deleteProjectAttachment('${file.id}')">Remove</button>` : "";
        return `<div class="list-group-item d-flex align-items-center justify-content-between gap-3"><div class="text-truncate"><a href="${url}" class="fw-semibold" data-preview-name="${escapeHtml(file.name)}" data-preview-mime="${escapeHtml(file.mime || "")}" onclick="openAttachmentPreview(event)">${escapeAttachmentText(file.name)}</a><div class="small text-muted">${formatAttachmentSize(Number(file.size) || 0)} · ${escapeAttachmentText(file.uploadedBy || "")}</div></div><div class="d-flex gap-2"><a href="${url}&download=1" class="btn btn-outline-secondary btn-sm">Download</a>${remove}</div></div>`;
    }).join("");
}

function uploadProjectAttachments() {
    if (editingId === null || !canEditProjects()) return;
    const input = document.getElementById("projectAttachmentFiles");
    if (!input?.files?.length) { showSystemNotification("Choose one or more files to add."); return; }
    pendingProjectAttachmentFiles.push(...input.files);
    input.value = "";
    saveAttachmentDraft();
    renderProjectAttachments(projectAttachmentRecords, editingId);
    showSystemNotification("Attachment changes are pending. Select Apply Records Change to save them.");
}

function deleteProjectAttachment(attachmentId) {
    if (attachmentId.startsWith("pending-")) pendingProjectAttachmentFiles.splice(Number.parseInt(attachmentId.slice(8), 10), 1);
    else pendingProjectAttachmentDeletes.add(attachmentId);
    saveAttachmentDraft();
    renderProjectAttachments(projectAttachmentRecords, editingId);
    showSystemNotification("Attachment changes are pending. Select Apply Records Change to save them.");
}

let projectSaveInProgress = false;
function setProjectSaveState(saving, message = "", failed = false) {
    projectSaveInProgress = saving;
    const button = document.getElementById("saveBtn");
    if (button) {
        button.disabled = saving;
        button.textContent = saving ? "Saving…" : editingId === null ? "Save Project" : "Apply Records Change";
        button.setAttribute("aria-busy", String(saving));
    }
    for (const id of ["cancelEditBtn", "editPaneCloseButton"]) {
        const control = document.getElementById(id);
        if (control) control.disabled = saving;
    }
    const status = document.getElementById("projectSaveStatus");
    if (status && message) {
        status.textContent = message;
        status.title = message;
        status.classList.remove("text-danger", "text-success", "text-muted");
        if (saving || failed) {
            status.classList.remove("d-none");
            status.classList.add(failed ? "text-danger" : "text-muted");
        } else {
            status.classList.add("d-none");
        }
    }
}

function saveDocument() {
    if (projectSaveInProgress) return;
    const canEditCurrentProject = projectEditSource === "alerts" ? canEditAlertProjects() : canEditProjects();
    if ((editingId !== null && !canEditCurrentProject) || (editingId === null && !canCreateProjects())) {
        showSystemNotification(editingId !== null ? "You do not have permission to edit projects." : "You do not have permission to add projects.");
        return;
    }
    const titleEl = document.getElementById("title");
    const title = titleEl ? titleEl.value.trim() : "";

    if (!title) {
        alert("'2. Project Title' is a required field.");
        return;
    }

    const getValue = (id) => {
        const el = document.getElementById(id);
        return el ? el.value.trim() : "";
    };

    const getDateValue = (id) => {
        const el = document.getElementById(id);
        return el ? el.value : "";
    };

    if (editingId === null) {
        const required = [
            ["approvedBudget", "Approved Budget for the Contract"],
        ];
        for (const [id, label] of required) {
            const value = id === "biddingDate" ? getDateValue(id) : getValue(id);
            if (!value) {
                document.getElementById(id)?.focus();
                alert(`${label} is a required field.`);
                return;
            }
        }
        const budget = getValue("approvedBudget").replace(/[₱$,\s]/g, "");
        if (!/^\d+(\.\d{1,2})?$/.test(budget)) {
            document.getElementById("approvedBudget")?.focus();
            alert("Approved Budget must be a non-negative amount with at most two decimal places.");
            return;
        }
    }

    const completionDate = getDateValue('completionDate');
    const statusValue = automaticProjectFormStatus();
    if (editingId === null && statusValue.toLowerCase() === "completed" && !completionDate) {
        updateCompletionDateHint();
        document.getElementById("completionDate")?.focus();
        showSystemNotification("Add a Completion Date before saving a completed project.");
        return;
    }

    const durationValue = getValue("contractDuration");
    if (durationValue !== "" && parseContractDuration(durationValue) === null) {
        showSystemNotification("Contract duration must be a positive number of working days (Monday–Friday), such as 30 or 30.5.");
        document.getElementById("contractDuration")?.focus();
        return;
    }
    const getSourceOfFundValue = () => {
        const selectEl = document.getElementById("sourceOfFund");
        if (!selectEl) return "";
        const selectedValue = selectEl.value;
        if (selectedValue === "Others") {
            const customEl = document.getElementById("sourceOfFundCustom");
            const customValue = customEl ? customEl.value.trim() : "";
            return customValue || selectedValue;
        }
        return selectedValue;
    };

    const docPayload = {
        sourceOfFund: getSourceOfFundValue(),                      // 6
        biddingDate: getDateValue("biddingDate"),                    // 1
        title: title,                                                // 2
        approvedBudget: getValue("approvedBudget"),                  // 3
        bidAmount: getValue("bidAmount"),                            // 4
        winningBidder: getValue("winningBidder"),                    // 5
        prNumber: getValue("prNumber"),                              // 7
        prDate: getDateValue("prDate"),                              // 8
        obre: getValue("obre"),                                      // 9
        resolutionNumber: getValue("resolutionNumber"),              // 10
        resolutionDate: getDateValue("resolutionDate"),              // 11
        noticePostQual: getDateValue("noticePostQual"),              // 12
        noaDueDate: getDateValue("noaDueDate"),
        noa: getDateValue("noa"),                                    // 13
        performanceBond: getDateValue("performanceBond"),            // 14
        contractRef: getDateValue("contractRef"),                    // 15
        contractDuration: getValue("contractDuration") === "" ? "" : Number(getValue("contractDuration")),              // 16
        ntpDate: getDateValue("ntpDate"),                            // 17
        dole: getDateValue("dole"),                                  // 18
        contractEndDate: getDateValue("contractEndDate"),            // 19
        materialTestingDate: getDateValue("materialTestingDate"),
        preInspection: getDateValue("preInspection"),                // 20
        finalInspection: getDateValue("finalInspection"),            // 21
        swa: getValue("swa"),                                        // 22

        // 23 Liquidated Damages subfields
        ldAmount: getValue("ldAmount"),
        ldNoOfDays: getValue("ldNoOfDays"),
        ldNewCompletionDate: getDateValue("ldNewCompletionDate"),
        ldActualDuration: getValue("ldActualDuration"),

        completion: completionDate,                                    // 24
        status: statusValue,                                           // 25
        remarks: getValue("remarks"),                                // 26

        updatedAt: new Date().toISOString()
    };

    if (editingId === null) {
        // On creation: fetch existing projects to assign projectNo and detect duplicates
        const transaction = db.transaction(["projects"], "readonly");
        const store = transaction.objectStore("projects");
        const getAllRequest = store.getAll();

        getAllRequest.onsuccess = () => {
            const existingProjects = getAllRequest.result || [];
           docPayload.projectNo =
           String(getNextProjectNumber(existingProjects));

            // Detect existing project with same title (case-insensitive)
            const normalizedNewTitle = (docPayload.title || "").trim().toLowerCase();
            const match = existingProjects.find(p => p.title && p.title.trim().toLowerCase() === normalizedNewTitle);

            if (match) {
                // Show a modal offering Delete / Overwrite / Duplicate choices
                showProjectDuplicateModal(match, docPayload);
            } else {
                addDocument(docPayload);
            }
        };
    } else {
        updateDocument(editingId, docPayload);
    }
}

// Duplicate resolution modal handlers (Delete / Overwrite / Duplicate)
function showProjectDuplicateModal(existingRecord, newPayload) {
    window._pendingDuplicatePayload = newPayload;
    window._pendingExistingRecord = existingRecord;

    const modalNode = document.getElementById('projectDuplicateModal');
    const titleEl = document.getElementById('projectDuplicateModalTitle');
    const bodyEl = document.getElementById('projectDuplicateModalBody');

    if (titleEl) titleEl.textContent = `Duplicate project found: ${existingRecord.title || 'Untitled'}`;

    // Build a side-by-side diff table of selected fields
    const displayFields = [
        { key: 'projectNo', label: 'Project No' },
        { key: 'title', label: 'Title' },
        { key: 'winningBidder', label: 'Winning Bidder' },
        { key: 'status', label: 'Status' },
        { key: 'approvedBudget', label: 'Approved Budget' },
        { key: 'bidAmount', label: 'Bid Amount' },
        { key: 'prNumber', label: 'PR Number' },
        { key: 'noaDueDate', label: 'NOA Due Date' },
        { key: 'noa', label: 'NOA Issued Date' },
        { key: 'ntpDate', label: 'NTP Date' },
        { key: 'completion', label: 'Completion Date' },
        { key: 'remarks', label: 'Remarks' }
    ];

    function fmt(v) {
        if (v === undefined || v === null || v === '') return '<span class="text-muted">—</span>';
        return ('' + v).replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function rowHtml(label, existingVal, newVal) {
        const changed = String(existingVal || '') !== String(newVal || '');
        const trClass = changed ? 'table-warning' : '';
        return `<tr class="${trClass}"><td class="fw-semibold small">${label}</td><td class="small text-muted">${fmt(existingVal)}</td><td class="small">${fmt(newVal)}</td></tr>`;
    }

    let diffHtml = '<div class="mb-2"><small class="text-muted">Preview below shows existing record (left) vs incoming data (right). Changed rows highlighted.</small></div>';
    diffHtml += '<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th style="width:28%">Field</th><th style="width:36%">Existing</th><th style="width:36%">Incoming</th></tr></thead><tbody>';

    // Render the canonical display fields first
    displayFields.forEach(f => {
        const existingVal = existingRecord ? existingRecord[f.key] : '';
        const newVal = newPayload ? newPayload[f.key] : '';
        diffHtml += rowHtml(f.label, existingVal, newVal);
    });

    // Then render any additional keys from the incoming payload that are not in the canonical list
    const canonicalKeys = displayFields.map(f => f.key);
    const extraKeys = Object.keys(newPayload || {}).filter(k => !canonicalKeys.includes(k)).slice(0, 20);
    extraKeys.forEach(k => {
        const label = k.replace(/([A-Z])/g, ' $1').replace(/^./, s => s.toUpperCase());
        diffHtml += rowHtml(label, existingRecord ? existingRecord[k] : '', newPayload[k]);
    });

    diffHtml += '</tbody></table></div>';

    if (bodyEl) bodyEl.innerHTML = diffHtml;

    if (modalNode) {
        const bsModal = new bootstrap.Modal(modalNode);
        bsModal.show();
    }
}

function projectDuplicateAction(action) {
    const payload = window._pendingDuplicatePayload;
    const existing = window._pendingExistingRecord;
    const modalNode = document.getElementById('projectDuplicateModal');
    if (modalNode) {
        const modalInstance = bootstrap.Modal.getInstance(modalNode);
        if (modalInstance) modalInstance.hide();
    }

    if (!payload) return;

    if (action === 'overwrite') {
        if (existing && existing.id) {
            updateDocument(existing.id, payload);
        } else {
            addDocument(payload);
        }
    } else if (action === 'duplicate') {
        addDocument(payload);
    } else if (action === 'delete') {
        if (existing && existing.id) {
            const transaction = db.transaction(['projects'], 'readwrite');
            const store = transaction.objectStore('projects');
            store.delete(existing.id);
            transaction.oncomplete = () => {
                showSystemNotification('🗑️ Existing project deleted. Imported row will be added as new.');
                addDocument(payload);
            };
        } else {
            addDocument(payload);
        }
    }

    // cleanup
    window._pendingDuplicatePayload = null;
    window._pendingExistingRecord = null;
}

function addDocument(payload) {
    if (!db || projectSaveInProgress) return;
    if (!canCreateProjects()) {
        showSystemNotification("Your account is view-only.");
        return;
    }
    setProjectSaveState(true, "Saving project to the server…");
    payload.createdAt = new Date().toISOString();
    payload.timelineCompletedMarked = false;
    payload.timelineAlertsCompleted = [];
    const transaction = db.transaction(["projects"], "readwrite");
    const store = transaction.objectStore("projects");

    store.add(payload);
    transaction.onerror = event => {
        const message = event.target.error?.message || "Unable to add the project. Please try again.";
        setProjectSaveState(false, `Save failed: ${message}`, true);
        showSystemNotification(message);
    };
    transaction.oncomplete = () => {
        setProjectSaveState(false, `Saved: ${payload.title}`);
        showSystemNotification(`🎉 Project successfully added: "${payload.title}"`);
        resetFormAccess();
        loadDocuments();

        switchWorkspace("documents");
        checkForSharedProjectUpdates();
    };
}


async function updateDocument(id, payload) {
    const canEditCurrentProject = projectEditSource === "alerts" ? canEditAlertProjects() : canEditProjects();
    if (!canEditCurrentProject) {
        showSystemNotification("You do not have permission to edit projects.");
        return;
    }
    if (!db || projectSaveInProgress) return;
    setProjectSaveState(true, "Saving project to the server…");
    const transaction = db.transaction(["projects"], "readonly");
    const projectStore = transaction.objectStore("projects");
    projectStore.get(id).onsuccess = async (e) => {
        const currentRecord = e.target.result;
        if (!currentRecord) {
            setProjectSaveState(false, "Save failed: this project no longer exists.", true);
            showSystemNotification("The project was not found. Close the editor to load shared changes.");
            return;
        }

        const editorRecord = projectEditSnapshot && String(projectEditSnapshot.id) === String(id)
            ? projectEditSnapshot : currentRecord;
        payload.projectNo = editorRecord.projectNo ?? "1";
        payload.timelineCompletedMarked = editorRecord.timelineCompletedMarked || false;
        payload.timelineAlertsCompleted = editorRecord.timelineAlertsCompleted || [];
        const updatedRecord = { ...editorRecord, ...payload };
        // The API uses this prior value to detect a truly stale browser tab.
        // It assigns a new updatedAt value itself after the comparison succeeds.
        updatedRecord.updatedAt = editorRecord.updatedAt || "";
        let savedRecord = null;
        try {
            const formData = new FormData();
            formData.append("projectId", String(id));
            formData.append("record", JSON.stringify(updatedRecord));
            formData.append("deleteIds", JSON.stringify([...pendingProjectAttachmentDeletes]));
            pendingProjectAttachmentFiles.forEach(file => formData.append("files[]", file));
            const expectedCsrf = window.sessionCsrfToken;
            const response = await fetch("api/project-edit.php", {
                method: "POST", credentials: "same-origin",
                headers: { "X-CSRF-Token": window.sessionCsrfToken || "" }, body: formData
            });
            const result = await response.json().catch(() => ({}));
            if (response.status === 401) {
                if (typeof handleTrackerAuthenticationFailure === "function") handleTrackerAuthenticationFailure(result, expectedCsrf);
                else if (typeof showLogin === "function") showLogin(result.message || "Your session has expired. Please sign in again.");
            }
            if (!response.ok || !result.ok) {
                const error = new Error(result.message || "Unable to save project changes.");
                error.status = response.status;
                throw error;
            }
            savedRecord = result.record;
            setProjectSaveState(true, `Saved: ${payload.title}. Updating the view…`);
            if (typeof db.acceptRecord === "function") db.acceptRecord("projects", savedRecord);
            pendingProjectAttachmentFiles = [];
            pendingProjectAttachmentDeletes = new Set();
            await clearAttachmentDraft(id);
            await db.refresh();
            const returnWorkspace = projectEditReturnWorkspace || "documents";
            resetFormAccess({ silent: true, navigate: false });
            switchWorkspace(returnWorkspace);
            loadDocuments();
            setProjectSaveState(false, `Saved: ${payload.title}`);
            showSystemNotification(`Project changes successfully saved: "${payload.title}"`);
        } catch (error) {
            if (savedRecord) {
                const returnWorkspace = projectEditReturnWorkspace || "documents";
                resetFormAccess({ silent: true, navigate: false });
                switchWorkspace(returnWorkspace);
                setProjectSaveState(false, `Saved: ${payload.title}. View update pending.`);
                showSharedUpdatesIndicator(true);
                showSystemNotification("Project changes were saved. The view will update automatically when the connection returns.");
            } else {
                const message = error.status === 409
                    ? "Changes were not saved because this project changed elsewhere. Your draft is still open. Copy your changes, then cancel and reopen the project to review the latest version."
                    : error.message || "Unable to save project changes.";
                setProjectSaveState(false, `Save failed: ${message}`, true);
                showSystemNotification(message);
            }
        } finally {
            setProjectSaveState(false);
            checkForSharedProjectUpdates();
        }
    };
}

function projectListFilters() {
    return {
        page: currentPage,
        pageSize: itemsPerPage,
        search: document.getElementById("searchInput")?.value.trim() || "",
        status: document.getElementById("filterStatusSelect")?.value || "All",
        projectNo: document.getElementById("filterProjectNo")?.value.trim() || "",
        brgy: document.getElementById("filterBrgy")?.value.trim() || "",
        dateField: document.getElementById("filterProjectDateField")?.value || "",
        month: document.getElementById("filterBiddingMonth")?.value || "",
        day: document.getElementById("filterBiddingDay")?.value || "",
        year: document.getElementById("filterBiddingYear")?.value || "",
        direction: projectNumberSortDirection,
        lifecycle: projectLifecycle
    };
}

async function loadServerProjectPage(silent = false) {
    const requestSequence = ++projectListRequestSequence;
    try {
        const result = await apiClient.listProjects(projectListFilters());
        if (requestSequence !== projectListRequestSequence || !["documents", "archive"].includes(activeWorkspace)) return;
        currentPage = result.page;
        currentProjectsList = result.projects || [];
        projectListTotal = result.totalRecords || 0;
        projectListServerPaged = true;
        renderTablePage();
        return true;
    } catch (error) {
        console.error("Unable to load the project page:", error);
        if (requestSequence === projectListRequestSequence && ["documents", "archive"].includes(activeWorkspace)) {
            const table = document.getElementById("documentTable");
            if (table) table.innerHTML = '<tr class="projects-loading-row"><td colspan="8">Unable to load project records. Please try again.</td></tr>';
        }
        if (!silent) showSystemNotification(error.message || "Unable to load project records.");
        return false;
    }
}

function loadDocuments(skipServerRefresh = false) {
    if (window.currentUser?.role === "superadmin") return;
    if (typeof refreshGlobalNotifications === "function") refreshGlobalNotifications();
    if (!db) return;

    if (activeWorkspace === "dashboard" && typeof loadDashboardData === "function") {
        loadDashboardData();
        return;
    }

    if (["documents", "archive"].includes(activeWorkspace)) {
        if (skipServerRefresh) {
            loadServerProjectPage();
        } else {
            clearTimeout(projectListSearchTimer);
            projectListSearchTimer = setTimeout(loadServerProjectPage, 300);
        }
        return;
    }

    projectListServerPaged = false;

    // Pull the latest shared records before rendering. This prevents users
    // from working from an old browser-local copy.
    if (!skipServerRefresh && typeof db.refresh === "function") {
        db.refresh().then(() => loadDocuments(true)).catch(error => {
            console.error("Could not refresh shared records:", error);
            showSystemNotification("Could not refresh the shared database.");
        });
        return;
    }

    const searchInput =
        document.getElementById("searchInput");

    const statusInput =
        document.getElementById("filterStatusSelect");

    const projectNoInput =
        document.getElementById("filterProjectNo");

    const brgyInput =
        document.getElementById("filterBrgy");

    const projectDateFieldInput =
        document.getElementById("filterProjectDateField");

    const biddingMonthInput =
        document.getElementById("filterBiddingMonth");

    const biddingDayInput =
        document.getElementById("filterBiddingDay");

    const biddingYearInput =
        document.getElementById("filterBiddingYear");

    const searchTerm =
        searchInput
            ? normalizeProjectSearch(searchInput.value)
            : "";


    const filterStatus =
        statusInput
            ? statusInput.value
            : "All";

    const filterProjectNo = normalizeProjectSearch(projectNoInput?.value);
    const filterBrgy = brgyInput?.value.trim() || "";

    const filterProjectDateField =
        projectDateFieldInput
            ? projectDateFieldInput.value
            : "";


    const filterBiddingMonth =
        biddingMonthInput
            ? Number.parseInt(biddingMonthInput.value, 10)
            : NaN;

    const filterBiddingDay =
        biddingDayInput
            ? Number.parseInt(biddingDayInput.value, 10)
            : NaN;

    const filterBiddingYear =
        biddingYearInput
            ? biddingYearInput.value
            : "";

    const transaction =
        db.transaction(
            ["projects"],
            "readonly"
        );


    const store =
        transaction.objectStore("projects");


    const request =
        store.getAll();


    request.onsuccess = function() {
        const records =
            request.result || [];

        /*
         * Keep database records in a predictable order.
         */
        records.sort((first, second) => projectNumberSortDirection === "asc"
            ? compareProjectNumbers(first, second)
            : compareProjectNumbers(second, first));


        const filtered =
            records.filter(item => {

                const completedAlerts =
                    Array.isArray(item.timelineAlertsCompleted)
                        ? item.timelineAlertsCompleted
                        : [];


                const globallyResolved =
                    item.timelineCompletedMarked === true;


                const isAlertResolved = (type) => {
                    return getAutomaticTimelineResolutions(item).some(resolved => resolved.aliases.includes(type)) || (
                        completedAlerts.includes(type) ||
                        (globallyResolved && !["award-close", "award-overdue"].includes(type))
                    );
                };


                const isNoaOverdue =
                    !isAlertResolved("noa-overdue") &&
                    checkNoaOverdueWindowAlert(
                        item.noa,
                        item.status,
                        item
                    );


                const isMaterialOverdue =
                    !isAlertResolved("material-overdue") &&
                    checkMaterialTestOverdueWindowAlert(
                        item.ntpDate,
                        item.status,
                        item
                    );


                const isNtpOverdue =
                    !isAlertResolved("ntp-overdue") &&
                    checkNtpOverdueWindowAlert(
                        item.ntpDate,
                        item.status,
                        item
                    );


                const isNoaAlertActive =
                    !isAlertResolved("noa-active") &&
                    checkNoaFifteenDayWindowAlert(
                        item.noa,
                        item.status, item
                    );


                const isNtpAwardActive =
                    !isAlertResolved("ntp-award") &&
                    checkNtpAwardingActiveWindow(
                        item.ntpDate,
                        item.status, item
                    );


                const isMaterialTestActive =
                    !isAlertResolved("material-test") &&
                    checkMaterialTestFifteenDayWindow(
                        item.ntpDate,
                        item.status,
                        item
                    );


                const isCompletionAlertActive =
                    !projectCalendarDate(item.swa) && String(item.status || "").trim().toLowerCase() !== "released" &&
                    !isAlertResolved("award-close") &&
                    !isAlertResolved("award-overdue") &&
                    checkFifteenDayWindowAlert(
                        item.completion,
                        item.status
                    );


                const today =
                    new Date();

                today.setHours(
                    0,
                    0,
                    0,
                    0
                );


                const awardingDateObj =
                    getAwardingDate(
                        item.completion
                    );


                if (awardingDateObj) {
                    awardingDateObj.setHours(
                        0,
                        0,
                        0,
                        0
                    );
                }


                const isAwardingOverdue =
                    Boolean(awardingDateObj) &&
                    !projectCalendarDate(item.swa) && String(item.status || "").trim().toLowerCase() !== "released" &&
                    !isAlertResolved("award-overdue") &&
                    today >= awardingDateObj;


                const milestoneAlerts = getProjectMilestoneAlerts(item);
                const hasActiveTimelineAlert =
                    milestoneAlerts.length > 0 ||
                    isNoaOverdue ||
                    isMaterialOverdue ||
                    isNtpOverdue ||
                    isNoaAlertActive ||
                    isNtpAwardActive ||
                    isMaterialTestActive ||
                    isCompletionAlertActive ||
                    isAwardingOverdue;


                const hasResolvedTimelineAlert =
                    globallyResolved ||
                    completedAlerts.length > 0;


                /*
                 * Dashboard only needs projects with
                 * currently active timeline alerts.
                 */
                if (
                    activeWorkspace === "dashboard" &&
                    !hasActiveTimelineAlert
                ) {
                    return false;
                }


                /*
                 * Resolved Alerts workspace only needs
                 * projects containing resolved alerts.
                 */
                if (
                    activeWorkspace === "dashboard-done" &&
                    !hasResolvedTimelineAlert
                ) {
                    return false;
                }


                const searchableText = normalizeProjectSearch([
                    item.projectNo,
                    item.projectNo ? `project ${item.projectNo}` : "",
                    item.title,
                    item.winningBidder,
                    item.prNumber,
                    item.sourceOfFund,
                    item.status
                ]
                    .filter(Boolean)
                    .join(" "));
                const compactSearch = searchTerm.replace(/\s/g, "");
                const compactSearchableText = searchableText.replace(/\s/g, "");


                const alertSearchMatch =
                    milestoneAlerts.some(alert => `${alert.label} ${alert.type} ${alert.dueDate}`.toLowerCase().includes(searchTerm)) ||
                    (
                        searchTerm.includes("noa") &&
                        (
                            isNoaOverdue ||
                            isNoaAlertActive
                        )
                    ) ||
                    (
                        searchTerm.includes("ntp") &&
                        (
                            isNtpOverdue ||
                            isNtpAwardActive
                        )
                    ) ||
                    (
                        (
                            searchTerm.includes("material") ||
                            searchTerm.includes("test")
                        ) &&
                        (
                            isMaterialOverdue ||
                            isMaterialTestActive
                        )
                    ) ||
                    (
                        searchTerm.includes("overdue") &&
                        (
                            isNoaOverdue ||
                            isNtpOverdue ||
                            isMaterialOverdue ||
                            isAwardingOverdue
                        )
                    );


                const matchSearch =
                    !searchTerm ||
                    searchableText.includes(searchTerm) ||
                    compactSearchableText.includes(compactSearch) ||
                    alertSearchMatch;


                const matchStatus =
                    filterStatus === "All" ||
                    item.status === filterStatus;

                const matchProjectNo =
                    !filterProjectNo ||
                    normalizeProjectSearch(item.projectNo) === filterProjectNo;

                const matchBrgy = !filterBrgy || matchesBarangayFilter(item, filterBrgy);


                const allProjectDates = [
                    item.biddingDate,
                    item.prDate,
                    item.resolutionDate,
                    item.noticePostQual,
                    item.noa,
                    item.performanceBond,
                    item.contractRef,
                    item.ntpDate,
                    item.dole,
                    item.contractEndDate,
                    item.preInspection,
                    item.finalInspection,
                    item.ldNewCompletionDate,
                    item.ldActualDuration,
                    item.completion
                ];

                const projectDates = (
                    filterProjectDateField
                        ? [item[filterProjectDateField]]
                        : allProjectDates
                )
                    .map(value => String(value || "").trim().split("T")[0])
                    .filter(value => /^\d{4}-\d{2}-\d{2}$/.test(value))
                    .map(value => {
                        const [year, month, day] = value.split("-");
                        return {
                            year,
                            month: Number.parseInt(month, 10),
                            day: Number.parseInt(day, 10)
                        };
                    });

                const matchProjectDate =
                    (Number.isNaN(filterBiddingMonth) && Number.isNaN(filterBiddingDay) && !filterBiddingYear) ||
                    projectDates.some(date =>
                        (Number.isNaN(filterBiddingMonth) || date.month === filterBiddingMonth) &&
                        (Number.isNaN(filterBiddingDay) || date.day === filterBiddingDay) &&
                        (!filterBiddingYear || date.year === filterBiddingYear)
                    );


                return (
                    matchSearch &&
                    matchStatus &&
                    matchProjectNo &&
                    matchBrgy &&
                    matchProjectDate
                );
            });


        /*
         * IMPORTANT:
         * Do NOT renumber projectNo here.
         *
         * projectNo is persistent project data,
         * not the row number of filtered results.
         */
        currentProjectsList = filtered;


        /*
         * Prevent an empty table when the user
         * filters while currently on a high page.
         */
        const totalPages =
            Math.max(
                1,
                Math.ceil(
                    currentProjectsList.length /
                    itemsPerPage
                )
            );


        if (currentPage > totalPages) {
            currentPage = totalPages;
        }


        renderTablePage();

        renderMarkedDoneTabContents();
    };


    request.onerror = function(event) {
        console.error(
            "Unable to load project records:",
            event.target.error
        );
    };
}

function renderTablePage() {
    const canDelete = canDeleteProjects();
    const canArchive = canArchiveProjects();
    const startIdx = (currentPage - 1) * itemsPerPage;
    const endIdx = startIdx + itemsPerPage;

    const paginatedItems = projectListServerPaged
        ? currentProjectsList
        : currentProjectsList.slice(startIdx, endIdx);

    let html = "";


    const isAlertResolved = (doc, type) => {
        return getAutomaticTimelineResolutions(doc).some(item => item.aliases.includes(type)) || (
            (
                Array.isArray(doc.timelineAlertsCompleted) &&
                doc.timelineAlertsCompleted.includes(type)
            ) ||
            (
                doc.timelineCompletedMarked === true &&
                !["award-close", "award-overdue"].includes(type)
            )
        );
    };





    paginatedItems.forEach(doc => {

        const isMuted =
            doc.timelineCompletedMarked === true &&
            !["Completed", "Released"].includes(doc.status);


        /* =====================================================
           TIMELINE CONDITIONS
           ===================================================== */

        const isCompletionAlertActive =
            !isMuted &&
            !projectCalendarDate(doc.swa) && String(doc.status || "").trim().toLowerCase() !== "released" &&
            !isAlertResolved(doc, "award-overdue") &&
            !isAlertResolved(doc, "award-close") &&
            checkFifteenDayWindowAlert(
                doc.completion,
                doc.status
            );


        const isNoaAlertActive =
            !isMuted &&
            !isAlertResolved(doc, "noa-active") &&
            checkNoaFifteenDayWindowAlert(
                doc.noa,
                doc.status, doc
            );


        const isNtpAwardActive =
            !isMuted &&
            !isAlertResolved(doc, "ntp-award") &&
            checkNtpAwardingActiveWindow(
                doc.ntpDate,
                doc.status, doc
            );


        const isMaterialTestActive =
            !isMuted &&
            !isAlertResolved(doc, "material-test") &&
            checkMaterialTestFifteenDayWindow(
                doc.ntpDate,
                doc.status,
                doc
            );


        const isNoaOverdue =
            !isMuted &&
            !isAlertResolved(doc, "noa-overdue") &&
            checkNoaOverdueWindowAlert(
                doc.noa,
                doc.status,
                doc
            );


        const isMaterialOverdue =
            !isMuted &&
            !isAlertResolved(doc, "material-overdue") &&
            checkMaterialTestOverdueWindowAlert(
                doc.ntpDate,
                doc.status,
                doc
            );


        const isNtpOverdue =
            !isMuted &&
            !isAlertResolved(doc, "ntp-overdue") &&
            checkNtpOverdueWindowAlert(
                doc.ntpDate,
                doc.status,
                doc
            );


        const today = new Date();
        today.setHours(0, 0, 0, 0);


        const awardingDateObj =
            getAwardingDate(doc.completion);


       if (awardingDateObj) {
    awardingDateObj.setHours(
        0,
        0,
        0,
        0
    );
}


const isAwardingOverdue =
    Boolean(awardingDateObj) &&
    !projectCalendarDate(doc.swa) && String(doc.status || "").trim().toLowerCase() !== "released" &&
    today >= awardingDateObj &&
    !isMuted &&
    !isAlertResolved(
        doc,
        "award-overdue"
    );


        /* =====================================================
           ROW STATE
           ===================================================== */

        const milestoneAlerts = getProjectMilestoneAlerts(doc);
        let rowClass = "";


        if (
            milestoneAlerts.some(alert => alert.type.endsWith("overdue")) ||
            isAwardingOverdue ||
            isCompletionAlertActive ||
            isMaterialOverdue ||
            isNoaOverdue ||
            isNtpOverdue
        ) {

            rowClass = "table-danger";

        } else if (milestoneAlerts.length > 0 || isNoaAlertActive) {

            rowClass = "table-warning";

        } else if (isNtpAwardActive) {

            rowClass = "table-primary";

        } else if (isMaterialTestActive) {

            rowClass = "table-info";

        } else if (doc.status === "Completed") {

            rowClass = "table-success";

        }


        /* =====================================================
           ALERT CHIPS
           ===================================================== */

        const alerts = milestoneAlerts.map(alert => `<span class="project-alert-chip ${alert.type.endsWith("overdue") ? "alert-danger" : "alert-warning"}">${escapeHtml(alert.label)}</span>`);


        if (isNoaOverdue) {

            alerts.push(`
                <span class="project-alert-chip alert-danger">
                    NOA Overdue
                </span>
            `);

        } else if (isNoaAlertActive) {

            alerts.push(`
                <span class="project-alert-chip alert-warning">
                    NOA Window
                </span>
            `);

        }


        if (isNtpOverdue) {

            alerts.push(`
                <span class="project-alert-chip alert-danger">
                    NTP Overdue
                </span>
            `);

        } else if (isNtpAwardActive) {

            alerts.push(`
                <span class="project-alert-chip alert-primary">
                    NTP Award
                </span>
            `);

        }


        if (isMaterialOverdue) {

            alerts.push(`
                <span class="project-alert-chip alert-danger">
                    Material Overdue
                </span>
            `);

        } else if (isMaterialTestActive) {

            alerts.push(`
                <span class="project-alert-chip alert-info">
                    Material Test
                </span>
            `);

        }


        if (isAwardingOverdue) {

            alerts.push(`
                <span class="project-alert-chip alert-danger">
                    SWA Due — Notify Contractor
                </span>
            `);

        } else if (isCompletionAlertActive) {

            alerts.push(`
                <span class="project-alert-chip alert-warning">
                    SWA Reminder
                </span>
            `);

        }


        const alertsHtml =
            alerts.length > 0
                ? `
                    <div class="project-alert-chip-list">
                        ${alerts.join("")}
                    </div>
                `
                : `
                    <span class="project-no-alert">
                        No active alerts
                    </span>
                `;


        /* =====================================================
           DISPLAY VALUES
           ===================================================== */

        const currentStatus =
            (doc.status || "Pending").trim();


        const statusClass =
            getStatusClass(currentStatus);


        const projectTitle =
            escapeHtml(doc.title || "Untitled Project");


        const winningBidder =
            escapeHtml(
                doc.winningBidder ||
                "Not specified"
            );


        const sourceOfFund =
            escapeHtml(
                doc.sourceOfFund ||
                "Not specified"
            );


        /* =====================================================
           TABLE ROW
           ===================================================== */

        html += `
            <tr class="${rowClass}">

                <td class="project-number-cell">

                    <span class="project-number">
                        ${escapeHtml(doc.projectNo || "-")}
                    </span>

                </td>


                <td>

                    <button
                        type="button"
                        class="project-title-button"
                        onclick="viewProjectDetails(${doc.id})"
                    >
                        ${projectTitle}
                    </button>


                    <div class="project-secondary-text">
                        ${winningBidder}
                    </div>


                    <div class="project-tertiary-text">
                        ${sourceOfFund}
                    </div>
                    <div data-project-editors="${Number(doc.id)}" hidden></div>

                </td>


                <td>

                    <span class="project-date">
                        ${formatUIDate(doc.biddingDate)}
                    </span>

                </td>


                <td><span class="project-date">${formatUIDate(doc.noaDueDate)}</span></td>

                <td>

                    <div class="project-timeline-dates">

                        <div>
                            <span>NOA</span>

                            <strong>
                                ${formatUIDate(doc.noa)}
                            </strong>
                        </div>


                        <div>
                            <span>NTP</span>

                            <strong>
                                ${formatUIDate(doc.ntpDate)}
                            </strong>
                        </div>

                    </div>


                    ${alertsHtml}

                </td>


                <td>

                    <span class="project-date">
                        ${formatUIDate(doc.completion)}
                    </span>

                </td>


                <td>

                    <span
                        class="project-status-badge ${statusClass}"
                    >
                        ${escapeHtml(currentStatus)}
                    </span>

                </td>


                <td>

                    <div class="project-row-actions">


                        ${canArchive ? `
                            <button type="button" onclick="setDocumentArchived(${doc.id}, ${doc.archivedAt ? "false" : "true"})" class="btn btn-sm btn-outline-secondary">
                                ${doc.archivedAt ? "Restore" : "Archive"}
                            </button>
                        ` : ""}


                        <button
                            type="button"
                            onclick="viewHistory(${doc.id})"
                            class="btn btn-sm btn-outline-secondary"
                        >
                            Logs
                        </button>




                        ${canDelete ? `
                            <button
                                type="button"
                                onclick="deleteDocument(${doc.id})"
                                class="btn btn-sm project-delete-button"
                            >
                                Delete
                            </button>
                        ` : ""}


                    </div>

                </td>

            </tr>
        `;
    });


    const docTable =
        document.getElementById("documentTable");


    if (docTable) {

        docTable.innerHTML =
            html ||
            `
                <tr>

                    <td
                        colspan="8"
                        class="text-center py-5 text-muted"
                    >
                        No records match the current search or filters.
                    </td>

                </tr>
            `;
    }


    renderPaginationControls();
    if (typeof renderProjectEditingIndicators === "function") renderProjectEditingIndicators();
    if (typeof refreshProjectEditingPresence === "function") void refreshProjectEditingPresence();
}


let projectDetailsEditId = null;
let projectDetailsRequestSequence = 0;
let projectDetailsAlertsOnShow = null;
let projectDetailsModalShown = false;
let projectDetailsEditSource = "projects";
let projectEditReturnWorkspace = null;
let projectEditSource = "projects";

function viewProjectDetails(id, source = "", { openAlerts = false } = {}) {
    const sequence = ++projectDetailsRequestSequence;
    projectDetailsAlertsOnShow = null;
    if (!db) {
        return;
    }


    const transaction =
        db.transaction(
            ["projects"],
            "readonly"
        );


    const store =
        transaction.objectStore("projects");


    const request =
        store.get(id);


    request.onsuccess = function(event) {
        if (sequence !== projectDetailsRequestSequence) return;

        const project =
            event.target.result;


        if (!project) {
            alert("Project record could not be found.");
            return;
        }


        const body =
            document.getElementById(
                "projectDetailsBody"
            );


        const title =
            document.getElementById(
                "projectDetailsModalLabel"
            );


        if (!body || !title) {
            return;
        }


        title.textContent =
            project.title ||
            "Project Details";


        const value = (content) => {

            if (
                content === undefined ||
                content === null ||
                String(content).trim() === ""
            ) {

                return `
                    <span class="project-detail-empty">
                        Not recorded
                    </span>
                `;
            }


            return escapeHtml(content);
        };


        const dateValue = (content) => {

            if (!content) {

                return `
                    <span class="project-detail-empty">
                        Not recorded
                    </span>
                `;
            }


            return escapeHtml(
                formatUIDate(content)
            );
        };


        const field = (label, content) => {

            return `
                <div class="project-detail-field">

                    <div class="project-detail-label">
                        ${label}
                    </div>

                    <div class="project-detail-value">
                        ${content}
                    </div>

                </div>
            `;
        };


        body.innerHTML = `

            <!-- ============================================
                 SUMMARY
                 ============================================ -->
            <div class="project-details-summary">

                <div>

                    <div class="project-details-project-number">
                        Project Number
                        ${value(project.projectNo)}
                    </div>


                    <h3 class="project-details-project-title">
                        ${value(project.title)}
                    </h3>


                    <div class="project-details-contractor">
                        ${value(project.winningBidder)}
                    </div>

                </div>


                <span class="project-details-status ${getStatusClass(project.status)}">
                    ${value(project.status || "Pending")}
                </span>

            </div>


            <!-- ============================================
                 BASIC INFORMATION
                 ============================================ -->
            <section class="project-detail-section">

                <h4 class="project-detail-section-title">
                    Basic Information
                </h4>


                <div class="project-detail-grid">




                    ${field(
                        "Approved Budget for the Contract",
                        value(project.approvedBudget)
                    )}








                    ${field(
                        "Source of Fund",
                        value(project.sourceOfFund === "Brgy Fund" ? "Barangay Fund" : project.sourceOfFund)
                    )}

                </div>

            </section>


            <!-- ============================================
                 PROCUREMENT & AWARD
                 ============================================ -->
            <section class="project-detail-section">

                <h4 class="project-detail-section-title">
                    Procurement and Award
                </h4>


                <div class="project-detail-grid">

                    ${field(
                        "Date of Bidding",
                        dateValue(project.biddingDate)
                    )}

                    ${field(
                        "Bid Amount",
                        value(project.bidAmount)
                    )}

                    ${field(
                        "Winning Bidder",
                        value(project.winningBidder)
                    )}

                    ${field(
                        "Purchase Request Number",
                        value(project.prNumber)
                    )}


                    ${field(
                        "Purchase Request Date",
                        dateValue(project.prDate)
                    )}


                    ${field(
                        "Obligation Request Date",
                        dateValue(project.obre)
                    )}


                    ${field(
                        "Resolution Number",
                        value(project.resolutionNumber)
                    )}


                    ${field(
                        "Resolution Date",
                        dateValue(project.resolutionDate)
                    )}


                    ${field(
                        "Notice of Post Qualification",
                        dateValue(project.noticePostQual)
                    )}


                    ${field(
                        "NOA Due Date", dateValue(project.noaDueDate))}
                    ${field("NOA Issued Date",
                        dateValue(project.noa)
                    )}


                    ${field(
                        "Performance Bond",
                        dateValue(project.performanceBond)
                    )}

                </div>

            </section>


            <!-- ============================================
                 CONTRACT
                 ============================================ -->
            <section class="project-detail-section">

                <h4 class="project-detail-section-title">
                    Contract and Implementation
                </h4>


                <div class="project-detail-grid">

                    ${field(
                        "Contract Date",
                        dateValue(project.contractRef)
                    )}


                    ${field(
                        "Contract Duration",
                        value(project.contractDuration)
                    )}


                    ${field(
                        "Notice to Proceed",
                        dateValue(project.ntpDate)
                    )}


                    ${field(
                        "Department of Labor and Employment Clearance",
                        dateValue(project.dole)
                    )}


                    ${field(
                        "Contract End Date",
                        dateValue(project.contractEndDate)
                    )}

                </div>

            </section>


            <!-- ============================================
                 INSPECTION
                 ============================================ -->
            <section class="project-detail-section">

                <h4 class="project-detail-section-title">
                    Inspection and Completion
                </h4>


                <div class="project-detail-grid project-detail-due-grid">

                    ${field("Pre-inspection Due Date", dateValue(getProjectMilestoneDates(project).preInspectionDueDate))}
                    ${field("Notice to Proceed Due Date", dateValue(getProjectMilestoneDates(project).ntpDueDate))}
                    ${field("Material Testing Due Date", dateValue(getProjectMilestoneDates(project).materialTestingDueDate))}
                    ${field("Final Inspection Due Date", dateValue(getProjectMilestoneDates(project).finalInspectionDueDate))}
                    ${field("SWA Due Date", dateValue(getSwaDueDateValue(project.completion)))}
                </div>
                <div class="project-detail-grid">
                    ${field("Material Testing Date", dateValue(project.materialTestingDate))}

                    ${field(
                        "Pre Inspection",
                        dateValue(project.preInspection)
                    )}


                    ${field(
                        "Final Inspection",
                        dateValue(project.finalInspection)
                    )}


                    ${field(
                        "Statement of Work Accomplished Date",
                        dateValue(project.swa)
                    )}


                    ${field(
                        "Completion Date",
                        dateValue(project.completion)
                    )}


                    ${field(
                        "Operational Status",
                        `<span class="project-status-badge ${getStatusClass(project.status)}">${value(project.status || "Pending")}</span>`
                    )}

                </div>

            </section>


            <!-- ============================================
                 LIQUIDATED DAMAGES
                 ============================================ -->
            <section class="project-detail-section">

                <h4 class="project-detail-section-title">
                    Liquidated Damages
                </h4>


                <div class="project-detail-grid">

                    ${field(
                        "Liquidated Damages Amount",
                        value(project.ldAmount)
                    )}


                    ${field(
                        "Liquidated Damages Number of Days",
                        value(project.ldNoOfDays)
                    )}


                    ${field(
                        "Revised Completion Date After Liquidated Damages",
                        dateValue(project.ldNewCompletionDate)
                    )}


                    ${field(
                        "Actual Duration After Liquidated Damages",
                        value(project.ldActualDuration)
                    )}

                </div>

            </section>


            <section class="project-detail-section">
                <h4 class="project-detail-section-title">Attachments</h4>
                ${(project.attachments || []).length
                    ? `<div class="list-group">${project.attachments.map(file => {
                        const url = `api/attachments.php?projectId=${encodeURIComponent(project.id)}&attachmentId=${encodeURIComponent(file.id)}`;
                        return `<div class="list-group-item d-flex justify-content-between align-items-center gap-3"><div class="text-truncate"><a href="${url}" data-preview-name="${escapeHtml(file.name)}" data-preview-mime="${escapeHtml(file.mime || "")}" onclick="openAttachmentPreview(event)">${escapeHtml(file.name)}</a><div class="small text-muted">${formatAttachmentSize(Number(file.size) || 0)}</div></div><a class="btn btn-outline-secondary btn-sm" href="${url}&download=1">Download</a></div>`;
                    }).join("")}</div>`
                    : '<span class="project-detail-empty">No attachments</span>'}
            </section>


            <!-- ============================================
                 REMARKS
                 ============================================ -->
            <section class="project-detail-section">

                <h4 class="project-detail-section-title">
                    Remarks
                </h4>


                <div class="project-detail-remarks">
                    ${value(project.remarks)}
                </div>

            </section>

        `;


        const modalElement =
            document.getElementById(
                "projectDetailsModal"
            );


        if (!modalElement) {
            return;
        }

        const editButton = document.getElementById("projectDetailsEditButton");
        const openedFromNeedsAttention = source === "needs-attention";
        projectDetailsEditId = id;
        const presence = document.getElementById("projectDetailsPresence");
        if (presence) presence.dataset.projectEditors = String(id);
        if (typeof renderProjectEditingIndicators === "function") renderProjectEditingIndicators();
        if (typeof refreshProjectEditingPresence === "function") void refreshProjectEditingPresence();
        hideProjectAlertsDropdown();
        const alertsButton = document.getElementById("projectDetailsAlertsButton");
        if (alertsButton) { alertsButton.setAttribute("aria-expanded", "false"); alertsButton.textContent = "Alerts"; }
        projectDetailsEditSource = openedFromNeedsAttention ? "alerts" : "projects";
        if (editButton) {
            editButton.classList.toggle(
                "d-none",
                !(openedFromNeedsAttention ? canEditAlertProjects() : canEditProjects())
            );
        }


        const modal =
            bootstrap.Modal.getOrCreateInstance(
                modalElement
            );


        projectDetailsAlertsOnShow = openAlerts ? id : null;
        modal.show();
        // An already open details modal does not emit another shown event.
        if (projectDetailsModalShown) openPendingProjectDetailsAlerts();
    };


    request.onerror = function(event) {
        if (sequence !== projectDetailsRequestSequence) return;

        console.error(
            "Unable to load project details:",
            event.target.error
        );

        alert(
            "The project details could not be loaded."
        );
    };
}

function editProjectFromDetails() {
    const projectId = projectDetailsEditId;
    const modalElement = document.getElementById("projectDetailsModal");
    const source = projectDetailsEditSource;
    if (!projectId || !modalElement || !(source === "alerts" ? canEditAlertProjects() : canEditProjects())) return;

    const modal = bootstrap.Modal.getInstance(modalElement);
    modalElement.addEventListener("hidden.bs.modal", () => editDocument(projectId, source), { once: true });
    modal?.hide();
}

function printProjectDetails() {
    const details = document.getElementById("projectDetailsBody");
    const heading = document.getElementById("projectDetailsModalLabel");
    if (!details?.innerHTML.trim()) return;

    const printable = details.cloneNode(true);
    printable.querySelectorAll(".btn").forEach(button => button.remove());
    printable.querySelectorAll("a").forEach(link => {
        link.replaceWith(document.createTextNode(link.textContent || ""));
    });

    printable.querySelectorAll(".project-detail-section").forEach(section => {
        const label = section.querySelector(".project-detail-section-title")?.textContent.trim();
        if (label === "Procurement and Award") section.classList.add("print-procurement");
        if (label === "Liquidated Damages") section.classList.add("print-liquidated");
    });
    const shortLabels = {
        "Liquidated Damages Amount": "Amount",
        "Liquidated Damages Number of Days": "Number of days",
        "Revised Completion Date After Liquidated Damages": "Revised completion date",
        "Actual Duration After Liquidated Damages": "Actual duration",
        "Department of Labor and Employment Clearance": "DOLE clearance",
        "Statement of Work Accomplished Date": "SWA recorded date"
    };
    printable.querySelectorAll(".project-detail-label").forEach(label => {
        const replacement = shortLabels[label.textContent.trim()];
        if (replacement) label.textContent = replacement;
    });
    printable.querySelectorAll(".project-detail-remarks").forEach(remarks => remarks.textContent = remarks.textContent.trim());

    const title = heading?.textContent?.trim() || "Project details";
    const preparedAt = new Date().toLocaleString("en-PH", {
        dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Manila"
    });
    const logoUrl = new URL("logo.png", document.baseURI).href;
    document.getElementById("projectPrintFrame")?.remove();
    const frame = document.createElement("iframe");
    frame.id = "projectPrintFrame";
    frame.title = "Printable project summary";
    frame.setAttribute("aria-hidden", "true");
    frame.style.cssText = "position:fixed;left:-10000px;top:0;width:794px;height:1123px;border:0;";
    document.body.appendChild(frame);
    const printWindow = frame.contentWindow;
    if (!printWindow) {
        frame.remove();
        showSystemNotification("The printable project summary could not be opened.");
        return;
    }

    printWindow.document.write(`<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>${escapeHtml(title)} — Project summary</title>
<style>
@page { size: A4 portrait; margin: 12mm; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { width: 186mm; color: #182433; background: #fff; font: 10.5pt/1.35 Arial, sans-serif; }
.print-heading { margin-bottom: 3mm; padding-bottom: 3mm; border-bottom: 1.5px solid #203b56; }
.print-heading-top { display:flex; align-items:center; gap:4mm; }
.print-logo { width:16mm; height:16mm; flex:none; object-fit:contain; }
.print-office { display:block; font-size:10pt; font-weight:700; color:#334155; }
.print-heading h1 { margin:1mm 0; font-size:20pt; line-height:1.15; color:#16324f; }
.print-heading p { margin:0; font-size:8.5pt; color:#64748b; }
.print-notice { display:block; margin-top:2mm; font-size:8pt; color:#64748b; }
.project-details-summary { display:flex; justify-content:space-between; align-items:flex-start; gap:4mm; margin-bottom:3mm; padding:2.5mm 4mm; border-left:1.3mm solid #203b56; background:#f2f5f8; break-inside:avoid; }
.project-details-summary > div { min-width:0; }
.project-details-project-number { font-size:8.5pt; color:#526174; }
.project-details-project-title { margin:1.5mm 0; font-size:13pt; line-height:1.3; font-weight:700; overflow-wrap:anywhere; }
.project-details-contractor { font-size:9pt; color:#334155; }
.project-details-status { flex:none; max-width:30mm; font-size:9pt; font-weight:700; border:1px solid #94a3b8; border-radius:3mm; padding:1mm 2.5mm; background:white; overflow-wrap:anywhere; }
.project-status-badge { display:inline-block; padding:1mm 2mm; border:1px solid; border-radius:3mm; font-size:9pt; font-weight:700; }

.project-status-pending { background:#fff7ed; color:#9a3412; border-color:#fed7aa; }
.project-status-bidding { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
.project-status-preinspection { background:#ecfeff; color:#155e75; border-color:#a5f3fc; }
.project-status-finalinspection { background:#f5f3ff; color:#6d28d9; border-color:#ddd6fe; }
.project-status-completed { background:#f0fdf4; color:#166534; border-color:#bbf7d0; }
.project-status-released { background:#f0fdfa; color:#115e59; border-color:#99f6e4; }
.project-detail-section { margin:0 0 2mm; break-inside:avoid; page-break-inside:avoid; }
.project-detail-section-title { margin:0 0 1.5mm; padding:1mm 0; border-bottom:1px solid #b9c5d1; color:#16324f; font-size:10pt; font-weight:700; }
.project-detail-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:1.8mm 5mm; }
.project-detail-due-grid { grid-template-columns:repeat(3,minmax(0,1fr)); margin-bottom:1.8mm; }
.print-procurement .project-detail-grid, .print-liquidated .project-detail-grid { grid-template-columns:repeat(4,minmax(0,1fr)); }
.project-detail-field { min-width:0; break-inside:avoid; overflow-wrap:anywhere; }
.project-detail-label { margin-bottom:.8mm; color:#526174; font-size:8.2pt; line-height:1.25; font-weight:400; }
.project-detail-value { white-space:normal; font-size:10pt; line-height:1.3; font-weight:600; }
.project-detail-empty { color:#75808c; font-weight:400; font-style:normal; }
.project-detail-remarks { white-space:pre-line; overflow-wrap:anywhere; font-weight:400; }
.list-group { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:2mm 4mm; }
.list-group-item { min-width:0; padding:1.5mm 0; border-bottom:1px solid #dbe2e8; break-inside:avoid; overflow-wrap:anywhere; }
.text-truncate { white-space:normal; overflow:visible; text-overflow:clip; }
.small { font-size:8.5pt; color:#64748b; }
.print-footer { margin-top:4mm; padding-top:2mm; border-top:1px solid #b9c5d1; font-size:8pt; color:#64748b; }
table { width:100%; border-collapse:collapse; table-layout:fixed; }
th,td { overflow-wrap:anywhere; padding:2mm; border:1px solid #cbd5e1; }
img { max-width:100%; }
@media print {
    body { width:auto; print-color-adjust:exact; -webkit-print-color-adjust:exact; }
    button,.btn,nav,input,select,textarea,[role="button"] { display:none !important; }
    h1,h3,h4 { break-after:avoid; }
    tr,.project-detail-field { break-inside:avoid; }
    a { color:inherit; text-decoration:none; }
}
</style></head><body>
<header class="print-heading"><div class="print-heading-top"><img id="projectPrintLogo" class="print-logo" src="${escapeHtml(logoUrl)}" alt="LGU Laoag logo"><div><strong class="print-office">LGU Laoag - Office of Engineering</strong><h1>Project summary</h1><p>Prepared ${escapeHtml(preparedAt)}</p></div></div><div class="print-notice">Reference copy — Not an official document</div></header>
<main>${printable.innerHTML}</main>
<footer class="print-footer">Generated from Project Tracker for reference only.</footer>
</body></html>`);
    printWindow.document.close();
    printWindow.addEventListener("afterprint", () => {
        setTimeout(() => frame.remove(), 500);
    }, { once: true });
    setTimeout(() => {
        if (frame.isConnected) frame.remove();
    }, 120000);
    let printStarted = false;
    const startPrint = () => {
        if (printStarted) return;
        printStarted = true;
        setTimeout(() => {
            printWindow.focus();
            printWindow.print();
        }, 150);
    };
    const logo = printWindow.document.getElementById("projectPrintLogo");
    if (logo && !logo.complete) {
        logo.addEventListener("load", startPrint, { once: true });
        logo.addEventListener("error", startPrint, { once: true });
        setTimeout(startPrint, 2000);
    } else {
        startPrint();
    }
}


function renderPaginationControls() {
    const totalItems = projectListServerPaged ? projectListTotal : currentProjectsList.length;
    const totalPages = Math.ceil(totalItems / itemsPerPage) || 1;
    if (currentPage > totalPages) currentPage = totalPages;

    const startItem = totalItems === 0 ? 0 : (currentPage - 1) * itemsPerPage + 1;
    const endItem = Math.min(currentPage * itemsPerPage, totalItems);

    const infoEl = document.getElementById("paginationInfo");
    if (infoEl) infoEl.textContent = `Showing ${startItem}-${endItem} of ${totalItems} entries`;

    let navHtml = `<ul class="pagination pagination-sm mb-0">`;
    navHtml += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}"><a class="page-link" href="#" onclick="changePage(${currentPage - 1})">Previous</a></li>`;
    const pageNumbers = new Set([1, totalPages]);
    for (let page = Math.max(1, currentPage - 2); page <= Math.min(totalPages, currentPage + 2); page++) pageNumbers.add(page);
    let previousPage = 0;
    [...pageNumbers].sort((a, b) => a - b).forEach(page => {
        if (page - previousPage > 1) navHtml += '<li class="page-item disabled"><span class="page-link">…</span></li>';
        navHtml += `<li class="page-item ${currentPage === page ? 'active' : ''}"><a class="page-link" href="#" onclick="changePage(${page}); return false;">${page}</a></li>`;
        previousPage = page;
    });
    navHtml += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}"><a class="page-link" href="#" onclick="changePage(${currentPage + 1})">Next</a></li></ul>`;

    const controlsEl = document.getElementById("paginationControls");
    if (controlsEl) controlsEl.innerHTML = navHtml;
}

function changePage(page) {
    const totalItems = projectListServerPaged ? projectListTotal : currentProjectsList.length;
    if (page < 1 || page > Math.ceil(totalItems / itemsPerPage)) return;
    currentPage = page;
    if (projectListServerPaged) loadDocuments(true);
    else renderTablePage();
}

const inputFieldsList = [
    "biddingDate", "title", "approvedBudget", "bidAmount", "winningBidder",
    "sourceOfFund", "sourceOfFundCustom", "prNumber", "prDate", "obre", "resolutionNumber",
    "resolutionDate", "noticePostQual", "noaDueDate", "noa", "performanceBond", "contractRef",
    "contractDuration", "ntpDate", "dole", "contractEndDate", "preInspection", "materialTestingDate",
    "finalInspection", "swa", "ldAmount", "ldNoOfDays", "ldNewCompletionDate",
    "ldActualDuration", "completionDate", "remarks"
];

// Forced sign-out retains fields only in this tab's memory, scoped to the account.
// Keep the original version so a resumed draft cannot overwrite newer changes.
const suspendedProjectDrafts = new Map();
function preserveSuspendedProjectDraft() {
    const userId = window.currentUser?.id;
    if (!userId || !document.body.classList.contains("project-edit-pane-open")) return false;
    const fields = {};
    for (const id of [...inputFieldsList, "status"]) {
        const field = document.getElementById(id);
        if (field) fields[id] = field.value;
    }
    suspendedProjectDrafts.set(userId, {
        userId, projectId: editingId, source: projectEditSource, returnWorkspace: projectEditReturnWorkspace,
        snapshot: projectEditSnapshot ? JSON.parse(JSON.stringify(projectEditSnapshot)) : null, fields,
        files: [...pendingProjectAttachmentFiles], deleteIds: [...pendingProjectAttachmentDeletes],
    });
    return true;
}

function applySuspendedProjectDraft(draft) {
    if (window.currentUser?.id !== draft.userId || editingId !== draft.projectId) return;
    for (const [id, value] of Object.entries(draft.fields)) {
        const field = document.getElementById(id);
        if (field) field.value = value;
    }
    projectEditSnapshot = draft.snapshot;
    projectEditReturnWorkspace = draft.returnWorkspace;
    projectEditSource = draft.source;
    pendingProjectAttachmentFiles = draft.files;
    pendingProjectAttachmentDeletes = new Set(draft.deleteIds);
    document.getElementById("sourceOfFundCustomRow")?.classList.toggle("d-none", draft.fields.sourceOfFund !== "Others");
    renderProjectAttachments(projectAttachmentRecords, editingId);
    initializeLiveValueTracking();
    suspendedProjectDrafts.delete(draft.userId);
    showSystemNotification("Your unsaved project draft was restored. Review it before saving.");
}

function resumeSuspendedProjectDraft() {
    const draft = suspendedProjectDrafts.get(window.currentUser?.id);
    if (!draft || !db || editingId !== null) return false;
    if (draft.projectId === null) {
        if (!canCreateProjects()) return false;
        openAddProjectPane();
        applySuspendedProjectDraft(draft);
    } else {
        if (!canEditProjects()) return false;
        editDocument(draft.projectId, draft.source, { suspendedDraft: draft });
    }
    return true;
}

function automaticProjectFormStatus() {
    const value=id=>document.getElementById(id)?.value||'';
    const current=value('status').toLowerCase();
    if(projectCalendarDate(value('swa'))||current==='released') return 'Released';
    if(projectCalendarDate(value('completionDate'))||current==='completed') return 'Completed';
    if(projectCalendarDate(value('finalInspection'))) return 'Pending';
    const project={biddingDate:value('biddingDate'),contractRef:value('contractRef'),contractDuration:value('contractDuration'),contractEndDate:value('contractEndDate')};
    const end=getProjectMilestoneDates(project).contractDueDate;
    if(end && timelineCalendarDay(new Date())>=end && !projectCalendarDate(value('finalInspection'))) return 'Final-inspection';
    if(['preInspection','materialTestingDate','ntpDate'].every(id=>projectCalendarDate(value(id)))) return 'Pending';
    return projectCalendarDate(value('biddingDate'))?'Pre-inspection':'On-going Bidding';
}

function updateCompletionDateHint() {
    const status = document.getElementById("status");
    const completionDate = document.getElementById("completionDate");
    const hint = document.getElementById("completionDateHint");
    if (!status || !completionDate || !hint) return;

    status.value = automaticProjectFormStatus();
    const isCompleted = String(status.value || "").trim().toLowerCase() === "completed";
    const hasCompletionDate = Boolean(completionDate.value);

    hint.classList.toggle("d-none", !isCompleted);
    hint.classList.toggle("text-danger", isCompleted && !hasCompletionDate);
    hint.classList.toggle("text-success", isCompleted && hasCompletionDate);
    hint.textContent = hasCompletionDate
        ? "This project will appear in Monthly Completions for this date's month and year."
        : "Completion Date is required for completed projects to appear in Monthly Completions.";
    updateSwaDueDate();
}

function getSwaDueDateValue(completionDate) {
    const dueDate = typeof getAwardingDate === "function" ? getAwardingDate(completionDate) : null;
    if (!dueDate || Number.isNaN(dueDate.getTime())) return "";
    const year = dueDate.getFullYear();
    const month = String(dueDate.getMonth() + 1).padStart(2, "0");
    const day = String(dueDate.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
}

function updateSwaDueDate() {
    const completionDate = document.getElementById("completionDate")?.value || "";
    const swaDueDate = document.getElementById("swaDueDate");
    if (swaDueDate) swaDueDate.value = getSwaDueDateValue(completionDate);
}

function updateProjectMilestoneDates() {
    const project = Object.fromEntries(["noaDueDate", "biddingDate", "preInspection", "materialTestingDate", "finalInspection", "swa", "ntpDate", "contractRef", "contractDuration", "contractEndDate", "ldNewCompletionDate"].map(id => [id, document.getElementById(id)?.value || ""]));
    const dates = getProjectMilestoneDates(project);
    const contractEnd = document.getElementById("contractEndDate");
    const computed = Boolean(projectCalendarDate(project.contractRef) && parseContractDuration(project.contractDuration) !== null);
    if (contractEnd) { contractEnd.readOnly = computed; if (computed) contractEnd.value = dates.contractDueDate; }
    Object.entries(dates).forEach(([id, value]) => {
        const input = document.getElementById(id);
        if (input) input.value = value;
    });
}

function initializeLiveValueTracking() {

    inputFieldsList.forEach(id => {

        const el =
            document.getElementById(id);


        if (!el) {
            return;
        }


        /*
         * Remove the previously registered
         * handler from this exact element.
         */
        if (el._projectValueTrackingHandler) {

            el.removeEventListener(
                "input",
                el._projectValueTrackingHandler
            );

            el.removeEventListener(
                "change",
                el._projectValueTrackingHandler
            );
        }


        const evalContentState = () => {

            const value =
                typeof el.value === "string"
                    ? el.value.trim()
                    : "";


            if (value !== "") {

                el.classList.add(
                    "has-value"
                );

                el.classList.remove(
                    "editing-empty"
                );

            } else {

                el.classList.remove(
                    "has-value"
                );


                if (editingId !== null) {

                    el.classList.add(
                        "editing-empty"
                    );

                } else {

                    el.classList.remove(
                        "editing-empty"
                    );
                }
            }
        };


        el._projectValueTrackingHandler =
            evalContentState;


        el.addEventListener(
            "input",
            evalContentState
        );


        el.addEventListener(
            "change",
            evalContentState
        );


        evalContentState();
    });

    const status = document.getElementById("status");
    const completionDate = document.getElementById("completionDate");
    if (status) {
        status.removeEventListener("change", status._completionDateHintHandler);
        status._completionDateHintHandler = updateCompletionDateHint;
        status.addEventListener("change", status._completionDateHintHandler);
    }
    if (completionDate) {
        completionDate.removeEventListener("change", completionDate._completionDateHintHandler);
        completionDate._completionDateHintHandler = updateCompletionDateHint;
        completionDate.addEventListener("change", completionDate._completionDateHintHandler);
        completionDate.removeEventListener("input", completionDate._swaDueDateHandler);
        completionDate._swaDueDateHandler = updateSwaDueDate;
        completionDate.addEventListener("input", completionDate._swaDueDateHandler);
    }
    ["noaDueDate", "biddingDate", "preInspection", "materialTestingDate", "finalInspection", "swa", "ntpDate", "contractRef", "contractDuration", "contractEndDate", "ldNewCompletionDate"].forEach(id => {
        const input = document.getElementById(id);
        if (!input) return;
        for (const event of ["input", "change"]) {
            input.removeEventListener(event, updateProjectMilestoneDates);
            input.addEventListener(event, updateProjectMilestoneDates);
            if(["biddingDate", "preInspection", "materialTestingDate", "finalInspection", "swa", "ntpDate", "contractRef", "contractDuration", "contractEndDate"].includes(id)) {input.removeEventListener(event, updateCompletionDateHint);input.addEventListener(event, updateCompletionDateHint);}
        }
    });
    updateProjectMilestoneDates();
    updateCompletionDateHint();
}


function editDocument(id, source = "projects", options = {}) {
    const editorUserId = window.currentUser?.id;
    const canEditThisSource = source === "alerts" ? canEditAlertProjects() : canEditProjects();
    if (!canEditThisSource) {
        showSystemNotification("You do not have permission to edit projects.");
        return;
    }
    if (!db) {
        return;
    }

    projectEditReturnWorkspace = source === "alerts" ? "dashboard" : activeWorkspace === "archive" ? "archive" : "documents";
    projectEditSource = source;


    const transaction =
        db.transaction(
            ["projects"],
            "readonly"
        );


    const store =
        transaction.objectStore("projects");


    const request =
        store.get(id);


    request.onsuccess = (event) => {

        if (window.currentUser?.id !== editorUserId) return;

        const doc =
            event.target.result;


        if (!doc) {
            alert(
                "The selected project could not be found."
            );
            return;
        }


        editingId = id;
        projectEditSnapshot = JSON.parse(JSON.stringify(doc));
        if (typeof startProjectEditingPresence === "function") startProjectEditingPresence(id);
        hideNextProjectNumberPreview();
        pendingProjectAttachmentFiles = [];
        pendingProjectAttachmentDeletes = new Set();
        renderProjectAttachments(doc.attachments || [], id);
        if (!options.suspendedDraft) restoreAttachmentDraft(id);


        const headerEl =
            document.getElementById(
                "formHeader"
            );


        if (headerEl) {
            headerEl.textContent =
                `Modify Project Parameters (Project Number: ${doc.projectNo || "Not available"})`;
        }


        const setVal = (elementId, value) => {

            const el =
                document.getElementById(
                    elementId
                );


            if (!el) {
                return;
            }


            el.value =
                value === undefined ||
                value === null
                    ? ""
                    : value;
        };


        setVal(
            "biddingDate",
            doc.biddingDate
        );

        setVal(
            "title",
            doc.title
        );

        setVal(
            "approvedBudget",
            doc.approvedBudget
        );

        setVal(
            "bidAmount",
            doc.bidAmount
        );

        setVal(
            "winningBidder",
            doc.winningBidder
        );


        /*
         * Source of Fund
         */
        const sourceSelect =
            document.getElementById(
                "sourceOfFund"
            );


        const sourceCustomRow =
            document.getElementById(
                "sourceOfFundCustomRow"
            );


        const sourceCustom =
            document.getElementById(
                "sourceOfFundCustom"
            );


        const storedSource =
            cleanStoredValue(
                doc.sourceOfFund
            );


        if (sourceSelect) {

            if (
                storedSource ===
                    "Brgy Fund" ||
                storedSource ===
                    "City Fund"
            ) {

                sourceSelect.value =
                    storedSource;


                if (sourceCustomRow) {
                    sourceCustomRow.classList.add(
                        "d-none"
                    );
                }


                if (sourceCustom) {
                    sourceCustom.value = "";
                }

            } else if (
                storedSource ===
                ""
            ) {

                sourceSelect.value = "";


                if (sourceCustomRow) {
                    sourceCustomRow.classList.add(
                        "d-none"
                    );
                }


                if (sourceCustom) {
                    sourceCustom.value = "";
                }

            } else if (
                storedSource ===
                "Others"
            ) {

                sourceSelect.value =
                    "Others";


                if (sourceCustomRow) {
                    sourceCustomRow.classList.remove(
                        "d-none"
                    );
                }


                if (sourceCustom) {
                    sourceCustom.value = "";
                }

            } else {

                sourceSelect.value =
                    "Others";


                if (sourceCustomRow) {
                    sourceCustomRow.classList.remove(
                        "d-none"
                    );
                }


                if (sourceCustom) {
                    sourceCustom.value =
                        storedSource;
                }
            }
        }


        setVal(
            "prNumber",
            doc.prNumber
        );

        setVal(
            "prDate",
            doc.prDate
        );

        setVal(
            "obre",
            doc.obre
        );

        setVal(
            "resolutionNumber",
            doc.resolutionNumber
        );

        setVal(
            "resolutionDate",
            doc.resolutionDate
        );

        setVal(
            "noticePostQual",
            doc.noticePostQual
        );

        setVal("noaDueDate", doc.noaDueDate);
        setVal(
            "noa",
            doc.noa
        );

        setVal(
            "performanceBond",
            doc.performanceBond
        );

        setVal(
            "contractRef",
            doc.contractRef
        );

        setVal(
            "contractDuration",
            doc.contractDuration
        );

        setVal(
            "ntpDate",
            doc.ntpDate
        );

        setVal(
            "dole",
            doc.dole
        );

        setVal(
            "contractEndDate",
            doc.contractEndDate
        );

        setVal("materialTestingDate", doc.materialTestingDate);
        setVal(
            "preInspection",
            doc.preInspection
        );

        setVal(
            "finalInspection",
            doc.finalInspection
        );

        setVal(
            "swa",
            doc.swa
        );

        setVal(
            "ldAmount",
            doc.ldAmount
        );

        setVal(
            "ldNoOfDays",
            doc.ldNoOfDays
        );

        setVal(
            "ldNewCompletionDate",
            doc.ldNewCompletionDate
        );

        setVal(
            "ldActualDuration",
            doc.ldActualDuration
        );

        setVal(
            "completionDate",
            doc.completion
        );

        setVal(
            "status",
            doc.status || "Pending"
        );

        setVal(
            "remarks",
            doc.remarks
        );


        const saveBtn =
            document.getElementById(
                "saveBtn"
            );


        if (saveBtn) {
            saveBtn.textContent =
                "Apply Records Change";
        }


        const cancelBtn =
            document.getElementById(
                "cancelEditBtn"
            );


        if (cancelBtn) {
            cancelBtn.classList.remove(
                "d-none"
            );
        }


        document.body.classList.add("project-edit-pane-open");
        document.getElementById("editPaneCloseButton")?.classList.remove("d-none");
        document.getElementById("projectEditorAlertsButton")?.classList.remove("d-none");
        if (options.suspendedDraft) applySuspendedProjectDraft(options.suspendedDraft);


        /*
         * Wait until the form is visible
         * before measuring the textarea.
         */
        setTimeout(() => {

            const titleArea =
                document.getElementById(
                    "title"
                );


            if (titleArea) {
                autoExpandTextarea(
                    titleArea
                );
            }


            initializeLiveValueTracking();

        }, 50);
    };


    request.onerror = (event) => {

        console.error(
            "Unable to load project for editing:",
            event.target.error
        );


        alert(
            "The selected project could not be loaded."
        );
    };
}


function resetFormAccess(options = {}) {
    if (projectSaveInProgress && !options.silent) return;

    const {
        silent = false,
        navigate = true,
        preserveDraft = false
    } = options;


    const wasEditing =
        editingId !== null;
    const attachmentDraftProjectId = editingId;
    const returnWorkspace = projectEditReturnWorkspace || "documents";
    hideProjectAlertsDropdown();
    if (typeof stopProjectEditingPresence === "function") void stopProjectEditingPresence();
    editingId = null;
    projectEditSnapshot = null;
    hideNextProjectNumberPreview();
    projectEditReturnWorkspace = null;
    projectEditSource = "projects";
    document.body.classList.remove("project-edit-pane-open");
    document.getElementById("editPaneCloseButton")?.classList.add("d-none");
    document.getElementById("projectEditorAlertsButton")?.classList.add("d-none");
    projectAttachmentRecords = [];
    pendingProjectAttachmentFiles = [];
    pendingProjectAttachmentDeletes = new Set();
    if (attachmentDraftProjectId !== null && !preserveDraft) clearAttachmentDraft(attachmentDraftProjectId);
    renderProjectAttachments([], null);
    const attachmentInput = document.getElementById("projectAttachmentFiles");
    if (attachmentInput) attachmentInput.value = "";


    const headerEl =
        document.getElementById(
            "formHeader"
        );


    if (headerEl) {
        headerEl.textContent =
            "Add Project Record";
    }


    const saveBtn =
        document.getElementById(
            "saveBtn"
        );


    if (saveBtn) {
        saveBtn.textContent =
            "Save Project";
    }


    const cancelBtn =
        document.getElementById(
            "cancelEditBtn"
        );


    if (cancelBtn) {
        cancelBtn.classList.add(
            "d-none"
        );
    }


    inputFieldsList.forEach(id => {

        const el =
            document.getElementById(id);


        if (!el) {
            return;
        }


        el.value = "";

        el.classList.remove(
            "editing-empty",
            "has-value"
        );
    });


    const sourceCustomRow =
        document.getElementById(
            "sourceOfFundCustomRow"
        );


    if (sourceCustomRow) {
        sourceCustomRow.classList.add(
            "d-none"
        );
    }


    const sourceCustom =
        document.getElementById(
            "sourceOfFundCustom"
        );


    if (sourceCustom) {
        sourceCustom.value = "";
    }


    const sourceSelect =
        document.getElementById(
            "sourceOfFund"
        );


    if (sourceSelect) {
        sourceSelect.value = "";
    }


    const statusEl =
        document.getElementById(
            "status"
        );


    if (statusEl) {
        statusEl.value =
            "Pending";
    }


    const titleArea =
        document.getElementById(
            "title"
        );


    if (titleArea) {
        titleArea.style.height =
            "auto";
    }


    if (
        wasEditing &&
        !silent
    ) {
        showSystemNotification(
            "Modification cancelled. Unsaved changes were discarded."
        );
    }


    updateProjectMilestoneDates();
    updateSwaDueDate();
    if (navigate) {
        switchWorkspace(returnWorkspace);
    }
    if (typeof checkForSharedProjectUpdates === "function") checkForSharedProjectUpdates();
}

function deleteDocument(id) {

    if (!canDeleteProjects()) {
        showSystemNotification("You do not have permission to delete projects.");
        return;
    }

    const confirmed =
        confirm(
            "Are you sure you want to permanently delete this project and its history?"
        );


    if (!confirmed) {
        return;
    }


    const transaction =
        db.transaction(
            [
                "projects",
                "history"
            ],
            "readwrite"
        );


    const projectStore =
        transaction.objectStore(
            "projects"
        );


    // The server deletes project history in the same transaction.
    projectStore.delete(id);

    transaction.oncomplete = () => {

        if (editingId === id) {
            if (typeof stopProjectEditingPresence === "function") void stopProjectEditingPresence();
            editingId = null;
        }


        showSystemNotification(
            "Project and associated history successfully deleted."
        );


        loadDocuments();
    };


    transaction.onerror = (event) => {

        console.error(
            "Project deletion failed:",
            event.target.error
        );


        alert(
            "The project could not be deleted."
        );
    };
}

async function setDocumentArchived(id, archived) {
    if (!canArchiveProjects()) {
        showSystemNotification("You do not have permission to archive projects.");
        return;
    }
    const action = archived ? "archive" : "restore";
    if (!confirm(`Are you sure you want to ${action} this project?`)) return;
    try {
        await apiClient.post("setProjectArchived", { id, archived });
        await db.refresh();
        loadDocuments(true);
        showSystemNotification(`Project successfully ${archived ? "archived" : "restored"}.`);
    } catch (error) {
        showSystemNotification(error.message || `Unable to ${action} the project.`);
    }
}

function showOverdueProjects() {
    document.getElementById("searchInput").value = "overdue";
    document.getElementById("filterStatusSelect").value = "All";
    currentPage = 1;
    loadDocuments();
}

function applyProjectFilters() {
    clearTimeout(projectListSearchTimer);
    projectListRequestSequence++;
    currentPage = 1;
    loadDocuments();
}

function clearProjectFilters() {
    resetProjectFilters();
    projectLifecycle = activeWorkspace === "archive" ? "archived" : "all";
    updateProjectLifecycleControls();
    syncProjectStatusOptions();
    applyProjectFilters();
}

document.addEventListener("DOMContentLoaded", () => {
    updateProjectNumberSortControl();
    const titleArea = document.getElementById("title");
    if (titleArea) {
        titleArea.addEventListener("input", function() {
            autoExpandTextarea(this);
        });
    }

    const sourceSelect = document.getElementById("sourceOfFund");
    const sourceCustomRow = document.getElementById("sourceOfFundCustomRow");
    if (sourceSelect) {
        sourceSelect.addEventListener("change", () => {
            const selectedValue = sourceSelect.value;
            if (selectedValue === "Others") {
                if (sourceCustomRow) sourceCustomRow.classList.remove("d-none");
            } else {
                if (sourceCustomRow) sourceCustomRow.classList.add("d-none");
            }
        });
    }
});

let projectAlertDropdownId = null;
let projectAlertDropdownAnchor = null;
let projectAlertDropdownSequence = 0;

function hideProjectAlertsDropdown(restoreFocus = false) {
    const anchor = projectAlertDropdownAnchor;
    document.getElementById("projectAlertsPanel")?.classList.add("d-none");
    anchor?.setAttribute("aria-expanded", "false");
    projectAlertDropdownId = null;
    projectAlertDropdownAnchor = null;
    ++projectAlertDropdownSequence;
    if (restoreFocus && anchor?.isConnected) anchor.focus();
}

function positionProjectAlertsDropdown() {
    const panel = document.getElementById("projectAlertsPanel");
    const anchor = projectAlertDropdownAnchor;
    if (!panel || panel.classList.contains("d-none") || !anchor) return;
    if (!anchor.isConnected) { hideProjectAlertsDropdown(); return; }
    const margin = 12, gap = 8;
    const viewportWidth = document.documentElement.clientWidth;
    const viewportHeight = window.innerHeight;
    const rect = anchor.getBoundingClientRect();
    const width = Math.min(420, viewportWidth - margin * 2);
    const below = viewportHeight - rect.bottom - gap - margin;
    const above = rect.top - gap - margin;
    const placeBelow = below >= 240 || below >= above;
    panel.style.width = `${width}px`;
    panel.style.maxHeight = `${Math.max(120, Math.min(560, placeBelow ? below : above))}px`;
    panel.style.left = `${Math.max(margin, Math.min(rect.right - width, viewportWidth - width - margin))}px`;
    panel.style.right = "auto";
    panel.style.top = `${placeBelow ? rect.bottom + gap : Math.max(margin, rect.top - gap - panel.getBoundingClientRect().height)}px`;
}

async function showProjectAlertsDropdown(id, anchor) {
    const panel = document.getElementById("projectAlertsPanel");
    if (!panel || !anchor) return;
    if (anchor.closest("#notificationAlertPanel")) anchor = document.getElementById("notificationBellBtn") || anchor;
    if (projectAlertDropdownId === id && projectAlertDropdownAnchor === anchor && !panel.classList.contains("d-none")) {
        hideProjectAlertsDropdown();
        return;
    }
    hideProjectAlertsDropdown();
    const sequence = ++projectAlertDropdownSequence;
    projectAlertDropdownId = id;
    projectAlertDropdownAnchor = anchor;
    // Keep a dropdown opened inside a modal within its keyboard focus boundary.
    (anchor.closest(".modal.show") || document.body).appendChild(panel);
    document.getElementById("notificationAlertPanel")?.classList.add("d-none");
    document.getElementById("accountMenu")?.classList.add("d-none");
    anchor.setAttribute("aria-expanded", "true");
    panel.classList.remove("d-none");
    panel.innerHTML = '<div class="notification-panel-header"><span class="notification-panel-title">Project alerts</span><span class="notification-panel-subtitle">Loading alerts…</span></div>';
    positionProjectAlertsDropdown();
    try {
        const result = await apiClient.getProjectAlerts(id);
        if (sequence !== projectAlertDropdownSequence || projectAlertDropdownId !== id) return;
        const alerts = result.alerts || [];
        panel.innerHTML = `<div class="notification-panel-header"><div class="project-alert-dropdown-heading"><span class="notification-panel-title">Project alerts</span><button type="button" class="btn-close" aria-label="Close project alerts" onclick="hideProjectAlertsDropdown(true)"></button></div><span class="notification-panel-subtitle">Project no. ${escapeHtml(result.project.projectNo || "Not assigned")} · ${alerts.length} alert${alerts.length === 1 ? "" : "s"}</span></div><div class="notification-panel-body"><ul class="notification-list project-alerts-list list-unstyled mb-0">${alerts.length ? alerts.map(item => {
            return `<li><div class="project-alert-dropdown-copy"><div class="project-alert-card-heading"><strong>${escapeHtml(item.label)}</strong><span class="project-alert-status ${item.status === "Active" ? "is-active" : item.status === "Resolved" ? "is-resolved" : "is-upcoming"}">${escapeHtml(item.status)}</span></div>${[item.dueDate ? `Due ${formatUIDate(item.dueDate)}` : "", item.doneDate ? `Completed ${formatUIDate(item.doneDate)}` : ""].filter(Boolean).map(value => `<span class="project-alert-date">${escapeHtml(value)}</span>`).join("")}</div></li>`;
        }).join("") : '<li class="text-muted">No alerts for this project.</li>'}</ul></div>`;
        positionProjectAlertsDropdown();
    } catch (error) {
        if (sequence !== projectAlertDropdownSequence) return;
        panel.innerHTML = '<div class="notification-panel-header"><span class="notification-panel-title">Project alerts</span></div><p class="p-3 mb-0"></p>';
        panel.querySelector("p").textContent = error.message || "Unable to load project alerts.";
        positionProjectAlertsDropdown();
    }
}

function openNotificationProject(id, event, openAlerts = false) {
    event?.preventDefault();
    event?.stopPropagation();
    document.getElementById("notificationAlertPanel")?.classList.add("d-none");
    document.getElementById("notificationBellBtn")?.setAttribute("aria-expanded", "false");
    const projectId = Number(id);
    if (!Number.isSafeInteger(projectId) || projectId <= 0) return;
    viewProjectDetails(projectId, "notifications", { openAlerts });
}

function openPendingProjectDetailsAlerts() {
    const id = projectDetailsAlertsOnShow;
    projectDetailsAlertsOnShow = null;
    if (id !== null && id === projectDetailsEditId) {
        showProjectAlertsDropdown(id, document.getElementById("projectDetailsAlertsButton"));
    }
}

function openProjectAlerts(id, event) {
    event?.stopPropagation();
    const anchor = event?.currentTarget || [...document.querySelectorAll(`[data-project-alerts-id="${Number(id)}"]`)].find(button => button.getClientRects().length > 0);
    if (anchor) showProjectAlertsDropdown(id, anchor);
}

function toggleProjectAlertsPanel(event) {
    event?.stopPropagation();
    if (projectDetailsEditId) showProjectAlertsDropdown(projectDetailsEditId, document.getElementById("projectDetailsAlertsButton"));
}

function toggleProjectEditorAlertsPanel(event) {
    event?.stopPropagation();
    if (editingId !== null) showProjectAlertsDropdown(editingId, document.getElementById("projectEditorAlertsButton"));
}

document.addEventListener("click", event => {
    const panel = document.getElementById("projectAlertsPanel");
    if (panel && !panel.classList.contains("d-none") && !panel.contains(event.target) && !projectAlertDropdownAnchor?.contains(event.target)) hideProjectAlertsDropdown();
});
document.addEventListener("keydown", event => {
    if (event.key === "Escape" && projectAlertDropdownId !== null) {
        event.preventDefault();
        event.stopPropagation();
        hideProjectAlertsDropdown(true);
    }
}, true);
document.addEventListener("shown.bs.modal", event => {
    if (event.target.id !== "projectDetailsModal") return;
    projectDetailsModalShown = true;
    openPendingProjectDetailsAlerts();
});
document.addEventListener("hide.bs.modal", event => {
    if (event.target.id !== "projectDetailsModal") return;
    projectDetailsModalShown = false;
    projectDetailsAlertsOnShow = null;
    ++projectDetailsRequestSequence;
    hideProjectAlertsDropdown();
});
document.addEventListener("scroll", event => {
    const panel = document.getElementById("projectAlertsPanel");
    if (!panel?.contains(event.target)) positionProjectAlertsDropdown();
}, true);
window.addEventListener("resize", positionProjectAlertsDropdown);
