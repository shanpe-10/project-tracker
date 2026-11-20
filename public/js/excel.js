/* ==========================================================================
   EXCEL IMPORT / EXPORT & DATABASE DATA MANAGEMENT
   ========================================================================== */


/* ==========================================================================
   EXCEL EXPORT
   ========================================================================== */


/**
 * Downloads all matching projects as a formatted Excel workbook.
 */
function exportToExcel() {
    if (!window.currentUser?.canExport) {
        showSystemNotification("You do not have permission to export projects.");
        return;
    }
    const filters = typeof projectListFilters === "function" ? projectListFilters() : {};
    delete filters.page;
    delete filters.pageSize;
    filters.format = "xlsx";
    window.location.href = `api/data.php?${new URLSearchParams({ action: "listProjects", ...filters }).toString()}`;
}


/* ==========================================================================
   DATABASE PURGE / BACKUP
   ========================================================================== */


/**
 * Prompts the user before deleting all
 * locally stored project and history records.
 *
 * The user may export an Excel backup first.
 */
function promptClearDatabaseSystem() {
    if (window.currentUser?.role !== "admin") {
        showSystemNotification("Only Admin can delete all records.");
        return;
    }
    openDeletionRequest({ targetType: "project", all: true });
}

// Retain compatibility with existing callers; this opens a request, never a purge.
function executeClearDatabaseWithLoadingOverlay() {
    promptClearDatabaseSystem();
}


/* ==========================================================================
   EXCEL IMPORT ENTRY POINT
   ========================================================================== */


/**
 * Opens the hidden Excel file input.
 */
let importIntoArchive = false;
let importProjectNumbersInUse = new Set();
let nextImportProjectNumber = 1;
let importPreviewRows = [];
let importPreviewFilter = "all";
let importPreviewEditing = null;
let importPreviewExistingProjects = [];
let importPreviewChoices = new Map();
let importPreviewAssignedNumbers = new Map();
let importServerNextProjectNumber = 1;
let importPreviewCalendarKey = null;

function downloadImportTemplate() {
    const link = document.createElement("a");
    link.href = "templates/project-import-template.xlsx";
    link.download = "project-import-template.xlsx";
    document.body.appendChild(link);
    link.click();
    link.remove();
}

function chooseImportFile() {
    bootstrap.Modal.getInstance(document.getElementById("importGuideModal"))?.hide();
    document.getElementById("excelImportInput")?.click();
}

const importPreviewAliases = {
    "projectNo": [
        "Project No.",
        "NO."
    ],
    "title": [
        "Project Title",
        "2. Project Title",
        "Name of Project"
    ],
    "biddingDate": [
        "Date of Bidding",
        "1. Date of Bidding"
    ],
    "approvedBudget": [
        "ABC",
        "3. ABC",
        "Approved Budget for Contract"
    ],
    "bidAmount": [
        "Winning Bid Amount",
        "Bid Amount",
        "4. Bid Amount"
    ],
    "winningBidder": [
        "Winning Bidder",
        "5. Winning Bidder"
    ],
    "sourceOfFund": [
        "Source of Fund",
        "6. Source of Fund"
    ],
    "prNumber": [
        "PR Number",
        "7. PR Number",
        "PR NO.",
        "PR #"
    ],
    "prDate": [
        "PR Date",
        "8. PR Date"
    ],
    "obre": [
        "OBRE",
        "9. OBRE",
        "9. OBRE Date",
        "OBRE DATE"
    ],
    "resolutionNumber": [
        "Resolution Number",
        "10. Resolution Number",
        "Resolution #"
    ],
    "resolutionDate": [
        "Resolution Date",
        "11. Resolution Date"
    ],
    "noticePostQual": [
        "Notice of Post Qualification",
        "12. Notice of Post Qualification",
        "Post Qua"
    ],
    "noaDueDate": [
        "NOA Due Date"
    ],
    "noa": [
        "Notice of Award",
        "13. Notice of Award",
        "NOA"
    ],
    "performanceBond": [
        "Performance Bond",
        "14. Performance Bond"
    ],
    "contractRef": [
        "Contract Date",
        "15. Contract Date"
    ],
    "contractDuration": [
        "Contract Duration",
        "16. Contract Duration"
    ],
    "ntpDate": [
        "Notice to Proceed",
        "17. Notice to Proceed",
        "NTP"
    ],
    "dole": [
        "DOLE Clearance",
        "18. DOLE Clearance"
    ],
    "contractEndDate": [
        "Contract End Date",
        "19. Contract End Date"
    ],
    "materialTestingDate": [
        "Material Testing Date"
    ],
    "preInspection": [
        "Pre Inspection",
        "20. Pre Inspection",
        "Pre-Inspection Date"
    ],
    "finalInspection": [
        "Final Inspection",
        "21. Final Inspection"
    ],
    "swa": [
        "SWA",
        "22. SWA",
        "22. SWA Date"
    ],
    "ldAmount": [
        "LD Amount",
        "23. LD Amount"
    ],
    "ldNoOfDays": [
        "LD Days",
        "23. No. of Days Delinquent"
    ],
    "ldNewCompletionDate": [
        "Extended Completion Date",
        "23. New Extended Completion Date"
    ],
    "ldActualDuration": [
        "Actual Construction Duration",
        "23. Actual Construction Duration"
    ],
    "completion": [
        "Completion Date",
        "24. Completion Date"
    ],
    "status": [
        "Operational Status",
        "25. Operational Status"
    ],
    "remarks": [
        "Additional Remarks",
        "26. Additional Remarks",
        "Remarks"
    ],
    "losingBidder1": [
        "Losing Bidder 1"
    ],
    "losingBidAmount1": [
        "Losing Bid Amount 1",
        "Losing Bidder 1 Bid Amount",
        "Losing Bidder 1 Amount"
    ],
    "losingBidder2": [
        "Losing Bidder 2"
    ],
    "losingBidAmount2": [
        "Losing Bid Amount 2",
        "Losing Bidder 2 Bid Amount",
        "Losing Bidder 2 Amount"
    ],
    "referenceNo": [
        "Ref. No."
    ],
    "approvedBudgetWords": [
        "ABC (in words)"
    ],
    "taker": [
        "Taker"
    ],
    "owner": [
        "Owner"
    ],
    "bidAmountWords": [
        "Bid Amount (in words)"
    ],
    "preProcurementDate": [
        "Pre-Procurement"
    ],
    "preBidDate": [
        "Pre-Bid"
    ],
    "liquidatedDamages": [
        "Liquidated Damages (source)",
        "Liquadated Damages",
        "Liquidated Damages"
    ],
    "completionSwaDate": [
        "Completion/SWA Date (source)",
        "Completion/SWA Date"
    ],
    "releasedBy": [
        "Released by"
    ],
    "philgeps": [
        "PhilGEPS"
    ],
    "physicalAccomplishment": [
        "Physical Accomplishment",
        "Physcial Accomplishment"
    ]
};

