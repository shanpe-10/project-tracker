/* ==========================================================================
   PROJECT HISTORY / AUDIT LOGS
   ========================================================================== */


function formatHistorySnapshot(changes) {
    let snapshot;
    try { snapshot = typeof changes === "string" ? JSON.parse(changes) : changes; }
    catch (_) { return `<p class="mb-0">${escapeHtml(String(changes || "No details recorded."))}</p>`; }

    const labels = { projectNo: "Project number", title: "Project title", status: "Status", manualStatus: "Manual operational status", biddingDate: "Bidding date", approvedBudget: "Approved budget", bidAmount: "Bid amount", winningBidder: "Winning bidder", sourceOfFund: "Source of fund", prNumber: "PR number", prDate: "PR date", noaDueDate: "NOA due date", noa: "NOA issued date", ntpDate: "Notice to proceed", completion: "Completion date", contractRef: "Contract reference", remarks: "Remarks" };
    const ignored = new Set(["id", "projectId", "updatedAt", "updatedBy", "createdAt", "actor"]);
    const entries = Object.entries(snapshot || {}).filter(([key, value]) => !ignored.has(key) && value !== "" && value !== "-" && value !== null && value !== undefined);
    if (!entries.length) return '<p class="mb-0 text-muted">No saved field details.</p>';

    return `<div class="history-readable-fields">${entries.map(([key, value]) => {
        const label = labels[key] || key.replace(/([A-Z])/g, " $1").replace(/^./, char => char.toUpperCase());
        const displayValue = typeof value === "object" ? JSON.stringify(value) : String(value);
        return `<div><span>${escapeHtml(label)}</span><strong>${escapeHtml(displayValue)}</strong></div>`;
    }).join("")}</div>`;
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
async function viewHistory(id, page = 1) {
    const sequence = ++historyRequestSequence;
    if (!window.apiClient) return;
    const historyBody = document.getElementById("historyBody");
    const modalNode = document.getElementById("historyModal");
    if (!historyBody || !modalNode) return;

    const currentProject = currentProjectsList.find(project => Number(project.id) === Number(id));
    historyBody.innerHTML = '<div class="text-center text-muted py-4">Loading project history…</div>';
    bootstrap.Modal.getOrCreateInstance(modalNode).show();

    try {
        const result = await apiClient.getProjectHistory(id, page, 25);
        if (sequence !== historyRequestSequence) return;
        const logs = result.history || [];
        let html = `<div class="mb-3">Showing alteration trail logs for: <strong>${escapeHtml(currentProject?.title || "Selected project")}</strong> (NO: ${escapeHtml(currentProject?.projectNo || id)})</div>`;

        if (!logs.length) {
            html += '<div class="alert alert-light border text-center py-3 text-muted small">No historic snapshots exist. Document matches initial insertion.</div>';
        } else {
            html += '<div class="accordion" id="historyAccordion">';
            logs.forEach((log, index) => {
                const formattedDate = formatUIDateTime(log.editedAt || log.timestamp || "");
                const safeAction = log.action ? escapeHtml(log.action) : "";
                const bodyContent = log.changes
                    ? formatHistorySnapshot(log.changes)
                    : `<p class="mb-1"><strong>Action:</strong> ${safeAction}</p><p class="mb-0"><strong>Details:</strong> ${escapeHtml(log.details || "No additional details.")}</p>`;
                html += `<div class="accordion-item small"><h2 class="accordion-header" id="historyHeading${index}"><button class="accordion-button collapsed py-2 px-3 bg-light text-dark" type="button" data-bs-toggle="collapse" data-bs-target="#historyCollapse${index}" aria-expanded="false" aria-controls="historyCollapse${index}"><span class="history-entry-heading"><span>State Saved On: ${escapeHtml(formattedDate)}${safeAction ? ` (${safeAction})` : ""}</span><span class="history-entry-author">${safeAction ? "By" : "Edited by"}: ${escapeHtml(historyActorLabel(log))}</span></span></button></h2><div id="historyCollapse${index}" class="accordion-collapse collapse" aria-labelledby="historyHeading${index}" data-bs-parent="#historyAccordion"><div class="accordion-body bg-white border-top p-3" style="max-height:280px;overflow-y:auto">${bodyContent}</div></div></div>`;
            });
            html += '</div>';
        }

        if (result.totalPages > 1) {
            html += `<div class="d-flex justify-content-between align-items-center gap-3 mt-3"><span class="small text-muted">Page ${result.page} of ${result.totalPages} · ${result.totalRecords} entries</span><div class="btn-group btn-group-sm"><button type="button" class="btn btn-outline-secondary" onclick="viewHistory(${Number(id)}, ${result.page - 1})" ${result.page <= 1 ? "disabled" : ""}>Previous</button><button type="button" class="btn btn-outline-secondary" onclick="viewHistory(${Number(id)}, ${result.page + 1})" ${result.page >= result.totalPages ? "disabled" : ""}>Next</button></div></div>`;
        }
        historyBody.innerHTML = html;
    } catch (error) {
        if (sequence !== historyRequestSequence) return;
        historyBody.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(error.message || "Unable to load project history.")}</div>`;
    }
}
