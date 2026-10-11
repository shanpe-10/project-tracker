/* ==========================================================================
   WORKSPACE NAVIGATION & APPLICATION UI
   ========================================================================== */


/**
 * Switches between the main application workspaces.
 *
 * Available workspaces:
 *
 * dashboard
 * dashboard-done
 * add-edit
 * documents
 * archive

 */
function switchWorkspace(targetWorkspace) {
    if (window.currentUser?.role === "superadmin") targetWorkspace = "account-requests";
    if (targetWorkspace === "account-requests" && !["admin", "superadmin"].includes(window.currentUser?.role)) targetWorkspace = "dashboard";
    const previousWorkspace = activeWorkspace;
    if (previousWorkspace !== targetWorkspace && (["documents", "archive"].includes(previousWorkspace) || ["documents", "archive"].includes(targetWorkspace))) {
        clearTimeout(projectListSearchTimer);
        projectListRequestSequence++;
    }
    if (["documents", "archive"].includes(targetWorkspace) && previousWorkspace !== targetWorkspace && typeof resetProjectFilters === "function") {
        resetProjectFilters();
    }
    if (targetWorkspace === "dashboard-done" && previousWorkspace !== "dashboard-done" && typeof resetResolvedAlertsFilters === "function") {
        resetResolvedAlertsFilters();
    }
    if (targetWorkspace === "dashboard" && previousWorkspace !== "dashboard") {
        const dashboardSearch = document.getElementById("dashboardSearchInput");
        if (dashboardSearch) dashboardSearch.value = "";
    }
    activeWorkspace = targetWorkspace;
    sessionStorage.setItem("lguProjectTrackerWorkspace", targetWorkspace);


    const tabButtons =
        document.querySelectorAll(
            "#workspaceTabs .nav-link"
        );


    tabButtons.forEach(button => {
        button.classList.remove(
            "active"
        );
    });


    const container =
        document.getElementById(
            "workspaceContainer"
        );


    const formSection =
        document.getElementById(
            "formSection"
        );


    const tableSection =
        document.getElementById(
            "tableSection"
        );


    if (!container) {
        console.error(
            "Workspace container could not be found."
        );

        return;
    }


    container.classList.remove(
        "view-dashboard",
        "view-dashboard-done",
        "view-documents",
        "view-archive",
        "view-account-requests",
    );


    /* =========================================================
       DASHBOARD
       ========================================================= */
    if (targetWorkspace === "dashboard") {

        const button =
            findTabButtonByText(
                "dashboard view"
            );


        if (button) {
            button.classList.add(
                "active"
            );
        }


        container.classList.add(
            "view-dashboard"
        );


        renderMarkedDoneTabContents();

        loadDocuments();
    }


    /* =========================================================
       RESOLVED ALERTS
       ========================================================= */
    else if (
        targetWorkspace ===
        "dashboard-done"
    ) {

        const button =
            findTabButtonByText(
                "resolved alerts"
            );


        if (button) {
            button.classList.add(
                "active"
            );
        }


        container.classList.add(
            "view-dashboard-done"
        );


        renderMarkedDoneTabContents();

        loadDocuments();
    }

    /* =========================================================
       ACCOUNT REQUESTS (ADMIN ONLY)
       ========================================================= */
    else if (targetWorkspace === "account-requests") {
        const button = document.getElementById("accountRequestsTabBtn");
        if (button) button.classList.add("active");
        container.classList.add("view-account-requests");
        if (window.currentUser?.role === "superadmin") loadAccountRequests();
        if (typeof loadHolidayCalendar === "function") loadHolidayCalendar();
        if (window.currentUser?.role === "admin") { loadAuditLog(); loadBackupSettings(); loadTimelineLeadTimes(); }
    }


    /* =========================================================
       ADD / EDIT PROJECT
       ========================================================= */
    else if (
        targetWorkspace ===
        "add-edit"
    ) {

        const button =
            findTabButtonByText(
                "add"
            );


        if (button) {
            button.classList.add(
                "active"
            );
        }


        if (formSection) {
            formSection.className =
                "col-12";
        }
    }


    /* =========================================================
       PROJECTS
       ========================================================= */
    else if (
        targetWorkspace ===
        "documents" || targetWorkspace === "archive"
    ) {

        projectLifecycle = targetWorkspace === "archive" ? "archived" : projectLifecycle === "archived" ? "active" : projectLifecycle;
        if (typeof syncProjectStatusOptions === "function") syncProjectStatusOptions();
        if (typeof updateProjectLifecycleControls === "function") updateProjectLifecycleControls();
        document.getElementById("projectsLifecycleTabs")?.classList.toggle("d-none", targetWorkspace === "archive");
        document.getElementById("addProjectButton")?.classList.toggle("d-none", targetWorkspace === "archive");
        const pageTitle = document.getElementById("projectsPageTitle");
        const pageDescription = document.getElementById("projectsPageDescription");
        if (pageTitle) pageTitle.textContent = targetWorkspace === "archive" ? "Historical Archive" : "Projects";
        if (pageDescription) pageDescription.textContent = targetWorkspace === "archive" ? "Search and review archived project records." : "Search, review, update, import, and export recorded project information.";

        if (previousWorkspace !== targetWorkspace && typeof prepareProjectListForLoad === "function") {
            prepareProjectListForLoad();
        }

        const button =
            targetWorkspace === "archive"
                ? document.getElementById("historicalArchiveTabBtn")
                : findTabButtonByText("view all projects");


        if (button) {
            button.classList.add(
                "active"
            );
        }


        container.classList.add(targetWorkspace === "archive" ? "view-archive" : "view-documents");


        if (tableSection) {
            tableSection.className =
                "col-12";
        }


        loadDocuments();
    }



    /*
     * Re-evaluate the form highlighting after
     * changing workspace visibility.
     */
    setTimeout(() => {

        if (
            typeof initializeLiveValueTracking ===
            "function"
        ) {
            initializeLiveValueTracking();
        }

    }, 20);
}

