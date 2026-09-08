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

// Exercise the shipped manual-sync AJAX/presentation functions with synthetic responses.
(async () => {
  const source = fs.readFileSync(path.join(__dirname, '../assets/js/admin.js'), 'utf8');
  const names = ['escapeHtml', 'formatNumber', 'setSyncText', 'setSyncProgress', 'setSyncStatus', 'updateSyncCenter', 'runAjaxSync'];
  const code = names.map(name => {
    const start = source.indexOf('\n\tfunction ' + name + '(');
    assert.ok(start >= 0, 'Shipped function exists: ' + name);
    const end = source.indexOf('\n\tfunction ', start + 1);
    return source.slice(start, end);
  }).join('\n');
  for (const scenario of ['mixed', 'success', 'pages', 'rejected']) {
    const node = () => ({ textContent: '', innerHTML: '', value: '1', style: {}, classList: { add() {}, remove() {} }, setAttribute() {} });
    const container = () => {
      const nodes = new Map();
      return { querySelector(selector) { if (!nodes.has(selector)) nodes.set(selector, node()); return nodes.get(selector); }, querySelectorAll(selector) { return [this.querySelector(selector)]; } };
    };
    const center = container(), summary = container(), attrs = new Map();
    const form = { closest: () => center, querySelector: selector => center.querySelector(selector), getAttribute: key => attrs.get(key) || null, setAttribute: (key, value) => attrs.set(key, value), removeAttribute: key => attrs.delete(key) };
    const sentPages = [];
    class Data { constructor() { this.fields = {}; } set(key, value) { this.fields[key] = value; } }
    const fetch = async (url, options) => {
      const page = options.body.fields.sync_page; sentPages.push(page);
      if (scenario === 'rejected') return { json: async () => ({ success: false, data: { message: 'Reset requires recovery.' } }) };
      const more = scenario === 'pages' && page === 1;
      const issues = scenario === 'success' ? 0 : 2;
      return { json: async () => ({ success: true, data: { hasMore: more, nextPage: page + 1, state: {
        status: more ? 'in_progress' : (issues ? 'completed_with_issues' : 'completed'), hasMore: more, nextPage: page + 1, percent: more ? 67 : 100,
        lastScanned: 3, lastProcessed: issues ? 1 : 3, lastRetryable: issues ? 1 : 0, lastUnresolved: issues ? 1 : 0, lastIssues: issues,
        totalScanned: 3, totalProcessed: issues ? 1 : 3, totalRetryable: issues ? 1 : 0, totalUnresolved: issues ? 1 : 0, totalIssues: issues, totalOrders: 3
      } } }) };
    };
    const document = { querySelector: () => summary, createElement: () => ({ textContent: '', get innerHTML() { return this.textContent; } }) };
    const context = { window: { fetch, FormData: Data, yoohwCosAdmin: { ajaxUrl: '/synthetic-ajax', syncIssuesText: 'Scan complete with issues.' } }, document, fetch, FormData: Data };
    vm.createContext(context); vm.runInContext(code, context);
    assert.equal(context.runAjaxSync(form), true);
    for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(sentPages, scenario === 'pages' ? [1, 2] : [1]);
    const message = center.querySelector('[data-yoohw-cos-sync-message]').textContent;
    if (scenario === 'rejected') {
      assert.equal(message, 'Reset requires recovery.');
      assert.match(center.querySelector('.yoohw-cos-sync-status-value').innerHTML, /--warning/);
    } else {
      const success = scenario === 'success';
      assert.equal(message, success ? 'Sync complete.' : 'Scan complete with issues.');
      assert.match(center.querySelector('.yoohw-cos-sync-status-value').innerHTML, success ? /--good/ : /--warning/);
      assert.equal(summary.querySelector('[data-yoohw-cos-sync-message]').textContent, message);
      assert.equal(center.querySelector('.yoohw-cos-sync-total-issues').textContent, success ? '0' : '2');
      assert.equal(center.querySelector('.yoohw-cos-sync-last-retryable').textContent, success ? '0' : '1');
      assert.equal(center.querySelector('[data-yoohw-cos-sync-percent]').textContent, '100%');
      assert.equal(center.querySelector('input[name="sync_page"]').value, 1);
    }
    assert.equal(attrs.has('data-yoohw-cos-syncing'), false);
  }
  console.log('PASS: shipped manual-sync AJAX preserves outcome warnings/counts, replay page and readiness parity');
})().catch(error => { console.error(error); process.exitCode = 1; });
