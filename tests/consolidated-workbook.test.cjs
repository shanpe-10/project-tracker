const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname,'..');
function context() {
    const c=vm.createContext({console,Date,Intl,URLSearchParams,setTimeout,clearTimeout});
    c.window=c;c.document={addEventListener(){},getElementById(){return null;}};
    for (const file of ['utils.js','project-fields.js','project-revision.js']) vm.runInContext(fs.readFileSync(path.join(root,'public/js',file),'utf8'),c);
    const s=fs.readFileSync(path.join(root,'public/js/projects.js'),'utf8');
    vm.runInContext(s.slice(s.indexOf('function validateProjectNumbersAndActualDates('),s.indexOf('function saveDocument(')),c);
    vm.runInContext(fs.readFileSync(path.join(root,'public/vendor/xlsx/xlsx.full.min.js'),'utf8'),c);
    vm.runInContext(fs.readFileSync(path.join(root,'public/js/excel.js'),'utf8'),c);
    return c;
}
function rows(c,file) { const wb=c.XLSX.read(fs.readFileSync(file),{type:'array'});return c.XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]],{defval:''}); }
test('Consolidated workbook maps real headings, dates, source notes and separate milestones',{skip:!process.env.CONSOLIDATED_REFERENCE_FILE},()=>{
    const c=context();const raw=rows(c,process.env.CONSOLIDATED_REFERENCE_FILE);
    const parsed=c.normalizeWorkbookRows(raw);assert.equal(parsed.length,raw.length);
    assert.equal(String(parsed[0].referenceNo),'12809708');assert.match(parsed[0].title,/Sanitary Landfill/);
    assert.equal(parsed[0].owner,'DAN CARLO I. TOLEDANES');assert.equal(parsed[0].approvedBudget,9999807.43);
    assert(parsed.some(r=>String(r.remarks).includes('Unlabelled source cells:')));
    const combined=c.normalizeWorkbookRows([{'Name of Project':'Example','Completion\n/SWA Date':46300}])[0];
    assert.match(combined.completionSwaDate,/^\d{4}-\d{2}-\d{2}$/);assert.equal(combined.completion,undefined);assert.equal(combined.swa,undefined);
    assert.throws(()=>c.normalizeWorkbookRows([{'Name of Project':'Example','Unexpected information':'keep me'}]),/Unsupported column/);
});
test('Both bidders round trip with typed amounts including zero, and original templates still import',{skip:!process.env.CONSOLIDATED_QA_EXPORT},()=>{
    const c=context(),raw=rows(c,process.env.CONSOLIDATED_QA_EXPORT);
    const parsed=c.normalizeWorkbookRows(raw)[0];
    assert.equal(parsed.losingBidder1,'=unsafe formula');assert.equal(parsed.losingBidAmount1,1234.5);
    assert.equal(parsed.losingBidder2,'Builder B');assert.equal(parsed.losingBidAmount2,0);
    assert.equal(c.parseImportDate(parsed.preBidDate),'2026-10-21');
    for(const field of ['losingBidAmount1','losingBidAmount2']) {
        assert.equal(c.importFieldIssues({title:'Example',[field]:0}).length,0);
        assert(c.importFieldIssues({title:'Example',[field]:'-1'}).some(e=>e.field===field && e.omit));
        assert(c.importFieldIssues({title:'Example',[field]:'1.234'}).some(e=>e.field===field && e.omit));
    }
    assert.equal(c.normalizeWorkbookRows([{'2. Project Title':'Old template','23. LD Amount':'10'}])[0].ldAmount,'10');
    assert.equal(c.normalizeWorkbookRows([{'Project Title':'LD only','LD Amount':'10'}])[0].ldAmount,'10');
});
test('Import payloads omit blank and absent columns to retain saved fields',()=>{
    const c=context();c.getNextProjectNumber=()=>2;
    c.db={transaction(){return {objectStore(){return {getAll(){const r={};Object.defineProperty(r,'onsuccess',{set(fn){fn({target:{result:[]}});}});return r;}};}};}};
    vm.runInContext('executeFinalDatabaseWriteTransaction=()=>{}',c);
    c.processImportedRows([{'Name of Project':'New','Losing Bidder 1':'','Losing Bid Amount 1':0,'Owner':'New owner'}]);
    const payload=vm.runInContext('pendingImportPayloads[0]',c);
    assert.equal(payload.losingBidAmount1,'0');assert.equal(payload.owner,'New owner');
    for(const key of ['losingBidder1','losingBidder2','approvedBudget','remarks']) assert.equal(Object.hasOwn(payload,key),false,key);
});
test('Losing bid statuses survive the form and import with either winning amount heading', () => {
    const c = context();
    for (const heading of ['Winning Bid Amount', 'Bid Amount', 'bidAmount']) {
        const [row] = c.normalizeWorkbookRows([{
            'Project Title': 'Example', [heading]: 1250, 'Losing Bid Amount 1': 'N/A', 'Losing Bid Amount 2': 'DQ'
        }]);
        const payload = c.importRowPayload(row);
        assert.equal(payload.bidAmount, '1250');
        assert.equal(payload.losingBidAmount1, 'N/A');
        assert.equal(payload.losingBidAmount2, 'DQ');
        assert.equal(c.importFieldIssues(payload).length, 0);
    }
    assert.equal(c.validateProjectNumbersAndActualDates({losingBidAmount1: 'dq', losingBidAmount2: 'n/a'}).length, 0);
    assert.equal(c.validateProjectNumbersAndActualDates({bidAmount: 'DQ'}).length, 1);
});
test('The form loads field definitions before the editor and stays available offline',()=>{
    const c=context();const html=fs.readFileSync(path.join(root,'resources/views/tracker.blade.php'),'utf8');
    assert(html.indexOf('js/project-fields.js')<html.indexOf('js/projects.js'));
    const markup=html.match(/(?:src|href)="(https?:[^\"]+)"/g)||[];assert.deepEqual(markup,[]);
});