function openAddProjectPane() {
    if (typeof canCreateProjects === "function" && !canCreateProjects()) {
        showSystemNotification("You do not have permission to add projects.");
        return;
    }
    if (typeof resetFormAccess === "function") resetFormAccess({ silent: true, navigate: false });
    projectEditReturnWorkspace = "documents";
    switchWorkspace("add-edit");
    document.body.classList.add("project-edit-pane-open");
    document.getElementById("editPaneCloseButton")?.classList.remove("d-none");
    if (typeof loadNextProjectNumberPreview === "function") loadNextProjectNumberPreview();
}

function restoreLastWorkspace() {
    const savedWorkspace = sessionStorage.getItem("lguProjectTrackerWorkspace");
    const allowedWorkspaces = ["dashboard", "dashboard-done", "documents", "archive", "account-requests"];
    const workspace = allowedWorkspaces.includes(savedWorkspace) ? savedWorkspace : "dashboard";
    switchWorkspace(workspace === "account-requests" && !["admin", "superadmin"].includes(window.currentUser?.role) ? "dashboard" : workspace);
}


/**
 * Finds a sidebar navigation button using
 * the button's visible or visually-hidden text.
 */
function findTabButtonByText(keyword) {
    const buttons =
        document.querySelectorAll(
            "#workspaceTabs .nav-link"
        );


    for (const button of buttons) {

        if (
            button.textContent
                .toLowerCase()
                .includes(
                    keyword.toLowerCase()
                )
        ) {
            return button;
        }
    }


    return null;
}


/* ==========================================================================
   SYSTEM TOAST NOTIFICATIONS
   ========================================================================== */


/**
 * Displays a Bootstrap toast containing
 * application feedback.
 */
function showSystemNotification(message) {
    const toastBody =
        document.getElementById(
            "toastMessageBody"
        );


    const toastElement =
        document.getElementById(
            "actionFeedbackToast"
        );


    if (
        !toastBody ||
        !toastElement
    ) {
        return;
    }


    toastBody.textContent =
        message;


    if (
        typeof bootstrap !== "undefined" &&
        bootstrap.Toast
    ) {

        const toast =
            bootstrap.Toast
                .getOrCreateInstance(
                    toastElement
                );


        toast.show();
    }
}


/* ==========================================================================
   NOTIFICATION PANEL
   ========================================================================== */


/**
 * Opens or closes the notification dropdown.
 */
function toggleNotifications(event) {
    hideProjectAlertsDropdown();
    if (event) {
        event.stopPropagation();
    }


    const panel =
        document.getElementById(
            "notificationAlertPanel"
        );


    if (panel) {
        if (panel.classList.contains("d-none")) refreshGlobalNotifications();
        panel.classList.toggle(
            "d-none"
        );
    }
}

function toggleAccountMenu(event) {
    hideProjectAlertsDropdown();
    if (event) event.stopPropagation();
    document.getElementById("notificationAlertPanel")?.classList.add("d-none");
    const menu = document.getElementById("accountMenu");
    const button = document.querySelector(".topbar-profile-button");
    if (!menu) return;
    menu.classList.toggle("d-none");
    button?.setAttribute("aria-expanded", String(!menu.classList.contains("d-none")));
}


/**
 * Close the notification panel when the user
 * clicks anywhere outside the panel or bell.
 */
document.addEventListener(
    "click",
    function (event) {

        const panel =
            document.getElementById(
                "notificationAlertPanel"
            );


        const bellButton =
            document.getElementById(
                "notificationBellBtn"
            );


        if (
            panel &&
            !panel.classList.contains(
                "d-none"
            ) &&
            bellButton &&
            !bellButton.contains(
                event.target
            ) &&
            !panel.contains(
                event.target
            )
        ) {
            panel.classList.add(
                "d-none"
            );
        }
        const accountMenu = document.getElementById("accountMenu");
        const profileButton = document.querySelector(".topbar-profile-button");
        if (accountMenu && !accountMenu.classList.contains("d-none") && profileButton && !profileButton.contains(event.target) && !accountMenu.contains(event.target)) {
            accountMenu.classList.add("d-none");
            profileButton.setAttribute("aria-expanded", "false");
        }
    }
);
