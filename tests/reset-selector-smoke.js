'use strict';
// Execute the shipped SelectWoo transport with synthetic fields; no browser/session/site.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

for (const epoch of ['', '11111111-1111-4111-8111-111111111111']) {
  let configuration;
  let disabled = false;
  let notice = '';
  const field = {
    length: 1,
    hasClass: () => false,
    data: () => undefined,
    selectWoo: options => { configuration = options; return field; },
    addClass: () => field,
    closest: () => ({ find: () => ({ val: () => epoch }) }),
    prop: (key, value) => { if (key === 'disabled') disabled = value; return field; }
  };
  const empty = { length: 0, addClass() { return this; }, hide() { return this; }, on() { return this; } };
  const $ = selector => {
    if (typeof selector === 'function') { selector(); return empty; }
    if (selector === '#yoohw_cos_customer_id') return field;
    if (typeof selector === 'string' && selector.startsWith('<p')) {
      return { text: value => { notice = value; return { insertAfter: () => {} }; } };
    }
    return empty;
  };
  $.fn = { selectWoo: true };
  $.each = (data, callback) => Object.entries(data).forEach(([key, value]) => callback(key, value));
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/js/order-admin.js'), 'utf8'), {
    jQuery: $, window: { yoohwCosOrderAdmin: { searchNonce: 'synthetic-nonce' } }, document: { body: {} }
  });
  const data = configuration.ajax.data({ term: 'Synthetic' });
  assert.equal(data.yoohw_cos_epoch, epoch);
  assert.equal(data.selection, '1');
  assert.equal(data.security, 'synthetic-nonce');
  assert.equal(configuration.ajax.processResults({ 1: 'Current customer' }).results[0].id, '1');
  configuration.ajax.error({ status: 409, responseJSON: { data: { message: 'Reload the page.' } } });
  assert.equal(disabled, true);
  assert.equal(notice, 'Reload the page.');
  assert.equal(configuration.ajax.data({ term: 'Retry' }).yoohw_cos_epoch, epoch, 'Never upgrade an old form epoch');
}
console.log('PASS: editable customer selector carries explicit legacy/current epoch, renders rejection and never upgrades stale selections');
