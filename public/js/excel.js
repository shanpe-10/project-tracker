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

    if (window.currentUser?.canDeleteAll !== true) {
        showSystemNotification("You do not have permission to delete all records.");
        return;
    }

    if (
        !confirm(
            "WARNING: You are initiating a full database purge. This will wipe ALL project records and history channels forever. Proceed?"
        )
    ) {
        return;
    }


    const wantExport =
        confirm(
            "Backup Prompt:\n\n" +
            "Would you like to EXPORT and download your current data as an Excel file before wiping the system?\n\n" +
            "• Click OK (Yes) to download a backup copy and then purge.\n" +
            "• Click Cancel (No) to immediately wipe everything without saving."
        );


    if (wantExport) {

        try {

            exportToExcel();


            showSystemNotification(
                "Backup report generated and downloaded successfully."
            );

        } catch (exportError) {

            console.error(
                "Export failed during purge process:",
                exportError
            );


            const forceWipe =
                confirm(
                    "The Excel export failed or was interrupted. Do you still want to proceed with completely wiping the data without a backup?"
                );


            if (!forceWipe) {
                return;
            }
        }
    }


    const confirmationPhrase = prompt(
        "Final confirmation required. Type DELETE ALL to permanently remove every project and history record."
    );

    if (confirmationPhrase === "DELETE ALL") {
        executeClearDatabaseWithLoadingOverlay();
    } else if (confirmationPhrase !== null) {
        showSystemNotification("Database purge cancelled: confirmation phrase did not match.");
    }
}


/**
 * Clears the projects and history IndexedDB stores.
 */
function executeClearDatabaseWithLoadingOverlay() {

    const loader =
        document.getElementById(
            "loadingOverlay"
        );


    if (loader) {
        loader.classList.add(
            "active"
        );
    }


    setTimeout(() => {

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


        // Clearing projects also clears their history on the server.
        projectStore.clear();

        transaction.oncomplete = () => {

            setTimeout(() => {

                if (loader) {
                    loader.classList.remove(
                        "active"
                    );
                }


                alert(
                    "All records and history channels have been wiped completely."
                );


                editingId = null;

                currentPage = 1;


                resetFormAccess();

                loadDocuments();

            }, 600);
        };


        transaction.onerror = event => {

            if (loader) {
                loader.classList.remove(
                    "active"
                );
            }


            console.error(
                "Database purge failed:",
                event.target.error
            );


            alert(
                "The database could not be cleared."
            );
        };

    }, 200);
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
let importPreviewIsLdSheet = false;
let importPreviewExistingProjects = [];
let importPreviewChoices = new Map();
let importPreviewAssignedNumbers = new Map();

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
    projectNo: ["Project No."], title: ["Project Title", "2. Project Title"],
    biddingDate: ["Date of Bidding", "1. Date of Bidding"],
    approvedBudget: ["ABC", "3. ABC"], bidAmount: ["Bid Amount", "4. Bid Amount"],
    prDate: ["PR Date", "8. PR Date"], obre: ["OBRE", "9. OBRE", "9. OBRE Date"],
    resolutionDate: ["Resolution Date", "11. Resolution Date"],
    noticePostQual: ["Notice of Post Qualification", "12. Notice of Post Qualification"],
    noaDueDate: ["NOA Due Date"],
    noa: ["Notice of Award", "13. Notice of Award"],
    performanceBond: ["Performance Bond", "14. Performance Bond"],
    contractRef: ["Contract Date", "15. Contract Date"],
    ntpDate: ["Notice to Proceed", "17. Notice to Proceed"],
    dole: ["DOLE Clearance", "18. DOLE Clearance"],
    contractEndDate: ["Contract End Date", "19. Contract End Date"],
    materialTestingDate: ["Material Testing Date"],
    preInspection: ["Pre Inspection", "20. Pre Inspection"],
    finalInspection: ["Final Inspection", "21. Final Inspection"],
    swa: ["SWA", "22. SWA"], ldAmount: ["LD Amount", "23. LD Amount"],
    ldNewCompletionDate: ["Extended Completion Date", "23. New Extended Completion Date"],
    completion: ["Completion Date", "24. Completion Date"],
    status: ["Operational Status", "25. Operational Status"]
};

