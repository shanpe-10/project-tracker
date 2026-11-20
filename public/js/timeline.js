/* ==========================================================================
   PROJECT TIMELINE & ALERT MANAGEMENT
   ========================================================================== */


/* ==========================================================================
   TIMELINE DATE HELPERS
   ========================================================================== */

const TIMELINE_DAY_SETTINGS_KEY = "offlineDocManager.timelineDaySettings";
const timelineDayDefaults = { noa: 10, ntp: 30, material: 15, completion: 15, finalInspection: 7, bidding: 15, contractEnd: 7 };
let dashboardCompletionYear = "";
let dashboardStatusMonth = "";
let dashboardStatusYear = "";
let dashboardChartDocuments = [];

function getProjectMilestoneAlerts(project, today = timelineCalendarDay(new Date())) {
    if (!today || project.archivedAt || project.timelineCompletedMarked || ["completed", "released"].includes(String(project.status || "").trim().toLowerCase())) return [];
    const dates = getProjectMilestoneDates(project);
    const resolved = Array.isArray(project.timelineAlertsCompleted) ? project.timelineAlertsCompleted : [];
    const alerts = [];
    const append = (field, actualField, type, overdueType, label, lead) => {
        const due = projectCalendarDate(dates[field]);
        const actual = actualField ? projectCalendarDate(project[actualField]) : null;
        if (!due || (actual && project[actualField] <= today) || resolved.includes(type) || resolved.includes(overdueType)) return;
        const warning = new Date(due.getTime());
        warning.setTime(addProjectWorkdays(warning, -(lead)).getTime());
        if (today < warning.toISOString().slice(0, 10)) return;
        const overdue = today > dates[field];
        alerts.push({ type: overdue ? overdueType : type, label: label + (overdue ? " Overdue" : " Due"), dueDate: dates[field], project });
    };
    append("noaDueDate", "noa", "noa-due", "noa-due-overdue", "NOA", getTimelineDays("noa"));
    append("preInspectionDueDate", "preInspection", "pre-inspection", "pre-inspection-overdue", "Pre-inspection", getTimelineDays("bidding"));
    append("ntpDueDate", "ntpDate", "ntp-due", "ntp-due-overdue", "Notice to Proceed", getTimelineDays("bidding"));
    append("materialTestingDueDate", "materialTestingDate", "material-test", "material-overdue", "Material Testing", getTimelineDays("bidding"));
    append("finalInspectionDueDate", "finalInspection", "final-inspection", "final-inspection-overdue", "Final Inspection", getTimelineDays("finalInspection"));
    append("contractEndReminderDate", "completion", "contract-end", "contract-end-overdue", "Contract End Follow-up", 0);
    return alerts;
}


function canResolveTimelineAlerts() {
    return window.currentUser?.role === "admin" || window.currentUser?.canResolveAlerts === true;
}

function notifyResolvePermissionRequired() {
    showSystemNotification("You do not have permission to restore historical alerts.");
}

function getTimelineDays(type) {
    const fallback = timelineDayDefaults[type] || 15;
    try {
        const saved = window.timelineDaySettings || JSON.parse(localStorage.getItem(TIMELINE_DAY_SETTINGS_KEY) || "{}");
        const value = Number(type === "bidding" ? (saved.bidding ?? saved.material) : saved[type]);
        return Number.isInteger(value) && value >= 1 && value <= 3650 ? value : fallback;
    } catch (error) {
        return fallback;
    }
}

function toggleTimelineCustomDays(type) {
    const name = type[0].toUpperCase() + type.slice(1);
    const select = document.getElementById(`timelineLead${name}`);
    const input = document.getElementById(`timelineLead${name}Custom`);
    if (!select || !input) return;
    if (select.value !== "custom") {
        [...select.options]
            .filter(option => option.dataset.custom === "true")
            .forEach(option => option.remove());
    }
    input.classList.toggle("d-none", select.value !== "custom");
    input.required = select.value === "custom";
}

function displayTimelineDays(type, days) {
    const name = type[0].toUpperCase() + type.slice(1);
    const select = document.getElementById(`timelineLead${name}`);
    const input = document.getElementById(`timelineLead${name}Custom`);
    if (!select || !input) return false;
    const value = Number(days);
    if (!Number.isInteger(value) || value < 1 || value > 3650) return false;
    [...select.options].filter(option => option.dataset.custom === "true").forEach(option => option.remove());
    if (![...select.options].some(option => option.value === String(value))) {
        const option = new Option(`${value} days`, String(value));
        option.dataset.custom = "true";
        select.add(option, select.querySelector('option[value="custom"]'));
    }
    select.value = String(value);
    input.value = value;
    input.required = false;
    input.classList.add("d-none");
    return true;
}

function applyTimelineCustomDays(type) {
    const name = type[0].toUpperCase() + type.slice(1);
    const input = document.getElementById(`timelineLead${name}Custom`);
    if (!displayTimelineDays(type, input?.value)) input?.focus();
}

function loadTimelineLeadTimes() {
    Object.keys(timelineDayDefaults).forEach(type => {
        const name = type[0].toUpperCase() + type.slice(1);
        const select = document.getElementById(`timelineLead${name}`);
        const input = document.getElementById(`timelineLead${name}Custom`);
        if (!select || !input) return;
        const days = getTimelineDays(type);
        displayTimelineDays(type, days);
    });
}

function saveTimelineLeadTimes() {
    const settings = { ntp: getTimelineDays("ntp"), material: getTimelineDays("material") };
    for (const type of ["noa", "bidding", "completion", "finalInspection", "contractEnd"]) {
        const name = type[0].toUpperCase() + type.slice(1);
        const select = document.getElementById(`timelineLead${name}`);
        const input = document.getElementById(`timelineLead${name}Custom`);
        if (select?.value === "custom") applyTimelineCustomDays(type);
        const days = Number(select?.value === "custom" ? input?.value : select?.value);
        if (!Number.isInteger(days) || days < 1 || days > 3650) {
            alert(`Enter a whole number from 1 to 3,650 for ${type === "completion" ? "the completion reminder" : type.toUpperCase()}.`);
            input?.focus();
            return;
        }
        settings[type] = days;
    }
    apiClient.post("saveTimelineSettings", { settings })
        .then(result => {
            window.timelineDaySettings = result.settings;
            localStorage.setItem(TIMELINE_DAY_SETTINGS_KEY, JSON.stringify(result.settings));
            if (typeof showToast === "function") showToast("Timeline day settings saved for all users.", "success");
            else alert("Timeline day settings saved for all users.");
            if (typeof loadDocuments === "function") loadDocuments();
        })
        .catch(error => alert(error.message || "Could not save timeline day settings."));
}

document.addEventListener("DOMContentLoaded", loadTimelineLeadTimes);
window.addEventListener("sharedDatabaseReady", loadTimelineLeadTimes);


