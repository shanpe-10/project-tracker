const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/js/projects.js'), 'utf8');
const start = source.indexOf('function printProjectDetails() {');
const end = source.indexOf('\nfunction renderPaginationControls()', start);
assert.ok(start >= 0 && end > start);

let written = '';
let printed = 0;
const logoHandlers = {};
const logo = { complete: false, addEventListener(event, handler) { logoHandlers[event] = handler; } };
const timers = [];
const frameHandlers = {};
const printWindow = {
    document: { write(html) { written = html; }, close() {}, getElementById() { return logo; } },
    addEventListener(event, handler) { frameHandlers[event] = handler; },
    focus() {},
    print() { printed++; }
};
const frame = {
    contentWindow: printWindow,
    isConnected: false,
    style: {},
    setAttribute() {},
    remove() { this.isConnected = false; }
};
const details = {
    innerHTML: '<div class="project-details-summary"><h3>Road improvement</h3></div><section class="project-detail-section"><h4>Basic Information</h4><div>Budget: 500</div></section>',
    cloneNode() { return { innerHTML: this.innerHTML, querySelectorAll() { return []; } }; }
};
const context = vm.createContext({
    Date,
    URL,
    setTimeout(handler, delay) { timers.push({ handler, delay }); },
    document: {
        baseURI: 'file:///D:/LGU LAOAG CITY/project-tracker/public/',
        body: { appendChild(node) { node.isConnected = true; } },
        createElement() { return frame; },
        getElementById(id) { return id === 'projectDetailsBody' ? details : id === 'projectPrintFrame' ? null : { textContent: 'Road improvement' }; },
        createTextNode(value) { return { textContent: value }; }
    },
    window: { open() { throw new Error('Unexpected popup'); } },
    escapeHtml: value => String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;'),
    showSystemNotification() { throw new Error('Unexpected notification'); }
});
vm.runInContext(source.slice(start, end), context);
vm.runInContext('printProjectDetails()', context);
assert.match(written, /Reference copy — Not an official document/);
assert.match(written, /LGU Laoag - Office of Engineering/);
assert.match(written, /logo\.png/);
assert.match(written, /Road improvement/);
assert.match(written, /Budget: 500/);
assert.doesNotMatch(written, /print-toolbar/);
if (process.env.PRINT_QA_HTML) fs.writeFileSync(process.env.PRINT_QA_HTML, written);
assert.equal(printed, 0);
assert.equal(frame.isConnected, true);
logoHandlers.load();
timers.find(timer => timer.delay === 150).handler();
assert.equal(printed, 1);
timers.find(timer => timer.delay === 2000).handler();
assert.equal(printed, 1);
frameHandlers.afterprint();
timers.find(timer => timer.delay === 500).handler();
assert.equal(frame.isConnected, false);
console.log('Project reference print view passed.');