function importPreviewValue(row, field) {
    for (const key of [field, ...(importPreviewAliases[field] || [])]) {
        if (row[key] !== undefined && row[key] !== null && String(row[key]).trim() !== "") return row[key];
    }
    return "";
}

function importPreviewKey(row, field) {
    const keys = [field, ...(importPreviewAliases[field] || [])];
    return keys.find(key => Object.hasOwn(row, key) && String(row[key] ?? "").trim() !== "")
        || keys.find(key => Object.hasOwn(row, key)) || field;
}

function importPreviewIdentity(value) {
    return String(value || "").trim().toLowerCase().replace(/\s+/g, " ");
}

function importPreviewMatch(row) {
    const number = importPreviewIdentity(importPreviewValue(row, "projectNo"));
    const title = importPreviewIdentity(importPreviewValue(row, "title"));
    const numberMatch = number && importPreviewExistingProjects.find(project => importPreviewIdentity(project.projectNo) === number);
    const titleMatch = title && importPreviewExistingProjects.find(project => importPreviewIdentity(project.title) === title);
    return {
        match: numberMatch || titleMatch || null,
        numberMatch: numberMatch || null,
        titleMatch: titleMatch || null,
        conflictingMatches: Boolean(numberMatch && titleMatch && numberMatch.id !== titleMatch.id)
    };
}

function importPreviewErrors(row, rowNumber) {
    const errors = [];
    const title = String(importPreviewValue(row, "title")).trim();
    if (!title) errors.push("Project title is required");
    for (const field of importDateFields) {
        const value = importPreviewValue(row, field);
        if (value === "" || value === "-" || /^n\/?a$/i.test(String(value))) continue;
        if (typeof value === "number" && XLSX.SSF.parse_date_code(value)) continue;
        const raw = String(value).trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
            if (Number.isNaN(new Date(raw).getTime())) errors.push(`${field}: invalid date`);
            continue;
        }
        const [year, month, day] = raw.split("-").map(Number);
        const date = new Date(Date.UTC(year, month - 1, day));
        if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) errors.push(`${field}: invalid date`);
    }
    for (const field of importMoneyFields) {
        const raw = String(importPreviewValue(row, field)).trim();
        if (!raw || raw === "-") continue;
        const value = raw.replace(/[₱,$\s]/g, "");
        if (!/^\d+(\.\d{1,2})?$/.test(value)) errors.push(`${field}: invalid amount`);
    }
    return errors;
}

