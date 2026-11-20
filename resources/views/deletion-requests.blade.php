<div class="modal fade" id="deletionRequestsModal" tabindex="-1" aria-labelledby="deletionRequestsTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content deletion-dialog">
        <div class="modal-header"><div><span class="request-eyebrow">Project records</span><h5 class="modal-title" id="deletionRequestsTitle">Deletion requests</h5></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <div id="deletionRequestError" class="request-message is-error" role="alert" hidden></div><div id="deletionRequestNotice" class="request-message is-success" role="status" hidden></div>
            <section id="deletionRequestListView">
                <p id="deletionRequestListDescription" class="request-description">Your requests awaiting review.</p>
                <div class="request-toolbar">
                    <div class="request-tabs" aria-label="Request view"><button id="deletionRequestActiveTab" type="button" class="is-active" aria-pressed="true" onclick="setDeletionRequestView('active')">Active</button><button id="deletionRequestHistoryTab" type="button" aria-pressed="false" onclick="setDeletionRequestView('history')">History</button></div>
                    <div class="request-toolbar-actions">
                        <label id="deletionRequestFilterControl" hidden>
                            <span class="visually-hidden">Request outcome</span>
                            <select id="deletionRequestFilter" class="form-select form-select-sm" onchange="loadDeletionRequests(1)">
                                <option value="">All outcomes</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                                <option value="deleted">Deleted</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </label>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="loadDeletionRequests()">Refresh</button>
                    </div>
                </div>
                <div id="deletionRequestList" class="request-list" aria-live="polite"></div>
                <div class="request-pagination"><button id="deletionRequestPrevious" type="button" class="btn btn-outline-secondary btn-sm" onclick="changeDeletionRequestPage(-1)">Previous</button><span id="deletionRequestPage"></span><button id="deletionRequestNext" type="button" class="btn btn-outline-secondary btn-sm" onclick="changeDeletionRequestPage(1)">Next</button></div>
            </section>
            <section id="deletionRequestCreateView" hidden>
                <span class="request-status is-pending">Approval required</span><h2 class="request-heading">Request a deletion</h2><p id="deletionRequestScope" class="request-description"></p>
                <div id="deletionRequestBlocked" class="request-message" hidden><p id="deletionRequestBlockedMessage"></p><button type="button" class="btn btn-outline-secondary btn-sm" onclick="openDeletionRequests()">Deletion requests</button></div>
                <form id="deletionRequestCreateForm" onsubmit="submitDeletionRequest(event)"><label for="deletionRequestReason" class="form-label">Reason for deletion</label><textarea id="deletionRequestReason" class="form-control" rows="4" maxlength="2000" placeholder="Explain why this record should be deleted." required></textarea><p class="request-help">The record stays intact while your request is reviewed.</p><div class="request-actions"><button type="submit" class="btn btn-danger">Submit request</button><button type="button" class="btn btn-outline-secondary" onclick="openDeletionRequests()">Cancel</button></div></form>
            </section>
            <section id="deletionRequestDirectView" hidden>
                <span class="request-status is-pending">Admin deletion</span><h2 id="deletionDirectTitle" class="request-heading">Review deletion</h2>
                <p id="deletionDirectScope" class="request-description"></p>
                <details class="request-targets" open><summary>Selected records</summary><ul id="deletionDirectTargets"></ul></details>
                <form id="deletionDirectForm" onsubmit="continueDirectDeletion(event)">
                    <label for="deletionDirectReason" class="form-label">Deletion note <span class="text-muted fw-normal">(optional)</span></label><textarea id="deletionDirectReason" class="form-control" rows="2" maxlength="2000" placeholder="Add a note for the activity log."></textarea>
                    <p class="request-help">Review the records, then confirm with your Admin password on the next screen. Nothing is deleted yet.</p>
                    <div class="request-actions"><button id="deletionDirectContinue" type="submit" class="btn btn-danger">Continue to final confirmation</button><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button></div>
                </form>
            </section>
            <section id="deletionRequestDetailView" hidden>
                <div class="request-detail-navigation"><button type="button" class="btn btn-outline-secondary btn-sm" onclick="returnToDeletionRequests()">Back to requests</button><button type="button" class="btn btn-link btn-sm" onclick="refreshDeletionRequest()">Refresh</button></div>
                <div id="deletionRequestDetails"></div>
                <div id="deletionRequestOwnerControls" class="request-owner-controls" hidden>
                    <div class="request-actions"><button type="button" class="btn btn-outline-secondary" onclick="editDeletionReason()">Edit reason</button><button type="button" class="btn btn-outline-danger" onclick="cancelOwnDeletionRequest()">Cancel request</button></div>
                    <form id="deletionRequestEditForm" class="request-edit-form" onsubmit="saveDeletionReason(event)" hidden><label for="deletionRequestEditedReason" class="form-label">Reason for deletion</label><textarea id="deletionRequestEditedReason" class="form-control" rows="4" maxlength="2000" required></textarea><p class="request-help">Changing an approved reason requires a fresh review.</p><div class="request-actions"><button type="submit" class="btn btn-danger">Save reason</button><button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('deletionRequestEditForm').hidden=true">Discard changes</button></div></form>
                </div>
                <div id="deletionRequestReviewControls" class="request-review-controls" hidden>
                    <label for="deletionRequestDecisionNote" class="form-label">Decision note <span class="text-muted fw-normal">(optional)</span></label><textarea id="deletionRequestDecisionNote" class="form-control" rows="2" maxlength="2000" placeholder="Add a note for the requester."></textarea><p class="request-help">Records remain intact until you confirm deletion on the next screen.</p>
                    <div class="request-actions"><button id="deletionRequestContinue" type="button" class="btn btn-danger" onclick="continueDeletionReview()">Continue to final confirmation</button><button type="button" class="btn btn-outline-secondary" onclick="reviewDeletionRequest('reject')">Reject request</button></div>
                </div>
            </section>
            <section id="deletionRequestConfirmView" class="request-confirm" hidden>
                <div class="request-confirm-icon" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6M14 10v6"/></svg></div><h2 class="request-heading">Confirm deletion</h2><p id="deletionRequestConfirmScope" class="request-confirm-scope"></p><p id="deletionRequestConfirmDescription" class="request-description"></p>
                <form onsubmit="confirmDeletionRequest(event)">
                    <label class="request-confirm-check" for="deletionRequestConfirmed"><input type="checkbox" id="deletionRequestConfirmed" class="form-check-input" required><span id="deletionRequestConfirmAcknowledgement">I have reviewed the request and confirm permanent deletion.</span></label>
                    <label for="deletionRequestAdminPassword" class="form-label">Your Admin password</label>
                    <input type="password" id="deletionRequestAdminPassword" class="form-control" autocomplete="current-password" maxlength="4096" aria-describedby="deletionRequestPasswordHelp" required>
                    <p id="deletionRequestPasswordHelp" class="request-help">Enter the password of the Admin account you are signed in with. Records are deleted only after verification.</p>
                    <div class="request-actions"><button type="submit" class="btn btn-danger">Verify password and delete</button><button type="button" class="btn btn-outline-secondary" onclick="cancelDeletionConfirmation()">Go back</button></div>
                </form>
            </section>
        </div>
    </div></div>
</div>
