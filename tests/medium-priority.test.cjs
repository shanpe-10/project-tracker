const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const context = vm.createContext({ Date, console, setTimeout });
context.window = context;
context.window.addEventListener = () => {};
context.document = { addEventListener() {} };
context.localStorage = { getItem() { return null; } };
vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/js/utils.js'), 'utf8'), context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/js/timeline.js'), 'utf8'), context);

const oldDate = '2020-01-01';
assert.equal(context.checkNoaOverdueWindowAlert(oldDate, 'Pending', {}), true);
assert.equal(context.checkNtpOverdueWindowAlert(oldDate, 'Pending', {}), true);
assert.equal(context.checkMaterialTestOverdueWindowAlert(oldDate, 'Pending', {}), true);
assert.equal(context.checkNoaOverdueWindowAlert(oldDate, 'Released', {}), false);

const completion = new Date();
completion.setFullYear(completion.getFullYear() - 1);
completion.setDate(completion.getDate() + 2);
const dateString = `${completion.getFullYear()}-${String(completion.getMonth() + 1).padStart(2, '0')}-${String(completion.getDate()).padStart(2, '0')}`;
assert.equal(context.checkFifteenDayWindowAlert(dateString, 'Completed'), true);
assert.equal(context.checkFifteenDayWindowAlert(dateString, 'Pending'), false);

console.log('Medium-priority timeline regressions passed.');
