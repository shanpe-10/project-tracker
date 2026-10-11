{{--
    System developers:
    Sherwin Angelo E. Pesado
    Gabriel C. Gamo
    Danna Ashlynn Bautista
    Guiller Verdadero
--}}
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>LGU Laoag - Project Tracker Management</title>

    <link rel="icon" type="image/png" href="logo.png">

    <link
        href="vendor/bootstrap/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="css/styles.css?v=20261011-account-sessions">
</head>


<body
    class="view-dashboard authentication-required"
    id="workspaceContainer"
>

    <div id="loginOverlay" class="login-overlay">
        <form id="loginForm" class="login-card" onsubmit="signIn(event)">
            <img src="logo.png" alt="LGU Laoag" class="login-logo">
            <h1>Project Tracker</h1>
            <p class="text-muted mb-4">Sign in to access the shared LGU registry.</p>
            <div class="mb-3">
                <label for="loginUsername" class="form-label">Username</label>
                <input id="loginUsername" class="form-control" autocomplete="username" required autofocus>
            </div>
            <div class="mb-2">
                <label for="loginPassword" class="form-label">Password</label>
                <input id="loginPassword" type="password" class="form-control" autocomplete="current-password" required>
            </div>
            <p id="loginError" class="text-danger small min-vh-0 mb-3"></p>
            <button class="btn btn-primary w-100" type="submit">Sign in</button>
            <p class="small text-muted text-center mt-3 mb-0">Need a new account? Contact the IT department.</p>
        </form>
    </div>

    <!-- =========================================================
         LOADING OVERLAY
         ========================================================= -->
    <div id="loadingOverlay">

        <div
            class="spinner-border text-danger mb-3"
            style="width: 3.5rem; height: 3.5rem;"
            role="status"
        ></div>

        <h4 class="fw-bold text-uppercase m-0">
            Processing Database Request...
        </h4>

        <p class="text-white-50 small mt-1 mb-0">
            Please do not refresh your browser interface.
        </p>

    </div>


    <!-- =========================================================
         TOP BAR
         ========================================================= -->
    <header class="app-topbar">

        <div class="app-brand">

            <img
                src="logo.png"
                alt="Project Tracker Logo"
                class="app-logo"
            >

            <div>

                <div class="app-brand-title">
                    Project Tracker
                </div>

                <div class="app-brand-subtitle">
                    LGU Laoag - Office of Engineering
                </div>

            </div>

        </div>


        <div class="topbar-actions position-relative">
            <span id="projectSaveStatus" class="project-save-status small d-none" role="status" aria-live="polite" aria-atomic="true"></span>

            <button
                type="button"
                id="sharedUpdatesButton"
                class="btn btn-sm btn-warning d-none"
                onclick="refreshSharedProjectData()"
                title="Shared changes will load automatically after you save or cancel your current changes"
            >
                Updates available
            </button>

            <button
                type="button"
                onclick="toggleNotifications(event)"
                id="notificationBellBtn"
                class="topbar-icon-button position-relative"
                aria-label="Notifications"
                title="Notifications"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
                    <path d="M10 21h4" />
                </svg>

                <span
                    id="notificationCount"
                    class="topbar-notification-count d-none position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                >
                    0
                </span>
            </button>

            <span class="topbar-account-divider" aria-hidden="true"></span>

            <button type="button" class="topbar-profile-button" onclick="toggleAccountMenu(event)" aria-label="Account menu" aria-expanded="false" aria-controls="accountMenu" title="Account menu">
                <span class="topbar-user-avatar" id="currentUserInitial">U<img id="currentUserPhoto" class="d-none" alt=""></span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5" /></svg>
            </button>

            <div id="accountMenu" class="topbar-account-menu d-none" aria-label="Your account">
                <div class="account-menu-profile">
                    <span class="topbar-user-avatar" id="accountMenuAvatar" aria-hidden="true">U</span>
                    <div class="account-menu-identity"><strong id="currentUserName"></strong><span id="accountMenuRole"></span></div>
                </div>
                <div class="account-menu-actions">
                    <button type="button" onclick="openProfileEditor()"><span class="account-menu-icon" aria-hidden="true">✎</span><span>Edit profile</span></button>
                    <button type="button" onclick="signOut()"><span class="account-menu-icon" aria-hidden="true">↪</span><span>Sign out</span></button>
                </div>
            </div>


            <div
                id="notificationAlertPanel"
                class="card notification-panel d-none bg-white" role="region" aria-label="Notifications"
            >

                <div class="notification-panel-header">
                    <span class="notification-panel-title">Notifications</span>
                    <span class="notification-panel-subtitle">Updates that need your attention</span>
                </div>

                <div class="card-body notification-panel-body">

                    <ul
                        id="notificationList"
                        class="notification-list list-unstyled mb-0"
                    >
                        <li>
                            No urgent timelines found.
                        </li>
                    </ul>

                </div>

            </div>

        </div>

    </header>

    <div class="modal fade profile-editor-modal" id="profileEditorModal" tabindex="-1" aria-labelledby="profileEditorTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form class="modal-content" onsubmit="saveProfile(event)">
                <div class="modal-header profile-editor-header">
                    <div>
                        <h5 class="modal-title" id="profileEditorTitle">Edit profile</h5>
                        <p>Choose and position your profile picture.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body profile-editor-body">
                    <div class="profile-picture-pane">
                        <div class="profile-picture-pane-toolbar">
                            <strong>Profile picture</strong>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="chooseProfilePhoto(event)">Upload new photo</button>
                        </div>
                        <div class="profile-editor-photo" id="profileCropArea" onclick="chooseProfilePhoto(event)" onpointerdown="beginProfilePhotoDrag(event)" role="button" tabindex="0" aria-label="Change profile photo">
                            <img id="profileEditorPreview" class="d-none" alt="Profile photo preview">
                            <span id="profileEditorInitial">U</span>
                            <span class="profile-crop-mask" aria-hidden="true"></span>
                            <span id="profilePhotoAction" class="profile-photo-action"><span aria-hidden="true">📷</span> Choose photo</span>
                            <span id="profileCropHint" class="d-none">Drag to reposition</span>
                        </div>
                        <input id="profilePhotoInput" class="visually-hidden" type="file" accept="image/jpeg,image/png,image/webp" onchange="previewProfilePhoto(this)">
                        <div id="profilePhotoTools" class="profile-photo-tools d-none">
                            <button type="button" aria-label="Rotate left" title="Rotate left" onclick="adjustProfilePhoto('rotate', -90)">↺</button>
                            <label class="profile-zoom-control"><span aria-hidden="true">−</span><input id="profilePhotoZoom" type="range" min="1" max="3" step="0.05" value="1" aria-label="Zoom profile photo" oninput="adjustProfilePhoto('zoom', this.value)"><span aria-hidden="true">＋</span></label>
                            <button type="button" aria-label="Rotate right" title="Rotate right" onclick="adjustProfilePhoto('rotate', 90)">↻</button>
                        </div>
                        <p class="profile-photo-help">JPG, PNG, or WebP up to 3 MB. Drag the image to reposition it inside the circle.</p>
                    </div>
                    <div class="profile-details-pane">
                        <label class="form-label" for="profileFullName">Display name</label>
                        <input id="profileFullName" class="form-control" required maxlength="160">
                    </div>
                    <p id="profileEditorError" class="text-danger small mt-3 mb-0"></p>
                </div>
                <div class="modal-footer profile-editor-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary px-4">Save changes</button></div>
            </form>
        </div>
    </div>


    <!-- =========================================================
         SIDEBAR
         ========================================================= -->
    <aside class="app-sidebar">

        <div class="sidebar-heading">
            Workspace
        </div>


        <nav
            class="nav flex-column sidebar-navigation"
            id="workspaceTabs"
        >

            <button
                type="button"
                class="nav-link active" data-project-navigation
                onclick="switchWorkspace('dashboard')"
            >
                <span class="sidebar-nav-icon" aria-hidden="true">▦</span>
                <span>Dashboard</span>

                <span class="visually-hidden">
                    Dashboard View
                </span>
            </button>


            <button
                type="button"
                class="nav-link" data-project-navigation
                onclick="openAllProjects()"
            >
                <span class="sidebar-nav-icon" aria-hidden="true">▤</span>
                <span>View All Projects</span>
            </button>


            <button type="button" class="nav-link" data-project-navigation id="historicalArchiveTabBtn" onclick="switchWorkspace('archive')">
                <span class="sidebar-nav-icon" aria-hidden="true">▣</span>
                <span>Historical Archive</span>
            </button>


            <button
                type="button"
                class="nav-link" data-project-navigation
                onclick="switchWorkspace('dashboard-done')"
            >
                <span class="sidebar-nav-icon" aria-hidden="true">✓</span>
                <span>Resolved Alerts</span>
            </button>

            <button
                type="button"
                class="nav-link d-none"
                id="accountRequestsTabBtn"
                onclick="switchWorkspace('account-requests')"
            >
                <span class="sidebar-nav-icon" aria-hidden="true">◌</span>
                <span>Admin</span>
            </button>

            <button type="button" class="nav-link" data-bs-toggle="modal" data-bs-target="#systemGuideModal">
                <span class="sidebar-nav-icon" aria-hidden="true">?</span>
                <span id="systemGuideNavigationLabel">How to use this tracker</span>
            </button>


            <div class="sidebar-divider"></div>
        </nav>

    </aside>



    <!-- =========================================================
         TOAST
         ========================================================= -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3">

        <div
            id="actionFeedbackToast"
            class="toast align-items-center text-white bg-dark border-0 shadow"
            role="alert"
            aria-live="assertive"
            aria-atomic="true"
            data-bs-delay="4000"
        >

            <div class="d-flex">

                <div
                    class="toast-body fw-medium"
                    id="toastMessageBody"
                ></div>

                <button
                    type="button"
                    class="btn-close btn-close-white me-2 m-auto"
                    data-bs-dismiss="toast"
                    aria-label="Close"
                ></button>

            </div>

        </div>

    </div>


    <!-- =========================================================
         MAIN APPLICATION
         ========================================================= -->
    <main class="app-content">

        <div class="container-fluid py-4">

            <div class="row g-4">


                <!-- =================================================
                     DASHBOARD
                     ================================================= -->
                <div
                    id="dashboardSection"
                    class="col-12"
                    style="display: none;"
                >

                    <!-- PAGE HEADER -->
                    <div class="dashboard-page-header">

                        <div>

                            <div class="dashboard-eyebrow">
                                Overview
                            </div>

                            <h1 class="dashboard-title">
                                Dashboard
                            </h1>

                            <p class="dashboard-description">
                                Monitor project activity, completion status,
                                and upcoming procurement timeline requirements.
                            </p>

                            <p id="dashboardLastUpdated" class="dashboard-last-updated" aria-live="polite">Last updated: —</p>

                        </div>

                    </div>


                    <!-- DASHBOARD METRICS -->
                    <div class="dashboard-metrics-grid">


                        <div class="dashboard-metric-card dashboard-metric-action" role="button" tabindex="0" onclick="openAllProjects()" onkeydown="handleDashboardMetricKey(event, openAllProjects)">

                            <div class="dashboard-metric-top">

                                <div class="dashboard-metric-icon metric-primary">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M3 6.75A1.75 1.75 0 0 1 4.75 5h4.1l1.65 2h8.75A1.75 1.75 0 0 1 21 8.75v9.5A1.75 1.75 0 0 1 19.25 20h-14A1.75 1.75 0 0 1 3.5 18.25v-11.5Z" />
                                    </svg>
                                </div>

                                <span class="dashboard-metric-label">
                                    Total Projects
                                </span>

                            </div>

                            <div
                                id="totalDocs"
                                class="dashboard-metric-value"
                            >
                                0
                            </div>

                            <div class="dashboard-metric-caption">
                                Current non-archived projects
                            </div>

                        </div>


                        <div class="dashboard-metric-card dashboard-metric-action" role="button" tabindex="0" onclick="openPendingProjects()" onkeydown="handleDashboardMetricKey(event, openPendingProjects)">

                            <div class="dashboard-metric-top">

                                <div class="dashboard-metric-icon metric-warning">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <circle cx="12" cy="12" r="8.5" />
                                        <path d="M12 7.5v5l3.25 2" />
                                    </svg>
                                </div>

                                <span class="dashboard-metric-label">
                                    Pending
                                </span>

                            </div>

                            <div
                                id="pendingDocs"
                                class="dashboard-metric-value"
                            >
                                0
                            </div>

                            <div class="dashboard-metric-caption">
                                Projects requiring action
                            </div>

                        </div>

                        <div class="dashboard-metric-card dashboard-metric-action" role="button" tabindex="0" onclick="openDashboardAlerts()" onkeydown="handleDashboardMetricKey(event, openDashboardAlerts)">

                            <div class="dashboard-metric-top">

                                <div class="dashboard-metric-icon metric-danger">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M12 3.75 21 19.5H3L12 3.75Z" />
                                        <path d="M12 9v4.5M12 16.5h.01" />
                                    </svg>
                                </div>

                                <span class="dashboard-metric-label">
                                    Active Alerts
                                </span>

                            </div>

                            <div
                                id="overdueDocs"
                                class="dashboard-metric-value"
                            >
                                0
                            </div>

                            <div class="dashboard-metric-caption">
                                Timeline items requiring attention
                            </div>

                        </div>

                    </div>


                    <!-- DASHBOARD CHARTS -->
                    <section class="dashboard-charts-grid" aria-label="Project dashboard charts">

                        <article class="dashboard-chart-card">
                            <div class="dashboard-chart-header">
                                <div class="dashboard-chart-title-row">
                                    <div>
                                        <h2>Projects by Status</h2>
                                        <p>Current status grouped by its relevant milestone month</p>
                                    </div>
                                    <div class="dashboard-status-date-controls">
                                        <label class="visually-hidden" for="dashboardStatusMonth">Status month</label>
                                        <select id="dashboardStatusMonth" class="form-select form-select-sm" onchange="setDashboardStatusPeriod(this.value, document.getElementById('dashboardStatusYear').value)">
                                            <option value="">All months</option>
                                            <option value="01">January</option><option value="02">February</option><option value="03">March</option><option value="04">April</option>
                                            <option value="05">May</option><option value="06">June</option><option value="07">July</option><option value="08">August</option>
                                            <option value="09">September</option><option value="10">October</option><option value="11">November</option><option value="12">December</option>
                                        </select>
                                        <label class="visually-hidden" for="dashboardStatusYear">Status year</label>
                                        <input id="dashboardStatusYear" type="number" min="1900" max="9999" step="1" inputmode="numeric" class="form-control form-control-sm dashboard-year-select" aria-label="Status year" onchange="setDashboardStatusPeriod(document.getElementById('dashboardStatusMonth').value, this.value)">
                                        <span id="dashboardLatestStatusYear" class="dashboard-year-note"></span>
                                    </div>
                                </div>
                            </div>
                            <canvas id="dashboardStatusChart" role="img" aria-label="Projects grouped by status"></canvas>
                        </article>

                        <article class="dashboard-chart-card">
                            <div class="dashboard-chart-header">
                                <div class="dashboard-chart-title-row">
                                    <div>
                                        <h2>Monthly Completions</h2>
                                        <p>Completed projects by completion month</p>
                                    </div>
                                    <label class="visually-hidden" for="dashboardCompletionYear">Completion year</label>
                                    <div class="dashboard-year-control">
                                        <input id="dashboardCompletionYear" type="number" min="1900" max="9999" step="1" inputmode="numeric" class="form-control form-control-sm dashboard-year-select" aria-label="Completion year" onchange="setDashboardCompletionYear(this.value)">
                                        <span id="dashboardLatestCompletionYear" class="dashboard-year-note"></span>
                                    </div>
                                </div>
                            </div>
                            <canvas id="dashboardCompletionChart" role="img" aria-label="Completed projects grouped by month"></canvas>
                        </article>

                    </section>


                    <section class="dashboard-recent-card" aria-labelledby="recentProjectsTitle">
                        <div class="dashboard-chart-header">
                            <h2 id="recentProjectsTitle">Recently updated projects</h2>
                            <p>Latest saved changes across the registry</p>
                        </div>
                        <ul id="dashboardRecentProjects" class="dashboard-recent-list">
                            <li class="dashboard-recent-empty">Loading recent activity…</li>
                        </ul>
                    </section>


                    <!-- TIMELINE ALERTS -->
                    <section
                        id="dashboardTimelineBlock"
                        class="dashboard-panel"
                    >

                        <div class="dashboard-panel-header">

                            <div>

                                <div class="dashboard-panel-title-row">

                                    <h2 class="dashboard-panel-title">Needs attention</h2>

                                    <span id="dashboardAlertBadge" class="dashboard-live-badge">
                                        Active
                                    </span>

                                </div>

                                <p class="dashboard-panel-description">Review active procurement and completion deadlines.</p>

                            </div>


                            <div class="dashboard-panel-controls">

                                <input
                                    type="search"
                                    id="dashboardSearchInput"
                                    class="form-control form-control-sm dashboard-search"
                                    placeholder="Search projects or alert type..."
                                    oninput="scheduleDashboardDataLoad()"
                                >

                            </div>

                        </div>


                        <div class="dashboard-alert-list-wrapper">

                            <ul
                                class="list-group list-group-flush"
                                id="dashboardNotificationList"
                            >

                                <li class="list-group-item text-muted text-center py-5">
                                    No active timeline alerts.
                                </li>

                            </ul>

                        </div>

                        <div id="dashboardAlertPagination" class="dashboard-alert-pagination d-none"></div>

                    </section>

                </div>


