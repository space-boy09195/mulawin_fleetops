// tests/unit/attendance_rows.js
// Behaviour checks for the batch-save script on pages/attendance.php: only
// changed rows are sent, a new row needs an explicit status, nothing is sent
// when nothing changed, and a failed save is not reported as success. The
// script is copied out of the page and run against stub rows, buttons and a
// stub window.sendAction: no browser, server or database.
// Run: node tests/unit/attendance_rows.js
const fs = require('fs');
const path = require('path');
const page = fs.readFileSync(path.join(__dirname, '../../pages/attendance.php'), 'utf8');
const start = page.indexOf('<?php if ($canSaveDate): ?><script>');
const body = page.slice(page.indexOf('<script>', start) + 8, page.indexOf('</script>', start))
  .replace(/<\?=[\s\S]*?\?>/g, 'PHPVALUE');
let pass = 0, fail = 0;
const check = (name, ok) => { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + name); };

function makeRow(id, name, originalStatus, originalNotes, status, notes) {
  const select = { value: status }, input = { value: notes };
  const classes = new Set();
  return {
    dataset: { employee: String(id), recorded: originalStatus ? '1' : '0', originalStatus, originalNotes },
    classList: { toggle: (c, on) => (on ? classes.add(c) : classes.delete(c)), has: c => classes.has(c) },
    querySelector: sel => sel === '.attendance-status' ? select : sel === '.attendance-notes' ? input
      : sel === 'td' ? { firstChild: { textContent: name } } : null,
    select, input
  };
}

async function run(rows, sendResult) {
  const listeners = {};
  const saveButton = { disabled: false, addEventListener: (t, fn) => { listeners.click = fn; } };
  const counter = { textContent: '' };
  const feedback = { textContent: '', className: 'alert d-none' };
  const sent = [];
  const document = {
    getElementById: id => ({ saveAttendanceRows: saveButton, attendanceFeedback: feedback, attendanceChangedCount: counter })[id] || null,
    querySelectorAll: () => rows,
    addEventListener: () => {}
  };
  const window = {
    CSRF_TOKEN_NAME: 'csrf', CSRF_TOKEN: 't', addEventListener: () => {},
    sendAction: async (url, params, options) => {
      sent.push(JSON.parse(params.get('rows')));
      if (sendResult.success) { options.feedback.textContent = sendResult.message; options.feedback.className = 'alert alert-success'; }
      else { options.feedback.textContent = sendResult.message; options.feedback.className = 'alert alert-danger'; }
      return sendResult;
    }
  };
  eval(body);
  const countBefore = counter.textContent, disabledBefore = saveButton.disabled;
  await listeners.click();
  return { sent, feedback, counter, countBefore, disabledBefore, saveButton };
}

(async () => {
  // Ana saved Present (unchanged), Ben not recorded -> Late (changed),
  // Cris not recorded and untouched, Dan saved Absent -> notes added.
  const rows = () => [
    makeRow(1, 'Ana', 'Present', '', 'Present', ''),
    makeRow(2, 'Ben', '', '', 'Late', ''),
    makeRow(3, 'Cris', '', '', '', ''),
    makeRow(4, 'Dan', 'Absent', '', 'Absent', 'sick')
  ];
  let r = await run(rows(), { success: true, message: 'Attendance saved for 2 employees on Oct 27, 2026.' });
  check('count shows the 2 changed rows', r.countBefore === 2);
  check('only changed rows are sent', JSON.stringify(r.sent[0].map(x => x.employee_id)) === '[2,4]');
  check('each row carries what the page showed', r.sent[0][0].expected_status === '' && r.sent[0][1].expected_status === 'Absent');
  check('the new row is sent with its explicit status', r.sent[0][0].status === 'Late');
  check('success shown only from the server answer', r.feedback.className === 'alert alert-success');

  const blank = rows(); blank[2].input.value = 'came late';  // Cris: notes typed, no status
  r = await run(blank, { success: true, message: 'should not be used' });
  check('a new row without a status blocks the save', r.sent.length === 0);
  check('the blocked row is named', r.feedback.className === 'alert alert-danger' && r.feedback.textContent.includes('Cris'));

  const none = [makeRow(1, 'Ana', 'Present', '', 'Present', ''), makeRow(3, 'Cris', '', '', '', '')];
  r = await run(none, { success: true, message: 'x' });
  check('nothing changed: nothing is sent and Save is disabled', r.sent.length === 0 && r.disabledBefore === true);

  r = await run(rows(), { success: false, message: 'Nothing was saved. Ben Reyes: this attendance was changed by someone else.' });
  check('a refused batch is shown as an error, not success', r.feedback.className === 'alert alert-danger' && r.feedback.textContent.startsWith('Nothing was saved.'));
  check('after a refused batch the rows are still marked changed', r.counter.textContent === 2);

  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
