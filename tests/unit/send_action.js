// tests/unit/send_action.js
// Behaviour checks for window.sendAction() and the soft-refresh rules for
// result boxes and <select data-refresh-options> in assets/js/layout.js.
// The functions are copied out of layout.js and run against stub fetch and
// element objects: no browser, server or database.
// Run: node tests/unit/send_action.js

const fs = require('fs');
const src = fs.readFileSync(require("path").join(__dirname, "../../assets/js/layout.js"), 'utf8');
const grab = (start, end) => { const i = src.indexOf(start); const j = src.indexOf(end, i); if (i < 0 || j < 0) throw new Error('missing ' + start); return src.slice(i, j + end.length); };
const sendSrc = grab('window.sendAction = async function', '\n  };');
const morphSrc = grab('function isEmptyFeedbackBox', '\n  }') + '\n' + grab('function syncSelectOptions', '\n  }');
let pass = 0, fail = 0; const check = (name, ok) => { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + name); };
const el = () => ({ textContent: '', className: 'alert d-none', innerHTML: 'Record disbursement', disabled: false, attrs: {},
  setAttribute(k, v) { this.attrs[k] = v; }, removeAttribute(k) { delete this.attrs[k]; }, getAttribute(k) { return this.attrs[k] ?? null; },
  scrollIntoView() {}, reset() { this.wasReset = true; } });