<!-- =================================================
     RESOLVED ALERTS
     ================================================= -->
<div
    id="dashboardDoneSection"
    class="col-12"
    style="display: none;"
>

    <div class="resolved-page">

        <!-- =================================================
             PAGE HEADER
             ================================================= -->
        <div class="resolved-page-header">

            <div>

                <div class="dashboard-eyebrow">
                    Timeline Management
                </div>

                <h1 class="resolved-page-title">
                    Resolved Alerts
                </h1>

                <p class="resolved-page-description">
                    Review completed timeline alerts and return them
                    to active monitoring when necessary.
                </p>

            </div>

        </div>


        <!-- =================================================
             CATEGORY GRID
             ================================================= -->
        <div class="resolved-category-grid">

            <div class="resolved-category-column">

            <!-- =================================================
                 NOA
                 ================================================= -->
            <section
                class="resolved-category-card resolved-category-card--noa"
            >

                <button
                    class="resolved-category-toggle collapsed"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#dashboardDoneNoaCollapse"
                    aria-expanded="false"
                    aria-controls="dashboardDoneNoaCollapse"
                >

                    <div class="resolved-category-heading">

                        <div class="resolved-category-icon">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M8 4h8v4a4 4 0 0 1-8 0V4Z" />
                                <path d="M8 5H5v1a4 4 0 0 0 3 4M16 5h3v1a4 4 0 0 1-3 4M12 12v4M9 20h6M10 16h4" />
                            </svg>
                        </div>

                        <div>

                            <div class="resolved-category-title">
                                Notice of Award
                            </div>

                            <div class="resolved-category-subtitle">
                                Resolved NOA timeline alerts
                            </div>

                        </div>

                    </div>


                    <div class="resolved-category-meta">

                        <span
                            class="resolved-category-count"
                            id="dashboardDoneNoaCount"
                        >
                            0
                        </span>

                        <span class="resolved-category-chevron">
                            ›
                        </span>

                    </div>

                </button>


                <div
                    id="dashboardDoneNoaCollapse"
                    class="collapse"
                >

                    <div class="resolved-category-body">

                        <ul
                            id="dashboardDoneNoaList"
                            class="list-group list-group-flush resolved-alert-list"
                        ></ul>

                    </div>

                </div>

            </section>


            <!-- =================================================
                 MATERIAL TEST
                 ================================================= -->
            <section
                class="resolved-category-card resolved-category-card--material"
            >

                <button
                    class="resolved-category-toggle collapsed"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#dashboardDoneMaterialCollapse"
                    aria-expanded="false"
                    aria-controls="dashboardDoneMaterialCollapse"
                >

                    <div class="resolved-category-heading">

                        <div class="resolved-category-icon">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M9 4h6M11 4v6.5l-3.5 6a2.2 2.2 0 0 0 1.9 3.3h5.2a2.2 2.2 0 0 0 1.9-3.3l-3.5-6V4" />
                                <path d="M9.5 15h5" />
                            </svg>
                        </div>

                        <div>

                            <div class="resolved-category-title">
                                Material Testing
                            </div>

                            <div class="resolved-category-subtitle">
                                Resolved material testing alerts
                            </div>

                        </div>

                    </div>


                    <div class="resolved-category-meta">

                        <span
                            class="resolved-category-count"
                            id="dashboardDoneMaterialCount"
                        >
                            0
                        </span>

                        <span class="resolved-category-chevron">
                            ›
                        </span>

                    </div>

                </button>


                <div
                    id="dashboardDoneMaterialCollapse"
                    class="collapse"
                >

                    <div class="resolved-category-body">

                        <ul
                            id="dashboardDoneMaterialList"
                            class="list-group list-group-flush resolved-alert-list"
                        ></ul>

                    </div>

                </div>

            </section>

            </div>


            <div class="resolved-category-column">


            <!-- =================================================
                 NTP
                 ================================================= -->
            <section
                class="resolved-category-card resolved-category-card--ntp"
            >

                <button
                    class="resolved-category-toggle collapsed"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#dashboardDoneNtpCollapse"
                    aria-expanded="false"
                    aria-controls="dashboardDoneNtpCollapse"
                >

                    <div class="resolved-category-heading">

                        <div class="resolved-category-icon">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M4 12h14" />
                                <path d="m13 6 6 6-6 6" />
                                <path d="M5 7v10" />
                            </svg>
                        </div>

                        <div>

                            <div class="resolved-category-title">
                                Notice to Proceed
                            </div>

                            <div class="resolved-category-subtitle">
                                Resolved NTP timeline alerts
                            </div>

                        </div>

                    </div>


                    <div class="resolved-category-meta">

                        <span
                            class="resolved-category-count"
                            id="dashboardDoneNtpCount"
                        >
                            0
                        </span>

                        <span class="resolved-category-chevron">
                            ›
                        </span>

                    </div>

                </button>


                <div
                    id="dashboardDoneNtpCollapse"
                    class="collapse"
                >

                    <div class="resolved-category-body">

                        <ul
                            id="dashboardDoneNtpList"
                            class="list-group list-group-flush resolved-alert-list"
                        ></ul>

                    </div>

                </div>

            </section>


             <!-- =================================================
                  SWA
                 ================================================= -->
            <section
                class="resolved-category-card resolved-category-card--award"
            >

                <button
                    class="resolved-category-toggle collapsed"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#dashboardDoneAwardCollapse"
                    aria-expanded="false"
                    aria-controls="dashboardDoneAwardCollapse"
                >

                    <div class="resolved-category-heading">

                        <div class="resolved-category-icon">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="12" cy="12" r="8.5" />
                                <path d="m8.25 12 2.35 2.35 5.15-5.15" />
                            </svg>
                        </div>

                        <div>

                            <div class="resolved-category-title">
                                SWA Follow-up
                            </div>

                            <div class="resolved-category-subtitle">
                                Resolved contractor SWA reminders
                            </div>

                        </div>

                    </div>


                    <div class="resolved-category-meta">

                        <span
                            class="resolved-category-count"
                            id="dashboardDoneAwardCount"
                        >
                            0
                        </span>

                        <span class="resolved-category-chevron">
                            ›
                        </span>

                    </div>

                </button>


                <div
                    id="dashboardDoneAwardCollapse"
                    class="collapse"
                >

                    <div class="resolved-category-body">

                        <ul
                            id="dashboardDoneAwardList"
                            class="list-group list-group-flush resolved-alert-list"
                        ></ul>

                    </div>

                </div>

            </section>

            </div>

           </div>


        </div>

    </div>

<!-- =================================================
ADD / EDIT PROJECT
================================================= -->
<div
                    id="formSection"
                    class="col-12"
                    style="display: none;"
