# Manual browser end-to-end checklist

**Status: NOT TESTED.** Browser end-to-end testing has not been run. Run it
against the application pointed at `mulawin_fleetops_test` only (see
`database_setup_checklist.md`), with test accounts the owner created. Use a
current Chrome/Edge and Firefox, with DevTools open (Console + Network).

For each item record: role, browser, PASS/FAIL, console errors, screenshot.

## 1. Login and session
- [ ] Login with valid credentials per role; wrong password message; lockout message after repeated failures.
- [ ] Logout returns to login; Back button does not show protected pages.
- [ ] Forgot-password request form submits without revealing whether the account exists.
- [ ] Session timeout / role change forces re-login.

## 2. Per-role access (repeat for each role)
- [ ] Sidebar shows only permitted items.
- [ ] Typing the URL of a hidden page shows the 403 page (hiding is not the only control).
- [ ] Each permitted page opens without PHP warnings or console errors.

## 3. CRUD smoke tests
- [ ] Clients, client rates, routes, trucks (with documents/photos), employees/users (Admin), parts, suppliers, office supplies, recruitment, announcements, documents: create, edit, list/search, delete/restore where offered.
- [ ] Form validation messages for required fields, bad dates, negative amounts, oversize uploads.

## 4. Dispatch flow
- [ ] Dispatcher: plan → submit request; sees it under "My Recent Requests".
- [ ] Operations Head: Requests page → approve / reject (reason required); conflicting assignment message.
- [ ] Trip workflow page: assignment, clearance, inspections (timer), departure, delivery, return, completion.
- [ ] Driver: dashboard current assignment, completion report.
- [ ] Incident: log, view, resolve.
- [ ] Double-click on submit/approve buttons does not create duplicates.

## 5. Finance and analytics
- [ ] Billing: create from completed trip (suggested amount), record partial and full payment, export CSV.
- [ ] Trip costs and Trip Pay (incl. second driver), payroll + payslip print, finance fund request / payable / voucher / disbursement.
- [ ] Requests page: purchasing / fund / voucher approval section (Admin).
- [ ] Analytics: change period and granularity; charts render; CSV export and print; Operations Head view has no money figures; margin chart note visible (Admin).
- [ ] Each role dashboard: charts render, empty states read sensibly, "—" for unavailable rates, links go to permitted pages.

## 6. Audit and unauthorized attempts
- [ ] Actions appear in Admin's recent activity / audit log with the right actor.
- [ ] A non-Admin cannot reach Recycle Bin, Automation, Users, Role Permissions (403).
- [ ] Tampered requests (DevTools: change ids, remove CSRF token) are refused with an error and no data change.

## 7. Responsive and console
- [ ] Phone width (375 px) and tablet: no horizontal scroll on dashboards, tables scroll inside their containers, modals usable.
- [ ] Dark and light theme: charts and badges readable.
- [ ] No console errors or failed network requests on any page visited.
