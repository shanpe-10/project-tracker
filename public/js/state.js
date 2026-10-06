/* ==========================================================================
   APPLICATION STATE
   ========================================================================== */


/*
 * Currently edited project.
 * null means the form is creating a new project.
 */
let editingId = null;


/*
 * Project table pagination.
 */
let currentPage = 1;

const itemsPerPage = 10;


/*
 * Projects currently available to the
 * Projects table after filtering/search.
 */
let currentProjectsList = [];
let projectListTotal = 0;
let projectListServerPaged = false;
let projectListSearchTimer = null;
let projectListRequestSequence = 0;


/*
 * Timeline alerts currently displayed
 * on the Dashboard.
 */
let activeDashboardAlertsData = [];


/*
 * Current application workspace.
 *
 * dashboard
 * dashboard-done
 * add-edit
 * documents
 */
let activeWorkspace = "dashboard";


/*
 * Temporary Excel-import processing state.
 */
let pendingImportPayloads = [];

let bulkImportConflictsQueue = [];
let bulkImportErrors = [];
let activeImportBatchId = "";