/**
 * Calculates the SWA due date.
 *
 * SWA due date = project completion date + 1 year.
 */
function getAwardingDate(completionDateStr) {
    if (!completionDateStr) {
        return null;
    }


    const parts =
        completionDateStr.split("-");


    if (parts.length !== 3) {
        return null;
    }


    const year =
        parseInt(parts[0], 10);


    const month =
        parseInt(parts[1], 10) - 1;


    const day =
        parseInt(parts[2], 10);


    const target =
        new Date(
            year,
            month,
            day
        );


    if (isNaN(target.getTime())) {
        return null;
    }


    target.setFullYear(
        target.getFullYear() + 1
    );


    return projectWeekday(target, false);
}


/* ==========================================================================
   NOA TIMELINE
   ========================================================================== */


/**
 * NOA active period:
 *
 * NOA date through NOA date + 10 days.
 */
function checkNoaFifteenDayWindowAlert(
    noaDateStr,
    status
) {
    if (projectCalendarDate(arguments[2]?.noaDueDate)) return false;
    if (
        !noaDateStr ||
        ["Completed", "Released"].includes(status)
    ) {
        return false;
    }


    const today =
        new Date();

    today.setHours(
        0,
        0,
        0,
        0
    );


    const noaDate =
        new Date(noaDateStr);


    if (
        isNaN(
            noaDate.getTime()
        )
    ) {
        return false;
    }


    noaDate.setHours(
        0,
        0,
        0,
        0
    );


    const noaDeadlineDate =
        new Date(
            noaDate.getTime()
        );


    noaDeadlineDate.setTime(addProjectWorkdays(noaDeadlineDate, getTimelineDays("noa"), false).getTime());


    return (
        today >= noaDate &&
        today <= noaDeadlineDate
    );
}


/**
 * Returns remaining days in the NOA active period.
 */
function getNoaDaysRemaining(
    noaDateStr
) {
    if (!noaDateStr) {
        return null;
    }


    const today =
        new Date();

    today.setHours(
        0,
        0,
        0,
        0
    );


    const noaDate =
        new Date(noaDateStr);


    if (
        isNaN(
            noaDate.getTime()
        )
    ) {
        return null;
    }


    const noaDeadlineDate =
        new Date(
            noaDate.getTime()
        );


    noaDeadlineDate.setTime(addProjectWorkdays(noaDeadlineDate, getTimelineDays("noa"), false).getTime());


    noaDeadlineDate.setHours(
        0,
        0,
        0,
        0
    );


    return projectWorkdaysBetween(today, noaDeadlineDate);
}


/**
 * NOA overdue period:
 *
 * Remains overdue after the NOA deadline until resolved or completed.
 */
function checkNoaOverdueWindowAlert(
    noaDateStr,
    status,
    doc
) {
    if (projectCalendarDate(arguments[2]?.noaDueDate)) return false;
    if (
        !noaDateStr ||
        ["Completed", "Released"].includes(status)
    ) {
        return false;
    }


    const today =
        new Date();

    today.setHours(
        0,
        0,
        0,
        0
    );


    const noaDate =
        new Date(noaDateStr);


    if (
        isNaN(
            noaDate.getTime()
        )
    ) {
        return false;
    }


    noaDate.setHours(
        0,
        0,
        0,
        0
    );


    const noaDeadlineDate =
        new Date(
            noaDate.getTime()
        );


    noaDeadlineDate.setTime(addProjectWorkdays(noaDeadlineDate, getTimelineDays("noa"), false).getTime());


    return today > noaDeadlineDate;
}


/* ==========================================================================
   NTP TIMELINE
   ========================================================================== */


/**
 * Returns remaining days in the NTP awarding period.
 */
function getNtpDaysRemaining(
    ntpDateStr
) {
    if (!ntpDateStr) {
        return null;
    }


    const today =
        new Date();

    today.setHours(
        0,
        0,
        0,
        0
    );


    const ntpDate =
        new Date(ntpDateStr);


    if (
        isNaN(
            ntpDate.getTime()
        )
    ) {
        return null;
    }


    const ntpDeadlineDate =
        new Date(
            ntpDate.getTime()
        );


    ntpDeadlineDate.setTime(addProjectWorkdays(ntpDeadlineDate, getTimelineDays("ntp"), false).getTime());


    ntpDeadlineDate.setHours(
        0,
        0,
        0,
        0
    );


    return projectWorkdaysBetween(today, ntpDeadlineDate);
}


/**
 * NTP awarding active period:
 *
 * NTP date through NTP date + 30 days.
 */
function checkNtpAwardingActiveWindow(
    ntpDateStr,
    status,
    doc
) {
    if (projectCalendarDate(doc?.biddingDate)) return false;
    if (
        !ntpDateStr ||
        ["Completed", "Released"].includes(status)
    ) {
        return false;
    }


    const today =
        new Date();

    today.setHours(
        0,
        0,
        0,
        0
    );


    const ntpDate =
        new Date(ntpDateStr);


    if (
        isNaN(
            ntpDate.getTime()
        )
    ) {
        return false;
    }


    ntpDate.setHours(
        0,
        0,
        0,
        0
    );


    const ntpDeadlineDate =
        new Date(
            ntpDate.getTime()
        );


    ntpDeadlineDate.setTime(addProjectWorkdays(ntpDeadlineDate, getTimelineDays("ntp"), false).getTime());


    return (
        today >= ntpDate &&
        today <= ntpDeadlineDate
    );
}


/**
 * NTP overdue period:
 *
 * Remains overdue after the NTP deadline until resolved or completed.
 */
function checkNtpOverdueWindowAlert(
    ntpDateStr,
    status,
    doc
) {
    if (projectCalendarDate(doc?.biddingDate)) return false;
    if (
        !ntpDateStr ||
        ["Completed", "Released"].includes(status)
    ) {
        return false;
    }


    const today =
        new Date();

    today.setHours(
        0,
        0,
        0,
        0
    );


    const ntpDate =
        new Date(ntpDateStr);


    if (
        isNaN(
            ntpDate.getTime()
        )
    ) {
        return false;
    }


    ntpDate.setHours(
        0,
        0,
        0,
        0
    );


    const ntpDeadlineDate =
        new Date(
            ntpDate.getTime()
        );


    ntpDeadlineDate.setTime(addProjectWorkdays(ntpDeadlineDate, getTimelineDays("ntp"), false).getTime());


    return today > ntpDeadlineDate;
}


/* ==========================================================================
   MATERIAL TEST TIMELINE
   ========================================================================== */


/**
 * Material test active period:
 *
 * NTP date through NTP date + 15 days.
 */
