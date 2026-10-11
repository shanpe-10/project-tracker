/* ==========================================================================
   SHARED UTILITY FUNCTIONS
   ========================================================================== */


/**
 * Returns the next available numeric project number.
 *
 * Example:
 * Existing project numbers:
 * 1, 2, 4, 7
 *
 * Result:
 * 8
 *
 * Project numbers are treated as persistent project data,
 * not as table row numbers.
 */
function getNextProjectNumber(projects = []) {
    let highestProjectNo = 0;

    projects.forEach(project => {
        if (!/^[0-9]+$/.test(String(project.projectNo ?? "").trim())) return;
        const numericProjectNo =
            Number.parseInt(
                project.projectNo,
                10
            );

        if (
            Number.isFinite(numericProjectNo) &&
            numericProjectNo > highestProjectNo
        ) {
            highestProjectNo =
                numericProjectNo;
        }
    });

    return highestProjectNo + 1;
}


/**
 * Cleans values read from stored project data.
 *
 * Null, undefined, and the legacy "-" placeholder
 * are converted into an empty string.
 */
function cleanStoredValue(value) {
    if (
        value === undefined ||
        value === null
    ) {
        return "";
    }

    const cleaned =
        String(value).trim();

    return cleaned === "-"
        ? ""
        : cleaned;
}


/**
 * Converts a stored date into a readable UI date.
 *
 * Example:
 * 2026-08-27
 *
 * becomes:
 * Aug 27, 2026
 */
function formatUIDate(dateVal) {
    if (!dateVal) {
        return "-";
    }

    let dateStr = "";


    /*
     * Handle actual JavaScript Date objects.
     */
    if (dateVal instanceof Date) {
        if (isNaN(dateVal.getTime())) {
            return "-";
        }

        const year =
            dateVal.getFullYear();

        const month =
            String(
                dateVal.getMonth() + 1
            ).padStart(
                2,
                "0"
            );

        const day =
            String(
                dateVal.getDate()
            ).padStart(
                2,
                "0"
            );

        dateStr =
            `${year}-${month}-${day}`;

    } else {

        /*
         * Handles:
         *
         * 2026-08-27
         *
         * and ISO values such as:
         *
         * 2026-08-27T10:30:00.000Z
         */
        dateStr =
            String(dateVal)
                .trim()
                .split("T")[0];
    }


    const parts =
        dateStr.split("-");


    if (parts.length !== 3) {
        return String(dateVal);
    }


    const year =
        parts[0];

    const monthIndex =
        Number.parseInt(
            parts[1],
            10
        ) - 1;

    const day =
        parts[2]
            .padStart(
                2,
                "0"
            );


    const months = [
        "Jan",
        "Feb",
        "Mar",
        "Apr",
        "May",
        "Jun",
        "Jul",
        "Aug",
        "Sep",
        "Oct",
        "Nov",
        "Dec"
    ];


    if (
        monthIndex < 0 ||
        monthIndex > 11
    ) {
        return String(dateVal);
    }


    return (
        `${months[monthIndex]} ` +
        `${day}, ${year}`
    );
}


/**
 * Formats a stored ISO timestamp using
 * Philippine Standard Time (UTC+8).
 *
 * Example:
 * 2026-09-02T01:00:00.000Z
 * becomes:
 * Sep 02, 2026 • 9:00 AM
 */
function formatUIDateTime(dateVal) {
    if (!dateVal) {
        return "Unknown Date";
    }


    const date =
        dateVal instanceof Date
            ? dateVal
            : new Date(dateVal);


    if (
        isNaN(
            date.getTime()
        )
    ) {
        return String(dateVal);
    }


    const datePart =
        new Intl.DateTimeFormat(
            "en-US",
            {
                timeZone: "Asia/Manila",

                month: "short",
                day: "2-digit",
                year: "numeric"
            }
        ).format(date);


    const timePart =
        new Intl.DateTimeFormat(
            "en-US",
            {
                timeZone: "Asia/Manila",

                hour: "numeric",
                minute: "2-digit",
                hour12: true
            }
        ).format(date);


    return `${datePart} • ${timePart}`;
}


/**
 * Formats dates used during Excel export.
 *
 * Empty values remain empty instead of
 * becoming the UI placeholder "-".
 */
function formatExportDate(dateVal) {
    return dateVal
        ? formatUIDate(dateVal)
        : "";
}


/**
 * Escapes text before inserting user/project data
 * into dynamically generated HTML.
 */
function escapeHtml(value) {
    if (
        value === undefined ||
        value === null
    ) {
        return "";
    }

    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}


/**
 * Expands a textarea vertically to fit its content.
 */
function autoExpandTextarea(element) {
    if (!element) {
        return;
    }

    element.style.height =
        "auto";

    element.style.height =
        `${element.scrollHeight}px`;
}

function compareProjectNumbers(first, second) {
    const firstNumber = Number.parseInt(first?.projectNo, 10);
    const secondNumber = Number.parseInt(second?.projectNo, 10);
    const firstIsNumeric = /^[0-9]+$/.test(String(first?.projectNo ?? "").trim()) && Number.isFinite(firstNumber);
    const secondIsNumeric = /^[0-9]+$/.test(String(second?.projectNo ?? "").trim()) && Number.isFinite(secondNumber);

    if (firstIsNumeric && secondIsNumeric) return firstNumber - secondNumber;
    if (firstIsNumeric) return -1;
    if (secondIsNumeric) return 1;
    return String(first?.projectNo || "").localeCompare(String(second?.projectNo || ""));
}