>

                    <div class="project-form-page">


                        <div class="project-form-page-header">

                            <div>

                                <div class="dashboard-eyebrow">
                                    Project Registry
                                </div>

                                <h1
                                    id="formHeader"
                                    class="project-form-title"
                                >
                                    Add Project Record
                                </h1>

                                <p class="project-form-description">
                                    Enter the project, procurement, contract,
                                    inspection, and completion information below.
                                </p>

                                <div id="projectEditorPresence" data-project-editors="" role="status" aria-live="polite" hidden></div>

                                <p id="nextProjectNumberPreview" class="project-form-description d-none mb-0">
                                    Next expected Project Number:
                                    <strong id="nextProjectNumberValue">—</strong>
                                    <span class="text-muted">(confirmed when saved)</span>
                                </p>

                            </div>

                            <div class="project-form-header-actions">
                                <button type="button" id="projectEditorAlertsButton" class="project-alert-button btn btn-sm btn-outline-danger d-none" aria-expanded="false" aria-controls="projectAlertsPanel" aria-haspopup="dialog" onclick="toggleProjectEditorAlertsPanel(event)">Alerts</button>
                                <button type="button" id="editPaneCloseButton" class="btn-close d-none" aria-label="Close editor" onclick="resetFormAccess()"></button>
                            </div>

                        </div>


                        <form
                            id="documentForm"
                            onsubmit="event.preventDefault(); saveDocument();"
                        >


                            <!-- BASIC INFORMATION -->
                            <section class="form-section-card">

                                <div class="form-section-header">

                                    <div class="form-section-number">
                                        01
                                    </div>

                                    <div>

                                        <h2 class="form-section-title">
                                            Basic Project Information
                                        </h2>

                                        <p class="form-section-description">
                                            Core project, budget, and funding details.
                                        </p>

                                    </div>

                                </div>


                                <div class="form-section-body">

                                    <div class="row g-3">





                                        <div class="col-12">

                                            <label
                                                for="title"
                                                class="form-label"
                                            >
                                                Project Title
                                                <span class="text-danger">*</span>
                                            </label>

                                            <textarea
                                                id="title"
                                                class="form-control auto-expand"
                                                rows="1"
                                                placeholder="e.g., Residential Building at Brgy. 1"
                                                aria-describedby="project-title-help"
                                            ></textarea>
                                            <div id="project-title-help" class="form-text text-muted">Use the project name followed by its location. Example: Residential Building at Brgy. 1.</div>

                                        </div>

                                        <div class="col-md-4">

                                            <label
                                                for="approvedBudget"
                                                class="form-label"
                                            >
                                                Approved Budget for the Contract
                                                <span class="text-danger">*</span>
                                            </label>

                                            <input
                                                type="text"
                                                id="approvedBudget"
                                                class="form-control"
                                                placeholder="₱0.00"
                                            >

                                        </div>








                                        <div class="col-md-4">

                                            <label
                                                for="sourceOfFund"
                                                class="form-label"
                                            >
                                                Source of Fund
                                            </label>

                                            <select
                                                id="sourceOfFund"
                                                class="form-select"
                                            >

                                                <option value="">
                                                    Select fund source
                                                </option>

                                                <option value="Others">
                                                    Others
                                                </option>

                                                <option value="Brgy Fund">
                                                    Barangay Fund
                                                </option>

                                                <option value="City Fund">
                                                    City Fund
                                                </option>

                                            </select>

                                        </div>


                                        <div
                                            class="col-md-6 d-none"
                                            id="sourceOfFundCustomRow"
                                        >

                                            <label
                                                for="sourceOfFundCustom"
                                                class="form-label"
                                            >
                                                Specify Other Fund Source
                                            </label>

                                            <input
                                                type="text"
                                                id="sourceOfFundCustom"
                                                class="form-control"
                                                placeholder="Enter source of fund"
                                            >

                                        </div>

                                    </div>

                                </div>

                            </section>


                            <!-- PROCUREMENT -->
                            <section class="form-section-card">

                                <div class="form-section-header">

                                    <div class="form-section-number">
                                        02
                                    </div>

                                    <div>

                                        <h2 class="form-section-title">
                                            Procurement and Award
                                        </h2>

                                        <p class="form-section-description">
                                            Bidding, bidder, procurement request, resolution,
                                            qualification, and award milestones.
                                        </p>

                                    </div>

                                </div>


                                <div class="form-section-body">

                                    <div class="row g-3">

<div class="col-md-4">

                                            <label
                                                for="biddingDate"
                                                class="form-label"
                                            >
                                                Date of Bidding
                                                <span class="text-danger">*</span>
                                            </label>

                                            <input
                                                type="date"
                                                id="biddingDate"
                                                class="form-control"
                                            >

                                        </div>

<div class="col-md-4">

                                            <label
                                                for="bidAmount"
                                                class="form-label"
                                            >
                                                Bid Amount
                                            </label>

                                            <input
                                                type="text"
                                                id="bidAmount"
                                                class="form-control"
                                                placeholder="₱0.00"
                                            >

                                        </div>