for (const field of projectFieldDefinitions) {
    importPreviewAliases[field.key] = [...new Set([...(importPreviewAliases[field.key] || []), field.label])];
}
importPreviewAliases.winningBidder.push('Contractor');
importPreviewAliases.contractRef.push('Contract Agreement Date');
importPreviewAliases.materialTestingAmount.push('Material Testing Amount');
importPreviewAliases.ldAmount.push('LD Amount', 'Liquidated Damages Amount');
importPreviewAliases.ldNoOfDays.push('LD Number of Days');

// Read formatted identifier cells without losing leading zeroes; percentages use points internally.
function prepareWorkbookTypedRows(rows, worksheet) {
    const aliases = new Map();
    for (const [key, names] of Object.entries(importPreviewAliases)) for (const name of [key,...names]) aliases.set(normalizeWorkbookHeading(name),key);
    const bounds = XLSX.utils.decode_range(worksheet['!ref']);
    const headers = new Map();
    for (let column=bounds.s.c; column<=bounds.e.c; column++) {
        const cell=worksheet[XLSX.utils.encode_cell({r:bounds.s.r,c:column})];
        const heading=String(cell?.v ?? '');
        headers.set(heading,column);
    }
    for (const row of rows) for (const [heading,value] of Object.entries(row)) {
        const key=aliases.get(normalizeWorkbookHeading(heading));
        const column=headers.get(heading);
        if (column == null) continue;
        const cell=worksheet[XLSX.utils.encode_cell({r:row.__rowNum__,c:column})];
        if (['referenceNo','prNumber','resolutionNumber','obreNumber','projectNo'].includes(key) && cell?.t === 'n' && /^0+$/.test(cell.z || '')) row[heading]=XLSX.utils.format_cell(cell);
        if (key === 'physicalAccomplishment' && cell?.t === 'n' && /%/.test(cell.z || '')) row[heading]=Number((Number(value)*100).toFixed(10));
    }
    return rows;
}

function normalizeWorkbookHeading(value) {
    return String(value).replace(/\s+/g, " ").replace(/\s*\/\s*/g, "/").trim().toLowerCase();
}

function normalizeWorkbookRows(rawRows, date1904 = false) {
    const headings = Object.keys(rawRows[0] || {});
    const lookup = new Map();
    for (const [key, aliases] of Object.entries(importPreviewAliases)) {
        for (const heading of [key, ...aliases]) lookup.set(normalizeWorkbookHeading(heading), key);
    }
    const mappedHeadings = headings.map(heading => lookup.get(normalizeWorkbookHeading(heading)));
    const has = key => mappedHeadings.includes(key);
    if (!has("title")) throw new Error("Project title column not found. Use the import template or the consolidated worksheet.");
    return rawRows.map(row => {
        const mapped = {};
        const sourceNotes = [];
        for (const [heading,value] of Object.entries(row)) {
            const key = lookup.get(normalizeWorkbookHeading(heading));
            if (!key) {
                if (String(value ?? "").trim()) {
                    if (/^__EMPTY/.test(heading)) sourceNotes.push(`${heading}: ${value}`);
                    else throw new Error(`Unsupported column: ${heading}. No records were saved.`);
                }
                continue;
            }
            if (Object.hasOwn(mapped,key) && String(mapped[key] ?? "").trim() && String(value ?? "").trim() && String(mapped[key]) !== String(value)) {
                throw new Error(`Multiple columns map to ${key}. Keep one column for this field.`);
            }
            if (!Object.hasOwn(mapped,key) || String(value ?? "").trim()) mapped[key] = value;
        }
        if (sourceNotes.length) mapped.remarks = [mapped.remarks, "Unlabelled source cells:\n" + sourceNotes.join("\n")].filter(Boolean).join("\n");
        // Keep the combined completion/SWA source cell separate from either milestone.
        for (const field of [...importDateFields, "completionSwaDate"]) {
            if (typeof mapped[field] === "number" || mapped[field] instanceof Date) mapped[field] = parseImportDate(mapped[field], date1904);
        }
        if (Number.isInteger(row.__rowNum__)) Object.defineProperty(mapped, "__rowNum__", { value: row.__rowNum__ });
        return mapped;
    });
}

function importPreviewValue(row, field) {
    for (const key of [field, ...(importPreviewAliases[field] || [])]) {
        if (row[key] !== undefined && row[key] !== null && String(row[key]).trim() !== "") return row[key];
    }
    return "";
}

function importPreviewIdentity(value) {
    return String(value || "").trim().toLowerCase().replace(/\s+/g, " ");
}

function importPreviewMatch(row, projects = importPreviewExistingProjects) {
    const number = importPreviewIdentity(importPreviewValue(row, "projectNo"));
    const title = importPreviewIdentity(importPreviewValue(row, "title"));
    const numberMatch = number && projects.find(project => importPreviewIdentity(project.projectNo) === number);
    const titleMatches = title ? projects.filter(project => importPreviewIdentity(project.title) === title) : [];
    let problem = "";
    if (numberMatch && titleMatches.length && !titleMatches.some(project => project.id === numberMatch.id)) {
        problem = "Project number and title match different saved projects.";
    } else if (!numberMatch && titleMatches.length > 1) {
        problem = "More than one saved project has this title. Enter the matching project number.";
    }
    return {
        match: problem ? null : numberMatch || titleMatches[0] || null,
        numberMatch: Boolean(numberMatch),
        problem
    };
}

function parseImportDate(value, date1904 = false) {
    // Spreadsheet parsers may return local calendar dates rather than serial numbers.
    if (value instanceof Date) return Number.isFinite(value.getTime())
        ? `${String(value.getFullYear()).padStart(4,'0')}-${String(value.getMonth()+1).padStart(2,'0')}-${String(value.getDate()).padStart(2,'0')}` : '';
    const raw = String(value ?? "").trim();
    if (!raw || /^(?:-|n\/?a)$/i.test(raw)) return "";
    const iso = (year, month, day) =>
        [String(year).padStart(4, "0"), String(month).padStart(2, "0"), String(day).padStart(2, "0")].join("-");
    if (typeof value === "number") {
        const parts = XLSX.SSF.parse_date_code(value, { date1904 });
        return parts ? iso(parts.y, parts.m, parts.d) : raw;
    }
    const text = raw.replace(/[\u2010-\u2015\u2212]/g, "-");
    const yearFirst = text.match(/^(\d{4})([-/.])(\d{1,2})\2(\d{1,2})(?=$|[T ])/);
    if (yearFirst) {
        const date = iso(yearFirst[1], yearFirst[3], yearFirst[4]);
        return yearFirst[0] === text || Number.isFinite(Date.parse(text)) ? date : raw;
    }
    const monthFirst = text.match(/^(\d{1,2})([-/.])(\d{1,2})\2(\d{2}|\d{4})$/);
    if (monthFirst) {
        const year = Number(monthFirst[4]);
        return iso(monthFirst[4].length === 4 ? year : year + (year < 50 ? 2000 : 1900), monthFirst[1], monthFirst[3]);
    }
    const namedDayFirst = text.match(/^(\d{1,2})(?:st|nd|rd|th)?[-\s]+([a-z]+)\.?[-\s,]+(\d{4})$/i);
    const namedMonthFirst = text.match(/^([a-z]+)\.?[-\s]+(\d{1,2})(?:st|nd|rd|th)?[-\s,]+(\d{4})$/i);
    if (!namedDayFirst && !namedMonthFirst) return raw;
    const [, day, name, year] = namedDayFirst || [null, namedMonthFirst[2], namedMonthFirst[1], namedMonthFirst[3]];
    const names = ["january", "february", "march", "april", "may", "june", "july", "august", "september", "october", "november", "december"];
    const monthName = name.toLowerCase().replace(/^sept$/, "sep");
    const month = names.findIndex(full => full === monthName || full.slice(0, 3) === monthName) + 1;
    const date = iso(year, month, day);
    return projectCalendarDate(date) ? date : raw;
}

