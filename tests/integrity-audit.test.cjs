const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const root=process.env.AUDIT_SOURCE_ROOT || path.resolve(__dirname,'..');
function context(){const ctx=vm.createContext({console:{error(){}},setTimeout,clearTimeout,URLSearchParams,Event:class{},document:{addEventListener(){}},alert(){}});ctx.window=ctx;vm.runInContext(fs.readFileSync(path.join(root,'public/js/db.js'),'utf8'),ctx);return ctx;}
function deferred(){let resolve;const promise=new Promise(r=>resolve=r);return {promise,resolve};}
function read(db){return new Promise((resolve,reject)=>{const tx=db.transaction();tx.objectStore('projects').get(1).onsuccess=e=>resolve(e.target.result);tx.onerror=reject;});}
async function test(name,fn){try{await fn();console.log('PASS '+name);}catch(e){console.error('FAIL '+name+': '+e.message);failures++;}}
let failures=0;
(async()=>{
 await test('Out-of-order refresh responses cannot replace newer records',async()=>{
  const c=context(),first=deferred(),second=deferred();let calls=0;c.apiClient.getBootstrap=()=>++calls===1?first.promise:second.promise;c.seed={projects:[{id:1,title:'Original'}]};vm.runInContext('window.testDb=createServerDatabase(seed)',c);
  const a=c.testDb.refresh(),b=c.testDb.refresh();second.resolve({projects:[{id:1,title:'Newest'}]});await b;first.resolve({projects:[{id:1,title:'Old'}]});await a;assert.equal((await read(c.testDb)).title,'Newest');
 });
 await test('Refresh started before a committed mutation cannot undo local state',async()=>{
  const c=context(),pending=deferred();c.apiClient.getBootstrap=()=>pending.promise;c.apiClient.post=async()=>({record:{id:1,title:'Committed'}});c.seed={projects:[{id:1,title:'Original'}]};vm.runInContext('window.testDb=createServerDatabase(seed)',c);
  const refresh=c.testDb.refresh();await new Promise((resolve,reject)=>{const tx=c.testDb.transaction();tx.objectStore('projects').put({id:1,title:'Committed'});tx.oncomplete=resolve;tx.onerror=reject;});pending.resolve({projects:[{id:1,title:'Original'}]});await refresh;assert.equal((await read(c.testDb)).title,'Committed');
 });
 await test('Failed initialization clears ready state and exposes a retryable sign-in',async()=>{
  const c=context();let notice='';c.showLogin=m=>notice=m;c.apiClient.getBootstrap=async()=>{throw new Error('Fixture database unavailable')};c.dbReady=true;await c.initializeDatabase();assert.equal(c.dbReady,false);assert.equal(c.db,null);assert.match(notice,/unavailable/);
 });
 await test('Repeated sign-in restores workspace without duplicate polling timers',async()=>{
  let restores=0,intervals=0;const c=vm.createContext({console,setInterval(){intervals++},document:{visibilityState:'visible',addEventListener(){}},restoreLastWorkspace(){restores++},refreshGlobalNotifications(){},loadDocuments(){},switchWorkspace(){}});c.window=c;c.addEventListener=()=>{};c.dbReady=false;c.currentUser={role:'staff'};vm.runInContext(fs.readFileSync(path.join(root,'public/js/app.js'),'utf8'),c);c.dbReady=true;c.startApplication();c.startApplication();assert.equal(restores,2);assert.equal(intervals,1);
 });
 await test('Year zero is consistently rejected by frontend calendar arithmetic',async()=>{
  const c=vm.createContext({console,Date});vm.runInContext(fs.readFileSync(path.join(root,'public/js/utils.js'),'utf8'),c);assert.equal(c.projectCalendarDate('0000-01-01'),null);assert(c.projectCalendarDate('2024-02-29'));assert.equal(c.projectCalendarDate('2025-02-29'),null);
 });
 if(failures)process.exitCode=1;
})();