<div class="col-md-4">

                                            <label
                                                for="winningBidder"
                                                class="form-label"
                                            >
                                                Winning Bidder

                                            </label>

                                            <input
                                                type="text"
                                                id="winningBidder"
                                                class="form-control"
                                                placeholder="Contractor or supplier (optional — add later)"
                                            >

                                        </div>


                                        <div class="col-md-4">

                                            <label
                                                for="prNumber"
                                                class="form-label"
                                            >
                                                Purchase Request Number
                                            </label>

                                            <input
                                                type="text"
                                                id="prNumber"
                                                class="form-control"
                                                placeholder="Purchase Request Number"
                                            >

                                        </div>


                                        <div class="col-md-4">

                                            <label
                                                for="prDate"
                                                class="form-label"
                                            >
                                                Purchase Request Date
                                            </label>

                                            <input
                                                type="date"
                                                id="prDate"
                                                class="form-control"
                                            >

                                        </div>


                                        <div class="col-md-4">

                                            <label
                                                for="obre"
                                                class="form-label"
                                            >
                                                Obligation Request Date
                                            </label>

                                            <input
                                                type="date"
                                                id="obre"
                                                class="form-control"
                                            >

                                        </div>


                                        <div class="col-md-4">

                                            <label
                                                for="resolutionNumber"
                                                class="form-label"
                                            >
                                                Resolution Number
                                            </label>

                                            <input
                                                type="text"
                                                id="resolutionNumber"
                                                class="form-control"
                                                placeholder="Resolution Number"
                                            >

                                        </div>


                                        <div class="col-md-4">

                                            <label
                                                for="resolutionDate"
                                                class="form-label"
                                            >
                                                Resolution Date
                                            </label>

                                            <input
                                                type="date"
                                                id="resolutionDate"
                                                class="form-control"
                                            >

                                        </div>


                                        <div class="col-md-4">

                                            <label
                                                for="noticePostQual"
                                                class="form-label"
                                            >
                                                Notice of Post Qualification
                                            </label>

                                            <input
                                                type="date"
                                                id="noticePostQual"
                                                class="form-control"
                                            >

                                        </div>


                                        <div class="col-md-6">
                                            <label for="noaDueDate" class="form-label">NOA Due Date</label>
                                            <input type="date" id="noaDueDate" class="form-control" aria-describedby="noaDueDateHint">
                                            <div id="noaDueDateHint" class="form-helper">Deadline for issuing the Notice of Award. Alerts remain overdue until the issuance date is recorded.</div>
                                        </div>
                                        <div class="col-md-6">

                                            <label
                                                for="noa"
                                                class="form-label"
                                            >
                                                NOA Issued Date
                                            </label>

                                            <input
                                                type="date"
                                                id="noa"
                                                class="form-control"
                                            >

                                            <div class="form-helper">
                                                Record the actual issuance date to resolve NOA alerts.
                                            </div>

                                        </div>


                                        <div class="col-md-6">

                                            <label
                                                for="performanceBond"
                                                class="form-label"
                                            >
                                                Performance Bond
                                            </label>

                                            <input
                                                type="date"
                                                id="performanceBond"
                                                class="form-control"
                                            >

                                        </div>

                                    </div>

                                </div>

                            </section>


                            <!-- CONTRACT -->
                            <section class="form-section-card">

                                <div class="form-section-header">

                                    <div class="form-section-number">
                                        03
                                    </div>

                                    <div>

                                        <h2 class="form-section-title">
                                            Contract and Implementation
                                        </h2>

                                        <p class="form-section-description">
                                            Contract execution, notice to proceed,
                                            duration, and implementation dates.
                                        </p>

                                    </div>

                                </div>


                                <div class="form-section-body">

                                    <div class="row g-3">


                                        <div class="col-md-4">

                                            <label
                                                for="contractRef"
                                                class="form-label"
                                            >
                                                Contract Date
                                            </label>

                                            <input
                                                type="date"
                                                id="contractRef"
                                                class="form-control"
                                            >

                                        </div>


                                        <div class="col-md-4">

                                            <label
                                                for="contractDuration"
                                                class="form-label"
                                            >
                                                Contract Duration (working days)
                                            </label>

                                            <input
                                                type="number" min="0.000001" max="36500" step="any" inputmode="decimal"
                                                id="contractDuration"
                                                class="form-control"
                                                placeholder="Calendar days (e.g. 30 or 30.5)"
                                            >

                                        </div>


                                        <div class="col-md-4">

                                            <label
                                                for="contractEndDate"
                                                class="form-label"
                                            >
                                                Contract Due Date
                                            </label>

                                            <input
                                                type="date"
                                                id="contractEndDate"
                                                class="form-control"
                                            >

                                        </div>


                                        <div class="col-md-6">

                                            <label
                                                for="ntpDate"
                                                class="form-label"
                                            >
                                                Notice to Proceed
                                            </label>

                                            <input
                                                type="date"
                                                id="ntpDate"
                                                class="form-control"
                                             aria-describedby="ntpDateHelp">
                                            <div id="ntpDateHelp" class="form-helper">Record the actual issuance date to resolve NTP alerts.</div>

                                        </div>


                                        <div class="col-md-6">

                                            <label
                                                for="dole"
                                                class="form-label"
                                            >
                                                Department of Labor and Employment Clearance
                                            </label>

                                            <input
                                                type="date"
                                                id="dole"
                                                class="form-control"
                                            >

                                        </div>

                                    </div>

                                </div>

                            </section>


                            <!-- INSPECTION -->
                            <section class="form-section-card">

                                <div class="form-section-header">

                                    <div class="form-section-number">
                                        04
                                    </div>

                                    <div>

                                        <h2 class="form-section-title">
                                            Inspection and Completion
                                        </h2>

                                        <p class="form-section-description">
                                            Inspection milestones and
                                            final project status.
                                        </p>

                                    </div>

                                </div>


                                <div class="form-section-body">

                                    <div class="row g-3">


                                        <div class="col-12">
                                            <div class="milestone-due-summary">
                                                <div class="milestone-due-heading">Due dates</div>
                                                <div class="milestone-due-grid">
                                                    <div><label for="preInspectionDueDate" class="form-label">Pre-inspection</label><input type="date" id="preInspectionDueDate" class="form-control" readonly></div>
                                                    <div><label for="ntpDueDate" class="form-label">Notice to Proceed</label><input type="date" id="ntpDueDate" class="form-control" readonly></div>
                                                    <div><label for="materialTestingDueDate" class="form-label">Material testing</label><input type="date" id="materialTestingDueDate" class="form-control" readonly></div>
                                                    <div><label for="finalInspectionDueDate" class="form-label">Final inspection</label><input type="date" id="finalInspectionDueDate" class="form-control" readonly></div>
                                                    <div><label for="swaDueDate" class="form-label">SWA</label><input type="date" id="swaDueDate" class="form-control" readonly aria-describedby="swaDueDateHint"></div>
                                                </div>
                                                <p class="milestone-due-note">Pre-inspection, NTP and material testing: one calendar month after bidding, moved to the next working day if it falls on a weekend or Philippine non-working holiday. Final inspection: contract end, including extensions. <span id="swaDueDateHint">SWA: one year after completion.</span> Durations and reminder lead times follow the working-day calendar selected by Superadmin. Declared national holidays sync automatically when online. Superadmin can add local holidays and corrections under Philippine holidays. Record actual dates below.</p>
                                            </div>
                                        </div>

                                        <div class="col-md-3">
                                            <label for="materialTestingDate" class="form-label">Material Testing Date</label>
                                            <input type="date" id="materialTestingDate" class="form-control" aria-describedby="materialTestingDateHelp">
                                            <div id="materialTestingDateHelp" class="form-helper">Record the actual testing date to resolve material testing alerts.</div>
                                        </div>
                                        <div class="col-md-3">

                                            <label
                                                for="preInspection"
                                                class="form-label"
                                            >
                                                Pre Inspection
                                            </label>

                                            <input
                                                type="date"
                                                id="preInspection"
                                                class="form-control"
                                             aria-describedby="preInspectionHelp">
                                            <div id="preInspectionHelp" class="form-helper">Record the actual inspection date to resolve pre-inspection alerts.</div>

                                        </div>


                                        <div class="col-md-3">

                                            <label
                                                for="finalInspection"
                                                class="form-label"
                                            >
                                                Final Inspection
                                            </label>

                                            <input
                                                type="date"
                                                id="finalInspection"
                                                class="form-control"
                                             aria-describedby="finalInspectionHelp">
                                            <div id="finalInspectionHelp" class="form-helper">Record the actual inspection date. It sets the status to Pending unless the project is Completed or Released. Matching alerts resolve when the recorded date is reached.</div>

                                        </div>


                                        <div class="col-md-3">

                                            <label
                                                for="swa"
                                                class="form-label"
                                            >
                                                Statement of Work Accomplished Date
                                            </label>

                                            <input
                                                type="date"
                                                id="swa"
                                                class="form-control"
                                             aria-describedby="swaHelp">
                                            <div id="swaHelp" class="form-helper">Record the actual SWA date to resolve SWA alerts.</div>

                                        </div>


                                        <div class="col-md-6">

                                            <label
                                                for="completionDate"
                                                class="form-label"
                                            >
                                                Completion Date
                                            </label>

                                            <input
                                                type="date"
                                                id="completionDate"
                                                class="form-control"
                                             aria-describedby="completionDateHelp">
                                            <div id="completionDateHelp" class="form-helper">Record the actual project completion date to track completion and calculate the SWA due date.</div>

                                            <div id="completionDateHint" class="form-text text-danger fw-semibold d-none">
                                                Completion Date is required for completed projects to appear in Monthly Completions.
                                            </div>

                                        </div>


                                        <div class="col-md-6">

                                            <label
                                                for="status"
                                                class="form-label"
                                            >
                                                Operational Status
                                            </label>

                                            <select
                                                id="status"
                                                class="form-select"
                                            >

                                                <option value="Pending">
                                                    Pending
                                                </option>

                                                <option value="On-going Bidding">
                                                    On-going Bidding
                                                </option>

                                                <option value="Pre-inspection">
                                                    Pre-inspection
                                                </option>

                                                <option value="Final-inspection">
                                                    Final-inspection
                                                </option>

                                                <option value="Completed">
                                                    Completed
                                                </option>

                                                <option value="Released">
                                                    Released
                                                </option>

                                            </select>

                                        </div>

                                    </div>

                                </div>

                            </section>


                            <!-- LD -->
                            <section class="form-section-card">

                                <div class="form-section-header">

                                    <div class="form-section-number">
                                        05
                                    </div>

                                    <div>

                                        <h2 class="form-section-title">
                                            Liquidated Damages
                                        </h2>

                                        <p class="form-section-description">
                                            Complete only when liquidated damages
                                            apply to the project.
                                        </p>

                                    </div>

                                </div>


                                <div class="form-section-body">

                                    <div class="row g-3">


                                        <div class="col-md-3">

                                            <label
                                                for="ldAmount"
                                                class="form-label"
                                            >
                                                Liquidated Damages Amount
                                            </label>

                                            <input
                                                type="text"
                                                id="ldAmount"
                                                class="form-control"
                                                placeholder="₱0.00"
                                            >

                                        </div>


                                        <div class="col-md-3">

                                            <label
                                                for="ldNoOfDays"
                                                class="form-label"
                                            >
                                                Liquidated Damages Number of Days
                                            </label>

                                            <input
                                                type="number"
                                                id="ldNoOfDays"
                                                class="form-control"
                                                placeholder="0"
                                            >

                                        </div>


                                        <div class="col-md-3">

                                            <label
                                                for="ldNewCompletionDate"
                                                class="form-label"
                                            >
                                                Revised Completion Date After Liquidated Damages
                                            </label>

                                            <input
                                                type="date"
                                                id="ldNewCompletionDate"
                                                class="form-control"
                                            >

                                        </div>


                                        <div class="col-md-3">

                                            <label
                                                for="ldActualDuration"
                                                class="form-label"
                                            >
                                                Actual Duration After Liquidated Damages
                                            </label>

                                            <input
                                                type="text"
                                                id="ldActualDuration"
                                                class="form-control"
                                                placeholder="Duration used"
                                            >

                                        </div>

                                    </div>

                                </div>

                            </section>


                            <!-- REMARKS -->
                            <section class="form-section-card">

                                <div class="form-section-header">

                                    <div class="form-section-number">
                                        06
                                    </div>

                                    <div>

                                        <h2 class="form-section-title">
                                            Remarks
                                        </h2>

                                        <p class="form-section-description">
                                            Optional notes or additional
                                            project information.
                                        </p>

                                    </div>

                                </div>


                                <div class="form-section-body">

                                    <label
                                        for="remarks"
                                        class="form-label"
                                    >
                                        Additional Remarks
                                    </label>

                                    <textarea
                                        id="remarks"
                                        class="form-control"
                                        rows="4"
                                        placeholder="Enter notes, observations, or additional information..."
                                    ></textarea>

                                </div>

                            </section>


                            <!-- ATTACHMENTS -->
                            <section class="form-section-card" aria-labelledby="attachmentsTitle">
                                <div class="form-section-header">
                                    <div class="form-section-number">07</div>
                                    <div>
                                        <h2 id="attachmentsTitle" class="form-section-title">Project Attachments</h2>
                                        <p class="form-section-description">Attach notices, contracts, photos, and inspection files to this project.</p>
                                    </div>
                                </div>
                                <div class="form-section-body">
                                    <div id="projectAttachmentsNewNotice" class="alert alert-light border mb-3">Save the project first, then edit it to attach files.</div>
                                    <div id="projectAttachmentsEditor" class="d-none">
                                        <label class="form-label" for="projectAttachmentFiles">Choose files</label>
                                        <div class="mb-2">
                                            <input id="projectAttachmentFiles" class="form-control" type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,image/jpeg,image/png,image/gif,image/webp" onchange="uploadProjectAttachments()">
                                        </div>
                                        <div class="form-text mb-3">PDF, Word, Excel, text, and image files; up to 25 MB each. Added or removed files are saved only when you select Apply Records Change.</div>
                                        <div id="projectAttachmentsList" class="list-group" aria-live="polite"></div>
                                    </div>
                                </div>
                            </section>


                        </form>

                            <!-- FORM ACTIONS -->
                            <div class="project-form-actions">

                                <div class="project-form-actions-note">
                                    Fields marked with
                                    <span class="text-danger">*</span>
                                    are required.
                                </div>


                                <div class="d-flex gap-2">

                                    <button
                                        type="button"
                                        id="cancelEditBtn"
                                        onclick="resetFormAccess()"
                                        class="btn btn-outline-secondary d-none"
                                    >
                                        Cancel
                                    </button>


                                    <button
                                        type="submit"
                                        form="documentForm"
                                        id="saveBtn"
                                        class="btn btn-primary px-4"
                                    >
                                        Save Project
                                    </button>

                                </div>

                            </div>

                    </div>

                </div>


                <!-- =================================================
                     PROJECTS
                     ================================================= -->
                <div
                    id="tableSection"
                    class="col-12"
                    style="display: none;"
                >

                    <div class="projects-page">


                        <!-- PAGE HEADER -->
                        <div class="projects-page-header">

                            <div>

                                <div class="dashboard-eyebrow">
                                    Project Registry
                                </div>

                                <h1 class="projects-page-title" id="projectsPageTitle">
                                    Projects
                                </h1>

                                <p class="projects-page-description" id="projectsPageDescription">
                                    Search, review, update, import, and
                                    export recorded project information.
                                </p>

                            </div>


                            <button
                                type="button"
                                class="btn btn-primary projects-add-button"
                                id="addProjectButton"
                                onclick="openAddProjectPane()"
                            >
                                + Add Project
                            </button>

                        </div>


                        <div class="projects-lifecycle-tabs" id="projectsLifecycleTabs" role="group" aria-label="Project lifecycle view">
                            <button type="button" id="projectLifecycleActive" class="active" onclick="setProjectLifecycle('active')">Active projects</button>
                            <button type="button" id="projectLifecycleCompleted" onclick="setProjectLifecycle('completed')">Completed projects</button>
                            <button type="button" id="projectLifecycleReleased" onclick="setProjectLifecycle('released')">Released projects</button>
                            <button type="button" id="projectLifecycleAlerts" onclick="setProjectLifecycle('alerts')">Active alerts</button>
                            <button type="button" id="projectLifecycleAll" onclick="setProjectLifecycle('all')">All projects</button>
                        </div>


                        <!-- TOOLBAR -->
                        <div class="projects-toolbar">


                            <div class="projects-search-group">

                                <label
                                    for="searchInput"
                                    class="projects-filter-label"
                                >
                                    Search
                                </label>

                                <input
                                    type="search"
                                    id="searchInput"
                                    oninput="applyProjectFilters()"
                                    class="form-control"
                                    placeholder="Project no., title, bidder, PR..."
                                >

                            </div>


                            <div class="projects-filter-group projects-status-filter">

                                <label
                                    for="filterStatusSelect"
                                    class="projects-filter-label"
                                >
                                    Status
                                </label>

                                <select
                                    id="filterStatusSelect"
                                    class="form-select"
                                    onchange="applyProjectFilters()"
                                >

                                    <option value="All">
                                        All statuses
                                    </option>

                                    <option value="Pending">
                                        Pending
                                    </option>

                                    <option value="On-going Bidding">
                                        On-going Bidding
                                    </option>

                                    <option value="Pre-inspection">
                                        Pre-inspection
                                    </option>

                                    <option value="Final-inspection">
                                        Final-inspection
                                    </option>

                                </select>

                            </div>


                            <div class="projects-filter-group projects-date-filter">

                                <label
                                    for="filterBiddingMonth"
                                    class="projects-filter-label"
                                >
                                    Project Date
                                </label>

                                <div class="projects-date-filter-controls">
                                    <select
                                        id="filterProjectDateField"
                                        onchange="applyProjectFilters()"
                                        class="form-select"
                                        aria-label="Choose the project date to filter"
                                    >
                                        <option value="">All dates</option>
                                        <option value="biddingDate">Bidding</option>
                                        <option value="prDate">PR</option>
                                        <option value="resolutionDate">Resolution</option>
                                        <option value="noticePostQual">Post-qualification</option>
                                        <option value="noaDueDate">NOA due date</option><option value="noa">NOA issued date</option>
                                        <option value="performanceBond">Performance bond</option>
                                        <option value="contractRef">Contract reference</option>
                                        <option value="ntpDate">NTP</option>
                                        <option value="dole">DOLE</option>
                                        <option value="contractEndDate">Contract end</option>
                                        <option value="preInspection">Pre-inspection</option>
                                        <option value="materialTestingDate">Material testing</option>
                                        <option value="finalInspection">Final inspection</option>
                                        <option value="ldNewCompletionDate">LD completion</option>
                                        <option value="completion">Completion</option>
                                    </select>

                                    <select
                                        id="filterBiddingMonth"
                                        onchange="applyProjectFilters()"
                                        class="form-select"
                                        aria-label="Filter by bidding month"
                                    >
                                        <option value="">Month</option>
                                        <option value="1">Jan</option>
                                        <option value="2">Feb</option>
                                        <option value="3">Mar</option>
                                        <option value="4">Apr</option>
                                        <option value="5">May</option>
                                        <option value="6">Jun</option>
                                        <option value="7">Jul</option>
                                        <option value="8">Aug</option>
                                        <option value="9">Sep</option>
                                        <option value="10">Oct</option>
                                        <option value="11">Nov</option>
                                        <option value="12">Dec</option>
                                    </select>

                                    <select
                                        id="filterBiddingDay"
                                        onchange="applyProjectFilters()"
                                        class="form-select"
                                        aria-label="Filter by bidding date"
                                    >
                                        <option value="">Date</option>
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="5">5</option>
                                        <option value="6">6</option>
                                        <option value="7">7</option>
                                        <option value="8">8</option>
                                        <option value="9">9</option>
                                        <option value="10">10</option>
                                        <option value="11">11</option>
                                        <option value="12">12</option>
                                        <option value="13">13</option>
                                        <option value="14">14</option>
                                        <option value="15">15</option>
                                        <option value="16">16</option>
                                        <option value="17">17</option>
                                        <option value="18">18</option>
                                        <option value="19">19</option>
                                        <option value="20">20</option>
                                        <option value="21">21</option>
                                        <option value="22">22</option>
                                        <option value="23">23</option>
                                        <option value="24">24</option>
                                        <option value="25">25</option>
                                        <option value="26">26</option>
                                        <option value="27">27</option>
                                        <option value="28">28</option>
                                        <option value="29">29</option>
                                        <option value="30">30</option>
                                        <option value="31">31</option>
                                    </select>

                                    <input
                                        type="number"
                                        id="filterBiddingYear"
                                        oninput="applyProjectFilters()"
                                        class="form-control"
                                        min="1900"
                                        step="1"
                                        inputmode="numeric"
                                        placeholder="Year"
                                        aria-label="Filter by bidding year"
                                    >
                                </div>

                            </div>

                            <div class="projects-filter-group projects-number-filter">

                                <label for="filterProjectNo" class="projects-filter-label">
                                    Project No.
                                </label>

                                <input
                                    type="search"
                                    id="filterProjectNo"
                                    oninput="applyProjectFilters()"
                                    class="form-control"
                                    placeholder="Filter project no."
                                >

                            </div>

                            <div class="projects-filter-group projects-brgy-filter">

                                <label for="filterBrgy" class="projects-filter-label">
                                    Brgy
                                </label>

                                <input
                                    type="search"
                                    id="filterBrgy"
                                    oninput="applyProjectFilters()"
                                    class="form-control"
                                    placeholder="Filter barangay"
                                >

                            </div>

                            <div class="projects-data-actions">
                                <div>
                                    <div class="projects-filter-label">Data</div>
                                    <div class="d-flex gap-2">
                                        <button type="button" onclick="exportToExcel()" id="exportExcelButton" class="btn btn-outline-secondary">
                                            <svg class="data-action-icon" viewBox="0 0 24 24" aria-hidden="true">
                                                <path d="M12 3v11m0 0 4-4m-4 4-4-4M5 15v4h14v-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path>
                                            </svg>
                                            <span>Export</span>
                                        </button>
                                        <button type="button" onclick="importFromExcel()" id="importExcelButton" class="btn btn-outline-secondary mb-0">
                                            <svg class="data-action-icon" viewBox="0 0 24 24" aria-hidden="true">
                                                <path d="M12 21V10m0 0 4 4m-4-4-4 4M5 5V3h14v2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path>
                                            </svg>
                                            <span>Import</span>
                                        </button>
                                        <input type="file" id="excelImportInput" accept=".xlsx,.xls,.csv" class="d-none">
                                    </div>
                                </div>
                            </div>

                        </div>



                        <!-- TABLE -->
                        <section class="projects-table-card">

                            <div class="projects-table-header">

                                <div>

                                    <h2 class="projects-table-title">
                                        Project Records
                                    </h2>

                                    <p class="projects-table-description">
                                        Showing the project registry based on
                                        the active search and filters.
                                    </p>

                                </div>

                            </div>


                            <div class="table-responsive projects-table-wrapper">

                                <table class="table projects-table align-middle mb-0">

                                    <thead>

                                        <tr>
                                            <th scope="col" aria-sort="ascending">
                                                <button id="projectNumberSortButton" type="button" class="btn btn-link p-0 text-decoration-none text-reset fw-semibold" onclick="toggleProjectNumberSort()" aria-label="Sort project numbers ascending">
                                                    No. <span id="projectNumberSortIcon" aria-hidden="true">↑</span>
                                                </button>
                                            </th>
                                            <th>Project</th>
                                            <th>Bidding Date</th>
                                            <th>NOA Due Date</th>
                                            <th>Timeline</th>
                                            <th>Completion</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>

                                    </thead>


                                    <tbody id="documentTable"></tbody>

                                </table>

                            </div>


                            <div class="projects-table-footer">

                                <div
                                    id="paginationInfo"
                                    class="projects-pagination-info"
                                >
                                    Showing 0-0 of 0 entries
                                </div>

                                <div id="paginationControls"></div>

                            </div>

                        </section>


                        <!-- DATA MANAGEMENT -->
                        <section class="projects-danger-zone">

                            <div>

                                <div class="projects-danger-title">
                                    Data Management
                                </div>

                                <div class="projects-danger-description">
                                    Permanently remove all shared project records
                                    and history from the system.
                                </div>

                            </div>


                            <button
                                type="button"
                                id="deleteAllRecordsButton"
                                onclick="promptClearDatabaseSystem()"
                                class="btn btn-outline-danger btn-sm"
                            >
                                Delete All Records
                            </button>

                        </section>

                    </div>

                </div>


                <div id="accountRequestsSection" class="col-12" style="display: none;">
                    <section class="projects-page">
                        <div class="projects-page-header">
                            <div>
                                <div class="dashboard-eyebrow" id="administrationPageEyebrow">Administration</div>
                                <h1 class="projects-page-title" id="administrationPageTitle">Account</h1>
                                <p class="projects-page-description" id="administrationPageDescription">Review every account, approve pending staff registrations, and manage staff project access.</p>
                            </div>
                        </div>
                        <div id="accountManagementCard" class="card account-directory-card">
                            <div class="card-body p-0">
                                <div class="account-directory-toolbar"><div><h2>Account directory</h2><p id="accountDirectorySummary">Manage accounts and access permissions.</p></div><button type="button" class="btn btn-primary" onclick="openAccountEditor()">Create account</button></div>
                                <div id="accountRequestsMessage" class="p-4 text-muted">Loading accounts…</div>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0" aria-label="Account directory">
                                        <thead><tr><th scope="col">Full name</th><th scope="col">Username</th><th scope="col">Role</th><th scope="col">Status / access</th><th scope="col">Created</th><th scope="col" class="text-end">Actions</th></tr></thead>
                                        <tbody id="accountRequestsList"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <section id="holidayCalendarCard" hidden class="d-none account-audit-card mt-4" aria-labelledby="holidayCalendarTitle">
                            <div class="account-audit-header"><div><div class="dashboard-eyebrow">Working-day calendar</div><h2 id="holidayCalendarTitle" class="account-audit-title">Philippine holidays</h2></div><button type="button" class="btn btn-sm btn-outline-secondary" onclick="loadHolidayCalendar()">Refresh</button></div>
                            <div class="p-4">
                                <p id="holidaySyncStatus" class="mb-2">Loading holiday calendar…</p>
                                <p class="text-muted small">Checks official Presidential Communications Office announcements daily at 03:00 Philippine time, with hourly retries after failures. The saved calendar remains available offline. Choose non-working weekdays and whether holidays are excluded. Use each date’s action to include or exclude an individual holiday.</p>
                                <div id="holidaySyncControls" hidden class="d-none d-flex flex-wrap gap-3 align-items-center mb-3"><label class="form-check-label"><input id="holidaySyncEnabled" type="checkbox" class="form-check-input me-2">Automatic updates</label><button type="button" class="btn btn-sm btn-primary" onclick="saveHolidayOptions()">Save setting</button><button type="button" class="btn btn-sm btn-outline-primary" onclick="saveHolidayChange('sync')">Sync now</button></div>
                                <fieldset class="mb-3"><legend class="fs-6">Non-working weekdays</legend><label class="form-check-label me-3"><input class="form-check-input me-1" type="checkbox" data-non-working-day value="0">Sunday</label><label class="form-check-label me-3"><input class="form-check-input me-1" type="checkbox" data-non-working-day value="1">Monday</label><label class="form-check-label me-3"><input class="form-check-input me-1" type="checkbox" data-non-working-day value="2">Tuesday</label><label class="form-check-label me-3"><input class="form-check-input me-1" type="checkbox" data-non-working-day value="3">Wednesday</label><label class="form-check-label me-3"><input class="form-check-input me-1" type="checkbox" data-non-working-day value="4">Thursday</label><label class="form-check-label me-3"><input class="form-check-input me-1" type="checkbox" data-non-working-day value="5">Friday</label><label class="form-check-label me-3"><input class="form-check-input me-1" type="checkbox" data-non-working-day value="6">Saturday</label></fieldset><label class="form-check-label mb-3"><input id="holidayExcludeHolidays" type="checkbox" class="form-check-input me-2">Exclude holidays from working-day calculations</label><p id="holidaySyncMessage" role="status" aria-live="polite"></p>
                                <p id="holidayCalendarCoverage" class="text-muted small"></p>
                                <div id="holidaySyncReviewPanel" hidden><p>These official articles need review before their dates can be applied:</p><ul id="holidaySyncReview"></ul></div>
                                <form id="holidayOverrideForm" hidden onsubmit="submitHolidayOverride(event)" class="d-none row g-2 mb-3">
                                    <div class="col-sm-3"><label class="form-label" for="holidayOverrideDate">Date</label><input id="holidayOverrideDate" class="form-control" type="date" name="date" required></div>
                                    <div class="col-sm-4"><label class="form-label" for="holidayOverrideName">Holiday / correction name</label><input id="holidayOverrideName" class="form-control" name="name" maxlength="160" required></div>
                                    <div class="col-sm-3"><label class="form-label" for="holidayOverrideType">Day type</label><select id="holidayOverrideType" class="form-select" name="nonWorking"><option value="true">Non-working holiday</option><option value="false">Working day correction</option></select></div>
                                    <div class="col-sm-2 d-flex align-items-end"><button class="btn btn-primary" type="submit">Save date</button></div>
                                    <p class="small text-muted mb-0">Manual corrections take priority over automatic updates. Restore source removes a correction.</p>
                                </form>
                                <label class="form-label" for="holidayCalendarYear">Calendar year</label><select id="holidayCalendarYear" class="form-select mb-3" style="max-width:160px" onchange="renderHolidayCalendar()"></select>
                                <div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Holiday</th><th>Day type / source</th><th>Action</th></tr></thead><tbody id="holidayCalendarRows"></tbody></table></div>
                            </div>
                        </section>
                        <section data-admin-section class="account-audit-card mt-4" aria-labelledby="timelineSettingsTitle">
                            <div class="account-audit-header">
                                <div>
                                    <div class="dashboard-eyebrow">Timeline notifications</div>
                                    <h2 id="timelineSettingsTitle" class="account-audit-title">Timeline day settings</h2>
                                </div>
                            </div>
                            <p class="text-muted small px-4 pt-3 mb-2">Choose a standard timeline length, or select Custom and enter the exact number of days.</p>
                            <div class="timeline-settings-grid">
                                <div><label class="form-label" for="timelineLeadNoa">NOA</label><select id="timelineLeadNoa" class="form-select" onchange="toggleTimelineCustomDays('noa')"><option value="7">7 days</option><option value="10">10 days</option><option value="14">14 days</option><option value="15">15 days</option><option value="30">30 days</option><option value="custom">Custom</option></select><input id="timelineLeadNoaCustom" class="form-control mt-2 d-none" type="number" min="1" max="3650" step="1" inputmode="numeric" placeholder="Number of days" aria-label="Custom NOA days"></div>
                                <div><label class="form-label" for="timelineLeadBidding">Pre-inspection / NTP / Material testing</label><select id="timelineLeadBidding" class="form-select" onchange="toggleTimelineCustomDays('bidding')"><option value="7">7 days</option><option value="10">10 days</option><option value="14">14 days</option><option value="15">15 days</option><option value="30">30 days</option><option value="custom">Custom</option></select><input id="timelineLeadBiddingCustom" class="form-control mt-2 d-none" type="number" min="1" max="3650" step="1" inputmode="numeric" placeholder="Number of days" aria-label="Custom Pre-inspection / NTP / Material testing days"></div>
                                <div><label class="form-label" for="timelineLeadCompletion">SWA advance reminder</label><select id="timelineLeadCompletion" class="form-select" onchange="toggleTimelineCustomDays('completion')"><option value="7">7 days</option><option value="10">10 days</option><option value="14">14 days</option><option value="15">15 days</option><option value="30">30 days</option><option value="custom">Custom</option></select><input id="timelineLeadCompletionCustom" class="form-control mt-2 d-none" type="number" min="1" max="3650" step="1" inputmode="numeric" placeholder="Number of days" aria-label="Custom SWA advance reminder days"></div>
                                <div><label class="form-label" for="timelineLeadFinalInspection">Final inspection reminder</label><select id="timelineLeadFinalInspection" class="form-select" onchange="toggleTimelineCustomDays('finalInspection')"><option value="7">7 days before contract due date</option><option value="10">10 days before contract due date</option><option value="14">14 days before contract due date</option><option value="15">15 days before contract due date</option><option value="30">30 days before contract due date</option><option value="custom">Custom days…</option></select><input id="timelineLeadFinalInspectionCustom" class="form-control mt-2 d-none" type="number" min="1" max="3650" step="1" inputmode="numeric" placeholder="Number of days" aria-label="Custom Final inspection reminder days"></div>
                            </div>
                            <p class="form-helper px-4 mb-3">The shared setting controls how early all three bidding-based alerts appear. Their due dates remain one calendar month after bidding, moved to the next working day if it falls on a weekend or Philippine non-working holiday. Saving actual milestone dates automatically resolves the matching alerts.</p>
                            <div class="px-4 pb-4"><button type="button" class="btn btn-primary btn-sm" onclick="saveTimelineLeadTimes()">Save timeline days</button></div>
                        </section>
                        <section data-admin-section class="account-audit-card mt-4" aria-labelledby="auditLogTitle">
                            <div class="account-audit-header">
                                <div>
                                    <div class="dashboard-eyebrow">Accountability</div>
                                    <h2 id="auditLogTitle" class="account-audit-title">Recent activity</h2>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="loadAuditLog()">Refresh</button>
                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearAuditHistory()">Clear history</button>
                                </div>
                            </div>
                            <ul id="auditLogList" class="account-audit-list">
                                <li class="text-muted p-3">Loading activity…</li>
                            </ul>
                        </section>
                        <section data-admin-section class="account-audit-card backup-settings-card mt-4" aria-labelledby="backupSettingsTitle">
                            <div class="account-audit-header">
                                <div>
                                    <div class="dashboard-eyebrow">Data protection</div>
                                    <h2 id="backupSettingsTitle" class="account-audit-title">Automatic project backups</h2>
                                </div>
                                <label class="backup-switch">
                                    <input id="automaticBackupEnabled" type="checkbox">
                                    <span aria-hidden="true"></span>
                                    <b id="automaticBackupLabel">On</b>
                                </label>
                            </div>
                            <div class="backup-settings-body">
                                <label class="form-label" for="backupDestinationPath">{{ config('tracker.cloud') ? 'Backup storage' : 'Destination folder' }}</label>
                                <div class="backup-destination-row">
                                    <input id="backupDestinationPath" class="form-control" type="text" spellcheck="false" onchange="saveBackupSettings('Backup destination saved.')" placeholder="D:\LGU Backups" @if(config('tracker.cloud')) readonly @endif>
                                    @if(!config('tracker.cloud'))
                                    <button type="button" id="browseBackupDestinationButton" class="btn btn-outline-secondary" onclick="browseBackupDestination()">Browse…</button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="useDefaultBackupDestination()">Use default</button>
                                    @endif
                                    <button type="button" id="runManualBackupButton" class="btn btn-success" onclick="runManualBackup()">Back up projects now</button>
                                    @if(config('tracker.cloud'))
                                    <a id="downloadLatestBackup" class="btn btn-outline-primary" href="api/backups.php?action=downloadLatest" hidden>Download latest backup</a>
                                    @endif
                                </div>
                                <p id="backupSettingsStatus" class="backup-settings-status">Loading backup settings…</p>
                                <p class="form-text mb-0">Automatic and manual backups include project records, history, numbering, an Excel export and project attachments only. @if(config('tracker.cloud'))Private storage retains the latest snapshot of seven days, four weeks and three months. Automatic backups run while the pilot server is awake.@else Daily, weekly, and monthly copies are saved in this location.@endif</p>
                            </div>
                        </section>
                    </section>
                </div>
            </div>

        </div>

    </main>


    <!-- =========================================================
         PROJECT DETAILS MODAL
         ========================================================= -->
    <div
        class="modal fade"
        id="projectDetailsModal"
        tabindex="-1"
        aria-labelledby="projectDetailsModalLabel"
        aria-hidden="true"
    >

        <div class="modal-dialog modal-xl modal-dialog-scrollable">

            <div class="modal-content project-details-modal">


                <div class="modal-header">

                    <div>

                        <div class="project-details-eyebrow">
                            Project Record
                        </div>

                        <h5
                            class="modal-title"
                            id="projectDetailsModalLabel"
                        >
                            Project Details
                        </h5>
                        <div id="projectDetailsPresence" data-project-editors="" role="status" aria-live="polite" hidden></div>

                    </div>


                    <div id="projectAlertsPanel" class="card notification-panel project-alert-dropdown d-none bg-white" role="dialog" aria-modal="false" aria-label="Project alerts"></div>
                    <div class="project-details-header-actions">
                        <button type="button" id="projectDetailsAlertsButton" class="project-alert-button btn btn-sm btn-outline-danger" aria-expanded="false" aria-controls="projectAlertsPanel" aria-haspopup="dialog" onclick="toggleProjectAlertsPanel(event)">Alerts</button>
                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                    </div>

                </div>


                <div
                    class="modal-body"
                    id="projectDetailsBody"
                ></div>


                <div class="modal-footer">

                    <div class="project-details-footer-actions me-auto">
                        <button
                            type="button"
                            id="projectDetailsPrintButton"
                            class="btn btn-outline-primary"
                            onclick="printProjectDetails()"
                        >
                            Print
                        </button>

                        <button
                            type="button"
                            id="projectDetailsEditButton"
                            class="btn btn-primary d-none"
                            onclick="editProjectFromDetails()"
                        >
                            Edit
                        </button>
                    </div>

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal"
                    >
                        Close
                    </button>

                </div>


            </div>

        </div>

    </div>