function importRowPayload(row) {
    const payload = {};
    for (const key of Object.keys(importPreviewAliases)) {
        const value = importPreviewValue(row, key);
        if (value === "") continue;
        const typed = importDateFields.includes(key) || importMoneyFields.includes(key) || importDayFields.includes(key);
        const text = String(value).trim();
        const losingBidStatus = key.startsWith("losingBidAmount") && text.toUpperCase() === "N/A";
        if (typed && !losingBidStatus && /^(?:-|n\/?a)$/i.test(text)) continue;
        payload[key] = importDateFields.includes(key) ? parseImportDate(value) : String(value);
        if (key === "title" || key === "projectNo") payload[key] = payload[key].trim();
    }
    if (payload.statusMode) payload.statusMode=String(payload.statusMode).trim().toLowerCase().replace(/^auto$/, 'automatic');
    else if (payload.status) payload.statusMode='manual';
    return normalizeProjectRevisionPayload(payload);
}

function importRowNumber(row, index) {
    return Number.isInteger(row.__rowNum__) ? row.__rowNum__ + 1 : index + 2;
}

function importFieldLabel(field) {
    const labels = {
        projectNo: "Project no.", approvedBudget: "Approved budget",
        referenceNo: "Reference no.", preInspection: "Pre-inspection date", finalInspection: "Final inspection date",
        ntpDate: "Notice to Proceed date", noa: "Notice of Award date", swa: "SWA date"
    };
    return labels[field] || importPreviewAliases[field][0];
}

function importCalendarKey() {
    const { nonWorkingDays, excludeHolidays, years, dateOverrides } = window.projectHolidayCalendar || {};
    return JSON.stringify([nonWorkingDays, excludeHolidays, years, dateOverrides]);
}

function adjustImportDates(payload, previous) {
    const adjustments = [];
    const issues = [];
    if (window.projectHolidayCalendar) {
        for (const field of importDateFields) {
            const from = payload[field];
            if (from === previous?.[field]) continue;
            const date = projectCalendarDate(from);
            if (!date || isProjectWorkday(date)) continue;
            const to = projectWeekday(date).toISOString().split("T")[0];
            if (!projectCalendarDate(to)) {
                issues.push({ field, message: "The next working date is outside the supported range." });
                continue;
            }
            payload[field] = to;
            adjustments.push({ field, from, to });
        }
    }
    return { adjustments, issues };
}

function applyCalculatedImportEnd(payload, previous, adjustments) {
    const adjustment = adjustments.find(({ field }) => field === "contractEndDate");
    if (!adjustment) return;
    const project = { ...previous, ...payload };
    if (!projectCalendarDate(project.contractRef) || parseContractDuration(project.contractDuration) === null) return;
    const { contractDueDate } = getProjectMilestoneDates(project);
    if (!projectCalendarDate(contractDueDate)) return;
    payload.contractEndDate = contractDueDate;
    adjustment.to = contractDueDate;
    adjustment.calculated = true;
}

function reviewImportRows() {
    const usedNumbers = new Set([
        ...importPreviewExistingProjects.map(project => project.projectNo),
        ...importPreviewRows.map(row => importPreviewValue(row, "projectNo"))
    ].map(importPreviewIdentity).filter(Boolean));
    let nextNumber = Math.max(importServerNextProjectNumber, getNextProjectNumber([...usedNumbers].map(projectNo => ({ projectNo }))));
    const titles = new Set();
    const numbers = new Set();
    importPreviewAssignedNumbers = new Map();

    return importPreviewRows.map((row, index) => {
        const payload = importRowPayload(row);
        const matchInfo = importPreviewMatch(row);
        const match = matchInfo.match;
        if (importPreviewChoices.get(index)?.existingId !== match?.id) importPreviewChoices.delete(index);
        if (match && !importPreviewChoices.has(index)) importPreviewChoices.set(index, { action: "new", existingId: match.id });
        const action = importPreviewChoices.get(index)?.action || "new";
        const previous = action === "overwrite" ? match : null;
        const { adjustments, issues: dateIssues } = adjustImportDates(payload, previous);
        const issues = [...importFieldIssues(payload), ...dateIssues];
        const title = importPreviewIdentity(payload.title);
        const number = importPreviewIdentity(payload.projectNo);
        if (title && titles.has(title)) issues.push({ field: "title", message: "Project title is repeated in this file." });
        if (number && numbers.has(number)) issues.push({ field: "projectNo", message: "Project number is repeated in this file." });
        if (title) titles.add(title);
        if (number) numbers.add(number);
        if (matchInfo.problem) issues.push({ field: "projectNo", message: matchInfo.problem });
        const warnings = issues.filter(issue => issue.omit);
        for (const { field } of warnings) delete payload[field];
        applyCalculatedImportEnd(payload, previous, adjustments);
        const merged = {...previous,...payload};
        for (const [field,required,message] of [['finalInspection','preInspection','Record Pre-Inspection before entering Final Inspection.'],['completion','finalInspection','Record Final Inspection before entering Completion Date.']]) {
            if (projectCalendarDate(merged[field]) && !projectCalendarDate(merged[required]) && !(merged[field] === previous?.[field] && (merged[required] || '') === (previous?.[required] || ''))) issues.push({field,message});
        }
        const blockers = issues.filter(issue => !issue.omit);
        let assigned = "";
        if (match && !blockers.length && action === "new") {
            while (usedNumbers.has(String(nextNumber))) nextNumber++;
            assigned = String(nextNumber++);
            usedNumbers.add(assigned);
            importPreviewAssignedNumbers.set(index, assigned);
        }
        return { row, index, payload, match, action, assigned, previous, warnings, blockers, adjustments };
    });
}