// Spreadsheet applications can execute formula-like CSV cells. Export them as text.
function safeCsvText(value) {
    const text = String(value ?? "");
    return /^[\s\x00-\x1f]*[=+@-]|^[\t\r\n]/u.test(text) ? "'" + text : text;
}

// Date-only arithmetic uses UTC so calendar deadlines do not shift with DST or timezone.
function projectCalendarDate(value) {
    if (typeof value !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(value) || value.startsWith("0000-")) return null;
    const date = new Date(value + "T00:00:00Z");
    return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value ? date : null;
}

function parseContractDuration(value) {
    if (!["string", "number"].includes(typeof value) || !/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i.test(String(value).trim())) return null;
    const days = Number(value);
    return Number.isFinite(days) && days > 0 && days <= 36500 ? days : null;
}

// Workday offsets exclude the starting date, weekends and declared national holidays.
function isProjectWorkday(date, utc = true) {
    const day = utc ? date.getUTCDay() : date.getDay();
    const year = utc ? date.getUTCFullYear() : date.getFullYear();
    const month = (utc ? date.getUTCMonth() : date.getMonth()) + 1;
    const dayOfMonth = utc ? date.getUTCDate() : date.getDate();
    const key = `${year}-${String(month).padStart(2, "0")}-${String(dayOfMonth).padStart(2, "0")}`;
    return !(window.projectHolidayCalendar?.nonWorkingDays || [0,6]).includes(day) && (window.projectHolidayCalendar?.excludeHolidays === false || !window.projectHolidayCalendar?.years?.[year]?.[key]);
}


function projectWeekday(date, utc = true) {
    if (!date) return null;
    const result = new Date(date);
    while (!isProjectWorkday(result, utc)) {
        if (utc) result.setUTCDate(result.getUTCDate() + 1);
        else result.setDate(result.getDate() + 1);
    }
    return result;
}

function addProjectWorkdays(date, days, utc = true) {
    let result = new Date(date);
    const direction = days < 0 ? -1 : 1;
    const whole = Math.floor(Math.abs(days));
    for (let i = 0; i < whole; i++) {
        do {
            if (utc) result.setUTCDate(result.getUTCDate() + direction);
            else result.setDate(result.getDate() + direction);
        } while (!isProjectWorkday(result, utc));
    }
    result = projectWeekday(result, utc);
    result = new Date(result.getTime() + direction * Math.round((Math.abs(days) - whole) * 86400) * 1000);
    return projectWeekday(result, utc);
}

function projectWorkdaysBetween(start, end) {
    const a = new Date(Date.UTC(start.getFullYear(), start.getMonth(), start.getDate()));
    const b = new Date(Date.UTC(end.getFullYear(), end.getMonth(), end.getDate()));
    if (a > b) return -projectWorkdaysBetween(end, start);
    let days = 0;
    while (a < b) {
        a.setUTCDate(a.getUTCDate() + 1);
        if (isProjectWorkday(a)) days++;
    }
    return days;
}

function getProjectMilestoneDates(project = {}) {
    const bidding = projectCalendarDate(project.biddingDate);
    let biddingDue = "";
    if (bidding) {
        const next = new Date(bidding.getTime());
        next.setUTCDate(1);
        next.setUTCMonth(next.getUTCMonth() + 1);
        const last = new Date(next.getTime());
        last.setUTCMonth(last.getUTCMonth() + 1);
        last.setUTCDate(0);
        next.setUTCDate(Math.min(bidding.getUTCDate(), last.getUTCDate()));
        biddingDue = projectWeekday(next).toISOString().slice(0, 10);
    }
    const days = parseContractDuration(project.contractDuration);
    const start = projectCalendarDate(project.contractRef);
    const end = start && days !== null
        ? addProjectWorkdays(start, days)
        : projectWeekday(projectCalendarDate(project.contractEndDate));
    return { noaDueDate: projectCalendarDate(project.noaDueDate) ? projectWeekday(projectCalendarDate(project.noaDueDate)).toISOString().slice(0, 10) : "", preInspectionDueDate: biddingDue, ntpDueDate: biddingDue, materialTestingDueDate: biddingDue, contractDueDate: end ? end.toISOString().slice(0, 10) : "", finalInspectionDueDate: end ? end.toISOString().slice(0, 10) : "" };
}

function projectMilestoneDueDate(project, type) {
    const dates = getProjectMilestoneDates(project);
    if (type.startsWith("noa")) return dates.noaDueDate;
    if (type.startsWith("pre-inspection")) return dates.preInspectionDueDate;
    if (type.startsWith("final-inspection")) return dates.finalInspectionDueDate;
    if (type.startsWith("ntp-due")) return dates.ntpDueDate;
    if (type.startsWith("material") && dates.materialTestingDueDate) return dates.materialTestingDueDate;
    return "";
}
