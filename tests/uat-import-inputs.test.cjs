const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const context = vm.createContext({ Date, Uint8Array, ArrayBuffer, setTimeout, clearTimeout, console });
context.window = context;
vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/js/utils.js'), 'utf8'), context);
context.currentUser = { role: 'staff', canImport: true };
context.document = { getElementById() { return null; }, addEventListener() {} };
context.alert = () => {};
context.XLSX = {
    read() { return { SheetNames: ['Projects'], Sheets: { Projects: {} } }; },
    utils: { decode_range(){return {s:{c:0,r:0},e:{c:-1,r:0}};}, sheet_to_json() { return context.rows; } },
};
context.FileReader = class {
    readAsArrayBuffer() { this.onload({ target: { result: new ArrayBuffer(1) } }); }
};
for (const file of ['project-fields.js','project-revision.js']) vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/js',file),'utf8'),context);
const projectValidation = fs.readFileSync(path.join(__dirname, '../public/js/projects.js'),'utf8');
vm.runInContext(projectValidation.slice(projectValidation.indexOf('function validateProjectNumbersAndActualDates('),projectValidation.indexOf('function saveDocument(')),context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/js/excel.js'), 'utf8'), context);
vm.runInContext('showImportPreview = rows => { captured = { rows }; }', context);

const mapping = {
    'Project No.': '9901', 'Date of Bidding': '2026-09-01', 'Project Title': 'Road UAT',
    ABC: '1,250.50', 'Bid Amount': '1,100.00', 'Winning Bidder': 'Builder',
    'Source of Fund': 'City Fund', 'PR Number': 'PR-1', 'PR Date': '2026-09-02',
    OBRE: '2026-09-03', 'Resolution Number': 'RES-1', 'Resolution Date': '2026-09-04',
    'Notice of Post Qualification': '2026-09-05', 'Notice of Award': '2026-09-06',
    'Performance Bond': '2026-09-07', 'Contract Date': '2026-09-08',
    'Contract Duration': '60CD', 'Notice to Proceed': '2026-09-09',
    'DOLE Clearance': '2026-09-10', 'Contract End Date': '2026-11-08',
    'Pre Inspection': '2026-11-01', 'Final Inspection': '2026-11-02',
    SWA: '2026-11-03', 'LD Amount': '25', 'LD Days': '2',
    'Extended Completion Date': '2026-11-10', 'Actual Construction Duration': '65CD',
    'Completion Date': '2026-11-04', 'Operational Status': 'Completed',
    'Additional Remarks': 'Imported for UAT', 'Material Testing Date': '2026-09-30',
};
context.rows = [mapping];
vm.runInContext('handleExcelImport({ target: { files: [{}], value: "uat.csv" } })', context);
const payload = context.captured.rows[0];
const fields = {
    projectNo: '9901', biddingDate: '2026-09-01', title: 'Road UAT',
    approvedBudget: '1,250.50', bidAmount: '1,100.00', winningBidder: 'Builder',
    sourceOfFund: 'City Fund', prNumber: 'PR-1', prDate: '2026-09-02',
    obre: '2026-09-03', resolutionNumber: 'RES-1', resolutionDate: '2026-09-04',
    noticePostQual: '2026-09-05', noa: '2026-09-06', performanceBond: '2026-09-07',
    contractRef: '2026-09-08', contractDuration: '60CD', ntpDate: '2026-09-09',
    dole: '2026-09-10', contractEndDate: '2026-11-08', preInspection: '2026-11-01',
    finalInspection: '2026-11-02', swa: '2026-11-03', ldAmount: '25', ldNoOfDays: '2',
    ldNewCompletionDate: '2026-11-10', ldActualDuration: '65CD', completion: '2026-11-04',
    status: 'Completed', remarks: 'Imported for UAT', materialTestingDate: '2026-09-30',
};
for (const [field, expected] of Object.entries(fields)) assert.equal(payload[field], expected, field);

const validate = data => vm.runInContext('validateImportedProject(input, 2)', Object.assign(context, { input: data }));
assert.deepEqual(Array.from(validate({ title: 'Valid', biddingDate: '2026-09-01', approvedBudget: '1,250.50' })), []);
assert.match(validate({ title: 'Valid', biddingDate: '2026-02-30' })[0].message, /invalid date/i);
assert.match(validate({ title: 'Valid', approvedBudget: '-1' })[0].message, /invalid monetary value/i);
assert.match(validate({ title: '' })[0].message, /Project title is required/);
assert.match(validate({ title: 'Valid', materialTestingDate: '2026-02-30' })[0].message, /invalid date/i);
delete mapping['Material Testing Date'];
context.rows=[mapping];
vm.runInContext('handleExcelImport({ target: { files: [{}], value: "old.csv" } })', context);
assert.equal(Object.hasOwn(context.captured.rows[0],'materialTestingDate'),false);
console.log('Import field mapping and input validation passed.');

// Exercise real serial-date decoding from the bundled spreadsheet library.
(async () => {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/vendor/xlsx/xlsx.full.min.js'), 'utf8'), context);
    context.db = { transaction() { return { objectStore() { return { getAll() {
        const request = {}; setTimeout(() => request.onsuccess({ target: { result: [] } }), 0); return request;
    } }; } }; } };
    context.serialDate = (Date.UTC(2026, 0, 31) - Date.UTC(1899, 11, 30)) / 86400000;
    vm.runInContext('executeFinalDatabaseWriteTransaction = () => { capturedImport = pendingImportPayloads; }; processImportedRows([{title:"Numeric spreadsheet dates", biddingDate:serialDate, materialTestingDate:serialDate+1}])', context);
    await new Promise(resolve => setTimeout(resolve, 20));
    assert.equal(context.capturedImport.length, 1);
    assert.equal(context.capturedImport[0].biddingDate, '2026-01-31');
    assert.equal(context.capturedImport[0].materialTestingDate, '2026-02-01');
    for (const [text, iso] of [
        ['18-Mar-2026', '2026-03-18'], ['08-Apr-2026', '2026-04-08'],
        ['18–Mar–2026', '2026-03-18'], ['08‑Apr‑2026', '2026-04-08'],
        ['April 8, 2026', '2026-04-08'], ['18 March 2026', '2026-03-18']
    ]) assert.equal(context.parseImportDate(text), iso);
    assert.match(validate({ title: 'Invalid April date', biddingDate: context.parseImportDate('31-Apr-2026') })[0].message, /invalid date/i);
    const workbook = context.XLSX.utils.book_new();
    workbook.Workbook = { WBProps: { date1904: true } };
    const worksheet = context.XLSX.utils.aoa_to_sheet([
        ['Project Title', 'Date of Bidding'], ['Named date format', 44637]
    ]);
    worksheet.B2.z = 'dd-mmm-yyyy';
    context.XLSX.utils.book_append_sheet(workbook, worksheet, 'Projects');
    const reopened = context.XLSX.read(context.XLSX.write(workbook, { bookType: 'xlsx', type: 'array' }), { type: 'array' });
    const decodedRows = context.XLSX.utils.sheet_to_json(reopened.Sheets.Projects, { defval: '' });
    assert.equal(context.normalizeWorkbookRows(decodedRows, reopened.Workbook.WBProps.date1904)[0].biddingDate, '2026-03-18');
    console.log('Bundled spreadsheet serial dates import without missing library functions or timezone shifts.');
})().catch(error => { console.error(error); process.exitCode = 1; });