function renderImportPreview() {
    const table = document.getElementById("importPreviewTable");
    const summary = document.getElementById("importPreviewSummary");
    if (!table || !summary) return;
    const scroller = table.closest(".import-preview-scroll");
    const scrollLeft = scroller?.scrollLeft || 0;
    const scrollTop = scroller?.scrollTop || 0;
    const columns = [...new Set([...importPreviewRows.flatMap(row => Object.keys(row)), "projectNo", "title"])];
    const identityColumns = new Set(["projectNo", "title", ...importPreviewAliases.projectNo, ...importPreviewAliases.title]);
    const detailColumns = columns.filter(column => !identityColumns.has(column));
    const usedProjectNumbers = new Set([
        ...importPreviewExistingProjects.map(project => String(project.projectNo || "").trim()),
        ...importPreviewRows.map(row => String(importPreviewValue(row, "projectNo") || "").trim())
    ].filter(Boolean));
    let nextPreviewProjectNumber = getNextProjectNumber([
        ...importPreviewExistingProjects,
        ...importPreviewRows.map(row => ({ projectNo: importPreviewValue(row, "projectNo") }))
    ]);
    importPreviewAssignedNumbers = new Map();
    const titles = new Set();
    const numbers = new Set();
    let invalid = 0;
    let duplicates = 0;
    const body = importPreviewRows.map((row, index) => {
        const errors = importPreviewErrors(row, index + 2);
        const title = importPreviewIdentity(importPreviewValue(row, "title"));
        const number = importPreviewIdentity(importPreviewValue(row, "projectNo"));
        if (title && titles.has(title)) errors.push("Duplicate title in file");
        if (number && numbers.has(number)) errors.push("Duplicate project number in file");
        if (title) titles.add(title);
        if (number) numbers.add(number);
        const matchInfo = importPreviewMatch(row);
        if (matchInfo.conflictingMatches) errors.push("Number and title match different existing projects");
        if (matchInfo.match) duplicates++;
        if (errors.length) invalid++;
        const numberKey = importPreviewKey(row, "projectNo");
        const titleKey = importPreviewKey(row, "title");
        const savedChoice = importPreviewChoices.get(index);
        if (savedChoice && savedChoice.existingId !== matchInfo.match?.id) importPreviewChoices.delete(index);
        if (matchInfo.match && !errors.length && !importPreviewChoices.has(index)) {
            importPreviewChoices.set(index, { action: "new", existingId: matchInfo.match.id });
        }
        const choice = importPreviewChoices.get(index)?.action || "new";
        let assignedProjectNumber = "";
        if (matchInfo.match && !errors.length && choice === "new") {
            while (usedProjectNumbers.has(String(nextPreviewProjectNumber))) nextPreviewProjectNumber++;
            assignedProjectNumber = String(nextPreviewProjectNumber++);
            usedProjectNumbers.add(assignedProjectNumber);
            importPreviewAssignedNumbers.set(index, assignedProjectNumber);
        }
        const matchLabel = matchInfo.match ? `#${matchInfo.match.projectNo || "—"} ${matchInfo.match.title || "Untitled project"}` : "";
        const matchKind = matchInfo.numberMatch && matchInfo.titleMatch ? "Number and title match" : matchInfo.numberMatch ? "Project number matches" : "Project title matches";
        const review = errors.length
            ? `<span class="import-preview-badge import-preview-badge--invalid">Invalid</span><div class="import-preview-reason">${escapeHtml(errors.join("; "))}</div>`
            : matchInfo.match
                ? `<span class="import-preview-badge import-preview-badge--duplicate">Duplicate</span><div class="import-preview-match">${escapeHtml(matchKind)}: ${escapeHtml(matchLabel)}</div><label class="visually-hidden" for="importChoice${index}">Action for row ${index + 2}</label><select id="importChoice${index}" class="form-select form-select-sm import-preview-choice" data-choice-row="${index}"><option value="new" ${choice === "new" ? "selected" : ""}>Add as a new project</option><option value="overwrite" ${choice === "overwrite" ? "selected" : ""}>Overwrite existing project</option></select>${assignedProjectNumber ? `<div class="import-preview-assigned-number">New project no. <strong>${escapeHtml(assignedProjectNumber)}</strong></div>` : ""}`
                : `<span class="import-preview-badge import-preview-badge--ready">Ready to add</span>`;
        const cells = detailColumns.map(column => {
            const colIndex = columns.indexOf(column);
            const value = escapeHtml(row[column] ?? "");
            const field = Object.keys(importPreviewAliases).find(key => key === column || importPreviewAliases[key].includes(column)) || column;
            const compact = importDateFields.includes(field) || importMoneyFields.includes(field) || field === "ldNoOfDays";
            const editor = compact
                ? `<input class="form-control form-control-sm" aria-label="Row ${index + 2}, ${escapeHtml(column)}" data-row="${index}" data-column="${colIndex}" value="${value}">`
                : `<textarea class="form-control form-control-sm" rows="3" aria-label="Row ${index + 2}, ${escapeHtml(column)}" data-row="${index}" data-column="${colIndex}">${value}</textarea>`;
            return `<td class="import-preview-data-cell">${editor}</td>`;
        }).join("");
        return `<tr class="${errors.length ? "import-preview-row-invalid" : matchInfo.match ? "import-preview-row-duplicate" : ""}"><th scope="row" class="import-preview-sticky import-preview-row-number">${index + 2}</th><td class="import-preview-sticky import-preview-project-cell"><label>${assignedProjectNumber ? "New project no." : "Project no."}<input class="form-control form-control-sm" aria-label="Row ${index + 2}, project number" data-row="${index}" data-column="${columns.indexOf(numberKey)}" value="${escapeHtml(assignedProjectNumber || importPreviewValue(row, "projectNo"))}" ${assignedProjectNumber ? "readonly" : ""}></label>${assignedProjectNumber ? `<div class="import-preview-original-number">Original spreadsheet no. ${escapeHtml(importPreviewValue(row, "projectNo") || "—")}</div>` : ""}<label>Project title<textarea class="form-control form-control-sm" rows="2" aria-label="Row ${index + 2}, project title" data-row="${index}" data-column="${columns.indexOf(titleKey)}">${escapeHtml(importPreviewValue(row, "title"))}</textarea></label></td><td class="import-preview-sticky import-preview-review-cell">${review}</td>${cells}</tr>`;
    }).join("");
    table.innerHTML = `<thead><tr><th class="import-preview-sticky import-preview-row-number">Row</th><th class="import-preview-sticky import-preview-project-cell">Project</th><th class="import-preview-sticky import-preview-review-cell">Review and action</th>${detailColumns.map(column => `<th class="import-preview-data-cell">${escapeHtml(column)}</th>`).join("")}</tr></thead><tbody>${body}</tbody>`;
    table.dataset.columns = JSON.stringify(columns);
    summary.innerHTML = `<strong>${importPreviewRows.length}</strong> rows <span class="import-preview-summary-divider">•</span> <strong>${importPreviewRows.length - invalid}</strong> ready <span class="import-preview-summary-divider">•</span> <strong>${duplicates}</strong> existing matches <span class="import-preview-summary-divider">•</span> <strong>${invalid}</strong> invalid`;
    if (scroller) { scroller.scrollLeft = scrollLeft; scroller.scrollTop = scrollTop; }
    document.getElementById("continueImportPreviewButton").disabled = !importPreviewRows.length || invalid === importPreviewRows.length;
}