<!-- =========================================================
     PROJECT HISTORY / AUDIT TRAIL MODAL
     ========================================================= -->
<div
    class="modal fade"
    id="historyModal"
    tabindex="-1"
    aria-labelledby="historyModalLabel"
    aria-hidden="true"
>
    <div
        class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"
    >
        <div class="modal-content history-modal">

            <!-- Header -->
            <div class="modal-header history-modal-header">

                <div>
                    <div class="history-modal-eyebrow">
                        Project Records
                    </div>

                    <h5
                        class="modal-title history-modal-title"
                        id="historyModalLabel"
                    >
                        Audit Trail
                    </h5>

                    <p class="history-modal-subtitle">
                        Review saved project changes and timeline actions.
                    </p>
                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>

            </div>


            <!-- Body -->
            <div
                class="modal-body history-modal-body"
                id="historyBody"
            >
                <div class="history-empty-state">
                    Loading project history...
                </div>
            </div>


            <!-- Footer -->
            <div class="modal-footer history-modal-footer">

                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    data-bs-dismiss="modal"
                >
                    Close
                </button>

            </div>

        </div>
    </div>
</div>


    <!-- =========================================================
         DUPLICATE PROJECT MODAL
         ========================================================= -->
    <div
        class="modal fade"
        id="projectDuplicateModal"
        tabindex="-1"
        aria-hidden="true"
    >

        <div class="modal-dialog modal-dialog-centered">

            <div class="modal-content border-0 shadow-lg">


                <div class="modal-header py-3">

                    <h5
                        class="modal-title fw-bold"
                        id="projectDuplicateModalTitle"
                    >
                        Duplicate Project Detected
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>

                </div>


                <div
                    class="modal-body bg-light p-3"
                    id="projectDuplicateModalBody"
                >

                    <p class="small text-muted mb-0">
                        An existing project with the same title was found.
                        Choose how to resolve this.
                    </p>

                </div>


                <div class="modal-footer bg-white border-0 d-flex gap-2">

                    <button
                        type="button"
                        class="btn btn-danger"
                        onclick="projectDuplicateAction('delete')"
                    >
                        Delete Existing & Add New
                    </button>


                    <button
                        type="button"
                        class="btn btn-primary"
                        onclick="projectDuplicateAction('overwrite')"
                    >
                        Overwrite Existing
                    </button>


                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        onclick="projectDuplicateAction('duplicate')"
                    >
                        Add as New (Duplicate)
                    </button>


                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                </div>

            </div>

        </div>

    </div>


    <div class="modal fade" id="systemGuideModal" tabindex="-1" aria-labelledby="systemGuideTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="guide-heading"><span class="guide-book" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5.5C8.5 3 5 3 2 4.5v15C5 18 8.5 18 12 20.5c3.5-2.5 7-2.5 10-1v-15C19 3 15.5 3 12 5.5Zm0 0v15"/></svg></span><div><span class="guide-eyebrow">Project Tracker · Help center</span><h5 class="modal-title" id="systemGuideTitle">How to use the Project Tracker</h5><p>Choose a topic to find the steps you need.</p></div></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
