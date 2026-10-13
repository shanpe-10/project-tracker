/* ==========================================================================
   APPLICATION STARTUP
   ========================================================================== */


/*
 * Wait for IndexedDB initialization from db.js
 * before starting the application.
 */


let applicationStarted = false;
function startApplication() {
    if (!window.dbReady) return;
    if (window.currentUser?.role === "superadmin") {
        switchWorkspace("account-requests");
        return;
    }
    try {
        restoreLastWorkspace();

        // Install listeners once, but restore the workspace on each successful sign-in.
        if (applicationStarted) return;
        applicationStarted = true;
        // Keep the dashboard in sync when another user updates a project.
        window.setInterval(() => {
            if (document.visibilityState === "visible") {
                if (activeWorkspace === "dashboard") loadDocuments();
                else refreshGlobalNotifications();
            }
        }, 30000);

        document.addEventListener("visibilitychange", () => {
            if (document.visibilityState === "visible") {
                if (activeWorkspace === "dashboard") loadDocuments();
                else refreshGlobalNotifications();
            }
        });
    } catch (error) {
        console.error("Critical error during workspace dashboard initialization:", error);
    }
}

window.addEventListener("sharedDatabaseReady", startApplication);
if (window.dbReady) startApplication();