async function showImportPreview(rows, isLdSheet) {
    importPreviewRows = rows;
    importPreviewIsLdSheet = isLdSheet;
    importPreviewChoices = new Map();
    if (!db) {
        showSystemNotification("Project records are not ready. Please reload and try again.");
        return;
    }
    try {
        await db.refresh();
    } catch (error) {
        showSystemNotification(error.message || "Could not refresh projects before checking duplicates.");
        return;
    }
    const request = db.transaction(["projects"], "readonly").objectStore("projects").getAll();
    request.onsuccess = event => {
        importPreviewExistingProjects = event.target.result || [];
        renderImportPreview();
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
}

function continueImportPreview() {
    const rows = importPreviewRows;
    if (!rows.length) return;
    const choices = Object.fromEntries([...importPreviewChoices].map(([index, choice]) => [
        index, { ...choice, assignedProjectNo: importPreviewAssignedNumbers.get(index) || "" }
    ]));
    bootstrap.Modal.getInstance(document.getElementById("importPreviewModal"))?.hide();
    importPreviewRows = [];
    importPreviewExistingProjects = [];
    importPreviewChoices = new Map();
    importPreviewAssignedNumbers = new Map();
    processImportedRows(rows, importPreviewIsLdSheet, choices);
}

document.addEventListener("change", event => {
    const choice = event.target.closest("#importPreviewTable select[data-choice-row]");
    if (choice) {
        const rowIndex = Number(choice.dataset.choiceRow);
        const match = importPreviewMatch(importPreviewRows[rowIndex]).match;
        if (match) importPreviewChoices.set(rowIndex, { action: choice.value, existingId: match.id });
        renderImportPreview();
        return;
    }
    const input = event.target.closest("#importPreviewTable [data-row][data-column]");
    if (!input) return;
    const rowIndex = Number(input.dataset.row);
    const column = JSON.parse(document.getElementById("importPreviewTable").dataset.columns)[Number(input.dataset.column)];
    importPreviewRows[rowIndex][column] = input.value;
    renderImportPreview();
});

function assignFreshImportProjectNumber(payload) {
    while (importProjectNumbersInUse.has(String(nextImportProjectNumber))) nextImportProjectNumber++;
    payload.projectNo = String(nextImportProjectNumber++);
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
                "Processing Excel Spreadsheet Serialization...";
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
                        type: "array"
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


            /*
             * Detect whether the imported file is the
             * Liquidated Damages spreadsheet.
             */
            const exportedColumns = {
                "Date of Bidding": "biddingDate", "Project Title": "title",
                "ABC": "approvedBudget", "Bid Amount": "bidAmount",
                "Winning Bidder": "winningBidder", "Source of Fund": "sourceOfFund",
                "PR Number": "prNumber", "PR Date": "prDate", "OBRE": "obre",
                "Resolution Number": "resolutionNumber", "Resolution Date": "resolutionDate",
                "Notice of Post Qualification": "noticePostQual", "Notice of Award": "noa",
                "Performance Bond": "performanceBond", "Contract Date": "contractRef",
                "Contract Duration": "contractDuration", "Notice to Proceed": "ntpDate",
                "DOLE Clearance": "dole", "Contract End Date": "contractEndDate",
                "NOA Due Date": "noaDueDate",
                "Material Testing Date": "materialTestingDate",
                "Pre Inspection": "preInspection", "Final Inspection": "finalInspection",
                "SWA": "swa", "LD Amount": "ldAmount", "LD Days": "ldNoOfDays",
                "Extended Completion Date": "ldNewCompletionDate",
                "Actual Construction Duration": "ldActualDuration",
                "Completion Date": "completion", "Operational Status": "status",
                "Additional Remarks": "remarks"
            };
            const isServerExport = rawRows[0]["Project No."] !== undefined &&
                rawRows[0]["Project Title"] !== undefined &&
                rawRows[0]["Operational Status"] !== undefined;
            const importRows = isServerExport ? rawRows.map(row => {
                const mapped = { "Project No.": row["Project No."] };
                Object.entries(exportedColumns).forEach(([heading, field]) => {
                    if (["materialTestingDate", "noaDueDate"].includes(field) && !Object.hasOwn(row, heading)) return;
                    mapped[field] = row[heading] ?? "";
                });
                return mapped;
            }) : rawRows;
            const isIsolatedLdSheet = !isServerExport &&
                rawRows[0]["2. Project Title"] === undefined &&
                (rawRows[0]["23. LD Amount"] !== undefined ||
                    rawRows[0]["ldAmount"] !== undefined ||
                    rawRows[0]["Project Title"] !== undefined);


            if (loader) loader.classList.remove("active");
            showImportPreview(importRows, isIsolatedLdSheet);

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

const importDateFields = ["biddingDate", "prDate", "obre", "resolutionDate", "noticePostQual", "noa", "noaDueDate", "performanceBond", "contractRef", "ntpDate", "dole", "contractEndDate", "preInspection", "materialTestingDate", "finalInspection", "swa", "ldNewCompletionDate", "completion"];
const importMoneyFields = ["approvedBudget", "bidAmount", "ldAmount"];

function validateImportedProject(payload, rowNumber) {
    const errors = [];
    if (!String(payload.title || "").trim() || payload.title === "Imported Unnamed Project") errors.push("Project title is required");
    importDateFields.forEach(field => {
        const value = String(payload[field] || "").trim();
        if (!value) return;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) errors.push(`${field}: invalid date`);
        else {
            const [year, month, day] = value.split("-").map(Number);
            const date = new Date(Date.UTC(year, month - 1, day));
            if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) errors.push(`${field}: invalid date`);
        }
    });
    importMoneyFields.forEach(field => {
        const raw = String(payload[field] || "").trim();
        if (!raw || raw === "-") return;
        const value = raw.replace(/[₱,$\s]/g, "");
        if (!/^\d+(\.\d{1,2})?$/.test(value) || Number(value) < 0) errors.push(`${field}: invalid monetary value`);
    });
    return errors.map(message => ({ row: rowNumber, projectNo: payload.projectNo || "", title: payload.title || "", message }));
}