function renderImportEditor(entry) {
    const { row, index, warnings, blockers } = entry;
    const issues = [...blockers, ...warnings];
    const identity = ["title", "projectNo"];
    const problemFields = [...new Set(issues.map(issue => issue.field))];
    const showIdentity = !problemFields.length || problemFields.some(field => identity.includes(field));
    const fields = showIdentity ? [...new Set([...identity, ...problemFields])] : problemFields;
    const moreFields = Object.keys(importPreviewAliases).filter(field =>
        !fields.includes(field) && (identity.includes(field) ||
            [field, ...importPreviewAliases[field]].some(key => Object.hasOwn(row, key))));
    const input = field => {
        const raw = importPreviewValue(row, field);
        const issuesForField = issues.filter(issue => issue.field === field);
        const value = importDateFields.includes(field) && !issuesForField.length ? parseImportDate(raw) : raw;
        const id = `importField-${index}-${field}`;
        const isTextArea = field === "title" || field === "remarks" || String(value).includes("\n");
        const attrs = `id="${id}" name="${field}" class="form-control" ${issuesForField.length ? `aria-describedby="${id}-hint"` : ""}`;
        const editor = isTextArea
            ? `<textarea ${attrs} rows="2">${escapeHtml(value)}</textarea>`
            : `<input ${attrs} value="${escapeHtml(value)}" ${importDateFields.includes(field) ? 'placeholder="YYYY-MM-DD"' : ''}>`;
        return `<div class="import-field ${isTextArea ? 'import-field-wide' : ''}"><label for="${id}">${escapeHtml(importFieldLabel(field))}</label>${editor}${issuesForField.length ? `<small id="${id}-hint">${escapeHtml(issuesForField.map(issue => issue.message).join(" "))}</small>` : ""}</div>`;
    };
    return `<form class="import-preview-editor" data-edit-form="${index}">
        <div class="import-field-grid">${fields.map(input).join("")}</div>
        ${moreFields.length ? `<details class="import-more-fields"><summary>More fields</summary><div class="import-field-grid">${moreFields.map(input).join("")}</div></details>` : ""}
        <div class="import-editor-actions"><button type="button" class="btn btn-sm btn-outline-secondary" data-cancel-edit>Cancel edit</button><button type="submit" class="btn btn-sm btn-primary">Apply changes</button></div>
    </form>`;
}

function renderImportRow(entry) {
    const { row, index, payload, match, action, assigned, previous, warnings, blockers, adjustments } = entry;
    const editing = index === importPreviewEditing;
    const issueLabels = warnings.map(({ field }) => importFieldLabel(field)).join(", ");
    const badge = blockers.length ? "Fix to import" : warnings.length ? (previous ? "Keep saved fields" : "Leave fields blank") : match ? "Existing match" : "Ready";
    const state = blockers.length ? "blocked" : warnings.length || match ? "review" : "ready";
    const rowNumber = importRowNumber(row, index);
    return `<li class="import-preview-row">
        <div class="import-row-heading">
            <div class="import-row-project">
                <strong>${escapeHtml(payload.title || "Missing project title")}</strong>
                <small>Row ${rowNumber}${payload.projectNo ? ` · Project no. ${escapeHtml(payload.projectNo)}` : ""}</small>
            </div>
            <span class="import-preview-badge is-${state}">${badge}</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-edit-row="${index}" aria-label="Edit row ${rowNumber}" ${importPreviewEditing !== null ? "disabled" : ""}>Edit</button>
        </div>
        ${blockers.length ? `<p class="import-row-note is-blocked">${escapeHtml(blockers.map(issue => `${importFieldLabel(issue.field)}. ${issue.message}`).join(" "))}</p>` : ""}
        ${warnings.length ? `<p class="import-row-note">${escapeHtml(issueLabels)}</p>` : ""}
        ${adjustments.length ? `<details class="import-date-adjustments">
            <summary>${adjustments.length} ${adjustments.length === 1 ? "date adjusted" : "dates adjusted"}</summary>
            <ul>${adjustments.map(({ field, from, to, calculated }) => `<li>${escapeHtml(importFieldLabel(field))} · ${escapeHtml(from)} → ${escapeHtml(to)}${calculated ? " (calculated from start date and duration)" : ""}</li>`).join("")}</ul>
        </details>` : ""}
        ${match ? `<div class="import-match">
            <label for="importChoice${index}">Matches ${escapeHtml(match.projectNo ? `#${match.projectNo} · ` : "")}${escapeHtml(match.title || "Saved project")}</label>
            <select id="importChoice${index}" class="form-select form-select-sm" data-choice-row="${index}" ${importPreviewEditing !== null ? "disabled" : ""}>
                <option value="new" ${action === "new" ? "selected" : ""}>Add as a new project</option>
                <option value="overwrite" ${action === "overwrite" ? "selected" : ""}>Update existing project</option>
            </select>
            ${assigned ? `<small>New project no. ${escapeHtml(assigned)}</small>` : ""}
        </div>` : ""}
        ${editing ? renderImportEditor(entry) : ""}
    </li>`;
}

function renderImportPreview() {
    importPreviewCalendarKey = importCalendarKey();
    const list = document.getElementById("importPreviewList");
    const summary = document.getElementById("importPreviewSummary");
    const entries = reviewImportRows();
    const review = entries.filter(entry => entry.match || entry.warnings.length || entry.blockers.length);
    const blocked = entries.filter(entry => entry.blockers.length).length;
    const warnings = entries.reduce((sum, entry) => sum + entry.warnings.length, 0);
    const adjusted = entries.reduce((sum, entry) => sum + entry.adjustments.length, 0);
    const rowLabel = count => `${count} ${count === 1 ? "row" : "rows"}`;
    const isReview = importPreviewFilter === "review";
    const filtered = isReview ? review : entries;
    const scroller = list.parentElement;
    const scrollTop = scroller.scrollTop;
    list.innerHTML = filtered.map(renderImportRow).join("");
    scroller.scrollTop = scrollTop;
    document.getElementById("importPreviewEmpty").hidden = filtered.length > 0;
    summary.innerHTML = `<strong>${rowLabel(entries.length)}</strong> in file <span>·</span> <strong>${entries.length - blocked}</strong> can import`;
    const note = document.getElementById("importPreviewNote");
    const notices = [];
    if (blocked) notices.push(`Fix ${rowLabel(blocked)} marked “Fix to import” before continuing.`);
    if (adjusted) notices.push(`${adjusted} ${adjusted === 1 ? "date adjusted" : "dates adjusted"} using the working-day calendar. See All rows for the original and adjusted dates.`);
    if (warnings) notices.push(`${warnings} ${warnings === 1 ? "cell has an invalid format" : "cells have invalid formats"}. New projects will leave those fields blank. Updates will keep saved values.`);
    note.textContent = notices.join(" ");
    note.hidden = !note.textContent;
    for (const button of document.querySelectorAll("[data-import-filter]")) {
        const active = button.dataset.importFilter === importPreviewFilter;
        button.setAttribute("aria-pressed", String(active));
        button.disabled = importPreviewEditing !== null;
        if (button.dataset.importFilter === "review") button.textContent = `Needs review (${review.length})`;
    }
    const button = document.getElementById("continueImportPreviewButton");
    button.textContent = `Import ${entries.length} ${entries.length === 1 ? "project" : "projects"}`;
    button.disabled = !entries.length || blocked > 0 || importPreviewEditing !== null;
}

