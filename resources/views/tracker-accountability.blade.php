<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accountability | Project Tracker</title>
    <link rel="icon" href="{{ asset('logo.png') }}">
    <link rel="stylesheet" href="{{ asset('css/accountability.css') }}?v=20261120">
</head>
<body class="audit-page">
    <header class="audit-header">
        <div class="audit-brand"><img src="{{ asset('logo.png') }}" alt="" width="42" height="42"><div><strong>Project Tracker</strong><span>LGU Laoag City · Office of Engineering</span></div></div>
        <div class="audit-header-actions"><span class="read-only-badge">Read-only access</span><span id="auditSignedInUser"></span><button id="auditSignOut" class="button button-secondary" type="button">Sign out</button></div>
    </header>
    <main class="audit-main">
        <div class="audit-title-row"><div><p class="eyebrow">Accountability</p><h1>Activity log</h1><p class="page-description">Review who acted, which record was affected, and the changes they made. Dates use Philippine time.</p></div><button id="auditRefresh" class="button button-secondary" type="button">Refresh activity</button></div>
        <section class="audit-panel" aria-label="Search activity">
            <form id="auditFilterForm" class="audit-filters">
                <label>Search<input name="search" type="search" maxlength="200" placeholder="Record, action, or username"></label>
                <label>Account<input name="actor" maxlength="80" placeholder="Username"></label>
                <label>Action<input name="actionFilter" maxlength="80" placeholder="For example, project.saved"></label>
                <label>From<input name="fromDate" type="date"></label>
                <label>To<input name="toDate" type="date"></label>
                <div class="filter-actions"><button class="button button-primary" type="submit">Apply filters</button><button id="auditReset" class="button button-secondary" type="button">Reset</button></div>
            </form>
        </section>
        <section class="audit-panel audit-results" aria-labelledby="auditResultsTitle">
            <div class="results-heading"><h2 id="auditResultsTitle">Recorded activity</h2><span id="auditResultsStatus" role="status" aria-live="polite">Loading activity…</span></div>
            <p id="auditError" class="audit-error" role="alert" hidden></p>
            <ol id="auditEntries" class="audit-entries"></ol>
            <nav id="auditPagination" class="audit-pagination" aria-label="Activity pages" hidden><button id="auditPrevious" class="button button-secondary" type="button">Previous</button><span id="auditPageLabel"></span><button id="auditNext" class="button button-secondary" type="button">Next</button></nav>
        </section>
        <p class="audit-footnote">Log numbers stay with their entries. Activity hidden from the Admin view is retained here. Older entries show the details recorded at the time.</p>
    </main>
    <script src="{{ asset('js/accountability.js') }}?v=20261120b" defer></script>
</body>
</html>
