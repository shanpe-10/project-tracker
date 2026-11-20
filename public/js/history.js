/* ==========================================================================
   PROJECT HISTORY / AUDIT LOGS
   ========================================================================== */


const projectHistoryLabels = {
    projectNo: "Project number", title: "Project title", status: "Status",
    biddingDate: "Bidding date", approvedBudget: "Approved budget", bidAmount: "Winning bid amount",
    winningBidder: "Winning bidder", sourceOfFund: "Source of fund", prNumber: "Purchase request number",
    prDate: "Purchase request date", obre: "Obligation request date", noticePostQual: "Notice of post-qualification",
    noaDueDate: "NOA due date", noa: "NOA issued date", ntpDate: "Notice to proceed",
    completion: "Completion date", contractRef: "Contract date", dole: "DOLE clearance date",
    swa: "SWA release date", ldAmount: "Liquidated damages amount", ldNoOfDays: "Liquidated damages days",
    ldNewCompletionDate: "Extended completion date", ldActualDuration: "Actual construction duration",
    remarks: "Remarks"
};
const projectHistoryMetadata = new Set([
    "id", "projectId", "actor", "createdAt", "updatedAt", "updatedBy", "archivedAt", "archivedBy",
    "attachments", "timelineAlertsCompleted", "timelineCompletedMarked", "timelineMarkedDoneDate",
    "timelineUnmarkedType", "timelineUnmarkedAt"
]);

function parseHistorySnapshot(value) {
    try {
        const snapshot = typeof value === "string" ? JSON.parse(value) : value;
        return snapshot && typeof snapshot === "object" && !Array.isArray(snapshot) ? snapshot : null;
    } catch (_) {
        return null;
    }
}

function projectHistoryLabel(key) {
    if (Object.hasOwn(projectHistoryLabels, key)) return projectHistoryLabels[key];
    const workbookLabel = typeof additionalProjectFields === "undefined" ? null
        : additionalProjectFields.find(field => field.key === key)?.label;
    return workbookLabel || key.replace(/([A-Z])/g, " $1").replace(/^./, char => char.toUpperCase());
}

function projectHistoryFields(snapshot) {
    const fields = new Map();
    for (const [key, value] of Object.entries(snapshot)) {
        if (projectHistoryMetadata.has(key) || !["string", "number", "boolean"].includes(typeof value)) continue;
        const text = String(value);
        if (text.trim()) fields.set(key, text);
    }
    return fields;
}

function projectHistoryAttachments(snapshot) {
    const files = Array.isArray(snapshot.attachments) ? snapshot.attachments : [];
    return new Map(files.filter(file => file && typeof file.name === "string" && file.name)
        .map(file => [file.id || file.name, file.name]));
}

function projectHistoryDifferences(before, after, compareStatus = true) {
    const previous = projectHistoryFields(before);
    const saved = projectHistoryFields(after);
    const changes = [];
    for (const key of new Set([...previous.keys(), ...saved.keys()])) {
        if (!compareStatus && key === "status") continue;
        const oldValue = previous.get(key);
        const newValue = saved.get(key);
        if (oldValue !== newValue) changes.push({ label: projectHistoryLabel(key), before: oldValue, after: newValue });
    }
    const oldFiles = projectHistoryAttachments(before);
    const newFiles = projectHistoryAttachments(after);
    for (const [id, name] of oldFiles) {
        if (!newFiles.has(id)) changes.push({ label: "Removed attachment", before: name });
    }
    for (const [id, name] of newFiles) {
        if (!oldFiles.has(id)) changes.push({ label: "Added attachment", after: name });
    }
    return changes;
}

function formatHistorySnapshot(snapshot) {
    const entries = [...projectHistoryFields(snapshot)].map(([key, value]) => [projectHistoryLabel(key), value]);
    for (const name of projectHistoryAttachments(snapshot).values()) entries.push(["Attachment", name]);
    if (!entries.length) return '<p class="mb-0 text-muted">No field values were recorded.</p>';
    return `<div class="history-readable-fields">${entries.map(([label, value]) =>
        `<div><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`
    ).join("")}</div>`;
}

function formatHistoryDifferences(changes) {
    if (!changes.length) return '<p class="mb-0 text-muted">This save did not change any project fields or attachments.</p>';
    const cell = value => value === undefined ? '<span class="text-muted">Empty</span>' : escapeHtml(value);
    return `<table class="table table-sm history-change-table">
        <thead><tr><th scope="col">Field</th><th scope="col">Before</th><th scope="col">After</th></tr></thead>
        <tbody>${changes.map(change => `<tr>
            <th scope="row">${escapeHtml(change.label)}</th>
            <td class="history-change-value">${cell(change.before)}</td>
            <td class="history-change-value">${cell(change.after)}</td>
        </tr>`).join("")}</tbody>
    </table>`;
}