async function showImportPreview(rows) {
    if (!db) {
        showSystemNotification("Project records are not ready. Please reload and try again.");
        return;
    }
    try {
        await db.refresh();
        const sequence = await apiClient.getNextProjectNumber();
        importServerNextProjectNumber = Number(sequence.nextProjectNumber) || 1;
    } catch (error) {
        showSystemNotification(error.message || "Could not refresh projects before checking duplicates.");
        return;
    }
    const request = db.transaction(["projects"], "readonly").objectStore("projects").getAll();
    request.onsuccess = event => {
        importPreviewRows = rows;
        importPreviewExistingProjects = event.target.result || [];
        importPreviewChoices = new Map();
        importPreviewEditing = null;
        importPreviewFilter = reviewImportRows().some(entry => entry.match || entry.warnings.length || entry.blockers.length) ? "review" : "all";
        renderImportPreview();
        document.getElementById("importPreviewList").parentElement.scrollTop = 0;
        bootstrap.Modal.getOrCreateInstance(document.getElementById("importPreviewModal")).show();
    };
    request.onerror = () => showSystemNotification("Could not check existing projects for duplicates.");
}

function cancelImportPreview() {
    bootstrap.Modal.getInstance(document.getElementById("importPreviewModal"))?.hide();
    importPreviewRows = [];
    importPreviewExistingProjects = [];
    importPreviewChoices = new Map();
    importPreviewAssignedNumbers = new Map();
    importPreviewEditing = null;
    importPreviewCalendarKey = null;
}

function continueImportPreview() {
    if (!importPreviewRows.length || importPreviewEditing !== null) return;
    if (importPreviewCalendarKey !== null && importPreviewCalendarKey !== importCalendarKey()) {
        renderImportPreview();
        showSystemNotification("The working-day calendar changed. Review the updated dates before importing.");
        return;
    }
    const entries = reviewImportRows();
    if (entries.some(entry => entry.blockers.length)) return;
    const choices = Object.fromEntries([...importPreviewChoices].map(([index, choice]) => [
        index, { ...choice, assignedProjectNo: importPreviewAssignedNumbers.get(index) || "" }
    ]));
    const rows = entries.map(({ row, payload }) => {
        Object.defineProperty(payload, "__rowNum__", { value: row.__rowNum__ });
        return payload;
    });
    cancelImportPreview();
    processImportedRows(rows, choices);
}

function closeImportEditor() {
    const index = importPreviewEditing;
    importPreviewEditing = null;
    renderImportPreview();
    const focus = document.querySelector(`#importPreviewList [data-edit-row="${index}"]`) ||
        document.querySelector("[data-import-filter][aria-pressed=true]");
    focus?.focus();
}

document.addEventListener("click", event => {
    const filter = event.target.closest("[data-import-filter]");
    if (filter) {
        importPreviewFilter = filter.dataset.importFilter;
        renderImportPreview();
        document.getElementById("importPreviewList").parentElement.scrollTop = 0;
    }
    const edit = event.target.closest("#importPreviewList [data-edit-row]");
    if (edit) {
        importPreviewEditing = Number(edit.dataset.editRow);
        renderImportPreview();
        const form = document.querySelector("#importPreviewList [data-edit-form]");
        form.scrollIntoView({ block: "nearest" });
        form.querySelector(".form-control").focus({ preventScroll: true });
    }
    if (event.target.closest("#importPreviewList [data-cancel-edit]")) closeImportEditor();
});

document.addEventListener("submit", event => {
    if (!event.target.matches("#importPreviewList [data-edit-form]")) return;
    event.preventDefault();
    const row = importPreviewRows[Number(event.target.dataset.editForm)];
    for (const [field, value] of new FormData(event.target)) {
        for (const alias of importPreviewAliases[field]) delete row[alias];
        row[field] = value;
    }
    closeImportEditor();
});

document.addEventListener("change", event => {
    const choice = event.target.closest("#importPreviewList select[data-choice-row]");
    if (!choice) return;
    const rowIndex = Number(choice.dataset.choiceRow);
    const match = importPreviewMatch(importPreviewRows[rowIndex]).match;
    if (match) importPreviewChoices.set(rowIndex, { action: choice.value, existingId: match.id });
    renderImportPreview();
    document.getElementById(choice.id)?.focus({ preventScroll: true });
});


function assignFreshImportProjectNumber(payload) {
    while (importProjectNumbersInUse.has(String(nextImportProjectNumber))) nextImportProjectNumber++;
    payload.projectNo = String(nextImportProjectNumber++);
    payload.autoProjectNumber = true;
    importProjectNumbersInUse.add(payload.projectNo);
}
function importFromExcel() {
    if (!window.currentUser?.canImport) {
        showSystemNotification("You do not have permission to import projects.");
        return;
    }

    importIntoArchive = activeWorkspace === "archive";
    bootstrap.Modal.getOrCreateInstance(document.getElementById("importGuideModal")).show();
}


/**
 * Connect the hidden Excel input to the
 * import processing handler.
 */
document.addEventListener(
    "DOMContentLoaded",
    () => {

        const fileInput =
            document.getElementById(
                "excelImportInput"
            ) ||
            document.getElementById(
                "importExcelInput"
            );


        if (!fileInput) {
            return;
        }


        fileInput.onchange =
            event => {

                handleExcelImport(
                    event
                );
            };
    }
);


/* ==========================================================================
   EXCEL FILE READER
   ========================================================================== */