function processImportedRows(
    rows,
    isIsolatedLdSheet,
    previewChoices = {}
) {

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


            /**
             * Preserve supplied numbers. Assign missing numbers after all
             * spreadsheet rows have been read, so later rows cannot collide.
             */
            const resolveImportedProjectNo =
                row => {

                    const provided =
                        row["Project No."] ??
                        row["projectNo"];


                    if (
                        provided !== undefined &&
                        provided !== null &&
                        String(provided)
                            .trim() !== ""
                    ) {

                        return String(
                            provided
                        ).trim();
                    }


                    return "";
                };


            pendingImportPayloads = [];

            bulkImportConflictsQueue = [];
            bulkImportErrors = [];
            activeImportBatchId = "";
            const importedTitles = new Set();
            const importedProjectNumbers = new Set();


            /**
             * Converts imported spreadsheet date values
             * into YYYY-MM-DD when possible.
             */
                const parseImportDate =
                value => {

                    if (!value || ["-", "n/a", "na"].includes(String(value).trim().toLowerCase())) {
                        return "";
                    }


                    if (typeof value === "number") {
                        const parts = XLSX.SSF.parse_date_code(value);
                        return parts
                            ? `${String(parts.y).padStart(4, "0")}-${String(parts.m).padStart(2, "0")}-${String(parts.d).padStart(2, "0")}`
                            : String(value);
                    }

                    const rawValue = String(value).trim();
                    if (/^\d{4}-\d{2}-\d{2}$/.test(rawValue)) return rawValue;

                    const date =
                        new Date(
                            value
                        );


                    return !isNaN(
                        date.getTime()
                    )
                        ? date
                            .toISOString()
                            .split("T")[0]
                        : String(value);
                };


            rows.forEach(
                (row, index) => {

                    let payload = {};


                    /* =====================================================
                       LIQUIDATED DAMAGES IMPORT
                       ===================================================== */

                    if (
                        isIsolatedLdSheet
                    ) {

                        payload = {

                            projectNo:
                                resolveImportedProjectNo(
                                    row
                                ),

                            title:
                                String(
                                    row["Project Title"] ||
                                    row["title"] ||
                                    "Imported LD Reference Record"
                                ).trim(),

                            winningBidder:
                                String(
                                    row["Winning Bidder"] ||
                                    row["winningBidder"] ||
                                    ""
                                ),

                            ldAmount:
                                String(
                                    row["23. LD Amount"] ||
                                    row["ldAmount"] ||
                                    ""
                                ),

                            ldNoOfDays:
                                String(
                                    row["23. No. of Days Delinquent"] ||
                                    row["ldNoOfDays"] ||
                                    ""
                                ),

                            ldNewCompletionDate:
                                parseImportDate(
                                    row["23. New Extended Completion Date"] ||
                                    row["ldNewCompletionDate"]
                                ),

                            ldActualDuration:
                                String(
                                    row["23. Actual Construction Duration"] ||
                                    row["ldActualDuration"] ||
                                    ""
                                ),

                            biddingDate: "",

                            approvedBudget: "",

                            bidAmount: "",

                            sourceOfFund: "",

                            prNumber: "",

                            prDate: "",

                            obre: "",

                            resolutionNumber: "",

                            resolutionDate: "",

                            noticePostQual: "",

                            noa: "",

                            performanceBond: "",

                            contractRef: "",

                            contractDuration: "",

                            ntpDate: "",

                            dole: "",

                            contractEndDate: "",

                            ...(Object.hasOwn(row, "NOA Due Date") || Object.hasOwn(row, "noaDueDate") ? { noaDueDate: parseImportDate(row["NOA Due Date"] ?? row.noaDueDate) } : {}),
                            ...(Object.hasOwn(row, "Material Testing Date") || Object.hasOwn(row, "materialTestingDate") ? { materialTestingDate: parseImportDate(row["Material Testing Date"] ?? row.materialTestingDate) } : {}),
                            preInspection: "",

                            finalInspection: "",

                            swa: "",

                            completion: "",

                            remarks: "",

                            status:
                                "Pending"
                        };

                    } else {

                        /* =================================================
                           GENERAL PROJECT IMPORT
                           ================================================= */

                        payload = {

                            projectNo:
                                resolveImportedProjectNo(
                                    row
                                ),

                            biddingDate:
                                parseImportDate(
                                    row["1. Date of Bidding"] ||
                                    row["biddingDate"]
                                ),

                            title:
                                String(
                                    row["2. Project Title"] ||
                                    row["title"] ||
                                    "Imported Unnamed Project"
                                ).trim(),

                            approvedBudget:
                                String(
                                    row["3. ABC"] ||
                                    row["approvedBudget"] ||
                                    ""
                                ),

                            bidAmount:
                                String(
                                    row["4. Bid Amount"] ||
                                    row["bidAmount"] ||
                                    ""
                                ),

                            winningBidder:
                                String(
                                    row["5. Winning Bidder"] ||
                                    row["winningBidder"] ||
                                    ""
                                ),

                            sourceOfFund:
                                String(
                                    row["6. Source of Fund"] ||
                                    row["sourceOfFund"] ||
                                    ""
                                ),

                            prNumber:
                                String(
                                    row["7. PR Number"] ||
                                    row["prNumber"] ||
                                    ""
                                ),

                            prDate:
                                parseImportDate(
                                    row["8. PR Date"] ||
                                    row["prDate"]
                                ),

                            obre:
                                parseImportDate(
                                    row["9. OBRE"] ||
                                    row["9. OBRE Date"] ||
                                    row["obre"]
                                ),

                            resolutionNumber:
                                String(
                                    row["10. Resolution Number"] ||
                                    row["resolutionNumber"] ||
                                    ""
                                ),

                            resolutionDate:
                                parseImportDate(
                                    row["11. Resolution Date"] ||
                                    row["resolutionDate"]
                                ),

                            noticePostQual:
                                parseImportDate(
                                    row["12. Notice of Post Qualification"] ||
                                    row["noticePostQual"]
                                ),

                            noa:
                                parseImportDate(
                                    row["13. Notice of Award"] ||
                                    row["noa"]
                                ),

                            performanceBond:
                                parseImportDate(
                                    row["14. Performance Bond"] ||
                                    row["performanceBond"]
                                ),

                            contractRef:
                                parseImportDate(
                                    row["15. Contract Date"] ||
                                    row["contractRef"]
                                ),

                            contractDuration:
                                String(
                                    row["16. Contract Duration"] ||
                                    row["contractDuration"] ||
                                    ""
                                ),

                            ntpDate:
                                parseImportDate(
                                    row["17. Notice to Proceed"] ||
                                    row["ntpDate"]
                                ),

                            dole:
                                parseImportDate(
                                    row["18. DOLE Clearance"] ||
                                    row["dole"]
                                ),

                            contractEndDate:
                                parseImportDate(
                                    row["19. Contract End Date"] ||
                                    row["contractEndDate"]
                                ),

                            ...(Object.hasOwn(row, "NOA Due Date") || Object.hasOwn(row, "noaDueDate") ? { noaDueDate: parseImportDate(row["NOA Due Date"] ?? row.noaDueDate) } : {}),
                            ...(Object.hasOwn(row, "Material Testing Date") || Object.hasOwn(row, "materialTestingDate") ? { materialTestingDate: parseImportDate(row["Material Testing Date"] ?? row.materialTestingDate) } : {}),
                            preInspection:
                                parseImportDate(
                                    row["20. Pre Inspection"] ||
                                    row["preInspection"]
                                ),

                            finalInspection:
                                parseImportDate(
                                    row["21. Final Inspection"] ||
                                    row["finalInspection"]
                                ),

                            swa:
                                parseImportDate(
                                    row["22. SWA"] ||
                                    row["22. SWA Date"] ||
                                    row["swa"]
                                ),

                            ldAmount:
                                String(
                                    row["23. LD Amount"] ||
                                    row["ldAmount"] ||
                                    ""
                                ),

                            ldNoOfDays:
                                String(
                                    row["23. No. of Days Delinquent"] ||
                                    row["ldNoOfDays"] ||
                                    ""
                                ),

                            ldNewCompletionDate:
                                parseImportDate(
                                    row["23. New Extended Completion Date"] ||
                                    row["ldNewCompletionDate"]
                                ),

                            ldActualDuration:
                                String(
                                    row["23. Actual Construction Duration"] ||
                                    row["ldActualDuration"] ||
                                    ""
                                ),

                            completion:
                                parseImportDate(
                                    row["24. Completion Date"] ||
                                    row["completion"]
                                ),

                            status:
                                String(
                                    row["25. Operational Status"] ||
                                    row["status"] ||
                                    "Pending"
                                ),

                            remarks:
                                String(
                                    row["26. Additional Remarks"] ||
                                    row["remarks"] ||
                                    ""
                                )
                        };
                    }


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
                        if (originalProjectNo && originalProjectNo !== assignedProjectNo) {
                            payload.sourceProjectNo = originalProjectNo;
                            payload.remarks = [payload.remarks, `Imported project no.: ${originalProjectNo}`]
                                .filter(Boolean).join("\n");
                        }
                    }

                    payload.updatedAt =
                        new Date()
                            .toISOString();

                    const rowErrors = validateImportedProject(payload, index + 2);
                    const normalizedTitle = String(payload.title || "").trim().toLowerCase().replace(/\s+/g, " ");
                    const normalizedProjectNo = String(payload.projectNo || "").trim().toLowerCase();
                    if (importedTitles.has(normalizedTitle)) rowErrors.push({ row: index + 2, projectNo: payload.projectNo || "", title: payload.title || "", message: "Duplicate title within spreadsheet" });
                    if (normalizedProjectNo && importedProjectNumbers.has(normalizedProjectNo)) rowErrors.push({ row: index + 2, projectNo: payload.projectNo || "", title: payload.title || "", message: "Duplicate project number within spreadsheet" });
                    importedTitles.add(normalizedTitle);
                    if (normalizedProjectNo) importedProjectNumbers.add(normalizedProjectNo);
                    if (rowErrors.length) {
                        bulkImportErrors.push(...rowErrors);
                        return;
                    }


                    /* Existing project numbers are unique across active and archived records. */
                    const matchByNumber = normalizedProjectNo
                        ? existingProjects.find(project => String(project.projectNo || "").trim().toLowerCase() === normalizedProjectNo)
                        : null;
                    const matchByTitle = existingProjects.find(project =>
                        project.title && project.title.trim().toLowerCase().replace(/\s+/g, " ") === normalizedTitle
                    );
                    if (matchByNumber && matchByTitle && matchByNumber.id !== matchByTitle.id) {
                        bulkImportErrors.push({ row: index + 2, projectNo: payload.projectNo || "", title: payload.title || "", message: "Project number and title match different existing projects" });
                        return;
                    }
                    const match = matchByNumber || matchByTitle;

                    if (match) {

                        bulkImportConflictsQueue.push({

                            index,

                            existingId:
                                match.id,
                            existingTitle: match.title || "Untitled project",
                            existingProjectNo: match.projectNo || "",
                            numberConflict: Boolean(matchByNumber),
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
            nextImportProjectNumber = getNextProjectNumber(allProjectsForNumbers);
            [...pendingImportPayloads, ...bulkImportConflictsQueue.map(item => item.payload)]
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
    if (summary) summary.textContent = `${bulkImportConflictsQueue.length} project title or number match${bulkImportConflictsQueue.length === 1 ? "" : "es"} found. New projects receive a fresh number when needed. ${bulkImportErrors.length} invalid row${bulkImportErrors.length === 1 ? "" : "s"} will be skipped.`;


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
                        assignFreshImportProjectNumber(item.payload);
                    }

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
            const result = await apiClient.post("bulkSaveProjects", { records, batchId: activeImportBatchId, archive: importIntoArchive });
            imported += result.count || records.length;
            showImportProgress(imported, importCount, bulkImportErrors.length, `Importing batch ${Math.floor(offset / batchSize) + 1} of ${Math.ceil(importCount / batchSize)}…`);
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
