const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const nodes = new Map();
const context = vm.createContext({console, setTimeout, clearTimeout, setInterval:()=>1, clearInterval(){},
    document:{addEventListener(){},getElementById:id=>nodes.get(id)||null,querySelectorAll:()=>[]},
    localStorage:{getItem:()=>null},URLSearchParams,Map,Set,Event,alert(){},confirm:()=>true,calls:[],notifications:[]});
context.window = context;
context.addEventListener = ()=>{};
for (const file of ['state','utils','db','projects','timeline']) vm.runInContext(fs.readFileSync(path.join(__dirname,'../public/js/'+file+'.js'),'utf8'), context);
context.currentUser = {id:1,role:'admin',canEdit:true,canResolveAlerts:true};
context.loadDocuments = ()=>{};
context.showSystemNotification = message=>context.notifications.push(message);
context.updateDashboardAlertScrollLimit = ()=>{};
context.apiClient.post = async(action, body)=>{context.calls.push({action,body});return {ok:true,record:{...body.record,updatedAt:'new-version'}};};
const pause = ()=>new Promise(resolve=>setTimeout(resolve,30));

async function run() {
    for (const name of ['invokeTimelineMarkDoneAction','promptTimelineMarkDoneAction','executeDashboardBulkMarkDone','getManualTimelineAlertOptions']) assert.equal(typeof context[name], 'undefined');
    const blade = fs.readFileSync(path.join(__dirname,'../resources/views/tracker.blade.php'),'utf8');
    assert(!/mark\s+(?:selected\s+)?done/i.test(blade));
    assert(!blade.includes('dashboardBulkDoneBtn'));
    assert(!blade.includes('timelineMarkDoneModal'));
    const due = {noa:'2026-10-09',preInspection:'2026-10-09',ntpDate:'2026-10-09',materialTestingDate:'2026-10-09',finalInspection:'2026-10-09',swa:'2026-10-09'};
    const resolutions = context.getAutomaticTimelineResolutions(due, '2026-10-10');
    const aliases = Array.from(resolutions).flatMap(item=>Array.from(item.aliases));
    for (const type of ['noa-warning','noa-overdue','ntp-award','ntp-overdue','pre-inspection-overdue','material-overdue','final-inspection-overdue','award-overdue']) assert(aliases.includes(type), type);
    assert.equal(context.getAutomaticTimelineResolutions(due,'2026-10-08').length,0);

    // Historical flags can be reopened; an automatically resolved alert requires a date correction.
    vm.runInContext('db=createServerDatabase({projects:[{id:1,title:"Historical",timelineAlertsCompleted:["noa-overdue","ntp-overdue"],updatedAt:"old"},{id:2,title:"Dated",noa:"2020-01-01",updatedAt:"old"}],history:[]})',context);
    context.invokeTimelineUnmarkDoneAction(1,'noa-overdue');
    await pause();
    assert.equal(context.calls.length,1);
    assert.equal(context.calls[0].action,'saveTimelineRecord');
    assert.deepEqual(Array.from(context.calls[0].body.record.timelineAlertsCompleted),['ntp-overdue']);
    context.invokeTimelineUnmarkDoneAction(2,'noa-overdue');
    await pause();
    assert.equal(context.calls.length,1);
    assert(context.notifications.some(message=>message.includes('actual milestone date')));
    context.currentUser = {id:1,role:'staff',canResolveAlerts:false};
    context.invokeTimelineUnmarkDoneAction(1,'ntp-overdue');
    await pause();
    assert.equal(context.calls.length,1);
    assert(context.notifications.some(message=>message.includes('permission')));

    // Dashboard alerts remain grouped and navigate to details, with no completion checkboxes.
    nodes.set('dashboardNotificationList',{innerHTML:''});
    context.input = [{type:'noa-overdue',label:'NOA Overdue',project:{id:3,title:'Road'}},{type:'final-inspection',label:'Final Inspection Due',project:{id:3,title:'Road'}}];
    vm.runInContext('activeDashboardAlertsData=input;filterDashboardAlertsList()',context);
    const html = nodes.get('dashboardNotificationList').innerHTML;
    assert.equal((html.match(/dashboard-project-alert-row/g)||[]).length,1);
    assert(html.includes('2 alerts'));
    assert(html.includes('>Alerts</button>'));
    assert(!html.includes('checkbox'));
    assert(!/mark\s+done/i.test(html));
    console.log('Date-based resolution, historical restore, permissions and grouped dashboard checks passed.');
}
run().catch(error=>{console.error(error);process.exitCode=1;});