function checkMaterialTestFifteenDayWindow(
    ntpDateStr,
    status,
    doc
) {
    if (projectCalendarDate(doc?.biddingDate) || (projectCalendarDate(doc?.materialTestingDate) && doc.materialTestingDate <= timelineCalendarDay(new Date()))) return false;

    if (
        !ntpDateStr ||
        ["Completed", "Released"].includes(status)
    ) {
        return false;
    }


    const today =
        new Date();

    today.setHours(
        0,
        0,
        0,
        0
    );


    const ntpDate =
        new Date(ntpDateStr);


    if (
        isNaN(
            ntpDate.getTime()
        )
    ) {
        return false;
    }


    ntpDate.setHours(
        0,
        0,
        0,
        0
    );


    const materialTestDeadline =
        new Date(
            ntpDate.getTime()
        );


    materialTestDeadline.setTime(addProjectWorkdays(materialTestDeadline, getTimelineDays("material"), false).getTime());


    return (
        today >= ntpDate &&
        today <= materialTestDeadline
    );
}


/**
 * Material testing overdue period:
 *
 * Remains overdue after the material deadline until resolved or completed.
 */
function checkMaterialTestOverdueWindowAlert(
    ntpDateStr,
    status,
    doc
) {
    if (projectCalendarDate(doc?.biddingDate) || (projectCalendarDate(doc?.materialTestingDate) && doc.materialTestingDate <= timelineCalendarDay(new Date()))) return false;

    if (
        !ntpDateStr ||
        ["Completed", "Released"].includes(status)
    ) {
        return false;
    }


    const today =
        new Date();

    today.setHours(
        0,
        0,
        0,
        0
    );


    const ntpDate =
        new Date(ntpDateStr);


    if (
        isNaN(
            ntpDate.getTime()
        )
    ) {
        return false;
    }


    ntpDate.setHours(
        0,
        0,
        0,
        0
    );


    const materialTestDeadline =
        new Date(
            ntpDate.getTime()
        );


    materialTestDeadline.setTime(addProjectWorkdays(materialTestDeadline, getTimelineDays("material"), false).getTime());


    return today > materialTestDeadline;
}


/**
 * SWA advance reminder period.
 *
 * Active only during the final 15 days
 * BEFORE the awarding date.
 *
 * The SWA due date itself and dates after it are handled separately.
 */
function checkFifteenDayWindowAlert(
    completionDateStr,
    status
) {
    if (
        !completionDateStr || String(status || "").trim().toLowerCase() === "released"
    ) {
        return false;
    }


    const today =
        new Date();

    today.setHours(
        0,
        0,
        0,
        0
    );


    const awardingDate =
        getAwardingDate(
            completionDateStr
        );


    if (!awardingDate) {
        return false;
    }


    awardingDate.setHours(
        0,
        0,
        0,
        0
    );


    const warningStartDate =
        new Date(
            awardingDate.getTime()
        );


    warningStartDate.setTime(addProjectWorkdays(warningStartDate, -getTimelineDays("completion"), false).getTime());


    return (
        today >= warningStartDate &&
        today < awardingDate
    );
}

/* ==========================================================================
   RESOLVE / UNRESOLVE TIMELINE ALERTS
   ========================================================================== */