function handleExcelImport(event) {
    if (!window.currentUser?.canImport) {
        event.target.value = "";
        showSystemNotification("You do not have permission to import projects.");
        return;
    }

    const file =
        event.target.files[0];


    if (!file) {
        return;
    }


    const loader =
        document.getElementById(
            "loadingOverlay"
        );


    if (loader) {

        const loaderText =
            loader.querySelector(
                "h4"
            );


        if (loaderText) {

            loaderText.innerText =
                "Reading spreadsheet…";
        }


        loader.classList.add(
            "active"
        );
    }


    const reader =
        new FileReader();


    reader.onload = event => {

        try {

            const data =
                new Uint8Array(
                    event.target.result
                );


            const workbook =
                XLSX.read(
                    data,
                    {
                        type: "array", cellNF: true
                    }
                );


            const firstSheetName =
                workbook.SheetNames[0];


            const worksheet =
                workbook.Sheets[
                    firstSheetName
                ];


            const rawRows =
                XLSX.utils.sheet_to_json(
                    worksheet,
                    {
                        defval: ""
                    }
                );


            if (
                rawRows.length === 0
            ) {

                throw new Error(
                    "Spreadsheet contains zero valid rows inside its primary worksheet."
                );
            }


            const importRows = normalizeWorkbookRows(prepareWorkbookTypedRows(rawRows, worksheet), workbook.Workbook?.WBProps?.date1904 === true);
            if (loader) loader.classList.remove("active");
            showImportPreview(importRows);

        } catch (error) {

            if (loader) {
                loader.classList.remove(
                    "active"
                );
            }


            alert(
                `Import Interruption: ${error.message}`
            );


            console.error(
                error
            );

        } finally {

            event.target.value =
                "";
        }
    };


    reader.onerror = event => {

        if (loader) {
            loader.classList.remove(
                "active"
            );
        }


        console.error(
            "Unable to read Excel file:",
            event.target.error
        );


        alert(
            "The selected Excel file could not be read."
        );
    };


    reader.readAsArrayBuffer(
        file
    );
}


/* ========================================================================== 
   IMPORT ROW PROCESSING
   ========================================================================== */

const importDateFields = ["materialRequestDate", "preProcurementDate", "preBidDate", "biddingDate", "prDate", "obre", "resolutionDate", "noticePostQual", "noa", "noaDueDate", "performanceBond", "contractRef", "ntpDate", "dole", "contractEndDate", "preInspection", "materialTestingDate", "finalInspection", "swa", "ldNewCompletionDate", "completion"];
const importMoneyFields = ["approvedBudget", "bidAmount", "materialTestingAmount", "losingBidAmount1", "losingBidAmount2"];

const importDayFields = ["contractDuration", "ldActualDuration"];
const importTitleLimit = 400;

function importFieldIssues(payload) {
    const issues = [];
    const title = String(payload.title ?? "").trim();
    if (!title) issues.push({ field: "title", message: "Project title is required." });
    if ([...title].length > importTitleLimit) issues.push({ field: "title", message: `Use up to ${importTitleLimit} characters.` });
    if ([...String(payload.projectNo ?? "")].length > 60) issues.push({ field: "projectNo", message: "Use up to 60 characters." });

    const values = { ...payload };
    for (const field of importDateFields) {
        if (values[field] && !projectCalendarDate(values[field])) {
            issues.push({ field, message: "Invalid date. Use YYYY-MM-DD.", omit: true });
            delete values[field];
        }
    }
    for (const reason of validateProjectNumbersAndActualDates(values)) {
        const separator = reason.indexOf(": ");
        const field = reason.slice(0, separator);
        const money = importMoneyFields.includes(field);
        const detail = reason.slice(separator + 2).replace(/^\w/, letter => letter.toUpperCase());
        const message = money ? `Invalid monetary value. ${detail}` : detail;
        issues.push({ field, message, omit: money || importDayFields.includes(field) });
    }
    return issues;
}

function validateImportedProject(payload, rowNumber) {
    return importFieldIssues(payload).map(({ field, message }) => ({
        row: rowNumber, projectNo: payload.projectNo || "", title: payload.title || "",
        message: field === "title" ? message : `${importFieldLabel(field)}. ${message}`
    }));
}

function processImportedRows(rows, previewChoices = {}) {
    const transaction =
        db.transaction(
            ["projects"],
            "readonly"
        );


    const store =
        transaction.objectStore(
            "projects"
        );


    const getAllRequest =
    store.getAll();


getAllRequest.onsuccess =
    event => {

            const existingProjects =
                event.target.result || [];


            pendingImportPayloads = [];
            bulkImportConflictsQueue = [];
            bulkImportErrors = [];
            activeImportBatchId = "";
            const importedTitles = new Set();
            const importedProjectNumbers = new Set();

            rows = rows.map(row => normalizeWorkbookRows([row])[0]);
            rows.forEach(
                (row, index) => {
                    const rowNumber = importRowNumber(row, index);
                    // Blank and omitted fields must never erase an existing value.
                    const payload = importRowPayload(row);

                    /* =====================================================
                       NORMALIZE STATUS VALUES
                       ===================================================== */

                    if (
                        payload.status
                            ?.toLowerCase() ===
                        "on going bidding"
                    ) {

                        payload.status =
                            "On-going Bidding";
                    }


                    if (
                        payload.status
                            ?.toLowerCase() ===
                        "pre inspection"
                    ) {

                        payload.status =
                            "Pre-inspection";
                    }


                    if (
                        payload.status
                            ?.toLowerCase() ===
                        "final inspection"
                    ) {

                        payload.status =
                            "Final-inspection";
                    }


                    const assignedProjectNo = previewChoices[index]?.action === "new"
                        ? String(previewChoices[index]?.assignedProjectNo || "").trim() : "";
                    if (assignedProjectNo) {
                        const originalProjectNo = String(payload.projectNo || "").trim();
                        payload.projectNo = assignedProjectNo;
                        payload.autoProjectNumber = true;
                        if (originalProjectNo && originalProjectNo !== assignedProjectNo) {
                            payload.sourceProjectNo = originalProjectNo;
                            payload.remarks = [payload.remarks, `Imported project no.: ${originalProjectNo}`]
                                .filter(Boolean).join("\n");
                        }
                    }

                    payload.updatedAt =
                        new Date()
                            .toISOString();

                    const rowErrors = validateImportedProject(payload, rowNumber);
                    const normalizedTitle = String(payload.title || "").trim().toLowerCase().replace(/\s+/g, " ");
                    const normalizedProjectNo = String(payload.projectNo || "").trim().toLowerCase();
                    if (importedTitles.has(normalizedTitle)) rowErrors.push({ row: rowNumber, projectNo: payload.projectNo || "", title: payload.title || "", message: "Duplicate title within spreadsheet" });
                    if (normalizedProjectNo && importedProjectNumbers.has(normalizedProjectNo)) rowErrors.push({ row: rowNumber, projectNo: payload.projectNo || "", title: payload.title || "", message: "Duplicate project number within spreadsheet" });
                    importedTitles.add(normalizedTitle);
                    if (normalizedProjectNo) importedProjectNumbers.add(normalizedProjectNo);
                    if (rowErrors.length) {
                        bulkImportErrors.push(...rowErrors);
                        return;
                    }


                    /* Existing project numbers are unique across active and archived records. */
                    const { match, numberMatch, problem } = importPreviewMatch(payload, existingProjects);
                    if (problem) {
                        bulkImportErrors.push({ row: rowNumber, projectNo: payload.projectNo || "", title: payload.title || "", message: problem });
                        return;
                    }

                    if (match) {

                        bulkImportConflictsQueue.push({

                            index,

                            existingId:
                                match.id,
                            existingTitle: match.title || "Untitled project",
                            existingProjectNo: match.projectNo || "",
                            numberConflict: numberMatch,
                            previewAction: previewChoices[index]?.existingId === match.id
                                && ["new", "overwrite"].includes(previewChoices[index]?.action)
                                ? previewChoices[index].action : null,

                            existingCreatedAt:
                                match.createdAt,
                            existingUpdatedAt: match.updatedAt || '',

                            existingTimelineCompletedMarked:
                                match.timelineCompletedMarked,

                            existingTimelineAlertsCompleted:
                                Array.isArray(
                                    match.timelineAlertsCompleted
                                )
                                    ? match.timelineAlertsCompleted
                                    : [],

                            payload
                        });

                    } else {

                        pendingImportPayloads.push(
                            payload
                        );
                    }
                }
            );


            const allProjectsForNumbers = [
                ...existingProjects,
                ...pendingImportPayloads,
                ...bulkImportConflictsQueue.map(item => item.payload)
            ];
            importProjectNumbersInUse = new Set(
                allProjectsForNumbers.map(project => String(project.projectNo || "").trim()).filter(Boolean)
            );
            nextImportProjectNumber = Math.max(importServerNextProjectNumber, getNextProjectNumber(allProjectsForNumbers));
            pendingImportPayloads
                .filter(payload => !String(payload.projectNo || "").trim())
                .forEach(assignFreshImportProjectNumber);

            const loader =
                document.getElementById(
                    "loadingOverlay"
                );


            if (
                bulkImportConflictsQueue.length >
                0
            ) {

                if (loader) {
                    loader.classList.remove(
                        "active"
                    );
                }


                if (bulkImportConflictsQueue.every(item => item.previewAction)) {
                    executeBulkImportProcessing(true);
                } else {
                    launchConflictResolutionModal();
                }

            } else {

                executeFinalDatabaseWriteTransaction();
            }
        };


    getAllRequest.onerror = event => {

        console.error(
            "Unable to read existing projects before import:",
            event.target.error
        );
    };
}


