/* One account session, with user activity kept separate from background polling. */
(() => {
    let generation = 0, accountId = null, idleDeadline = null, absoluteDeadline = null;
    let pendingActivity = false, lastActivitySent = 0, lastInputAt = 0, lastActivityAttempt = 0, activityBusy = false, checkBusy = false;
    const warning = () => document.getElementById("sessionIdleWarning");
    const now = () => Date.now();
    const effectiveIdleDeadline = () => idleDeadline === null ? null : pendingActivity
        ? Math.max(idleDeadline, lastInputAt + 1800000) : idleDeadline;

    function timing(value) {
        if (!value || !Number.isFinite(value.serverNow)) return;
        absoluteDeadline = value.expiresAt === null ? null : now() + Math.max(0, value.expiresAt - value.serverNow) * 1000;
        idleDeadline = window.currentUser?.role === "superadmin" || value.idleExpiresAt === null
            ? null : now() + Math.max(0, value.idleExpiresAt - value.serverNow) * 1000;
        renderWarning();
    }

    function renderWarning() {
        const box = warning();
        if (!box) return;
        const deadline = effectiveIdleDeadline();
        const seconds = deadline === null ? Infinity : Math.ceil((deadline - now()) / 1000);
        box.hidden = !window.currentUser || seconds > 60 || seconds <= 0;
        const countdown = document.getElementById("sessionIdleCountdown");
        if (countdown && !box.hidden) countdown.textContent = String(seconds);
    }

    window.stopTrackerSession = () => {
        ++generation;
        accountId = null;
        pendingActivity = false;
        idleDeadline = absoluteDeadline = null;
        activityBusy = checkBusy = false;
        if (warning()) warning().hidden = true;
    };

    window.startTrackerSession = value => {
        if (!value || !window.currentUser) return;
        window.stopTrackerSession();
        accountId = window.currentUser.id;
        lastActivitySent = now();
        lastActivityAttempt = lastInputAt = now();
        timing(value);
    };

    window.handleTrackerAuthenticationFailure = (payload, expectedCsrf) => {
        if (!window.currentUser || expectedCsrf !== window.sessionCsrfToken) return;
        showLogin(payload?.message || "Your session has ended. Please sign in again.");
    };

    async function checkSession() {
        if (!window.currentUser || checkBusy) return;
        const started = generation, expectedId = accountId;
        checkBusy = true;
        try {
            const result = await authRequest("session");
            if (started !== generation || !window.currentUser) return;
            if (!result.ok || !result.authenticated) {
                showLogin(result.message || "Your session has ended. Please sign in again.");
            } else if (result.user.id !== expectedId) {
                showLogin("The account in this browser changed. Sign in with your account to continue.");
            } else {
                // Tabs share the browser cookie. Refresh their CSRF token after a new login.
                window.sessionCsrfToken = result.csrfToken;
                if (result.user.role !== window.currentUser.role) applyAuthenticatedUser(result.user, result.csrfToken);
                timing(result.sessionTiming);
            }
        } catch (_) {
            if (started === generation && ((absoluteDeadline !== null && now() >= absoluteDeadline)
                || (effectiveIdleDeadline() !== null && now() >= effectiveIdleDeadline()))) {
                showLogin("Your session timed out. Please sign in again when the connection is available.");
            }
        } finally {
            if (started === generation) checkBusy = false;
        }
    }

    async function sendActivity(force = false) {
        if (!window.currentUser || !pendingActivity || activityBusy || (!force && now() - lastActivitySent < 15000)) return;
        if (now() - lastActivityAttempt < 1000) return;
        // Do not replay old clicks after a long offline period as new activity.
        if (now() - lastInputAt > 15000) { pendingActivity = false; return; }
        const started = generation;
        lastActivityAttempt = now();
        activityBusy = true;
        pendingActivity = false;
        try {
            const result = await authRequest("activity");
            if (started !== generation || !window.currentUser) return;
            if (result.ok) { lastActivitySent = now(); timing(result.sessionTiming); }
            else if (result.reason || result.message === "Authentication required.") showLogin(result.message);
            else pendingActivity = true;
        } catch (_) { if (started === generation) pendingActivity = true; }
        finally { if (started === generation) { activityBusy = false; renderWarning(); } }
    }

    function userActivity(event) {
        if (event && !event.isTrusted) return;
        if (!window.currentUser || window.currentUser.role === "superadmin") return;
        if ((idleDeadline !== null && now() >= idleDeadline) || (absoluteDeadline !== null && now() >= absoluteDeadline)) {
            void checkSession();
            return;
        }
        pendingActivity = true;
        lastInputAt = now();
        renderWarning();
        void sendActivity(idleDeadline !== null && idleDeadline - now() <= 60000);
    }

    window.keepTrackerSessionActive = () => { userActivity(); void sendActivity(true); };
    for (const event of ["pointerdown", "keydown", "scroll"]) document.addEventListener(event, userActivity, { capture: true, passive: true });
    document.addEventListener("visibilitychange", () => { if (!document.hidden) void checkSession(); });
    window.addEventListener("focus", () => { void checkSession(); });
    window.addEventListener("online", () => { void checkSession(); void sendActivity(true); });
    setInterval(() => {
        if (!window.currentUser) return;
        void sendActivity();
        renderWarning();
        if ((absoluteDeadline !== null && now() >= absoluteDeadline) || (effectiveIdleDeadline() !== null && now() >= effectiveIdleDeadline())) void checkSession();
    }, 1000);
    setInterval(() => { if (!document.hidden) void checkSession(); }, 15000);
})();
