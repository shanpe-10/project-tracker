const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const storage = new Map();
const context = vm.createContext({
    console, setTimeout, clearTimeout, setInterval: () => 1, clearInterval() {},
    document: { addEventListener() {}, getElementById() { return null; }, querySelectorAll() { return []; } },
    localStorage: { getItem: key => storage.get(key) || null, setItem: (key,value) => storage.set(key,value), removeItem: key => storage.delete(key) },
    alert() {}, confirm: () => true, URLSearchParams, Map, Set, Event,
    notifications: [], changes: 0, calls: []
});
context.window=context;
context.addEventListener=()=>{};
context.showSystemNotification=message=>context.notifications.push(message);
context.loadDocuments=()=>context.changes++;
for (const name of ['state','utils','db','projects','timeline']) vm.runInContext(fs.readFileSync(path.join(root,'public/js/'+name+'.js'),'utf8'),context);
context.loadDocuments=()=>context.changes++;
async function run() {
    for (const text of ['=1+1','+SUM(1,2)','-1+1','@SUM(A1)','\t=2','  =3']) assert.equal(context.safeCsvText(text),"'"+text);
    assert.equal(context.safeCsvText('ordinary text'),'ordinary text');
    assert.equal(context.safeCsvText('100.50'),'100.50');
    assert.equal(context.timelineCalendarDay("2026-10-04T16:30:00Z"),"2026-10-05");
    assert.equal(context.timelineCalendarDay("2026-02-30"),"");
    assert(context.timelineDateInRange("2026-10-05","2026-10-05","2026-10-05"));
    assert(context.timelineDateInRange("2026-10-04T16:30:00Z","2026-10-05","2026-10-05"));
    assert(!context.timelineDateInRange("2026-10-05","2026-10-06","2026-10-07"));
    assert(context.timelinePresetRange("last7",new Date().toISOString()));
    context.currentUser={id:1,role:'admin'};
    assert.equal(context.attachmentDraftIdentity(10),'1:10');
    const first=context.attachmentDraftLocationKey();
    context.currentUser={id:2,role:'admin'};
    assert.equal(context.attachmentDraftIdentity(10),'2:10');
    assert.notEqual(context.attachmentDraftLocationKey(),first);
    context.apiClient.post=async (action,body)=>{
        context.calls.push({action,body});
        return {ok:true,record:{...body.record,updatedAt:'new-version'}};
    };
    vm.runInContext('db=createServerDatabase({projects:[{id:10,title:"Demo",updatedAt:"old-version",timelineAlertsCompleted:["noa-overdue","ntp-overdue"]},{id:11,title:"Failure fixture",updatedAt:"old-version",timelineAlertsCompleted:["ntp-overdue"]}],history:[]})',context);
    context.invokeTimelineUnmarkDoneAction(10,'noa-overdue');
    await new Promise(resolve=>setTimeout(resolve,30));
    assert.equal(context.calls.length,1);
    assert.equal(context.calls[0].action,'saveTimelineRecord');
    assert.equal(context.calls[0].body.history.projectId,10);
    assert.deepEqual(Array.from(context.calls[0].body.record.timelineAlertsCompleted),['ntp-overdue']);
    context.invokeTimelineUnmarkDoneAction(10,'ntp-overdue');
    await new Promise(resolve=>setTimeout(resolve,30));
    assert.equal(context.calls.length,2);
    assert.equal(context.calls[1].action,'saveTimelineRecord');
    assert.equal(context.calls[1].body.record.timelineUnmarkedType,'ntp-overdue');
    assert.equal(context.calls[1].body.record.updatedAt,'new-version');
    context.apiClient.post=async ()=>{throw new Error('Conflicting change');};
    context.invokeTimelineUnmarkDoneAction(11,'ntp-overdue');
    await new Promise(resolve=>setTimeout(resolve,30));
    assert(context.notifications.includes('Conflicting change'));
    console.log('Focused frontend audit regressions passed (CSV, draft ownership, atomic historical restore and failure feedback).');
}
run().catch(error=>{console.error(error);process.exitCode=1;});