/* ==========================================================================
   IMPORT CONFLICT MODAL
   ========================================================================== */


function launchConflictResolutionModal() {

    const listContainer =
        document.getElementById(
            "conflictItemsList"
        );


    if (!listContainer) {
        return;
    }


    let html = "";


    bulkImportConflictsQueue
        .forEach(
            (item, index) => {

                html += `
                    <li
                        class="list-group-item p-3 border-bottom"
                    >

                        <div
                            class="import-conflict-row"
                        >

                            <div class="flex-grow-1">

                                <div class="import-conflict-title">
                                    ${escapeHtml(item.payload.title)}
                                </div>
                                <div class="import-conflict-meta">
                                    Existing: ${escapeHtml(item.existingTitle)}
                                    (Project no. ${escapeHtml(item.existingProjectNo || "—")})
                                </div>

                                <div class="import-conflict-meta">
                                    <strong>
                                        Imported row:
                                    </strong>

                                    Bidder:
                                    ${
                                        escapeHtml(
                                            item.payload.winningBidder ||
                                            "N/A"
                                        )
                                    }

                                    |

                                    Budget:
                                    ${
                                        escapeHtml(
                                            item.payload.approvedBudget ||
                                            "N/A"
                                        )
                                    }
                                </div>

                            </div>


                            <div class="import-conflict-action">

                                <select
                                    class="form-select form-select-sm conflict-action-select"
                                    data-index="${index}"
                                >
                                    <option value="new" ${item.previewAction !== "overwrite" ? "selected" : ""}>
                                        ${item.numberConflict ? "Add as new project (assign new number)" : "Add as new project"}
                                    </option>

                                    <option value="overwrite" ${item.previewAction === "overwrite" ? "selected" : ""}>
                                        Overwrite existing project
                                    </option>
                                </select>

                            </div>

                        </div>

                    </li>
                `;
            }
        );


    listContainer.innerHTML =
        html;

    const summary = document.getElementById("importConflictSummary");
    if (summary) summary.textContent = `${bulkImportConflictsQueue.length} project title or number match${bulkImportConflictsQueue.length === 1 ? "" : "es"} found. New projects receive a fresh number when needed. ${bulkImportErrors.length} invalid row${bulkImportErrors.length === 1 ? "" : "s"} must be corrected before importing.`;


    const modalNode =
        document.getElementById(
            "importConflictModal"
        );


    if (modalNode) {

        const modal =
            bootstrap.Modal
                .getOrCreateInstance(
                    modalNode
                );


        modal.show();
    }
}


/**
 * Convenience button used by the conflict modal.
 */
function overwriteAllConflicts(
    shouldOverwrite
) {

    const selects =
        document.querySelectorAll(
            ".conflict-action-select"
        );


    selects.forEach(select => {

        select.value =
            shouldOverwrite
                ? "overwrite"
                : "new";
    });
}


/**
 * Aborts the current Excel import.
 */
function cancelBulkImportProgress() {

    pendingImportPayloads = [];

    bulkImportConflictsQueue = [];
    bulkImportErrors = [];


    const modalNode =
        document.getElementById(
            "importConflictModal"
        );


    if (modalNode) {

        const modalInstance =
            bootstrap.Modal
                .getInstance(
                    modalNode
                );


        if (modalInstance) {
            modalInstance.hide();
        }
    }


    showSystemNotification(
        "Excel spreadsheet import workflow canceled."
    );
}


/* ==========================================================================
   CONFLICT PROCESSING
   ========================================================================== */


function executeBulkImportProcessing(usePreviewActions = false) {

    const modalNode =
        document.getElementById(
            "importConflictModal"
        );


    if (modalNode) {

        const modalInstance =
            bootstrap.Modal
                .getInstance(
                    modalNode
                );


        if (modalInstance) {
            modalInstance.hide();
        }
    }


    const loader =
        document.getElementById(
            "loadingOverlay"
        );


    if (loader) {
        loader.classList.add(
            "active"
        );
    }


    bulkImportConflictsQueue
        .forEach(
            (item, index) => {

                const select =
                    document.querySelector(
                        `.conflict-action-select[data-index="${index}"]`
                    );


                const action = usePreviewActions
                    ? item.previewAction
                    : (select ? select.value : "new");


                if (
                    action ===
                    "overwrite"
                ) {

                    /*
                     * Existing project is updated
                     * using its original IndexedDB ID.
                     */
                    item.payload.id =
                        item.existingId;
                    item.payload.updatedAt = item.existingUpdatedAt || ''; 


                    item.payload.createdAt =
                        item.existingCreatedAt ||
                        new Date()
                            .toISOString();


                    item.payload.timelineCompletedMarked =
                        item.existingTimelineCompletedMarked ||
                        false;


                    item.payload.timelineAlertsCompleted =
                        Array.isArray(
                            item.existingTimelineAlertsCompleted
                        )
                            ? item.existingTimelineAlertsCompleted
                            : [];


                    pendingImportPayloads.push(
                        item.payload
                    );

                } else {
                    if (item.numberConflict) {
                        const originalNumber = item.payload.projectNo;
                        item.payload.sourceProjectNo = originalNumber;
                        item.payload.remarks = [item.payload.remarks, "Imported project no.: " + originalNumber]
                            .filter(Boolean).join("\n");
                    }
                    if (item.numberConflict || !String(item.payload.projectNo || "").trim()) assignFreshImportProjectNumber(item.payload);

                    item.payload.createdAt =
                        new Date()
                            .toISOString();


                    item.payload.timelineCompletedMarked =
                        false;


                    item.payload.timelineAlertsCompleted =
                        [];


                    pendingImportPayloads.push(
                        item.payload
                    );
                }
            }
        );


    setTimeout(
        executeFinalDatabaseWriteTransaction,
        100
    );
}