function invokeTimelineUnmarkDoneAction(
    id,
    alertType
) {
    if (!canResolveTimelineAlerts()) {
        notifyResolvePermissionRequired();
        return;
    }
    if (!db) {
        return;
    }


    if (
        !confirm(
            "Are you sure you want to unmark this item? It will return to active tracking panels if parameters are met."
        )
    ) {
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


    projectStore
        .get(id)
        .onsuccess = event => {

            const record =
                event.target.result;


            if (!record) {
                return;
            }


            if (getAutomaticTimelineResolutions(record).some(item => item.aliases.includes(alertType))) {
                showSystemNotification("Edit or clear the actual milestone date to reopen this alert.");
                return;
            }
            let details =
                "Project metrics timeline warning reversed back to active tracking dashboard view.";


            let action =
                "Unmark Timeline Complete";


            if (alertType) {

                record.timelineAlertsCompleted =
                    Array.isArray(
                        record.timelineAlertsCompleted
                    )
                        ? record.timelineAlertsCompleted
                            .filter(
                                type =>
                                    type !== alertType
                            )
                        : [];


                if (
                    record.timelineAlertsCompleted
                        .length === 0
                ) {

                    delete record.timelineAlertsCompleted;

                    record.timelineCompletedMarked =
                        false;

                    delete record.timelineMarkedDoneDate;
                }


                if (
                    alertType.endsWith(
                        "-overdue"
                    )
                ) {

                    record.timelineUnmarkedType =
                        alertType;


                    record.timelineUnmarkedAt =
                        new Date()
                            .toISOString();
                }


                action =
                    `Unmark ${alertType} Timeline Alert`;


                details =
                    `Warning type ${alertType} has been unmarked and returned to active dashboard tracking for project "${record.title || id}".`;

            } else {

                record.timelineCompletedMarked =
                    false;


                delete record.timelineMarkedDoneDate;


                record.timelineAlertsCompleted =
                    [];


                delete record.timelineAlertsCompleted;

                delete record.timelineUnmarkedType;

                delete record.timelineUnmarkedAt;
            }


            if (
                !record.timelineCompletedMarked &&
                (
                    !Array.isArray(
                        record.timelineAlertsCompleted
                    ) ||
                    record.timelineAlertsCompleted
                        .length === 0
                )
            ) {
                delete record.timelineMarkedDoneDate;
            }


            projectStore.putTimeline(record, {
                projectId: id,

                timestamp:
                    new Date()
                        .toISOString(),

                action,

                details
            });
        };


    transaction.onerror = event => showSystemNotification(event.target.error?.message || "Unable to update alerts. Refresh and try again.");
    transaction.oncomplete = () => {
        loadDocuments();
    };
}


/* ==========================================================================
   RESOLVED ALERTS WORKSPACE
   ========================================================================== */


// Resolved alerts use the current workspace renderer below.

window.resolvedAlertsState = { category: "noa", query: "", page: 1, pageSize: 10, entries: [], filters: { dueRange: "all", dueFrom: "", dueTo: "", resolvedRange: "all", resolvedFrom: "", resolvedTo: "" } };

function resetResolvedAlertsFilters() {
    window.resolvedAlertsState.query = "";
    window.resolvedAlertsState.page = 1;
    window.resolvedAlertsState.filters = { dueRange: "all", dueFrom: "", dueTo: "", resolvedRange: "all", resolvedFrom: "", resolvedTo: "" };
}

function resolvedAlertCategory(type) {
    if (type.startsWith("contract-end")) return "award";
    if (type.startsWith("pre-inspection")) return "preInspection";
    if (type.startsWith("final-inspection")) return "finalInspection";
    if (type.startsWith("noa")) return "noa";
    if (type.startsWith("ntp")) return "ntp";
    if (type.startsWith("material")) return "material";
    return "award";
}

// Compare calendar days consistently in Philippine time, including ISO timestamps.
function timelineCalendarDay(value) {
    if (!value) return "";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "";
    if (typeof value === "string" && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return date.toISOString().slice(0, 10) === value ? value : "";
    }
    const parts = new Intl.DateTimeFormat("en-US", { timeZone: "Asia/Manila", year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(date);
    const part = type => parts.find(item => item.type === type)?.value;
    return `${part("year")}-${part("month")}-${part("day")}`;
}

function timelineDateInRange(value, from, to) {
    const day = timelineCalendarDay(value);
    if (!day) return !value && !from && !to;
    return (!from || day >= from) && (!to || day <= to);
}

function timelinePresetRange(range, value) {
    if (range === "all") return true;
    const day = timelineCalendarDay(value);
    if (!day) return false;
    const today = timelineCalendarDay(new Date());
    if (range === "overdue") return day < today;
    const days = range === "next7" || range === "last7" ? 7 : 30;
    const boundary = new Date(today + "T00:00:00Z");
    boundary.setUTCDate(boundary.getUTCDate() + (range.startsWith("last") ? -days : days));
    const boundaryDay = boundary.toISOString().slice(0, 10);
    return range.startsWith("last") ? day >= boundaryDay && day <= today : day >= today && day <= boundaryDay;
}

function renderResolvedAlertsWorkspace() {
    const state = window.resolvedAlertsState;
    const page = document.querySelector("#dashboardDoneSection .resolved-page");
    if (!page) return;
    const definitions = [
        ["noa", "Notice of Award", '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h10v7a5 5 0 0 1-10 0V3Z"/><path d="M7 6H4v2a4 4 0 0 0 4 4M17 6h3v2a4 4 0 0 1-4 4M12 15v4M8 21h8"/></svg>'],
        ["ntp", "Notice to Proceed", '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M14 7l5 5-5 5"/></svg>'],
        ["material", "Material Testing", '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3M8 15h8"/></svg>'],
        ["preInspection", "Pre-inspection", '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5h10v16H5V5h4M9 3h6v4H9zM8 13l2 2 5-5"/></svg>'],
        ["finalInspection", "Final Inspection", '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>'],
        ["award", "Award & Completion", '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>']
    ];
    const counts = Object.fromEntries(definitions.map(([key]) => [key, state.entries.filter(item => resolvedAlertCategory(item.type) === key).length]));
    const selected = definitions.find(([key]) => key === state.category) || definitions[0];
    const query = state.query.toLowerCase();
    const filters = state.filters;
    const dueDate = item => item.dueDate || projectMilestoneDueDate(item.project, item.type) || (item.type.startsWith("noa") ? item.project.noa : item.type.startsWith("ntp") || item.type.startsWith("material") ? item.project.ntpDate : item.project.completion);
    const filtered = state.entries.filter(item => resolvedAlertCategory(item.type) === state.category
        && `${item.project.title || ""} ${item.label}`.toLowerCase().includes(query)
        && (filters.dueRange === "custom" ? timelineDateInRange(dueDate(item), filters.dueFrom, filters.dueTo) : timelinePresetRange(filters.dueRange, dueDate(item)))
        && (filters.resolvedRange === "custom" ? timelineDateInRange(item.doneDate, filters.resolvedFrom, filters.resolvedTo) : timelinePresetRange(filters.resolvedRange, item.doneDate)));
    const totalPages = Math.max(1, Math.ceil(filtered.length / state.pageSize));
    state.page = Math.min(state.page, totalPages);
    const start = (state.page - 1) * state.pageSize;
    const rows = filtered.slice(start, start + state.pageSize);
    const date = value => value ? escapeHtml(formatUIDate(value)) : "—";
    page.innerHTML = `
        <div class="dashboard-eyebrow">Timeline Management</div>
        <div class="resolved-page-header"><div><h1 class="resolved-page-title">Resolved Alerts</h1><p class="resolved-page-description">Review completed timeline alerts and return them to active monitoring when necessary.</p></div></div>
        <div class="resolved-reference-cards">${definitions.map(([key, title, icon]) => `<button type="button" title="${title}: ${counts[key]} resolved alerts" aria-label="${title}: ${counts[key]} resolved alerts" class="resolved-reference-card resolved-reference-card--${key} ${state.category === key ? "is-selected" : ""}" onclick="selectResolvedAlertCategory('${key}')"><span class="resolved-reference-icon">${icon}</span><span class="resolved-reference-copy"><strong>${({noa:"NOA",ntp:"NTP",material:"Material testing",preInspection:"Pre-inspection",finalInspection:"Final inspection",award:"SWA"})[key]}</strong><b>${counts[key]}</b><small>Resolved alerts</small></span><span class="resolved-reference-arrow">›</span></button>`).join("")}</div>
        <section class="resolved-table-card"><div class="resolved-table-heading"><div><h2>Resolved ${selected[1]} Alerts</h2><p>Showing ${filtered.length} resolved timeline alert${filtered.length === 1 ? "" : "s"} for ${selected[1]}.</p></div><div class="resolved-table-tools"><label class="resolved-search"><span>⌕</span><input type="search" value="${escapeHtml(state.query)}" oninput="filterResolvedAlerts(this.value)" placeholder="Search alerts…" aria-label="Search resolved alerts"></label><button type="button" class="resolved-filter-button" onclick="toggleResolvedAlertFilter()">⌑ Filter</button></div></div><div id="resolvedAlertFilter" class="resolved-filter-menu d-none"><div class="resolved-filter-grid"><label>Due date <select onchange="setResolvedAlertFilter('dueRange', this.value)"><option value="all">Any date</option><option value="overdue" ${filters.dueRange === "overdue" ? "selected" : ""}>Overdue</option><option value="next7" ${filters.dueRange === "next7" ? "selected" : ""}>Next 7 days</option><option value="next30" ${filters.dueRange === "next30" ? "selected" : ""}>Next 30 days</option><option value="custom" ${filters.dueRange === "custom" ? "selected" : ""}>Custom range</option></select></label><label>Resolved date <select onchange="setResolvedAlertFilter('resolvedRange', this.value)"><option value="all">Any date</option><option value="last7" ${filters.resolvedRange === "last7" ? "selected" : ""}>Last 7 days</option><option value="last30" ${filters.resolvedRange === "last30" ? "selected" : ""}>Last 30 days</option><option value="custom" ${filters.resolvedRange === "custom" ? "selected" : ""}>Custom range</option></select></label>${filters.dueRange === "custom" ? `<label>Due from <input type="date" value="${filters.dueFrom}" onchange="setResolvedAlertFilter('dueFrom', this.value)"></label><label>Due to <input type="date" value="${filters.dueTo}" onchange="setResolvedAlertFilter('dueTo', this.value)"></label>` : ""}${filters.resolvedRange === "custom" ? `<label>Resolved from <input type="date" value="${filters.resolvedFrom}" onchange="setResolvedAlertFilter('resolvedFrom', this.value)"></label><label>Resolved to <input type="date" value="${filters.resolvedTo}" onchange="setResolvedAlertFilter('resolvedTo', this.value)"></label>` : ""}<button type="button" class="resolved-clear-filter" onclick="clearResolvedAlertFilters()">Clear filters</button></div></div>
        <div class="table-responsive"><table class="table resolved-alerts-table"><thead><tr><th>#</th><th>Project Name</th><th>Alert / Milestone</th><th>Due Date</th><th>Resolved Date</th><th>Status</th><th>Action</th></tr></thead><tbody>${rows.length ? rows.map((item, index) => `<tr><td>${start + index + 1}.</td><td class="resolved-project-name"><button type="button" class="resolved-project-link" onclick="viewProjectDetails(${item.project.id})">${escapeHtml(item.project.title || "Untitled project")}</button></td><td>${escapeHtml(item.label)}</td><td>${date(dueDate(item))}</td><td>${date(item.doneDate)}</td><td><span class="resolved-status-pill">Resolved</span></td><td>${item.automatic ? `<button type="button" class="resolved-restore-button" title="Edit or clear the actual milestone date to reopen this alert" onclick="viewProjectDetails(${item.project.id})">View</button>` : canResolveTimelineAlerts() ? `<button type="button" class="resolved-restore-button" onclick="invokeTimelineUnmarkDoneAction(${item.project.id}, '${item.type}')"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 7 4 12l5 5M4 12h10a6 6 0 1 1-6 6"/></svg><span>Restore</span></button>` : '<span class="text-muted small">View only</span>'}</td></tr>`).join("") : `<tr><td colspan="7" class="resolved-empty-cell">No resolved alerts match this view.</td></tr>`}</tbody></table></div>
        <div class="resolved-pagination"><span>Showing ${filtered.length ? start + 1 : 0} to ${Math.min(start + state.pageSize, filtered.length)} of ${filtered.length} results</span><div><button aria-label="Previous page" type="button" onclick="changeResolvedAlertsPage(-1)" ${state.page === 1 ? "disabled" : ""}>‹</button>${Array.from({ length: totalPages }, (_, i) => `<button type="button" class="${state.page === i + 1 ? "is-current" : ""}" onclick="goToResolvedAlertsPage(${i + 1})">${i + 1}</button>`).join("")}<button aria-label="Next page" type="button" onclick="changeResolvedAlertsPage(1)" ${state.page === totalPages ? "disabled" : ""}>›</button></div></div></section>`;
    arrangeResolvedAlertFilterFields(filters);
}

function arrangeResolvedAlertFilterFields(filters) {
    const grid = document.querySelector("#resolvedAlertFilter .resolved-filter-grid");
    if (!grid) return;
    const labels = [...grid.querySelectorAll(":scope > label")];
    const clearButton = grid.querySelector(":scope > .resolved-clear-filter");
    if (labels.length < 2) return;

    const dueGroup = document.createElement("div");
    dueGroup.className = "resolved-filter-group";
    dueGroup.append(labels[0]);

    const resolvedGroup = document.createElement("div");
    resolvedGroup.className = "resolved-filter-group";
    resolvedGroup.append(labels[1]);

    let index = 2;
    if (filters.dueRange === "custom") {
        if (labels[index]) dueGroup.append(labels[index++]);
        if (labels[index]) dueGroup.append(labels[index++]);
    }
    if (filters.resolvedRange === "custom") {
        if (labels[index]) resolvedGroup.append(labels[index++]);
        if (labels[index]) resolvedGroup.append(labels[index++]);
    }

    grid.replaceChildren(dueGroup, resolvedGroup);
    if (clearButton) grid.append(clearButton);
}

function selectResolvedAlertCategory(category) { window.resolvedAlertsState.category = category; window.resolvedAlertsState.page = 1; renderResolvedAlertsWorkspace(); }
function filterResolvedAlerts(query, mode = "all") {
    window.resolvedAlertsState.query = query;
    window.resolvedAlertsState.page = 1;
    renderResolvedAlertsWorkspace();
    const searchInput = document.querySelector(".resolved-search input");
    if (searchInput && mode === "all") {
        searchInput.focus();
        searchInput.setSelectionRange(query.length, query.length);
    }
}
function setResolvedAlertFilter(name, value) { window.resolvedAlertsState.filters[name] = value; window.resolvedAlertsState.page = 1; renderResolvedAlertsWorkspace(); document.getElementById("resolvedAlertFilter")?.classList.remove("d-none"); }
function clearResolvedAlertFilters() { window.resolvedAlertsState.filters = { dueRange: "all", dueFrom: "", dueTo: "", resolvedRange: "all", resolvedFrom: "", resolvedTo: "" }; window.resolvedAlertsState.query = ""; window.resolvedAlertsState.page = 1; renderResolvedAlertsWorkspace(); document.getElementById("resolvedAlertFilter")?.classList.remove("d-none"); }
function changeResolvedAlertsPage(delta) { window.resolvedAlertsState.page += delta; renderResolvedAlertsWorkspace(); }
function goToResolvedAlertsPage(page) { window.resolvedAlertsState.page = page; renderResolvedAlertsWorkspace(); }
function toggleResolvedAlertFilter() { document.getElementById("resolvedAlertFilter")?.classList.toggle("d-none"); }

// Completed milestone dates are the source of automatic resolution; manual flags stay intact.
function isMilestoneDateCompleted(value, today = timelineCalendarDay(new Date())) {
    return Boolean(projectCalendarDate(value) && value <= today);
}

function getAutomaticTimelineResolutions(project, today = timelineCalendarDay(new Date())) {
    const dates = getProjectMilestoneDates(project);
    const definitions = [
        ["noa", dates.noaDueDate ? "noa-due" : "noa-active", "Notice of Award", ["noa-warning", "noa-active", "noa-overdue", "noa-due", "noa-due-overdue"], dates.noaDueDate || project.noa],
        ["preInspection", "pre-inspection", "Pre-inspection deadline", ["pre-inspection", "pre-inspection-overdue"], dates.preInspectionDueDate],
        ["ntpDate", "ntp-due", "NTP issuance deadline", ["ntp-due", "ntp-due-overdue", "ntp-award", "ntp-overdue"], dates.ntpDueDate],
        ["materialTestingDate", "material-test", "Material testing deadline", ["material-test", "material-overdue"], dates.materialTestingDueDate],
        ["completion", "contract-end", "Contract End Follow-up", ["contract-end", "contract-end-overdue"], dates.contractEndReminderDate],
        ["finalInspection", "final-inspection", "Final inspection deadline", ["final-inspection", "final-inspection-overdue"], dates.finalInspectionDueDate],
        ["swa", "award-overdue", "SWA contractor follow-up", ["award-close", "award-overdue"], getSwaDueDateValue(project.completion)]
    ];
    return definitions.flatMap(([field, type, label, aliases, dueDate]) => {
        if (type === 'contract-end' && !dueDate) return [];
        const date = projectCalendarDate(project[field]);
        if (!date || !today || project[field] > today) return [];
        if (field === "materialTestingDate" && !dueDate) {
            const start = projectCalendarDate(project.ntpDate);
            if (start) { start.setTime(addProjectWorkdays(start, (getTimelineDays("material"))).getTime()); dueDate = start.toISOString().slice(0, 10); }
        }
        return [{type, label, aliases, doneDate: project[field], dueDate, automatic: true}];
    });
}

function resolvedTimelineEntries(project, today = timelineCalendarDay(new Date())) {
    const automatic = getAutomaticTimelineResolutions(project, today);
    let types = Array.isArray(project.timelineAlertsCompleted) ? [...project.timelineAlertsCompleted] : [];
    if (!types.length && project.timelineCompletedMarked) {
        if (projectCalendarDate(project.noa) && project.noa <= today) types.push("noa-overdue");
        if (projectCalendarDate(project.ntpDate) && project.ntpDate <= today) types.push("ntp-overdue", "material-overdue");
        if (projectCalendarDate(project.completion) && project.completion <= today) types.push("award-overdue");
        if (!types.length) types = ["resolved-general"];
    }
    const labels = {"contract-end":"Contract End Follow-up","contract-end-overdue":"Contract End Follow-up","noa-due":"NOA issuance deadline","noa-due-overdue":"NOA issuance deadline","noa-warning":"NOA issuance deadline","noa-active":"NOA issuance deadline","noa-overdue":"NOA issuance deadline","ntp-award":"NTP issuance deadline","ntp-overdue":"NTP issuance deadline","ntp-due":"NTP issuance deadline","ntp-due-overdue":"NTP issuance deadline","material-test":"Material testing deadline","material-overdue":"Material testing deadline","award-close":"SWA contractor reminder","award-overdue":"SWA contractor follow-up","pre-inspection":"Pre-inspection deadline","pre-inspection-overdue":"Pre-inspection deadline","final-inspection":"Final inspection deadline","final-inspection-overdue":"Final inspection deadline","resolved-general":"Resolved timeline alert"};
    const entries = types.map(type => {
        const auto = automatic.find(item => item.aliases.includes(type));
        return {project, type, label: labels[type] || type.replace(/-/g, " "), doneDate: project.timelineMarkedDoneDate, ...(auto ? {...auto, type} : {})};
    });
    automatic.forEach(auto => {
        if (!types.some(type => auto.aliases.includes(type))) entries.push({project, ...auto});
    });
    return entries;
}

function renderMarkedDoneTabContents() {
    if (!db) return;
    const request = db.transaction(["projects"], "readonly").objectStore("projects").getAll();
    request.onsuccess = event => {
        const entries = [];
        (event.target.result || []).filter(project => !project.archivedAt).forEach(project => {
            entries.push(...resolvedTimelineEntries(project));
        });
        window.resolvedAlertsState.entries = entries;
        renderResolvedAlertsWorkspace();
    };
}

/* ========================================================================== 
   DASHBOARD TIMELINE METRICS
   ========================================================================== */


function renderDashboardBarChart(canvasId, labels, values, color, emptyMessage) {
    const canvas = document.getElementById(canvasId);

    if (!canvas) {
        return;
    }

    const bounds = canvas.getBoundingClientRect();
    const width = Math.max(1, Math.floor(bounds.width));
    const height = Math.max(1, Math.floor(bounds.height));
    const pixelRatio = window.devicePixelRatio || 1;

    canvas.width = width * pixelRatio;
    canvas.height = height * pixelRatio;

    const context = canvas.getContext("2d");
    context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
    context.clearRect(0, 0, width, height);

    context.font = "12px system-ui, sans-serif";

    if (!values.length) {
        context.fillStyle = "#64748b";
        context.textAlign = "center";
        context.fillText(emptyMessage, width / 2, height / 2);
        return;
    }

    const labelWidth = Math.min(128, Math.max(76, width * 0.32));
    const topPadding = 8;
    const rowHeight = Math.max(24, Math.min(34, (height - topPadding) / values.length));
    const barHeight = Math.max(10, Math.min(16, rowHeight - 10));
    const maxValue = Math.max(...values, 1);
    const chartWidth = width - labelWidth - 34;

    labels.forEach((label, index) => {
        const y = topPadding + (index * rowHeight);
        const value = values[index];
        const barWidth = (value / maxValue) * chartWidth;
        const shortenedLabel = label.length > 18
            ? `${label.slice(0, 17)}…`
            : label;

        context.fillStyle = "#475569";
        context.textAlign = "right";
        context.textBaseline = "middle";
        context.fillText(shortenedLabel, labelWidth - 8, y + (barHeight / 2));

        context.fillStyle = "#e2e8f0";
        context.fillRect(labelWidth, y, chartWidth, barHeight);

        context.fillStyle = Array.isArray(color) ? (color[index] || "#64748b") : color;
        context.fillRect(labelWidth, y, barWidth, barHeight);

        context.fillStyle = "#334155";
        context.textAlign = "left";
        context.fillText(String(value), labelWidth + barWidth + 6, y + (barHeight / 2));
    });
}


function renderDashboardMonthlyChart(canvasId, labels, values, color, emptyMessage) {
    const canvas = document.getElementById(canvasId);

    if (!canvas) return;

    const bounds = canvas.getBoundingClientRect();
    const width = Math.max(1, Math.floor(bounds.width));
    const height = Math.max(1, Math.floor(bounds.height));
    const pixelRatio = window.devicePixelRatio || 1;
    const context = canvas.getContext("2d");

    canvas.width = width * pixelRatio;
    canvas.height = height * pixelRatio;
    context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
    context.clearRect(0, 0, width, height);
    context.font = "12px system-ui, sans-serif";

    if (!values.length || values.every(value => value === 0)) {
        context.fillStyle = "#64748b";
        context.textAlign = "center";
        context.fillText(emptyMessage, width / 2, height / 2);
        return;
    }

    const padding = { top: 20, right: 18, bottom: 38, left: 30 };
    const chartWidth = width - padding.left - padding.right;
    const chartHeight = height - padding.top - padding.bottom;
    const maxValue = Math.max(...values, 1);
    const slotWidth = chartWidth / values.length;
    const barWidth = Math.max(8, Math.min(44, slotWidth * 0.58));

    context.strokeStyle = "#e2e8f0";
    context.lineWidth = 1;
    context.beginPath();
    context.moveTo(padding.left, padding.top + chartHeight);
    context.lineTo(width - padding.right, padding.top + chartHeight);
    context.stroke();

    values.forEach((value, index) => {
        const barHeight = (value / maxValue) * chartHeight;
        const x = padding.left + (slotWidth * index) + ((slotWidth - barWidth) / 2);
        const y = padding.top + chartHeight - barHeight;
        const label = labels[index].replace(/\s\d{4}$/, "");

        context.fillStyle = color;
        context.fillRect(x, y, barWidth, barHeight);

        context.fillStyle = "#334155";
        context.textAlign = "center";
        context.fillText(String(value), x + (barWidth / 2), y - 6);

        context.fillStyle = "#64748b";
        context.fillText(label, x + (barWidth / 2), height - 16);
    });
}


function setDashboardCompletionYear(year) {
    dashboardCompletionYear = String(year || "");
    loadDashboardData(1);
}

function setDashboardStatusPeriod(month, year) {
    dashboardStatusMonth = String(month || "");
    dashboardStatusYear = /^\d{4}$/.test(String(year || "")) ? String(year) : String(new Date().getFullYear());
    loadDashboardData(1);
}

let dashboardAlertPage = 1;
let dashboardLoadTimer = null;
let dashboardRequestSequence = 0;

function scheduleDashboardDataLoad() {
    clearTimeout(dashboardLoadTimer);
    dashboardLoadTimer = setTimeout(() => loadDashboardData(1), 300);
}

async function loadDashboardData(page = dashboardAlertPage, silent = false) {
    if (!window.apiClient || activeWorkspace !== "dashboard") return;
    const requestSequence = ++dashboardRequestSequence;
    const selectedStatusYear = /^\d{4}$/.test(dashboardStatusYear) ? dashboardStatusYear : String(new Date().getFullYear());
    const selectedCompletionYear = /^\d{4}$/.test(dashboardCompletionYear) ? dashboardCompletionYear : selectedStatusYear;
    try {
        const result = await apiClient.getDashboard({
            year: selectedStatusYear,
            month: dashboardStatusMonth,
            completionYear: selectedCompletionYear,
            alertPage: page,
            alertPageSize: 25,
            alertSearch: document.getElementById("dashboardSearchInput")?.value.trim() || ""
        });
        if (requestSequence !== dashboardRequestSequence || activeWorkspace !== "dashboard") return;
        dashboardAlertPage = result.alertPage || 1;
        dashboardStatusYear = selectedStatusYear;
        dashboardCompletionYear = selectedCompletionYear;
        activeDashboardAlertsData = result.alerts || [];
        window.dashboardAlertTotal = Number(result.alertTotal) || activeDashboardAlertsData.length;

        const metrics = result.metrics || {};
        const metricValues = { totalDocs: metrics.total, pendingDocs: metrics.pending, completedDocs: metrics.completed, overdueDocs: metrics.alerts };
        Object.entries(metricValues).forEach(([id, value]) => { const element = document.getElementById(id); if (element) element.textContent = Number(value) || 0; });
        const timelineBlock = document.getElementById("dashboardTimelineBlock");
        const alertSearchActive = Boolean(document.getElementById("dashboardSearchInput")?.value.trim());
        // An empty search result must keep the search control available to clear it.
        timelineBlock?.classList.toggle("dashboard-panel-empty", (metrics.alerts || 0) === 0 && !alertSearchActive);
        const alertBadge = document.getElementById("dashboardAlertBadge");
        if (alertBadge) alertBadge.textContent = metrics.alerts ? `${metrics.alerts} active` : alertSearchActive ? "No matches" : "All clear";

        const statusMonth = document.getElementById("dashboardStatusMonth");
        const statusYear = document.getElementById("dashboardStatusYear");
        const completionYear = document.getElementById("dashboardCompletionYear");
        if (statusMonth) statusMonth.value = dashboardStatusMonth;
        if (statusYear) statusYear.value = dashboardStatusYear;
        if (completionYear) completionYear.value = dashboardCompletionYear;
        const latestStatus = document.getElementById("dashboardLatestStatusYear");
        const latestCompletion = document.getElementById("dashboardLatestCompletionYear");
        if (latestStatus) latestStatus.textContent = result.statusYears?.length ? `Latest: ${result.statusYears[0]}` : "No data";
        if (latestCompletion) latestCompletion.textContent = result.completionYears?.length ? `Latest: ${result.completionYears[0]}` : "No data";

        const statusEntries = Object.entries(result.statusCounts || {});
        renderDashboardBarChart("dashboardStatusChart", statusEntries.map(([label]) => label), statusEntries.map(([, count]) => count), statusEntries.map(([label]) => label === "Pending" ? "#d97706" : label === "Completed" ? "#16a34a" : label === "Final-inspection" ? "#dc2626" : "#2563eb"), "No project statuses in this period.");
        const monthLabels = Array.from({ length: 12 }, (_, index) => new Date(Number(dashboardCompletionYear), index, 1).toLocaleDateString(undefined, { month: "short" }));
        renderDashboardMonthlyChart("dashboardCompletionChart", monthLabels, result.completionCounts || Array(12).fill(0), "#16a34a", `No completed projects in ${dashboardCompletionYear}.`);
        renderRecentDashboardProjects(result.recent || []);
        filterDashboardAlertsList();
        renderDashboardAlertPagination(result);
        refreshGlobalNotifications();
        const lastUpdated = document.getElementById("dashboardLastUpdated");
        if (lastUpdated) lastUpdated.textContent = `Last updated: just now · ${new Date().toLocaleTimeString([], { hour: "numeric", minute: "2-digit" })}`;
        return true;
    } catch (error) {
        console.error("Unable to load dashboard data:", error);
        if (!silent) showSystemNotification(error.message || "Unable to load dashboard data.");
        return false;
    }
}

function renderDashboardAlertPagination(result) {
    const container = document.getElementById("dashboardAlertPagination");
    if (!container) return;
    const pages = Number(result.alertTotalPages) || 1;
    container.classList.toggle("d-none", pages <= 1);
    container.innerHTML = pages <= 1 ? "" : `<span>Showing page ${result.alertPage} of ${pages} · ${result.alertProjectTotal || 0} projects · ${result.alertTotal} alerts</span><div><button type="button" class="btn btn-outline-secondary btn-sm" onclick="loadDashboardData(${result.alertPage - 1})" ${result.alertPage <= 1 ? "disabled" : ""}>Previous</button><button type="button" class="btn btn-outline-secondary btn-sm" onclick="loadDashboardData(${result.alertPage + 1})" ${result.alertPage >= pages ? "disabled" : ""}>Next</button></div>`;
}

window.projectNotificationGroups = [];
window.notificationAlertTotal = 0;
let notificationRequestSequence = 0;
async function refreshGlobalNotifications() {
    if (!window.currentUser || !window.apiClient) return;
    const userId = window.currentUser.id;
    const sequence = ++notificationRequestSequence;
    try {
        const result = await apiClient.getNotifications();
        if (sequence !== notificationRequestSequence || userId !== window.currentUser?.id) return;
        window.projectNotificationGroups = result.groups || [];
        window.notificationAlertTotal = Number(result.alertTotal) || 0;
        renderServerDashboardNotifications();
    } catch (error) { console.warn("Unable to refresh notifications:",error); }
}

function renderServerDashboardNotifications() {
    const pending = window.currentUser?.role === "admin" ? window.pendingAccountRequests || [] : [];
    const list = document.getElementById("notificationList");
    if (list) {
        const accounts = pending.length ? `<li class="notification-project-row"><span>${pending.length} account requests awaiting approval</span><button class="btn btn-sm btn-primary" onclick="openPendingAccountRequests()">Review</button></li>` : "";
        list.innerHTML = accounts + (window.projectNotificationGroups || []).map(group => `<li class="notification-project-row"><div><button type="button" class="notification-project-link" onclick="openNotificationProject(${Number(group.project.id)}, event)" aria-haspopup="dialog" aria-controls="projectDetailsModal">${escapeHtml(group.project.title || "Untitled project")}</button><span>${group.alerts.length} alerts · ${escapeHtml([...new Set(group.alerts.map(item => item.label))].join(", "))}</span></div><button type="button" class="project-alert-button btn btn-sm btn-outline-danger" data-project-alerts-id="${Number(group.project.id)}" aria-haspopup="dialog" aria-expanded="false" aria-controls="projectDetailsModal projectAlertsPanel" onclick="openNotificationProject(${Number(group.project.id)}, event, true)">Alerts</button></li>`).join("") || '<li class="text-muted text-center p-3">No notifications found.</li>';
    }
    const count = document.getElementById("notificationCount");
    if (count) { const total = (Number(window.notificationAlertTotal) || 0) + pending.length; count.textContent = total; count.classList.toggle("d-none",total === 0); }
}

function renderRecentDashboardProjects(docs) {
    const list = document.getElementById("dashboardRecentProjects");
    if (!list) return;

    const recent = [...docs]
        .sort((first, second) => new Date(second.updatedAt || second.createdAt || 0) - new Date(first.updatedAt || first.createdAt || 0))
        .slice(0, 5);

    if (!recent.length) {
        list.innerHTML = '<li class="dashboard-recent-empty">No project activity yet.</li>';
        return;
    }

    list.innerHTML = recent.map(project => {
        const when = new Date(project.updatedAt || project.createdAt || 0);
        const timestamp = Number.isNaN(when.getTime()) ? "Recently" : when.toLocaleString();
        const rawStatus = String(project.status || "Pending").trim() || "Pending";
        const status = escapeHtml(rawStatus);
        const statusClass = rawStatus.toLowerCase().includes("complete") ? "completed" : rawStatus.toLowerCase().includes("pending") ? "pending" : rawStatus.toLowerCase().includes("inspection") ? "inspection" : rawStatus.toLowerCase().includes("award") ? "awarded" : "ongoing";
        return `<li class="dashboard-recent-item dashboard-recent-item--${statusClass}"><div><strong>${escapeHtml(project.title || "Untitled project")}</strong><span>${escapeHtml(project.projectNo ? `Project no. ${project.projectNo}` : "Project record")}</span></div><div class="dashboard-recent-meta"><span class="dashboard-recent-status dashboard-recent-status--${statusClass}">${status}</span><time>${escapeHtml(timestamp)}</time></div></li>`;
    }).join("");
}


/* Dashboard alerts below render the server-provided alert page. */

function updateDashboardAlertScrollLimit() {
    const wrapper = document.querySelector(".dashboard-alert-list-wrapper");
    const list = document.getElementById("dashboardNotificationList");
    if (!wrapper || !list) return;

    const rows = Array.from(list.querySelectorAll(".dashboard-alert-row"));
    if (rows.length <= 4) {
        wrapper.style.maxHeight = "none";
        wrapper.style.overflowY = "visible";
        return;
    }

    const listStyle = getComputedStyle(list);
    const gap = parseFloat(listStyle.rowGap) || 0;
    const padding = (parseFloat(listStyle.paddingTop) || 0) + (parseFloat(listStyle.paddingBottom) || 0);
    const fourRowsHeight = rows.slice(0, 4).reduce((total, row) => total + row.getBoundingClientRect().height, 0);
    wrapper.style.maxHeight = `${Math.ceil(fourRowsHeight + gap * 3 + padding)}px`;
    wrapper.style.overflowY = "auto";
}

function groupDashboardAlerts(alerts) {
    const groups = new Map();
    for (const alert of alerts) {
        if (!groups.has(alert.project.id)) groups.set(alert.project.id,{project:alert.project,alerts:[]});
        groups.get(alert.project.id).alerts.push(alert);
    }
    return [...groups.values()];
}

function filterDashboardAlertsList() {
    const list = document.getElementById("dashboardNotificationList");
    if (!list) return;
    const groups = groupDashboardAlerts(activeDashboardAlertsData);
    list.innerHTML = groups.length ? groups.map(group => {
        const overdue = group.alerts.filter(item => item.type.includes("overdue")).length;
        const labels = [...new Set(group.alerts.map(item => item.label.replace(/ (Due|Overdue)$/,"")))];
        return `<li class="dashboard-alert-row dashboard-project-alert-row ${overdue ? "has-overdue" : ""}">
            <div class="dashboard-project-alert-content"><button type="button" class="dashboard-project-link" onclick="viewProjectDetails(${Number(group.project.id)}, 'needs-attention')">${escapeHtml(group.project.title || "Untitled project")}</button>
            <span class="dashboard-project-alert-number">Project no. ${escapeHtml(group.project.projectNo ?? "Not assigned")}</span>
            <span class="dashboard-project-alert-summary">${escapeHtml(labels.join(" · "))}</span></div>
            <span class="dashboard-alert-count">${group.alerts.length} alert${group.alerts.length === 1 ? "" : "s"}${overdue ? ` · ${overdue} overdue` : ""}</span>
            <button type="button" class="project-alert-button btn btn-outline-danger btn-sm" data-project-alerts-id="${Number(group.project.id)}" aria-haspopup="dialog" aria-expanded="false" aria-controls="projectAlertsPanel" onclick="openProjectAlerts(${Number(group.project.id)}, event)">Alerts</button></li>`;
    }).join("") : '<li class="dashboard-alert-empty p-4 text-muted">No alerts match this view.</li>';
    updateDashboardAlertScrollLimit();
}

window.addEventListener("resize", () => {
    clearTimeout(window.dashboardAlertResizeTimer);
    window.dashboardAlertResizeTimer = setTimeout(updateDashboardAlertScrollLimit, 100);
});
