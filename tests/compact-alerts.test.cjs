const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const context=vm.createContext({console,Date,Intl,setTimeout,clearTimeout,setInterval:()=>1,clearInterval(){},document:{addEventListener(){},getElementById:()=>null,querySelectorAll:()=>[]},localStorage:{getItem:()=>null},URLSearchParams,Map,Set,alert(){}});
context.window=context; context.addEventListener=()=>{};
for (const file of ['state','utils','projects','timeline']) vm.runInContext(fs.readFileSync(path.join(__dirname,'../public/js/'+file+'.js'),'utf8'),context);
context.timelineDaySettings={bidding:7,ntp:30,material:15,finalInspection:7};
const today='2026-10-05';
const record={biddingDate:'2026-01-31',contractEndDate:'2026-03-20',completion:'2025-01-01',status:'Pending',preInspection:today,ntpDate:today,materialTestingDate:today,finalInspection:today,swa:today};
let entries=context.resolvedTimelineEntries(record,today);
assert.equal(entries.length,5);
assert(entries.every(item=>item.automatic&&item.doneDate===today));
assert.equal(entries.find(item=>item.type==='award-overdue').dueDate,'2026-01-01');
assert.deepEqual(Array.from(context.getProjectMilestoneAlerts(record,today)),[]);
assert.equal(context.checkNtpAwardingActiveWindow(today,'Pending',record),false);
assert.equal(context.checkNtpOverdueWindowAlert('2020-01-01','Pending',record),false);
assert.equal(context.checkMaterialTestOverdueWindowAlert('2020-01-01','Pending',{materialTestingDate:today}),false);
for (const field of ['preInspection','ntpDate','materialTestingDate','finalInspection','swa']) {
    for (const value of ['',null,'invalid','2026-02-30','2026-10-06']) {
        const changed={...record,[field]:value};
        assert.equal(context.resolvedTimelineEntries(changed,today).length,4,field+' '+value);
    }
}
const cleared={...record,materialTestingDate:''};
assert(context.getProjectMilestoneAlerts(cleared,today).some(item=>item.type==='material-overdue'));
const manual={...record,timelineAlertsCompleted:['material-overdue'],timelineMarkedDoneDate:'2026-10-01'};
entries=context.resolvedTimelineEntries(manual,today);
assert.equal(entries.length,5); assert.equal(entries.filter(item=>item.type.startsWith('material')).length,1);
assert(entries.find(item=>item.type==='material-overdue').automatic);
assert.deepEqual(manual.timelineAlertsCompleted,['material-overdue']);
entries=context.resolvedTimelineEntries({...manual,materialTestingDate:''},today);
assert.equal(entries.find(item=>item.type==='material-overdue').automatic,undefined);
assert.equal(entries.find(item=>item.type==='material-overdue').doneDate,'2026-10-01');
assert.equal(context.getAutomaticTimelineResolutions({ntpDate:today},today).length,1); // Completion dates resolve legacy NTP as well.
assert.equal(context.getAutomaticTimelineResolutions({materialTestingDate:today,ntpDate:'2026-01-01'},today).find(item=>item.type==='material-test').dueDate,'2026-01-16');
assert.equal(context.getProjectMilestoneAlerts({biddingDate:'2026-01-31'},'2026-02-20').length,0);
assert.equal(context.getProjectMilestoneAlerts({biddingDate:'2026-01-31'},'2026-02-21').length,3);
context.timelineDaySettings={material:19}; assert.equal(context.getTimelineDays('bidding'),19);
context.timelineDaySettings={bidding:3651}; assert.equal(context.getTimelineDays('bidding'),15);
console.log('Automatic milestone resolution, date clearing, future dates, preserved manual markers, legacy alerts, and shared reminders passed.');