/* ==========================================================================
   FINAL DATABASE WRITE
   ========================================================================== */


async function executeFinalDatabaseWriteTransaction() {
    activeImportBatchId = "";
    if (bulkImportErrors.length) {
        window.importInProgress = false;
        document.getElementById('loadingOverlay')?.classList.remove('active');
        showImportProgress(0, pendingImportPayloads.length, bulkImportErrors.length, 'Correct all invalid rows before importing. No projects were saved.', true);
        showSystemNotification('The file contains invalid rows. Correct them in the preview or spreadsheet and try again. No projects were saved.');
        return;
    }

    if (
        pendingImportPayloads.length ===
        0
    ) {

        const loader =
            document.getElementById(
                "loadingOverlay"
            );


        if (loader) {
            loader.classList.remove(
                "active"
            );
        }

        showImportProgress(0, 0, bulkImportErrors.length, bulkImportErrors.length ? "No valid rows to import" : "Nothing to import", true);
        return;
    }


    const importCount = pendingImportPayloads.length;
    const batchSize = 500;
    activeImportBatchId = `import_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
    const initialLoader = document.getElementById("loadingOverlay");
    if (initialLoader) initialLoader.classList.remove("active");
    showImportProgress(0, importCount, bulkImportErrors.length, "Importing records…");


    window.importInProgress = true;
    let importCommitted = false;
    try {
        let imported = 0;
        for (let offset = 0; offset < pendingImportPayloads.length; offset += batchSize) {
            const records = pendingImportPayloads.slice(offset, offset + batchSize);
            const result = await apiClient.post("bulkSaveProjects", { records, batchId: activeImportBatchId, archive: importIntoArchive, chunkIndex: offset / batchSize, expectedRows: importCount });
            imported += result.count || records.length;
            showImportProgress(imported, importCount, bulkImportErrors.length, `Validating batch ${Math.floor(offset / batchSize) + 1} of ${Math.ceil(importCount / batchSize)}…`);
            await new Promise(resolve => setTimeout(resolve, 0));
        }
        await apiClient.post("completeImport", { batchId: activeImportBatchId });
        importCommitted = true;
        await db.refresh();
        window.importInProgress = false;

        const loader =
            document.getElementById(
                "loadingOverlay"
            );


        if (loader) {
            loader.classList.remove(
                "active"
            );
        }


        showImportProgress(importCount, importCount, bulkImportErrors.length, "Import complete", true);
        showSystemNotification(`Import complete: ${importCount} saved, ${bulkImportErrors.length} errors.`);


        pendingImportPayloads = [];

        bulkImportConflictsQueue = [];


        switchWorkspace(importIntoArchive ? "archive" : "documents");
        currentPage = 1;
        await loadServerProjectPage();
    } catch (error) {
        window.importInProgress = false;

        console.error(
            "Database batch write transaction failed:",
            error
        );


        const loader =
            document.getElementById(
                "loadingOverlay"
            );


        if (loader) {
            loader.classList.remove(
                "active"
            );
        }


        if (importCommitted) {
            showImportProgress(importCount, importCount, bulkImportErrors.length, "Import saved; refresh the page to reload records", true);
            showSystemNotification("The import was saved, but the page could not refresh. Reload before importing again.");
            pendingImportPayloads = [];
            return;
        }
        let rolledBack = false;
        try {
            await apiClient.post("rollbackImport", { batchId: activeImportBatchId });
            activeImportBatchId = "";
            rolledBack = true;
            await db.refresh();
        } catch (rollbackError) { console.error("Automatic import rollback failed:", rollbackError); }
        const status = rolledBack ? "Import failed and was rolled back" : "Import failed; rollback could not be confirmed";
        showImportProgress(0, importCount, bulkImportErrors.length + 1, status, true);
        bulkImportErrors.push({ row: "Batch", projectNo: "", title: "", message: error.message || "Batch write failed" });
        showSystemNotification(rolledBack ? (error.message || status) : "Some rows may remain saved. Reload and review the import before retrying.");
    }
}

function showImportProgress(processed, total, errors, status, finished = false) {
    const modalNode = document.getElementById("importProgressModal");
    if (!modalNode) return;
    const percent = total ? Math.round((processed / total) * 100) : 100;
    document.getElementById("importProgressStatus").textContent = status;
    document.getElementById("importProgressCount").textContent = `${processed} / ${total}`;
    document.getElementById("importErrorCount").textContent = String(errors);
    const bar = document.getElementById("importProgressBar");
    bar.style.width = `${percent}%`;
    bar.setAttribute("aria-valuenow", String(percent));
    document.getElementById("importProgressActions").classList.toggle("d-none", !finished);
    const errorButton = document.getElementById("downloadImportErrorsButton");
    if (errorButton) errorButton.disabled = errors === 0;
    const rollbackButton = document.getElementById("rollbackImportButton");
    if (rollbackButton) rollbackButton.disabled = !activeImportBatchId;
    bootstrap.Modal.getOrCreateInstance(modalNode).show();
}

function downloadImportErrorReport() {
    if (!bulkImportErrors.length) return;
    const escapeCsv = value => `"${safeCsvText(value).replace(/"/g, '""')}"`;
    const csv = ["Row,Project No.,Title,Error", ...bulkImportErrors.map(error => [error.row, error.projectNo, error.title, error.message].map(escapeCsv).join(","))].join("\r\n");
    const link = document.createElement("a");
    link.href = URL.createObjectURL(new Blob(["\ufeff", csv], { type: "text/csv;charset=utf-8" }));
    link.download = `import-errors-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

async function rollbackLastImport() {
    if (!activeImportBatchId || !confirm("Roll back every record created or overwritten by this import?")) return;
    try {
        const result = await apiClient.post("rollbackImport", { batchId: activeImportBatchId });
        await db.refresh();
        loadDocuments(true);
        document.getElementById("rollbackImportButton").disabled = true;
        showSystemNotification(`Import rolled back (${result.count || 0} changes reversed).`);
    } catch (error) { showSystemNotification(error.message || "Unable to roll back the import."); }
}
