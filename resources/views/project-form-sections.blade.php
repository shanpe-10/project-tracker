@foreach (\App\Support\TrackerProjectFields::sections() as $section)
<section class="form-section-card tab-pane{{ $loop->first ? ' active' : '' }}" id="project-panel-{{ $section['id'] }}">
    <div class="form-section-header"><div class="form-section-number">{{ sprintf('%02d', $loop->iteration) }}</div><h2 class="form-section-title">{{ $section['label'] }}</h2></div>
    <div class="form-section-body">
    @foreach ([false, true] as $legacy)
        @php($fields = array_filter(\App\Support\TrackerProjectFields::fields(), fn($f) => $f['section'] === $section['id'] && (bool)($f['legacy'] ?? false) === $legacy && empty($f['hidden'])))
        @if ($legacy && count($fields))<details class="mt-4"><summary class="mb-3">Additional details</summary>@endif
        <div class="row g-3">
        @foreach ($fields as $field)
            <div class="{{ $field['type'] === 'textarea' ? 'col-12' : 'col-md-6 col-xl-4' }}">
                <label for="{{ $field['id'] }}" class="form-label">{{ $field['label'] }} @if(!empty($field['required']))<span class="text-danger">*</span>@endif</label>
                @if ($field['type'] === 'textarea')
                <textarea id="{{ $field['id'] }}" class="form-control" rows="2" maxlength="{{ $field['max'] }}"></textarea>
                @elseif ($field['type'] === 'fund')
                <select id="sourceOfFund" class="form-select" onchange="document.getElementById('sourceOfFundCustomRow').classList.toggle('d-none', this.value !== 'Others')"><option value="">Select fund source</option><option>Brgy Fund</option><option>City Fund</option><option>Others</option></select>
                <div id="sourceOfFundCustomRow" class="d-none mt-2"><label for="sourceOfFundCustom" class="form-label">Other source</label><input id="sourceOfFundCustom" class="form-control" maxlength="160"></div>
                @elseif ($field['type'] === 'mode')
                <select id="statusMode" class="form-select"><option value="automatic">Automatic</option><option value="manual">Manual</option></select>
                <div class="form-text">Manual status is retained until you return to Automatic.</div>
                @elseif ($field['type'] === 'status')
                <select id="status" class="form-select" disabled>@foreach(\App\Support\TrackerProjectRevision::STATUSES as $status)<option>{{ $status }}</option>@endforeach</select>
                @else
                <input id="{{ $field['id'] }}" class="form-control" type="{{ $field['type'] === 'date' ? 'date' : 'text' }}" maxlength="{{ $field['max'] }}" @if(in_array($field['type'], ['money','number','percentage'])) inputmode="decimal" @elseif($field['type'] === 'digits') inputmode="numeric" @endif @if(!empty($field['readonly'])) readonly aria-readonly="true" @endif>
                @endif
                @if($field['key'] === 'contractEndDate')<div class="form-text">Calculated from Contract Agreement and Contract Duration using the shared working-day calendar.</div>@endif
                @if($field['key'] === 'finalInspection')<div id="finalInspectionHelp" class="form-text">Final inspection date.</div>@endif
                @if($field['key'] === 'completion')<div id="completionDateHelp" class="form-text">Actual completion date.</div><div id="completionDateHint" class="form-text d-none"></div>@endif
                @if($field['key'] === 'physicalAccomplishment')<div id="physicalAccomplishmentDisplay" class="form-text">Enter 0–100%. Zero displays as NYS; blank remains unspecified.</div>@endif
                @if($field['key'] === 'ldAmount')<div class="form-text">Enter an amount or text. N/A marks this entire section as not applicable.</div>@endif
            </div>
        @endforeach
        </div>
        @if ($legacy && count($fields))</details>@endif
    @endforeach
    @if($section['id'] === 'contract')
    <div class="row g-3 mt-3 project-due-dates">
        @foreach(['preInspectionDueDate'=>'Pre-Inspection Due Date','ntpDueDate'=>'Notice to Proceed Due Date','materialTestingDueDate'=>'Material Testing Due Date','finalInspectionDueDate'=>'Final Inspection Due Date','contractEndReminderDate'=>'Contract End Follow-up Date'] as $id=>$label)
        <div class="col-md-6 col-xl-4"><label for="{{ $id }}" class="form-label">{{ $label }}</label><input type="date" id="{{ $id }}" class="form-control" readonly aria-readonly="true"></div>
        @endforeach
    </div><div class="form-text mt-2">Contract end follow-up defaults to 7 working days after Contract End Date. Administrators can change this in timeline settings.</div>
    @endif
    @if($section['id'] === 'damages')<div id="ldNotApplicableHint" class="alert alert-light border mt-3 d-none">Liquidated Damages: N/A</div>@endif
    @if($section['id'] === 'completion')
    <input type="hidden" id="manualStatus">
    <div class="mt-3"><label for="swaDueDate" class="form-label">SWA Due Date</label><input type="date" id="swaDueDate" class="form-control" readonly></div>
    <details class="mt-4"><summary class="mb-3">Project attachments</summary>
        <div id="projectAttachmentsNewNotice" class="alert alert-light border">Save the project first, then edit it to attach files.</div>
        <div id="projectAttachmentsEditor" class="d-none"><label for="projectAttachmentFiles" class="form-label">Choose files</label><input id="projectAttachmentFiles" class="form-control" type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,image/jpeg,image/png,image/gif,image/webp" onchange="uploadProjectAttachments()"><div class="form-text mb-3">Up to 25 MB each. Save Changes to apply additions and removals.</div><div id="projectAttachmentsList" class="list-group" aria-live="polite"></div></div>
    </details>
    @endif
    </div>
</section>
@endforeach
