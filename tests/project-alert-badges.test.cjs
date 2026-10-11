const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const clock = new Date('2026-10-11T04:00:00Z').getTime();
class FixedDate extends Date {
    constructor(...args) { super(...(args.length ? args : [clock])); }
    static now() { return clock; }
}
const table = { innerHTML: '' };
const context = vm.createContext({
    console, Date: FixedDate, Intl, URLSearchParams, Map, Set,
    setTimeout, clearTimeout, setInterval: () => 1, clearInterval() {},
    document: {
        addEventListener() {},
        getElementById: id => id === 'documentTable' ? table : null,
        querySelectorAll: () => []
    },
    localStorage: { getItem: () => null }, alert() {}
});
context.window = context;
context.addEventListener = () => {};
for (const name of ['state', 'utils', 'projects', 'timeline']) {
    vm.runInContext(fs.readFileSync(path.join(root, 'public/js', name + '.js'), 'utf8'), context);
}
context.canDeleteProjects = () => false;
context.canArchiveProjects = () => false;
context.renderPaginationControls = () => {};

function render(project) {
    context.testProject = project;
    vm.runInContext('currentProjectsList = [testProject]; currentPage = 1; renderTablePage();', context);
    return table.innerHTML;
}

const project = {
    id: 51, projectNo: '51', title: 'Pilot regression example', winningBidder: 'Sample contractor',
    biddingDate: '2025-09-23', noaDueDate: '2025-10-08', noa: '2025-10-06',
    contractEndDate: '2026-01-27', status: 'Final-inspection'
};
const resolvedNoaRow = render(project);
assert.doesNotMatch(resolvedNoaRow, /NOA Window|NOA Overdue|NOA Due/);
for (const label of ['Pre-inspection Overdue', 'Notice to Proceed Overdue', 'Material Testing Overdue', 'Final Inspection Overdue']) {
    assert.ok(resolvedNoaRow.includes(label), 'Missing actual outstanding reminder: ' + label);
}
assert.match(resolvedNoaRow, /table-danger/);
assert.match(resolvedNoaRow, /Final-inspection/);

const missingNoaRow = render({ ...project, noa: '', noaDueDate: '' });
assert.doesNotMatch(missingNoaRow, /NOA Window/);
assert.match(missingNoaRow, /Final Inspection Overdue/);

const futureNoaRow = render({ ...project, noa: '2026-11-02', noaDueDate: '' });
assert.doesNotMatch(futureNoaRow, /NOA Window/);

const upcomingNoaRow = render({ ...project, noa: '', noaDueDate: '2026-10-15' });
assert.match(upcomingNoaRow, /NOA Due/);
assert.doesNotMatch(upcomingNoaRow, /NOA Window/);

const overdueNoaRow = render({ ...project, noa: '' });
assert.match(overdueNoaRow, /NOA Overdue/);
assert.doesNotMatch(overdueNoaRow, /NOA Window/);

const dueInspectionRow = render({ id: 52, biddingDate: '2026-09-13', status: 'Pre-inspection' });
assert.match(dueInspectionRow, /table-warning/);
assert.match(dueInspectionRow, /Pre-inspection Due/);
assert.doesNotMatch(dueInspectionRow, /NOA Window/);

const quietRow = render({ id: 53, status: 'On-going Bidding' });
assert.match(quietRow, /No active alerts/);
assert.doesNotMatch(quietRow, /NOA Window/);

if (process.env.PROJECT_ALERT_QA_HTML) {
    const css = fs.readFileSync(path.join(root, 'public/css/styles.css'), 'utf8');
    fs.writeFileSync(process.env.PROJECT_ALERT_QA_HTML, `<!doctype html><html lang="en"><meta charset="utf-8"><title>Project alert badge verification</title><style>${css}</style><style>body{margin:0;padding:32px;background:#f5f7fb;color:#24344b;font-family:Arial,sans-serif}h1{font-size:24px}p{color:#52637c}table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #dde4ef}th,td{padding:18px;text-align:left;vertical-align:middle}th{font-size:12px;background:#f0f4fa}.table-danger{background:#fff7f7}.table-warning{background:#fff9ed}.project-title-button{max-width:260px}.project-alert-chip-list{max-width:330px}</style><h1>Project alerts after the fix</h1><p>Test preview: NOA is recorded. The four outstanding milestone reminders remain visible.</p><table><thead><tr><th>No.</th><th>Project</th><th>Bidding</th><th>NOA due</th><th>Milestones and alerts</th><th>Completion</th><th>Status</th><th>Actions</th></tr></thead><tbody>${resolvedNoaRow}</tbody></table></html>`);
}
console.log('Project alert badges passed: resolved/missing/future NOA, genuine NOA reminders, inspection row colors and empty alerts.');