// Stand-ins for the saved-message helpers next to sendAction in layout.js.
let savedMessage = null;
const rememberActionMessage = (boxId, message) => { savedMessage = { boxId, message }; };
const forgetActionMessage = () => { savedMessage = null; };
async function run(fetchImpl, reloadImpl) {
  savedMessage = null;
  const window = { softReload: reloadImpl || (async () => true) }; const alert = () => {};
  global.fetch = fetchImpl; eval(sendSrc);
  const feedback = el(), button = el(), form = el();
  feedback.id = "payrollFeedback";
  let busyDuringFetch = null;
  const origFetch = global.fetch; global.fetch = async (...a) => { busyDuringFetch = button.disabled && /Saving/.test(button.innerHTML); return origFetch(...a); };
  const result = await window.sendAction('/x', 'body', { feedback, button, form, label: 'record the disbursement' });
  return { result, feedback, button, form, busyDuringFetch, window };
}
const json = (status, body) => async () => ({ status, json: async () => body });
(async () => {
  let r = await run(json(200, { success: true, message: 'Disbursement recorded.' }));
  check('success: message shown from server', r.feedback.textContent === 'Disbursement recorded.' && r.feedback.className === 'alert alert-success');
  check('success: button busy while waiting', r.busyDuringFetch === true);
  check('success: form cleared', r.form.wasReset === true);
  check('success: untouched (modal) button given back', r.button.disabled === false && r.button.innerHTML === 'Record disbursement');
  r = await run(json(409, { success: false, message: 'Disbursement exceeds the remaining approved amount.' }));
  check('server refusal: server message, red', r.feedback.textContent.startsWith('Disbursement exceeds') && r.feedback.className === 'alert alert-danger');
  check('server refusal: entries kept, button back', r.form.wasReset !== true && r.button.disabled === false);
  r = await run(async () => ({ status: 500, json: async () => { throw new SyntaxError('HTML'); } }));
  check('non-JSON 500: says unconfirmed + check before retry', /did not confirm/.test(r.feedback.textContent) && /error 500/.test(r.feedback.textContent) && r.button.disabled === false);
  r = await run(async () => { throw new TypeError('Failed to fetch'); });
  check('network failure: readable error, button back, entries kept', /Could not reach the server/.test(r.feedback.textContent) && r.button.disabled === false && r.form.wasReset !== true);
  r = await run(json(401, { success: false, message: 'Authentication is required.' }));
  check('expired session JSON: shown', r.feedback.textContent === 'Authentication is required.');
  // double click: second call while first is busy is ignored
  { const window = { softReload: async () => true }; const alert = () => {}; let calls = 0; let release;
    global.fetch = () => { calls++; return new Promise(res => { release = () => res({ status: 200, json: async () => ({ success: true }) }); }); };
    eval(sendSrc); const button = el(), feedback = el();
    const first = window.sendAction('/x', 'b', { button, feedback }); const second = await window.sendAction('/x', 'b', { button, feedback });
    release(); await first; check('double click: only one request sent', calls === 1 && second === null); }
  // Saved success message (full-reload fallback)
  r = await run(json(200, { success: true, message: "Payroll payment recorded." }), async () => true);
  check("reload: updated in place, no message left saved", savedMessage === null);
  r = await run(json(200, { success: true, message: "Payroll payment recorded." }), async () => false);
  check("reload: full reload keeps the message for the same box", savedMessage && savedMessage.boxId === "payrollFeedback" && savedMessage.message === "Payroll payment recorded.");
  r = await run(json(200, { success: true }), async () => false);
  check("reload: no server message saves the generic Saved.", savedMessage && savedMessage.message === "Saved.");
  r = await run(json(409, { success: false, message: "Refused." }), async () => false);
  check("reload: a failure saves nothing", savedMessage === null);
  r = await run(async () => ({ status: 500, json: async () => { throw new SyntaxError("HTML"); } }), async () => false);
  check("reload: an unreadable answer saves nothing", savedMessage === null);
  { // showMessageAfterReload(): only on the same page, once
    const showSrc = grab("(function showMessageAfterReload", "})();");
    const make = (page, stored) => {
      const store = { "fleetops:actionMessage": stored }; const box = { textContent: "", className: "alert d-none" };
      const sessionStorage = { getItem: k => store[k] ?? null, removeItem: k => { delete store[k]; } };
      const window = { location: { pathname: page, search: "" } };
      const document = { getElementById: id => id === "payrollFeedback" ? box : null };
      const PENDING_MESSAGE_KEY = "fleetops:actionMessage";
      const forgetActionMessage = () => { try { sessionStorage.removeItem(PENDING_MESSAGE_KEY); } catch (e) {} };
      eval(showSrc); return { box, store };
    };
    const saved = JSON.stringify({ page: "/p/payroll.php", boxId: "payrollFeedback", message: "Payroll payment recorded." });
    let s = make("/p/payroll.php", saved);
    check("reload: message shown green after the reload", s.box.textContent === "Payroll payment recorded." && s.box.className === "alert alert-success");
    check("reload: message shown only once", s.store["fleetops:actionMessage"] === undefined);
    s = make("/p/finance.php", saved);
    check("reload: not shown on a different page", s.box.textContent === "" && s.store["fleetops:actionMessage"] === undefined);
    s = make("/p/payroll.php", "{broken");
    check("reload: unreadable saved value is ignored", s.box.textContent === "");
  }
  // morph helpers
  eval(morphSrc);
  const box = (cls, text, id = 'financeFeedback') => ({ textContent: text, matches: (sel) => sel === '[role="alert"][id].d-none' && /\bd-none\b/.test(cls) && !!id });
  check('morph: empty server feedback box keeps client message', isEmptyFeedbackBox(box('alert d-none', '')) === true);
  check('morph: server alert with text is still updated', isEmptyFeedbackBox(box('alert d-none', 'Server warning')) === false);
  check('morph: visible server alert is still updated', isEmptyFeedbackBox(box('alert alert-warning', '')) === false);
  const sel = (html, value, opt) => { const s = { attr: opt, innerHTML: html, value, hasAttribute: (k) => k === 'data-refresh-options' && s.attr, get options() { return [...s.innerHTML.matchAll(/value="([^"]*)"/g)].map(m => ({ value: m[1], disabled: false })); } };
    return s; };
  let o = sel('<option value="">None</option><option value="1">FR-105</option>', '1', true);
  syncSelectOptions(o, { innerHTML: '<option value="">No approved fund requests awaiting disbursement</option>' });
  check('select: disbursed request removed and choice cleared', !/FR-105/.test(o.innerHTML) && o.value === '');
  o = sel('<option value="">None</option><option value="1">A</option>', '1', true);
  syncSelectOptions(o, { innerHTML: '<option value="">None</option><option value="1">A</option><option value="2">B</option>' });
  check('select: choice kept when still offered', o.value === '1' && /B/.test(o.innerHTML));
  o = sel('<option value="9">script-filled</option>', '9', false);
  syncSelectOptions(o, { innerHTML: '<option value="">Pick</option>' });
  check('select without data-refresh-options untouched', /script-filled/.test(o.innerHTML));
  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})();
