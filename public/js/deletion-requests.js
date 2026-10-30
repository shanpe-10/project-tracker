/* Deletion requests are separate from the project cache until final admin confirmation. */
(() => {
    const state = { listView: 'active', listFingerprint: null, poll: null, scope: "mine", page: 1, lastPage: 1, target: null, detail: null, token: null, timer: null, busy: false, epoch: 0, read: 0 };
    const el = id => document.getElementById(id);
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char]);
    const identity = () => `${window.currentUser?.id || ''}:${window.sessionCsrfToken || ''}:${state.epoch}`;
    const isAdmin = () => window.currentUser?.role === 'admin';
    const workspaceScope = () => isAdmin() ? 'review' : 'mine';
    const person = actor => actor ? `${actor.fullName || actor.username} (@${actor.username})` : '—';
    const date = value => value ? new Date(value).toLocaleString() : '—';
    const label = item => `${item.targetCount} ${item.targetType === 'project' ? (item.targetCount === 1 ? 'project' : 'projects') : (item.targetCount === 1 ? 'history record' : 'history records')}`;
    const badge = item => `<span class="request-status is-${item.stage}">${escape(({pending:'Pending',approved:'Approved',rejected:'Rejected',deleted:'Deleted',cancelled:'Cancelled'})[item.stage] || item.stage)}</span>${item.stage === 'approved' ? '<span class="request-stage-note">Awaiting final confirmation</span>' : ''}`;
    function message(id, value = '') { el(id).textContent = value; el(id).hidden = !value; }
    function error(error) { message('deletionRequestError', error.message || 'Unable to process this request.'); }
    function view(name, title = 'Deletion requests') {
        for (const key of ['List', 'Create', 'Direct', 'Detail', 'Confirm']) el(`deletionRequest${key}View`).hidden = key !== name;
        el('deletionRequestsTitle').textContent = title;
        message('deletionRequestError');
        message('deletionRequestNotice');
    }
    function modal() { bootstrap.Modal.getOrCreateInstance(el('deletionRequestsModal')).show(); }
    function busy(value) {
        state.busy = value;
        el('deletionRequestsModal').querySelectorAll('button, input, textarea, select').forEach(control => {
            if (value) { control.dataset.wasDisabled = String(control.disabled); control.disabled = true; }
            else if ('wasDisabled' in control.dataset) { control.disabled = control.dataset.wasDisabled === 'true'; delete control.dataset.wasDisabled; }
        });
    }
    async function api(data = null, query = {}) {
        const key = identity();
        const response = await window.apiClient.request(`api/deletion-requests.php${data ? '' : `?${new URLSearchParams({ scope: state.scope, ...query })}`}`,
            data ? { method: 'POST', headers: { Accept: 'application/json' }, body: JSON.stringify(data) } : { headers: { Accept: 'application/json' } });
        if (key !== identity()) throw new Error('The signed-in account changed. Reopen deletion requests.');
        return response;
    }
    function count(value) {
        el('deletionRequestsBadge').textContent = String(value);
        el('deletionRequestsBadge').hidden = !value;
    }
    function scopeCount(scope, value) {
        if (scope === workspaceScope()) count(value);
    }
    async function refreshCounts() {
        const scopes = [workspaceScope()];
        await Promise.all(scopes.map(async scope => {
            try { scopeCount(scope, (await api(null, { scope, status: 'pending' })).pendingCount); } catch (_) { /* Shared auth handling remains active. */ }
        }));
    }
    window.resetDeletionRequests = () => {
        clearInterval(state.timer); clearInterval(state.poll); state.listView = "active"; state.listFingerprint = null;
        state.epoch++; state.read++; state.target = null; state.detail = null; state.token = null;
        state.scope = workspaceScope(); busy(false); count(0);
        el('deletionRequestList').replaceChildren(); el('deletionRequestDetails').replaceChildren();
        el('deletionRequestReason').value = ''; el('deletionRequestDecisionNote').value = ''; el('deletionRequestEditedReason').value = '';
        el('deletionDirectReason').value = '';
        el('deletionRequestConfirmed').checked = false;
    };
    window.startDeletionRequestUpdates = () => {
        const refresh = async () => {
            if (!window.currentUser || window.currentUser.role === 'superadmin' || document.hidden) return;
            await refreshCounts();
        };
        void refresh(); clearInterval(state.timer); state.timer = setInterval(refresh, 30000);
    };
    function updateListTabs() {
        for (const name of ['active', 'history']) {
            const button = el(name === 'active' ? 'deletionRequestActiveTab' : 'deletionRequestHistoryTab');
            button.classList.toggle('is-active', state.listView === name);
            button.setAttribute('aria-pressed', String(state.listView === name));
        }
        el('deletionRequestFilterControl').hidden = state.listView !== 'history';
        el('deletionRequestClearHistory').hidden = state.listView !== 'history';
        el('deletionRequestFilter').querySelector('[value="approved"]').hidden = state.scope === 'review';
        el('deletionRequestListDescription').textContent = state.listView === 'history'
            ? 'Reviewed and closed requests, kept for your reference.'
            : state.scope === 'review' ? 'Review requests and finish approved deletions.'
            : 'Your requests awaiting review. Approved and closed requests move to History.';
    }
    window.openDeletionRequests = async (scope = workspaceScope(), listView = 'active') => {
        if (state.busy || !window.currentUser || window.currentUser.role === 'superadmin' || (scope === 'review' && !isAdmin())) return;
        state.scope = scope; state.listView = listView; state.target = null; state.detail = null; state.token = null; state.listFingerprint = null;
        el('deletionRequestFilter').value = '';
        view('List', scope === 'review' ? 'Deletion approvals' : 'My deletion requests'); updateListTabs(); modal();
        await window.loadDeletionRequests(1);
    };
    window.returnToDeletionRequests = () => window.openDeletionRequests(state.scope, state.listView);
    window.setDeletionRequestView = async listView => {
        if (state.busy) return;
        state.listView = listView; state.listFingerprint = null; el('deletionRequestFilter').value = ''; updateListTabs();
        await window.loadDeletionRequests(1);
    };
    async function finishToList(notice) {
        busy(false); await window.openDeletionRequests(state.scope, 'active'); message('deletionRequestNotice', notice);
    }
    window.loadDeletionRequests = async (page = state.page, silent = false) => {
        if (state.busy) return;
        const read = ++state.read;
        if (!silent) { message('deletionRequestError'); state.listFingerprint = null; el('deletionRequestList').textContent = 'Loading requests…'; }
        try {
            const result = await api(null, { page, view: state.listView, status: el('deletionRequestFilter').value });
            if (read !== state.read) return;
            if (result.page > result.lastPage) return window.loadDeletionRequests(result.lastPage, silent);
            state.page = result.page; state.lastPage = result.lastPage; scopeCount(state.scope, result.pendingCount);
            const fingerprint = JSON.stringify([state.scope, state.listView, result.page, result.total, result.requests]);
            if (fingerprint === state.listFingerprint) return;
            state.listFingerprint = fingerprint;
            el('deletionRequestList').innerHTML = result.requests.length ? result.requests.map(item => `
                <article class="deletion-request-card" data-request-id="${item.id}">
                    <div class="request-card-top"><div><span class="request-card-reference">Request #${item.id}${item.projectNo && !item.bulk ? ` · Project ${escape(item.projectNo)}` : ''}</span><h3 class="request-card-title">${escape(item.bulk ? label(item) : item.title)}</h3></div><div class="request-card-status">${badge(item)}</div></div>
                    <p class="request-card-reason">${escape(item.reason)}</p>
                    <div class="request-card-footer"><span class="request-card-meta">${state.scope === 'review' ? `${escape(person(item.requester))}<br>` : ''}${escape(date(item.requestedAt))}</span><button type="button" class="btn btn-outline-secondary btn-sm" onclick="viewDeletionRequest(${item.id})">${isAdmin() && item.status === 'pending' ? 'Review request' : 'View request'}</button></div>
                </article>`).join('') : `<div class="request-empty"><strong>${state.listView === 'history' ? 'No request history yet' : 'No active requests'}</strong>${state.listView === 'history' ? 'Reviewed and closed requests will appear here.' : 'You’re all caught up.'}</div>`;
            el('deletionRequestPage').textContent = result.lastPage > 1 ? `Page ${result.page} of ${result.lastPage}` : `${result.total} request${result.total === 1 ? '' : 's'}`;
            for (const id of ['deletionRequestPrevious', 'deletionRequestNext']) el(id).hidden = result.lastPage <= 1;
            el('deletionRequestPrevious').disabled = result.page <= 1; el('deletionRequestNext').disabled = result.page >= result.lastPage;
            el('deletionRequestPage').closest('.request-pagination').hidden = result.total === 0;
        } catch (failure) { if (read === state.read && !silent) { el('deletionRequestList').replaceChildren(); error(failure); } }
    };
    window.changeDeletionRequestPage = direction => window.loadDeletionRequests(Math.max(1, Math.min(state.lastPage, state.page + direction)));
    window.clearDeletionRequestHistory = async () => {
        if (state.busy || state.listView !== 'history') return;
        if (!window.confirm('Clear all deletion request history from your view, including every outcome and page? Active requests and other users’ history will stay available.')) return;
        state.read++;
        busy(true);
        try {
            const result = await api({ action: 'clearHistory', scope: state.scope, confirmed: true });
            busy(false);
            state.listFingerprint = null;
            await window.loadDeletionRequests(1);
            message('deletionRequestNotice', result.message);
        } catch (failure) { error(failure); } finally { busy(false); }
    };
    function lookupQuery(target) {
        return { scope: workspaceScope(), lookup: '1', targetType: target.targetType, all: target.all ? '1' : '0', ...(target.all ? {} : { targetId: target.id }) };
    }
    window.openDeletionRequest = async target => {
        if (state.busy || !window.currentUser) return;
        if (!window.currentUser[target.all ? 'canDeleteAll' : 'canDelete']) { showSystemNotification('You do not have permission to request this deletion.'); return; }
        state.scope = workspaceScope(); state.listView = 'active'; state.target = { ...target }; state.detail = null; state.token = null; state.read++;
        view(isAdmin() ? 'Direct' : 'Create', isAdmin() ? 'Review deletion' : 'Request deletion');
        if (isAdmin()) {
            el('deletionDirectTitle').textContent = 'Loading selected records…';
            el('deletionDirectScope').textContent = ''; el('deletionDirectTargets').replaceChildren(); el('deletionDirectForm').hidden = true;
        }
        el('deletionRequestBlocked').hidden = true; el('deletionRequestCreateForm').hidden = true;
        el('deletionRequestScope').textContent = target.all
            ? 'All current projects, including archives. Their history and attachments are included.'
            : `Request deletion of ${target.targetType === 'project' ? 'project' : 'history record'} #${target.id}${target.targetType === 'project' ? ', with its history and attachments' : ''}.`;
        el('deletionRequestReason').value = ''; modal(); busy(true);
        try {
            const result = await api(null, lookupQuery(target));
            if (result.request) {
                state.target = null; renderDetail(result.request);
                message('deletionRequestNotice', isAdmin() ? 'Review this pending request and continue to final confirmation.' : 'Your pending deletion request is shown below. You can edit its reason or cancel it.');
            } else if (result.blocked) {
                view('Create', isAdmin() ? 'Review pending requests' : 'Request deletion');
                el('deletionRequestBlockedMessage').textContent = isAdmin()
                    ? 'Some selected records have pending deletion requests. Review them in Workspace → Deletion requests.'
                    : 'A pending request already covers these records. You can only view and change your own requests. An Admin can review it from Workspace → Deletion requests.';
                el('deletionRequestBlocked').hidden = false;
            } else if (isAdmin()) {
                const preview = await api({ action: 'previewDirect', ...state.target });
                el('deletionDirectReason').value = ''; renderDirect(preview.selection);
            } else el('deletionRequestCreateForm').hidden = false;
        } catch (failure) { error(failure); } finally { busy(false); }
    };
    window.submitDeletionRequest = async event => {
        event.preventDefault(); if (state.busy || !state.target || isAdmin()) return;
        const reason = el('deletionRequestReason').value.trim();
        if (!reason) { error(new Error('Enter a reason for deletion.')); el('deletionRequestReason').focus(); return; }
        busy(true);
        try {
            const response = await api({ action: 'create', ...state.target, reason });
            busy(false); state.target = null; await window.viewDeletionRequest(response.requestId);
            message('deletionRequestNotice', 'Request submitted. The records remain intact while awaiting admin review and final confirmation.');
            await refreshCounts();
        } catch (failure) {
            // Another tab may have submitted the same request after the initial lookup.
            try {
                const current = state.target ? await api(null, lookupQuery(state.target)) : null;
                if (current?.request) { state.target = null; renderDetail(current.request); message('deletionRequestNotice', 'Your existing pending request is shown below.'); }
                else error(failure);
            } catch (_) { error(failure); }
        } finally { busy(false); }
    };
    function renderDirect(item) {
        state.detail = item; state.token = null; view('Direct', 'Review deletion');
        el('deletionDirectForm').hidden = false;
        el('deletionDirectTitle').textContent = item.bulk ? `Delete ${label(item)}?` : `Delete ${item.title}?`;
        el('deletionDirectScope').textContent = item.targetType === 'project'
            ? 'The selected projects, their history and attachments will be permanently removed after final confirmation.'
            : 'The selected history records will be permanently removed after final confirmation. The projects will be kept.';
        el('deletionDirectTargets').innerHTML = item.targets.map(target => `<li><strong>${escape(target.record.title || target.record.action || 'History record')}</strong> · #${target.id}${target.record.projectNo ? ` · Project ${escape(target.record.projectNo)}` : ''}</li>`).join('')
            + (item.targetCount > item.targets.length ? `<li>And ${item.targetCount - item.targets.length} more records.</li>` : '');
    }
    window.continueDirectDeletion = async event => {
        event.preventDefault(); if (state.busy || !isAdmin() || !state.target || !state.detail?.direct) return;
        busy(true);
        try {
            const result = await api({ action: 'prepareDirect', ...state.target, expectedVersion: state.detail.version, reason: el('deletionDirectReason').value.trim() });
            finalConfirmation(result.confirmationToken);
        } catch (failure) { error(failure); } finally { busy(false); }
    };
    async function discardDirectConfirmation() {
        if (!state.detail?.direct || !state.token) return;
        const token = state.token; state.token = null;
        try { await api({ action: 'cancelDirect', confirmationToken: token }); } catch (_) { /* Tokens also expire after ten minutes. */ }
    }
    function renderDetail(item) {
        state.detail = item; state.token = null;
        view('Detail', `Request #${item.id}`);
        el('deletionRequestDetails').innerHTML = `
            <div>${badge(item)}</div><h2 class="request-heading">${escape(item.bulk ? label(item) : item.title)}</h2>
            <dl class="request-detail-meta"><div><dt>Requested by</dt><dd>${escape(person(item.requester))}</dd></div><div><dt>Submitted</dt><dd>${escape(date(item.requestedAt))}</dd></div></dl>
            <div class="request-reason-panel"><h3>Reason for deletion</h3><p>${escape(item.reason)}</p></div>
            ${item.reviewer ? `<p class="request-event">${item.stage === 'rejected' ? 'Rejected' : 'Reviewed'} by ${escape(person(item.reviewer))} · ${escape(date(item.reviewedAt))}</p>` : ''}
            ${item.decisionNote ? `<div class="request-reason-panel"><h3>Decision note</h3><p>${escape(item.decisionNote)}</p></div>` : ''}
            ${item.reasonUpdatedAt ? `<p class="request-event">Reason updated · ${escape(date(item.reasonUpdatedAt))}</p>` : ''}
            ${item.cancelledAt ? `<p class="request-event">Cancelled · ${escape(date(item.cancelledAt))}. Records were kept.</p>` : ''}
            ${item.deletedBy ? `<p class="request-event">Deletion confirmed by ${escape(person(item.deletedBy))} · ${escape(date(item.deletedAt))}</p>` : ''}
            <details class="request-targets"><summary>Included records (${item.targetCount})</summary><ul>${item.targets.map(target => `<li><strong>${escape(target.record.title || target.record.action || 'History record')}</strong> · #${target.id}${target.record.projectNo ? `<br>Project ${escape(target.record.projectNo)}` : ''}</li>`).join('')}</ul></details>`;
        el('deletionRequestOwnerControls').hidden = !item.isOwner || item.status !== 'pending';
        el('deletionRequestEditForm').hidden = true; el('deletionRequestEditedReason').value = item.reason;
        el('deletionRequestReviewControls').hidden = !isAdmin() || item.status !== 'pending';
        el('deletionRequestDecisionNote').value = '';
    }
    window.viewDeletionRequest = async id => {
        if (state.busy) return;
        const read = ++state.read;
        // A background list refresh must not supersede navigation to a request.
        busy(true);
        try { const result = await api(null, { id }); if (read === state.read) renderDetail(result.request); }
        catch (failure) { if (read === state.read) error(failure); }
        finally { busy(false); }
    };
    window.refreshDeletionRequest = () => { if (state.detail) void window.viewDeletionRequest(state.detail.id); };
    window.editDeletionReason = () => {
        if (state.busy || !state.detail?.isOwner || state.detail.status !== 'pending') return;
        el('deletionRequestEditForm').hidden = false;
        el('deletionRequestEditedReason').value = state.detail.reason; el('deletionRequestEditedReason').focus();
    };
    window.saveDeletionReason = async event => {
        event.preventDefault(); if (state.busy || !state.detail?.isOwner) return;
        const reason = el('deletionRequestEditedReason').value.trim();
        if (!reason) { error(new Error('Enter a reason for deletion.')); return; }
        busy(true);
        try {
            await api({ action: 'editReason', id: state.detail.id, expectedVersion: state.detail.version, reason });
            const latest = await api(null, { id: state.detail.id }); renderDetail(latest.request);
            message('deletionRequestNotice', 'Reason updated. The request is awaiting Admin review.'); await refreshCounts();
        } catch (failure) { error(failure); } finally { busy(false); }
    };
    window.cancelOwnDeletionRequest = async () => {
        if (state.busy || !state.detail?.isOwner || state.detail.status !== 'pending') return;
        if (!window.confirm('Cancel this deletion request? The records, history and attachments will be kept.')) return;
        busy(true);
        try {
            await api({ action: 'cancel', id: state.detail.id, expectedVersion: state.detail.version });
            await finishToList('Request cancelled. Records were kept.'); await refreshCounts();
        } catch (failure) { error(failure); } finally { busy(false); }
    };
    window.continueDeletionReview = () => {
        if (state.busy || !isAdmin() || !state.detail) return;
        if (state.detail.stage === 'approved') return window.prepareDeletionConfirmation();
        return window.reviewDeletionRequest('approve');
    };
    function finalConfirmation(token) {
        state.token = token; view('Confirm', 'Final confirmation');
        el('deletionRequestConfirmScope').textContent = `Delete ${state.detail.bulk ? label(state.detail) : state.detail.title}?`;
        el('deletionRequestConfirmDescription').textContent = state.detail.targetType === 'project'
            ? 'This permanently removes the selected projects, their history and attachments. Projects added after your review are excluded.'
            : 'This permanently removes the selected history records. The projects themselves will be kept.';
        el('deletionRequestConfirmAcknowledgement').textContent = state.detail.direct
            ? 'I have reviewed the selected records and confirm permanent deletion.'
            : 'I have reviewed the request and confirm permanent deletion.';
        el('deletionRequestConfirmed').checked = false;
    }
    window.reviewDeletionRequest = async decision => {
        if (state.busy || !isAdmin() || !state.detail) return;
        busy(true);
        try {
            const result = await api({ action: 'review', id: state.detail.id, decision, expectedVersion: state.detail.version, note: el('deletionRequestDecisionNote').value.trim() });
            if (decision === 'approve') {
                const latest = await api(null, { id: state.detail.id }); state.detail = latest.request;
                if (state.detail.stage === 'approved') finalConfirmation(result.confirmationToken);
                else { renderDetail(state.detail); message('deletionRequestNotice', 'The request changed and needs a fresh review.'); }
            }
            else await finishToList('Request rejected. The project was kept.');
            await refreshCounts();
        } catch (failure) { error(failure); } finally { busy(false); }
    };
    window.prepareDeletionConfirmation = async () => {
        if (state.busy || !isAdmin() || !state.detail) return;
        busy(true);
        try { const result = await api({ action: 'prepareConfirmation', id: state.detail.id }); finalConfirmation(result.confirmationToken); }
        catch (failure) { error(failure); } finally { busy(false); }
    };
    window.cancelDeletionConfirmation = async () => {
        if (state.busy || !state.detail) return;
        if (state.detail.direct) {
            busy(true); await discardDirectConfirmation(); renderDirect(state.detail); busy(false);
            message('deletionRequestNotice', 'No records were deleted. Review the records before continuing.'); return;
        }
        state.token = null; renderDetail(state.detail);
        message('deletionRequestNotice', 'No records were deleted. You can return to final confirmation later.');
    };
    window.confirmDeletionRequest = async event => {
        event.preventDefault();
        if (state.busy || !isAdmin() || !state.detail || !state.token || !el('deletionRequestConfirmed').checked) return;
        busy(true);
        try {
            const direct = state.detail.direct;
            await api({ action: direct ? 'confirmDirect' : 'confirm', ...(direct ? {} : { id: state.detail.id }), confirmationToken: state.token, confirmed: true });
            state.token = null;
            if (direct) {
                busy(false); bootstrap.Modal.getOrCreateInstance(el('deletionRequestsModal')).hide();
                showSystemNotification('Deletion confirmed. The selected records were deleted.');
            } else await finishToList('Deletion confirmed. The approved records were deleted.');
            await refreshCounts();
            if (window.db) await window.db.refresh();
            if (typeof loadDocuments === 'function') loadDocuments(true);
            if (typeof loadAuditLog === 'function') loadAuditLog();
        } catch (failure) { error(failure); } finally { busy(false); }
    };
    document.addEventListener('DOMContentLoaded', () => {
        el('deletionRequestsModal').addEventListener('shown.bs.modal', () => {
            clearInterval(state.poll);
            state.poll = setInterval(() => {
                if (!document.hidden && !state.busy && !el('deletionRequestListView').hidden) void window.loadDeletionRequests(state.page, true);
            }, 5000);
        });
        el('deletionRequestsModal').addEventListener('hidden.bs.modal', () => { void discardDirectConfirmation(); state.token = null; state.read++; clearInterval(state.poll); });
    });
})();