<div id="projectUserGuideContent" class="guide-layout" data-tracker-guide>
        <aside class="guide-sidebar">
            <div><label class="guide-search-label" for="projectUserGuideContent-search">Find a topic</label><div class="guide-search-box"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></svg><input id="projectUserGuideContent-search" type="search" data-guide-search placeholder="Search the guide" autocomplete="off"></div></div>
            <nav class="guide-topics" aria-label="Guide topics"><button type="button" class="guide-topic-button" aria-controls="projectUserGuideContent-topic-1" aria-current="true"><span class="guide-topic-number">01</span><span>Getting started</span></button><button type="button" class="guide-topic-button" aria-controls="projectUserGuideContent-topic-2" aria-current="false"><span class="guide-topic-number">02</span><span>Dashboard &amp; notifications</span></button><button type="button" class="guide-topic-button" aria-controls="projectUserGuideContent-topic-3" aria-current="false"><span class="guide-topic-number">03</span><span>Find projects</span></button><button type="button" class="guide-topic-button" aria-controls="projectUserGuideContent-topic-4" aria-current="false"><span class="guide-topic-number">04</span><span>Add &amp; edit projects</span></button><button type="button" class="guide-topic-button" aria-controls="projectUserGuideContent-topic-5" aria-current="false"><span class="guide-topic-number">05</span><span>Milestones &amp; status</span></button><button type="button" class="guide-topic-button" aria-controls="projectUserGuideContent-topic-6" aria-current="false"><span class="guide-topic-number">06</span><span>Resolve &amp; restore alerts</span></button><button type="button" class="guide-topic-button" aria-controls="projectUserGuideContent-topic-7" aria-current="false"><span class="guide-topic-number">07</span><span>Details, print &amp; audit trail</span></button><button type="button" class="guide-topic-button" aria-controls="projectUserGuideContent-topic-8" aria-current="false"><span class="guide-topic-number">08</span><span>Project attachments</span></button><button type="button" class="guide-topic-button" aria-controls="projectUserGuideContent-topic-9" aria-current="false"><span class="guide-topic-number">09</span><span>Import, export &amp; archive</span></button><button type="button" class="guide-topic-button" aria-controls="projectUserGuideContent-topic-10" aria-current="false"><span class="guide-topic-number">10</span><span>Shared updates &amp; editors</span></button><button type="button" class="guide-topic-button" aria-controls="projectUserGuideContent-topic-11" aria-current="false"><span class="guide-topic-number">11</span><span>Admin operations</span></button><button type="button" class="guide-topic-button" aria-controls="projectUserGuideContent-topic-12" aria-current="false"><span class="guide-topic-number">12</span><span>Profile &amp; sign out</span></button></nav>
            <label class="guide-mobile-topics" for="projectUserGuideContent-select">Choose a topic<select id="projectUserGuideContent-select" class="form-select" data-guide-select><option value="projectUserGuideContent-topic-1">01 · Getting started</option><option value="projectUserGuideContent-topic-2">02 · Dashboard &amp; notifications</option><option value="projectUserGuideContent-topic-3">03 · Find projects</option><option value="projectUserGuideContent-topic-4">04 · Add &amp; edit projects</option><option value="projectUserGuideContent-topic-5">05 · Milestones &amp; status</option><option value="projectUserGuideContent-topic-6">06 · Resolve &amp; restore alerts</option><option value="projectUserGuideContent-topic-7">07 · Details, print &amp; audit trail</option><option value="projectUserGuideContent-topic-8">08 · Project attachments</option><option value="projectUserGuideContent-topic-9">09 · Import, export &amp; archive</option><option value="projectUserGuideContent-topic-10">10 · Shared updates &amp; editors</option><option value="projectUserGuideContent-topic-11">11 · Admin operations</option><option value="projectUserGuideContent-topic-12">12 · Profile &amp; sign out</option></select></label>
        </aside>
        <div class="guide-reading-pane"><p class="guide-intro">Use this guide for Admin and Staff project work. The tracker stores saved records on the shared server. Your available actions depend on your account permissions.</p><section id="projectUserGuideContent-topic-1" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="projectUserGuideContent-topic-1-title"><span class="guide-topic-kicker">Access &amp; permissions</span><h2 id="projectUserGuideContent-topic-1-title">Sign in, navigation and permissions</h2><ol>
        <li>Open the tracker link provided by IT and sign in with your own username and password. Contact IT for a new account, password assistance or unavailable actions. Staff cannot register their own accounts.</li>
        <li>Use Workspace to open <strong>Dashboard, Add Project, View All Projects, Resolved Alerts, Historical Archive</strong> and, for Admin, <strong>Admin</strong>. On smaller screens, open the navigation menu to switch pages.</li>
        <li><strong>Admin</strong> has all project permissions and can manage shared timeline settings, recent activity and project backups. <strong>Staff</strong> can view records, details, history, alerts, attachments and printable summaries. IT separately grants <strong>Add, Edit, Import, Export, Delete, Delete all, Restore historical alerts</strong> and <strong>Archive/restore</strong>. Permission to resolve alerts does not grant permission to edit milestone dates.</li>
    </ol></section><section id="projectUserGuideContent-topic-2" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="projectUserGuideContent-topic-2-title" hidden><span class="guide-topic-kicker">Stay up to date</span><h2 id="projectUserGuideContent-topic-2-title">Dashboard and notifications</h2><ol>
        <li>The <strong>Total Projects, Pending</strong> and <strong>Active Alerts</strong> cards open their corresponding project views. Counts cover non-archived projects. Use the status chart’s month/year filters and Monthly Completions’ year filter to explore the registry.</li>
        <li><strong>Recently updated projects</strong> shows saved changes. <strong>Needs attention</strong> groups alerts by project; search and use Previous/Next to review additional pages.</li>
        <li>Select a project title for its details, or <strong>Alerts</strong> for individual reminders, deadlines and resolution dates. The notification bell works on every page. Selecting a project or its Alerts button closes Notifications and opens that project.</li>
    </ol></section><section id="projectUserGuideContent-topic-3" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="projectUserGuideContent-topic-3-title" hidden><span class="guide-topic-kicker">Search &amp; filters</span><h2 id="projectUserGuideContent-topic-3-title">Search, filters and project details</h2><ol>
        <li>In <strong>View All Projects</strong>, choose <strong>Active projects, Completed projects, Released projects, Active alerts</strong> or <strong>All projects</strong>. Archived records are in <strong>Historical Archive</strong>.</li>
        <li>Search records and combine the status, exact project number, barangay and selected milestone date filters. Select a date field, then its month, day or year as needed. Clear filters when a record is missing from your results.</li>
        <li>Select the project-number heading to reverse the sort order. Use pagination for more results. Select a title to open <strong>Project Details</strong>, including financial information, dates, remarks, attachments and Alerts.</li>
        <li>Use <strong>History</strong> to review saved edits and timeline actions in the project’s <strong>Audit Trail</strong>. Each entry shows when the change was saved and the user who made it; older entries without a recorded user show <strong>Not recorded</strong>. Use <strong>Print</strong> to open the formatted summary, then your browser’s print or Save as PDF option. Printed summaries are reference copies.</li>
    </ol></section><section id="projectUserGuideContent-topic-4" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="projectUserGuideContent-topic-4-title" hidden><span class="guide-topic-kicker">Record your project</span><h2 id="projectUserGuideContent-topic-4-title">Add and edit project records</h2><ol>
        <li>Choose <strong>Add Project</strong>. Enter the required <strong>Project Title</strong> and <strong>Approved Budget for the Contract</strong>. Include the location in the title, for example “Residential Building at Brgy. 1”. Add the bidding date and winning bidder when available. The next project number is a preview; the server confirms the assigned number when saving.</li>
        <li>Complete the relevant sections: project information and fund source (use <strong>Others</strong> for a custom source); bidding and bid amount; bidder; purchase request and obligation dates; resolution; post qualification; NOA deadline and issuance; performance bond; contract date and duration; NTP; DOLE clearance; inspection, testing, SWA and completion; liquidated damages; and remarks.</li>
        <li>Enter amounts as non-negative numbers with at most two decimal places. Enter <strong>Contract Duration</strong> as a positive number of working days, such as <strong>30</strong> or <strong>30.5</strong>. Do not append “days”. The Contract Due Date is calculated from the Contract Date and duration. Liquidated-damages fields record the amount, number of days, revised completion date and actual duration; entering a revised date does not replace the calculated contract deadline.</li>
        <li>Select <strong>Save Project</strong> and wait for confirmation. To update a project, use its <strong>Edit</strong> action, review the fields and choose <strong>Apply Records Change</strong>. <strong>Cancel</strong> or the editor’s close button discards unsaved edits.</li>
        <li>The save button shows <strong>Saving</strong> while the request is in progress. A successful save shows a brief confirmation, and the header message clears. <strong>Save failed</strong> remains visible and leaves your draft open for correction. Only a successful server response confirms saving. If saving succeeds but the view update fails, the notification distinguishes the saved record from the pending refresh.</li>
    </ol></section><section id="projectUserGuideContent-topic-5" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="projectUserGuideContent-topic-5-title" hidden><span class="guide-topic-kicker">Track progress</span><h2 id="projectUserGuideContent-topic-5-title">Milestone deadlines, actual dates and status</h2><div class="table-responsive">
        <table class="table table-sm table-bordered">
            <thead><tr><th scope="col">Milestone</th><th scope="col">Deadline / entry</th><th scope="col">How to resolve</th></tr></thead>
            <tbody>
                <tr><td data-label="Milestone">Notice of Award (NOA)</td><td data-label="Deadline / entry">Enter <strong>NOA Due Date</strong> separately from the actual <strong>NOA Issued Date</strong>.</td><td data-label="How to resolve">Record the actual issuance date.</td></tr>
                <tr><td data-label="Milestone">Pre-inspection</td><td data-label="Deadline / entry">One calendar month after Bidding Date.</td><td data-label="How to resolve">Record <strong>Pre Inspection</strong>.</td></tr>
                <tr><td data-label="Milestone">Notice to Proceed (NTP)</td><td data-label="Deadline / entry">One calendar month after Bidding Date.</td><td data-label="How to resolve">Record the actual <strong>Notice to Proceed</strong> date.</td></tr>
                <tr><td data-label="Milestone">Material testing</td><td data-label="Deadline / entry">One calendar month after Bidding Date.</td><td data-label="How to resolve">Record <strong>Material Testing Date</strong>.</td></tr>
                <tr><td data-label="Milestone">Final inspection</td><td data-label="Deadline / entry">Calculated Contract Due Date; when contract date/duration are absent, a saved Contract End Date is used.</td><td data-label="How to resolve">Record <strong>Final Inspection</strong>.</td></tr>
                <tr><td data-label="Milestone">SWA follow-up</td><td data-label="Deadline / entry">One year after <strong>Completion Date</strong>, moved to a working date.</td><td data-label="How to resolve">Record <strong>Statement of Work Accomplished Date</strong>.</td></tr>
            </tbody>
        </table>
    </div>
    <p>Calculated due dates are read-only and do not fill actual dates. Calendar-month deadlines clamp to the last valid day of the next month, then move to the next working day if necessary. Admin controls reminder lead times; final-inspection reminders default to <strong>7 working days</strong> before the contract deadline. A valid actual milestone date on or before today resolves its alerts. A future actual date remains scheduled and does not resolve the reminder early.</p>
    <p>Status follows recorded milestones: no bidding date → <strong>On-going Bidding</strong>; bidding date → <strong>Pre-inspection</strong>; actual pre-inspection, material-testing and NTP dates all present → <strong>Pending</strong>. At the contract deadline, missing final inspection produces <strong>Final-inspection</strong>. Entering a valid <strong>Final Inspection Date</strong> sets the status to <strong>Pending</strong>, even before the contract deadline or when earlier milestone dates are missing. Completion Date produces <strong>Completed</strong>; SWA Date produces <strong>Released</strong>, which takes priority. A project explicitly kept Completed/Released retains that status. A Completed project requires a Completion Date and appears in Monthly Completions for that date’s month/year.</p>
    <p>Status colors: <strong>Pending—amber; On-going Bidding—blue; Pre-inspection—cyan; Final-inspection—purple; Completed—green; Released—teal.</strong> Read the label as well as its color. Completed projects can still need SWA follow-up until SWA is recorded.</p></section><section id="projectUserGuideContent-topic-6" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="projectUserGuideContent-topic-6-title" hidden><span class="guide-topic-kicker">Keep reminders accurate</span><h2 id="projectUserGuideContent-topic-6-title">Resolve and restore alerts</h2><ol>
        <li>Choose <strong>Edit</strong> on the project, record the actual milestone date and save. Use <strong>Alerts</strong> in the project details or editor to check alert statuses and dates. NOA, pre-inspection, NTP, material-testing, final-inspection and SWA alerts resolve from their dates. Editing requires project-edit permission.</li>
        <li>Alerts resolve automatically from a valid actual milestone date on or before today, including older NOA and NTP reminders. A future date stays scheduled until that date. Recording or correcting a milestone requires <strong>Edit</strong> permission.</li>
        <li><strong>Resolved Alerts</strong> groups completed reminders by milestone. Use its search/date filters and category pages to review them. <strong>Restore</strong> reopens a historical manually resolved reminder and requires the <strong>Restore historical alerts</strong> permission. Automatically resolved entries offer <strong>View</strong>; edit or clear the actual date to reopen the reminder when due. Existing historical manual flags may also need restoring.</li>
    </ol></section><section id="projectUserGuideContent-topic-7" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="projectUserGuideContent-topic-7-title" hidden><span class="guide-topic-kicker">Review your records</span><h2 id="projectUserGuideContent-topic-7-title">Working dates and Philippine holidays</h2><p>The shared working-day calendar controls durations, reminder lead times and selectable dates. Saturdays, Sundays and Philippine non-working holidays are excluded by default. Shaded non-working dates cannot be selected; unchanged historical dates remain available for reference. Calculated deadlines move forward to a working day. Contact IT about calendar corrections; calendar-management instructions are in the separate account-management guide.</p></section><section id="projectUserGuideContent-topic-8" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="projectUserGuideContent-topic-8-title" hidden><span class="guide-topic-kicker">Files &amp; documents</span><h2 id="projectUserGuideContent-topic-8-title">Attachments</h2><ol>
        <li>Save a new project first, then edit it to add attachments. Choose files and use <strong>Add</strong>. PDF, Word, Excel, text and image files are supported, up to <strong>25 MB each</strong> and <strong>20 uploaded files per save</strong>.</li>
        <li>Added files and removals remain pending until <strong>Apply Records Change</strong> succeeds. A pending attachment draft may be restored in the same browser/account after reopening the page; it has not been uploaded until you save. Cancel discards the draft.</li>
        <li>Select a filename to preview it, or <strong>Download</strong> to save a copy. Preview availability depends on file type/browser support. Editing permission is required to add or remove files.</li>
    </ol></section><section id="projectUserGuideContent-topic-9" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="projectUserGuideContent-topic-9-title" hidden><span class="guide-topic-kicker">Manage the registry</span><h2 id="projectUserGuideContent-topic-9-title">Import, export, archive and deletion</h2><ol>
        <li>With Import permission, choose <strong>Import</strong> and <strong>Download template</strong>. Keep headings, use one project per row, <strong>YYYY-MM-DD</strong> working dates and non-negative amounts with at most two decimals. Choose a CSV or Excel file.</li>
        <li>Review and edit the preview, scrolling horizontally for all columns. Correct invalid rows; invalid rows are skipped. For each duplicate, choose <strong>Add as a new project</strong> or <strong>Overwrite existing project</strong>; review bulk choices carefully. Choose <strong>Import reviewed rows</strong> and wait for the progress result.</li>
        <li>Review imported/skipped counts and any errors. Use <strong>Roll back import</strong> when offered to undo that batch. The server can refuse rollback if later edits would be lost. A failed batch attempts automatic rollback; read whether rollback was confirmed.</li>
        <li><strong>Export Excel</strong> downloads all records matching the current lifecycle and filters, across all pages. Check the view and filters before exporting; use Historical Archive to export archived records.</li>
        <li><strong>Archive</strong> keeps a project and its history for reference and removes it from the active dashboard/alerts. View it in <strong>Historical Archive</strong>; use <strong>Restore</strong> with archive permission to return it.</li>
        <li><strong>Delete</strong> permanently removes a project and its history. <strong>Delete all records</strong> requires its separate permission and confirmation and removes all project records/history, including archives. Review the offered Excel export before confirming; it is not a full attachment backup.</li>
    </ol></section><section id="projectUserGuideContent-topic-10" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="projectUserGuideContent-topic-10-title" hidden><span class="guide-topic-kicker">Work together</span><h2 id="projectUserGuideContent-topic-10-title">Shared updates and simultaneous editing</h2><p>Visible tabs check for shared changes about every <strong>3 seconds</strong> and immediately when you return to the tab. Lists, dashboards and open project details update automatically when connected. Hidden/background tabs catch up when you return. Updates wait while you edit or review an import; the <strong>Updates available</strong> button indicates pending changes. Save or cancel your draft to load them safely.</p>
    <p>Two people may edit the same project. The first successful save changes its version; a later stale save is rejected. Your draft remains open. Copy the changes you need, cancel, reopen the latest project and reapply them after reviewing the other person’s work. Changes are not automatically merged.</p>
    <p><strong>Editing indicators</strong> appear below the project title in Project Details and the edit form. They show <strong>No one is editing</strong> when idle, or names and avatars when someone opens <strong>Edit</strong> for that same project. The project list also shows names when editors are active. Viewing Project Details alone does not register you as an editor. They refresh about every <strong>3 seconds</strong>. <strong>Checking editors…</strong> means the initial check is pending; <strong>Editing status unavailable</strong> means the check failed. A successful save, canceling, closing the editor or signing out clears your indicator. An unexpected disconnect or a suspended background tab can take up to <strong>45 seconds</strong> to disappear. The indicator allows simultaneous editing; it does not lock the project, show live typing or share unsaved changes.</p></section><section id="projectUserGuideContent-topic-11" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="projectUserGuideContent-topic-11-title" hidden><span class="guide-topic-kicker">For administrators</span><h2 id="projectUserGuideContent-topic-11-title">Admin operations</h2><ol>
        <li><strong>Admin → Timeline day settings</strong>: choose standard durations or <strong>Custom</strong> values from 1–3,650 working days, then <strong>Save timeline days</strong>. Settings cover NOA, shared pre-inspection/NTP/material reminders, SWA and final inspection. Reminder settings change when alerts appear; the bidding-based deadlines remain one calendar month after bidding.</li>
        <li><strong>Recent activity → Refresh</strong> reloads recorded actions. <strong>Clear history</strong> requires confirmation and clears the visible recent-activity list; older audit entries remain stored. This is separate from an individual project’s Audit Trail.</li>
        <li><strong>Automatic project backups</strong>: @if(config('tracker.cloud'))backups are saved in private cloud storage. Use <strong>Download latest backup</strong> to keep a copy. The free pilot server runs automatic backups while awake, with one automatic snapshot per day and hourly checks. Retention covers the latest snapshot of seven days, four weeks and three months.@else Set the destination using <strong>Browse</strong>, <strong>Use default</strong> or the path field. Scheduled daily/weekly/monthly copies need the server scheduler configured by IT.@endif Use the On/Off control to save the preference. <strong>Back up projects now</strong> creates a manual archive. Check its result and latest-backup details.</li>
        <li>Project backups include records, history, numbering, an Excel export and project attachments. Ask IT for restoration or a full-system backup; these project backups do not include accounts or profile photos.</li>
    </ol></section><section id="projectUserGuideContent-topic-12" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="projectUserGuideContent-topic-12-title" hidden><span class="guide-topic-kicker">Your account</span><h2 id="projectUserGuideContent-topic-12-title">Profile and sign out</h2><p>Each account has one active browser session. Signing in on another browser or device signs out the previous session; tabs in the same browser can stay open. Admin and Staff are signed out after <strong>30 minutes without typing, clicking or scrolling</strong>. Background updates do not extend this timer. A warning appears one minute before expiry; choose <strong>Stay signed in</strong> to continue. Every session ends after eight hours. If a session ends while editing, sign in with the same account in the same tab to recover the draft. Do not refresh or close that tab before recovery; unsaved fields are kept only in that tab’s memory. Review restored changes before saving.</p><ol><li>Open your top-right avatar → <strong>Edit profile</strong> to change your display name or photo. Use a JPG, PNG or WebP up to 3 MB, position the crop and save. Choose <strong>Sign out</strong> from the same menu when finished.</li></ol>
    <div class="alert alert-light border mb-0"><strong>Need help?</strong> Contact IT for access, connection or calendar issues and your Admin for project questions. Include the project number, attempted action and displayed message. If a session expires, sign in again. Copy unsaved field changes before leaving the page.</div></section><p class="guide-empty" role="status" hidden>No topics found. Try “milestones” or “permissions”.</p></div>
    </div>