function historyEntryContent(log, before, after) {
    if (after && ["Created", "Imported"].includes(log.action)) return formatHistorySnapshot(after);
    if (before && after) {
        const source = log.comparisonSource === "current" ? "the current saved version"
            : log.comparisonSource === "next" ? "the next saved version" : null;
        const note = source ? `<p class="text-muted mb-2">Compared with ${source}.</p>` : "";
        // Status can be recalculated after a save without creating a new project version.
        const changes = projectHistoryDifferences(before, after, !source);
        if (source && !changes.length) return note + '<p class="mb-0 text-muted">No field or attachment changes were found in the available versions.</p>';
        return note + formatHistoryDifferences(changes);
    }
    if (log.details) return `<p class="mb-0">${escapeHtml(log.details)}</p>`;
    if (log.action === "Archived") return '<p class="mb-0">Moved the project to Historical Archive.</p>';
    if (log.action === "Restored") return '<p class="mb-0">Restored the project from Historical Archive.</p>';
    if (before) {
        return '<p class="text-muted mb-2">Only the previous values were recorded for this edit.</p>' + formatHistorySnapshot(before);
    }
    if (typeof log.changes === "string" && log.changes.trim() && log.changes !== "[]") {
        return `<p class="mb-0">${escapeHtml(log.changes)}</p>`;
    }
    return '<p class="mb-0 text-muted">No additional details were recorded.</p>';
}

function historyActorLabel(log) {
    const actor = log.actor && typeof log.actor === "object" ? log.actor : {};
    const name = typeof actor.fullName === "string" ? actor.fullName.trim() : "";
    const username = typeof actor.username === "string" ? actor.username.trim()
        : (typeof log.updatedBy === "string" ? log.updatedBy.trim() : "");
    if (name && username && name !== username) return `${name} (@${username})`;
    return name || (username ? `@${username}` : "Not recorded");
}

/**
 * Opens the audit-history modal for a project.
 *
 * History records can contain:
 *
 * 1. Full project snapshots produced when a project is edited.
 * 2. Timeline actions and archive / restore events.
 */
/* Server-paged project history loader. */
let historyRequestSequence = 0;
async function viewHistory(id, page = 1, currentProject = currentProjectsList.find(project => Number(project.id) === Number(id)), parent = document.body) {
    const sequence = ++historyRequestSequence;
    if (!window.apiClient) return;
    const historyBody = document.getElementById("historyBody");
    const dialog = document.getElementById("historyModal");
    if (!historyBody || !dialog) return;

    historyBody.innerHTML = '<div class="text-center text-muted py-4">Loading project history…</div>';
    if (!dialog.open) {
        // Keep the history dialog inside Project Details so its focus stays within the parent modal.
        parent.appendChild(dialog);
        showProjectActionDialog(dialog);
    }

    try {
        const result = await apiClient.getProjectHistory(id, page, 25);
        if (sequence !== historyRequestSequence || !dialog.open) return;
        const logs = result.history || [];
        let html = `<p class="mb-3"><strong>${escapeHtml(currentProject?.title || "Selected project")}</strong> · Project no. ${escapeHtml(currentProject?.projectNo || id)}</p>`;

        if (!logs.length) {
            html += '<div class="alert alert-light border text-center py-3 text-muted small">No history has been recorded for this project.</div>';
        } else {
            html += '<div class="accordion" id="historyAccordion">';
            logs.forEach((log, index) => {
                const formattedDate = formatUIDateTime(log.editedAt || log.timestamp || "");
                const before = parseHistorySnapshot(log.changes);
                const after = parseHistorySnapshot(log.after);
                const action = log.action || (before ? "Edited" : "Project activity");
                html += `<div class="accordion-item small"><h2 class="accordion-header" id="historyHeading${index}"><button class="accordion-button collapsed py-2 px-3 bg-light text-dark" type="button" data-bs-toggle="collapse" data-bs-target="#historyCollapse${index}" aria-expanded="false" aria-controls="historyCollapse${index}"><span class="history-entry-heading"><span>${escapeHtml(action)} · ${escapeHtml(formattedDate)}</span><span class="history-entry-author">By ${escapeHtml(historyActorLabel(log))}</span></span></button></h2><div id="historyCollapse${index}" class="accordion-collapse collapse" aria-labelledby="historyHeading${index}" data-bs-parent="#historyAccordion"><div class="accordion-body bg-white border-top p-3">${historyEntryContent(log, before, after)}</div></div></div>`;
            });
            html += '</div>';
        }

        if (result.totalPages > 1) {
            html += `<div class="d-flex justify-content-between align-items-center gap-3 mt-3"><span class="small text-muted">Page ${result.page} of ${result.totalPages} · ${result.totalRecords} entries</span><div class="btn-group btn-group-sm"><button type="button" class="btn btn-outline-secondary" data-history-page="${result.page - 1}" ${result.page <= 1 ? "disabled" : ""}>Previous</button><button type="button" class="btn btn-outline-secondary" data-history-page="${result.page + 1}" ${result.page >= result.totalPages ? "disabled" : ""}>Next</button></div></div>`;
        }
        historyBody.innerHTML = html;
        historyBody.querySelectorAll("[data-history-page]").forEach(button => {
            button.addEventListener("click", () => viewHistory(id, Number(button.dataset.historyPage), currentProject));
        });
    } catch (error) {
        if (sequence !== historyRequestSequence || !dialog.open) return;
        historyBody.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(error.message || "Unable to load project history.")}</div>`;
    }
}
