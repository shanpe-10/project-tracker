const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = name => fs.readFileSync(path.join(__dirname,'../public/js',name),'utf8');
function extract(text,name) {
    const start=text.indexOf(`function ${name}(`); assert(start>=0);
    let depth=0, body=text.indexOf('{',start);
    for(let i=body;i<text.length;i++) { if(text[i]==='{') depth++; if(text[i]==='}'&&!--depth)return text.slice(start,i+1); }
    throw Error('Missing function');
}
for(const [before,after] of [[false,true],[true,false]]) {
    test(`Permission ${after?'grant':'revocation'} refreshes controls without restarting the session`,async()=>{
        const intervals=[]; let refreshed=0;
        const context={Date,Number,Math,JSON,console,document:{hidden:false,getElementById:()=>null,addEventListener(){}},
            setInterval(fn,ms){intervals.push({fn,ms});},addEventListener(){},
            authRequest:async()=>({ok:true,authenticated:true,user:{id:1,role:'staff',canEdit:after},csrfToken:'new-token'}),
            applyAuthenticatedUser(user,csrf,timing,refreshOnly){assert.equal(refreshOnly,true);context.currentUser=user;refreshed++;},showLogin(){throw Error('Unexpected logout');}};
        context.window=context; context.currentUser={id:1,role:'staff',canEdit:before};
        vm.runInNewContext(source('session.js'),context); context.startTrackerSession({});
        intervals.find(i=>i.ms===15000).fn(); await new Promise(setImmediate);
        assert.equal(context.currentUser.canEdit,after); assert.equal(refreshed,1); assert.equal(context.sessionCsrfToken,'new-token');
        intervals.find(i=>i.ms===15000).fn(); await new Promise(setImmediate); assert.equal(refreshed,1);
    });
}
test('Form and import number/date validation preserves decimals and rejects invalid values',()=>{
    const context={Intl,Date,Number}; vm.runInNewContext(extract(source('projects.js'),'validateProjectNumbersAndActualDates'),context);
    const validate=context.validateProjectNumbersAndActualDates;
    assert.equal(validate({ldAmount:'123.45',ldNoOfDays:'.5',ldActualDuration:'0',contractDuration:'30.5'}).length,0);
    for(const field of ['ldAmount','ldNoOfDays','ldActualDuration','contractDuration']) {
        for(const value of ['-1','text','Infinity','NaN','1e9',true]) assert(validate({[field]:value}).length>0);
    }
    for(const field of ['completion','swa']) assert(validate({[field]:'9999-01-04'}).length>0);
    assert(source('excel.js').includes('validateProjectNumbersAndActualDates(payload)'));
});
test('Future actual dates cannot cause premature terminal form status',()=>{
    const values={swa:'9999-01-04',completionDate:'9999-01-04',status:'Released',biddingDate:'2026-01-05'};
    const context={Date,document:{getElementById:id=>({value:values[id]||''})},
        projectCalendarDate:v=>/^\d{4}-\d{2}-\d{2}$/.test(v)?new Date(v+'T00:00:00Z'):null,
        timelineCalendarDay:()=>new Date().toISOString().slice(0,10),getProjectMilestoneDates:()=>({contractDueDate:null})};
    vm.runInNewContext(extract(source('projects.js'),'automaticProjectFormStatus'),context);
    assert.equal(context.automaticProjectFormStatus(),'Pre-inspection');
    values.swa='2025-01-06'; assert.equal(context.automaticProjectFormStatus(),'Released');
});