<div id="superadminGuideContent" class="guide-layout d-none" data-tracker-guide>
        <aside class="guide-sidebar">
            <div><label class="guide-search-label" for="superadminGuideContent-search">Find a topic</label><div class="guide-search-box"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></svg><input id="superadminGuideContent-search" type="search" data-guide-search placeholder="Search the guide" autocomplete="off"></div></div>
            <nav class="guide-topics" aria-label="Guide topics"><button type="button" class="guide-topic-button" aria-controls="superadminGuideContent-topic-1" aria-current="true"><span class="guide-topic-number">01</span><span>Manage accounts</span></button><button type="button" class="guide-topic-button" aria-controls="superadminGuideContent-topic-2" aria-current="false"><span class="guide-topic-number">02</span><span>Staff permissions</span></button><button type="button" class="guide-topic-button" aria-controls="superadminGuideContent-topic-3" aria-current="false"><span class="guide-topic-number">03</span><span>Working days &amp; holidays</span></button><button type="button" class="guide-topic-button" aria-controls="superadminGuideContent-topic-4" aria-current="false"><span class="guide-topic-number">04</span><span>Profile &amp; sign out</span></button></nav>
            <label class="guide-mobile-topics" for="superadminGuideContent-select">Choose a topic<select id="superadminGuideContent-select" class="form-select" data-guide-select><option value="superadminGuideContent-topic-1">01 · Manage accounts</option><option value="superadminGuideContent-topic-2">02 · Staff permissions</option><option value="superadminGuideContent-topic-3">03 · Working days &amp; holidays</option><option value="superadminGuideContent-topic-4">04 · Profile &amp; sign out</option></select></label>
        </aside>
        <div class="guide-reading-pane"><p class="guide-intro">Use Superadmin to manage accounts, Staff permissions and the shared working-day calendar. You can also manage your profile.</p><section id="superadminGuideContent-topic-1" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="superadminGuideContent-topic-1-title"><span class="guide-topic-kicker">Account administration</span><h2 id="superadminGuideContent-topic-1-title">Create and manage accounts</h2><ol><li>Open <strong>Accounts → Create account</strong>. Enter the person’s name, username, password and role, then save.</li><li>Staff cannot create their own accounts. Employees contact the IT department (Superadmin), which creates their accounts and assigns the appropriate role and permissions. Only active accounts can sign in.</li><li>Use <strong>Edit</strong> to update account details. Use <strong>Delete</strong> to remove an account that is no longer needed.</li><li>The original Superadmin account is protected and cannot be deleted, even if its name or role changes. You also cannot delete your signed-in account. Keep at least one active Superadmin account.</li></ol></section><section id="superadminGuideContent-topic-2" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="superadminGuideContent-topic-2-title" hidden><span class="guide-topic-kicker">Control access</span><h2 id="superadminGuideContent-topic-2-title">Assign Staff permissions</h2><p>Use the Staff account’s <strong>Permissions</strong> menu to enable the actions that person needs, such as adding or editing projects, import, export, deletion, restoring historical alerts and archiving. These controls apply to Staff accounts. Review the saved settings after making changes.</p></section><section id="superadminGuideContent-topic-3" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="superadminGuideContent-topic-3-title" hidden><span class="guide-topic-kicker">Shared calendar</span><h2 id="superadminGuideContent-topic-3-title">Manage working days and Philippine holidays</h2><ol><li>Under <strong>Accounts → Philippine holidays</strong>, select non-working weekdays and choose whether holidays are excluded. Saturdays and Sundays are excluded by default. Keep at least one working weekday and save the settings.</li><li>Add a holiday by its name and date, or use an individual holiday’s action to include or exclude that date. Restore a date to return it to the source calendar.</li><li>These shared settings affect milestone durations, work-hour calculations, calculated deadlines and selectable project dates. A calculated deadline on a non-working day moves to the next working day.</li><li>Enable <strong>Automatic updates</strong> and save. Official announcements are checked daily from <strong>03:00 Philippine time</strong>, with retries after failures. Use <strong>Sync now</strong> for an immediate check or <strong>Refresh</strong> to reload the panel. Review the last successful sync and any error; the saved calendar remains available if a sync fails.</li></ol></section><section id="superadminGuideContent-topic-4" class="guide-topic" data-guide-topic tabindex="-1" aria-labelledby="superadminGuideContent-topic-4-title" hidden><span class="guide-topic-kicker">Your account</span><h2 id="superadminGuideContent-topic-4-title">Update your profile and sign out</h2><p>Your account allows one active browser session. A successful login on another browser or device signs out the previous session. Multiple tabs in the same browser are allowed. <strong>Superadmin is exempt from the 30-minute inactivity logout.</strong> The eight-hour maximum session duration still applies. Sign out when finished.</p><p>Open your top-right avatar and choose <strong>Edit profile</strong> to update your display name or photo. Use a JPG, PNG or WebP up to 3 MB, position the photo and save. Choose <strong>Sign out</strong> from the same menu when finished.</p></section><p class="guide-empty" role="status" hidden>No topics found. Try “milestones” or “permissions”.</p></div>
    </div>
                </div>

                <div class="modal-footer"><span id="guideTopicProgress" class="guide-footer-note" role="status">Topic 1 of 12</span><div class="guide-footer-actions"><button id="guidePreviousButton" type="button" class="btn btn-outline-secondary" disabled>Previous</button><button id="guideNextButton" type="button" class="btn btn-outline-secondary">Next topic</button><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button></div></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="importGuideModal" tabindex="-1" aria-labelledby="importGuideTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="importGuideTitle">Import projects</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Download the template, fill one project per row, then choose a CSV or Excel file. Keep the column headings. Dates should use YYYY-MM-DD, and amounts should be non-negative numbers with up to two decimal places.</p>
                    <ol class="mb-0">
                        <li>Choose a file to preview its rows before saving.</li>
                        <li>Edit any values and review invalid rows in the preview.</li>
                        <li>Continue to resolve duplicates, then import valid rows.</li>
                    </ol>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="downloadImportTemplate()">Download template</button>
                    <button type="button" class="btn btn-primary" onclick="chooseImportFile()">Choose file</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="importPreviewModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="importPreviewTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered import-preview-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="importPreviewTitle">Preview import</h5>
                    <button type="button" class="btn-close" onclick="cancelImportPreview()" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="importPreviewSummary" class="import-preview-summary" aria-live="polite"></p>
                    <div class="import-preview-scroll" role="region" aria-label="Editable import rows" tabindex="0">
                        <table class="table table-bordered align-middle import-preview-table" id="importPreviewTable"></table>
                    </div>
                    <p class="small text-muted mt-2 mb-0">Scroll sideways for more columns. Edit any cell, then choose Add as a new project or Overwrite existing project for each duplicate. Invalid rows are skipped.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="cancelImportPreview()">Cancel</button>
                    <button type="button" class="btn btn-primary" id="continueImportPreviewButton" onclick="continueImportPreview()">Import reviewed rows</button>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================
         IMPORT CONFLICT MODAL
         ========================================================= -->
    <div
        class="modal fade"
        id="importConflictModal"
        data-bs-backdrop="static"
        data-bs-keyboard="false"
        tabindex="-1"
        aria-labelledby="importConflictModalLabel"
        aria-hidden="true"
    >

        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable import-conflict-dialog">

            <div class="modal-content border-0 shadow-lg">


                <div class="modal-header py-3">

                    <h5
                        class="modal-title"
                        id="importConflictModalLabel"
                    >
                        Review duplicates
                    </h5>

                </div>


                <div class="modal-body p-3">

                    <p id="importConflictSummary" class="text-muted small mb-3"></p>


                    <div class="d-flex flex-wrap gap-2 mb-3">

                        <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            onclick="overwriteAllConflicts(true)"
                        >
                            Overwrite all
                        </button>


                        <button
                            type="button"
                            class="btn btn-outline-secondary btn-sm"
                            onclick="overwriteAllConflicts(false)"
                        >
                            Keep all as new
                        </button>

                    </div>


                    <ul
                        class="list-group border rounded bg-white shadow-sm"
                        id="conflictItemsList"
                        style="max-height: 260px; overflow-y: auto;"
                    ></ul>

                </div>


                <div class="modal-footer bg-white border-top-0 d-flex justify-content-between">

                    <button
                        type="button"
                        class="btn btn-light btn-sm"
                        onclick="cancelBulkImportProgress()"
                    >
                        Cancel
                    </button>


                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        onclick="executeBulkImportProcessing()"
                    >
                        Continue import
                    </button>

                </div>

            </div>

        </div>

    </div>

    <div class="modal fade" id="importProgressModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered import-progress-dialog">
            <div class="modal-content import-progress-card">
                <div class="modal-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div><div class="dashboard-eyebrow">Historical import</div><h5 class="mb-1">Importing projects</h5><p id="importProgressStatus" class="text-muted small mb-0">Preparing…</p></div>
                        <div class="import-count"><strong id="importProgressCount">0 / 0</strong><span>processed</span></div>
                    </div>
                    <div class="progress import-progress-track" role="progressbar" aria-label="Import progress"><div id="importProgressBar" class="progress-bar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div></div>
                    <div class="import-error-summary"><span>Errors</span><strong id="importErrorCount">0</strong></div>
                </div>
                <div id="importProgressActions" class="modal-footer d-none">
                    <button type="button" id="downloadImportErrorsButton" class="btn btn-outline-secondary btn-sm" onclick="downloadImportErrorReport()">Download errors</button>
                    <button type="button" id="rollbackImportButton" class="btn btn-outline-danger btn-sm" onclick="rollbackLastImport()">Roll back import</button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">Done</button>
                </div>
            </div>
        </div>
    </div>


    <!-- =========================================================
         DEPENDENCIES
         ========================================================= -->
    <dialog id="attachmentPreviewDialog" class="attachment-preview-dialog" aria-labelledby="attachmentPreviewTitle" onclose="clearAttachmentPreview()">
        <div class="attachment-preview-header">
            <h2 id="attachmentPreviewTitle">Attachment</h2>
            <button type="button" class="attachment-preview-close" onclick="document.getElementById('attachmentPreviewDialog').close()" aria-label="Close preview">&times;</button>
        </div>
        <div id="attachmentPreviewContent" class="attachment-preview-content"></div>
        <div class="attachment-preview-footer">
            <a id="attachmentPreviewDownload" class="btn btn-primary btn-sm" href="#">Download</a>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('attachmentPreviewDialog').close()">Close</button>
        </div>
    </dialog>
    <script src="vendor/xlsx/xlsx.full.min.js?v=20261005-security"></script>

    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js?v=20261006-notification-navigation"></script>


    <!-- =========================================================
         APPLICATION
         ========================================================= -->
    <div id="sessionIdleWarning" class="session-idle-warning" role="alert" hidden>
        <div><strong>Your session is about to end</strong><p>You will be signed out in <span id="sessionIdleCountdown">60</span> seconds because of inactivity. Your project draft will stay in this tab.</p></div>
        <button type="button" class="btn btn-primary btn-sm" onclick="keepTrackerSessionActive()">Stay signed in</button>
    </div>
    <script src="js/auth.js?v=20261011-account-sessions"></script>
    <script src="js/session.js?v=20261011-account-sessions"></script>

    <script src="js/admin.js?v=20261011-cloud"></script>

    <script src="js/db.js?v=20261011-account-sessions"></script>

    <script src="js/state.js?v=20261006-notification-navigation"></script>

    <script>window.projectHolidayCalendar = @json(\App\Support\TrackerHolidays::calendar());</script>
    <script src="js/utils.js?v=20261007-block-dates"></script>

    <script src="js/history.js?v=20261010-history-actor"></script>

    <script src="js/timeline.js?v=20261010-date-only-alerts"></script>

    <script src="js/projects.js?v=20261011-account-sessions"></script>
    <script src="js/project-presence.js?v=20261011-account-sessions"></script>
    <script src="js/guide.js?v=20261010-guide-cleanup"></script>

    <script src="js/workspace.js?v=20261010-date-only-alerts"></script>

    <script src="js/excel.js?v=20261007-block-dates"></script>

    <script src="js/holidays.js?v=20261007-block-dates"></script>
    <script src="js/workday-picker.js?v=20261007-block-dates"></script>
    <script src="js/app.js?v=20261006-integrity-audit"></script>



</body>

</html>


