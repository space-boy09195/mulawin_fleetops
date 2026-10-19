# Analytics metric traceability matrix

Mulawin FleetOps — Fleet Management System with Accounting and Inventory
Management for RP Mulawin Trucking Services. Revalidated against the actual
client sources on 2026-10-15 (analytics verification pass). This replaces the
2026-10-14 version, which had no access to the client sources.

## Client sources

All inspection was read-only. Files stay where they were found. Only
structures, formulas and counts are recorded here, not personal or salary
details.

| Code | Source (path as found) | Status | What it holds |
|---|---|---|---|
| **Q** | `C:\Users\acer\Downloads\Mulawin_FleetOps_Client_Questionnaire-2.pdf` (13 pages) | **Inspected** — the filled-in questionnaire | Sections B–H answered, plus "Overall Notes". Typed answers and ticked checkboxes are read from the PDF form fields. **Radio answers are known only as `optN` values**; they are read in printed option order and marked *(radio)* below. Section I (positions / roles) is **not** in this version |
| Q0 | `C:\Users\acer\Downloads\Mulawin_FleetOps_Client_Questionnaire.pdf` | Inspected | An older, **blank** version of the form (includes Section I, unanswered). Not evidence of client answers |
| **CC** | `C:\Users\acer\Downloads\CAR CARRIER MONITORING 2026 (1).xlsx` | **Inspected** | Byte-identical to `CAR CARRIER MONITORING 2026.xlsx` (same SHA-256). 19 sheets: monthly trip lists Jan–Jul 2026 plus a sheet labelled "SEPT. 2026" that holds 27–28 Aug rows (**no August sheet**); weekly, monthly, daily-matrix and per-truck summaries; route lookup; hidden consolidated copies; hidden truck registration list |
| **CT** | `C:\Users\acer\Downloads\CONTAINER MONITORING  2026 (1).xlsx` (two spaces in the name) | **Inspected** | Byte-identical to `CONTAINER MONITORING  2026.xlsx`. 13 sheets: CJ Smart / Coca-Cola trip lists (Dec 2025–May 2026, 2025 archive), truck × day destination grids (TARA), monthly / weekly pivot summaries, client destination lists, hidden truck daily status sheet |
| **PR** | `C:\Users\acer\Downloads\DRIVERS PAYROLL.xlsx` | **Inspected (structure and formulas only)** | 16 sheets: weekly payroll per fleet (South Carrier, South Container, North, Wing Van), payslips, deductions and balances. No employee rows were reproduced |
| — | Client maintenance records, motorpool logbook, billing / collection records, expense reports, parts inventory list, trip tickets | **Not available** | Q25 says samples can be provided, but no file was found. **Their contents could not be verified** |
| — | `fleetops-analytics-2026-03-17-to-2026-09-17*.csv`, `top-trucks.csv` (Downloads) | Inspected, **not client evidence** | Exports from this application holding test data (`TEST-001`) |

Search scope: `C:\Users\acer` (Documents, Downloads, Desktop, OneDrive incl.
OneDrive Documents / Desktop, `mulawin_patches`) and `C:\xampp\htdocs`, to
depth 7, for `*.xlsx`, `*.xls`, `*.xlsm`, `*.csv`, `*questionnaire*`,
`*monitoring*`, payroll PDFs. AppData was excluded.

## Classifications

- **A — Explicitly client-provided:** stated in Q, or a formula in CC / CT / PR.
- **B — Client-requested, formula unspecified.**
- **C — Derived from client records:** the rule was inferred from the client's
  records and documented. "Reproduced" means the actual records were replayed
  through the application's rule and gave the client's own totals.
- **D — Requires clarification or missing data**, including metrics the
  application computes that the client does not use or cannot support with
  records.

**Validation status** names the strongest evidence actually obtained:
*Reproduced (client records)* > *Static-verified* (SQL names checked against
the schema, helpers unit-tested) > *Not validated*. No metric has been run
against a database: the test database is not approved.

## Matrix

### Trips

| Metric/report | Client evidence | Class. | Source records | Fields required | Implemented formula | Validation status | Known limitations | Authorized roles |
|---|---|---|---|---|---|---|---|---|
| Total trips (count) | Q Sec C table: "Kabuuang trips — M/E — **Total number of trips** — Scheduled trips — Trip records / Excel". Q26 #1: "Total trips per week, per client per truck, per driver"; formula "**Total Trips per Week / Waybill**". Q14 text: "**Total trips per week = Total ng waybills submitted by Operations per week.**" Q27 #1: total trips per day. CC: every trip row has `TRP = 1`; sheet totals `SUM(TRP)` | **A** (count) | `trips` | `trip_id`, `status`, date | Analytics trip counts use `departure_time` (proposed basis); current status groups are separately labeled and use scheduled departures. Operations Performance redirects to Analytics | Counting rule **reproduced (client records)**; SQL static-verified, not live-database tested | **Date basis differs.** CT summaries use **ACTUAL DEPARTURE DATE**. Q14 counts waybills **submitted** per week. The app has no waybill submission date. Whether Pending Approval / Cancelled trips count is not settled (CQ-1, CQ-2) | Analytics uses existing `reports.view`, `operations.reports.view`, `maintenance.reports.view`, and billing view gates by section |
| Daily / weekly / monthly / custom periods | Q3: compared daily, weekly, monthly, "per client & per truck". Q26 #1: "Daily/Weekly/Monthly/Custom". CC / CT weekly summaries use Monday–Sunday weeks ("WEEK 10: MARCH 2-8", "WEEK 5: JANUARY 26 - FEBRUARY 1") | C (bucket rule) | `trips` | date | Analytics offers monthly range options and Monday-start weekly buckets | **Reproduced (client records)** for the workbook comparison; SQL static-verified, not live-database tested | CC monthly summary splits weeks at month ends (Week 1 = Jan 1–4). Analytics labels a week by its Monday. CJ Smart's own row labels use other week ranges ("week 1 (MAY 1–MAY 10)") | as above |
| Trips by client | Q3 "per client"; Q26 #1 per client. CC / CT have **two levels**: COMPANY NAME (e.g. broker / forwarder) and CLIENT (brand / consignee) | B | `trips`, `clients` | `client_id` | Analytics groups recorded trip counts by the single modeled client level | SQL static-verified, not live-database tested | **One client level only.** The workbooks summarise by company, then client. The target has no company / parent-client column (CQ-3) | Existing Analytics section gates |
| Trips by truck | Q10 table: "Trips kada truck — M — Total trips per truck — Trip records — Excel / manual". CC: NO + PLATE NO. per row | **A** (count) | `trips`, `trucks` | `truck_id` | Analytics groups recorded trip counts by truck; no rank or performance score | SQL static-verified, not live-database tested | Client identifiers are inconsistent: CT `TRUCK STATUS` lists plate `CCE3646` for two units; one plate is stored as the number 44808; 0–29 trip rows a month in CC have no plate (see validation report) | Existing Analytics section gates |
| Trips by driver | Q26 #1 per driver. **CC: Driver 1 and Driver 2 on 93–100 % of trip rows** (Jan 408/412, Apr 367/396, Jul 703/729). CT: driver + helper per trip | B (request) / **D** (attribution) | `trips`, `employees` | `driver_id`, `helper_id`, T-T1 `second_driver_id` | Not shown in Analytics because the modeled primary-driver attribution is incomplete | n/a | **Mismatch with client records:** car-carrier trips normally have two drivers, and helper/driver treatment is unresolved (CQ-4) | — |
| Trips by dispatcher | Q26 #1 lists "Dispatcher" among the requested groupings | B | `trips`, `dispatch_requests` | `submitted_by` | Not shown as an Analytics performance grouping | n/a | Neither workbook has a dispatcher column, so nothing to compare | — |
| Completed trips | Q4 statuses ticked: Dispatched / Ongoing, **Completed**, "Unloading / Loading". Q5: "**Kapag na-deliver na ang total units based sa requirements ng client.**" Q Sec C table: "Total completed trips — Delivered units / trips" | **A** (definition) | `trips`; units | `status`; delivered units | Analytics shows current stored status groups only; status does not verify completed unit delivery | SQL static-verified, not live-database tested | **Units cannot be checked:** completion is defined by delivered units, but the target has no delivered-units column (only booked `client_bookings.quantity`; units are a labelled line in `trip_deliveries.delivery_notes`). CC tracks units on every row. Whether Delivered / Returned count as completed is unconfirmed (CQ-5) | Existing Analytics section gates |
| Completion / cancellation / delay **rates** | Q Sec C table rows are named "…or … rate", but every formula given is a **total** ("Total completed trips", "Total cancelled trips", "Total delayed trips") | D | — | — | **Not computed** by the app | n/a | No client rate formula; none should be invented | — |
| Cancelled trips | Q5: "**Trip cancelled by client or due to operational issues.**" Q Sec C table: "Total cancelled trips — Cancelled trips" | **A** (count) | `trips` | `status` | `status = 'Cancelled'` | Static-verified | Client vs operational cause is not reported separately (CQ-6). Neither workbook marks cancelled trips explicitly | `operations.reports.view` |
| **Delayed trips** | Q5: "**Trip delayed due to pending documents or operational issues.**" Q Sec C table: "Total delayed trips — **Delayed trips and reasons**". Q6: scheduled vs actual time "**Hindi kinukumpara**" (not compared). Q8: client-caused delay currently "not counted as delayed" *(radio)*; wanted: "Record client-caused delays … clearly identifying they are not on our side". Overall Notes 3: record the reason for each delay. CC remarks include "PENDING DOCS" | **A** (definition) — app **D** | `trips`, `trip_updates` ('Delayed'), `trip_incidents` ('Delay') | reason | No delayed-trip metric. Active trips past scheduled arrival are labeled **Past ETA** only in operational alerts/filters | SQL static-verified, not live-database tested | **Not the client definition:** a past ETA is not a reported delay and has no client-reason attribution. The record mapping is unsettled (CQ-7) | Permission-gated operational trip views only |
| Late deliveries / on-time rate | Q Sec C table: on-time delivery rate "**W** (no formal computation) — **Walang consistent na record**". Q6: not compared. Q7: no on-time definition given. Q8: early arrival — car carrier judged by completion of all trips and units, container "depende sa client". CC: actual times only, as text like `1513H` without dates, on a minority of rows; **no scheduled times in any workbook** | **D** | `trips` | `scheduled_arrival`, arrival | Not shown as an analytics rate; active trips past scheduled arrival are separately labeled Past ETA | n/a | **Not a client metric and not supportable from client records** (CQ-8) | — |
| Delay duration | Q Sec C table: "Gaano katagal ang delay" *(radio: Hindi)*, W, "Walang consistent na record" | D | — | — | Not computed (average trip duration is a different measure) | n/a | — | — |
| Average trip duration | none | D | `trips` | `departure_time`, arrival | AVG minutes departure → arrival | Static-verified | Developer measure | `operations.reports.view` |
| Schedule changes | Q8: currently uses the new schedule *(radio)*; wanted: "**Keep both original and updated schedules**". Overall Notes 2 | B | `trips` | original + revised schedule | One `scheduled_departure` / `scheduled_arrival` | — | No original-schedule field; history only in workflow events, not reported (CQ-9) | — |

### Fleet

| Metric/report | Client evidence | Class. | Source records | Fields required | Implemented formula | Validation status | Known limitations | Authorized roles |
|---|---|---|---|---|---|---|---|---|
| Truck availability (now) | Q27 #3: "Truck availability — para malaman kung available o nasa motorpool". Q10: "Manual checking kung available, nasa motorpool, o puwedeng bumiyahe" | B | `trucks` | `status` | Stored status is shown in the Analytics record grouping and truck registry without inferred relabeling | SQL static-verified, not live-database tested | Live snapshot only; no historical availability or motorpool status | `fleet.view`; Analytics section gates |
| Down-truck days per month | **CT `TRUCK STATUS` (hidden): daily code per truck (`OK`, `DT`, `NT`, `-`); formula `COUNTIF(D4:AH4,"DT")` = "COUNTS OF DOWN TRUCK"** | **A** (formula) | daily truck status | per-day status | **Not implemented** (no daily status history) | Not validated | Meaning of `NT` and `-` unknown; sheet filled for Jan, two days in Feb, a few days in Mar (CQ-10) | — |
| Motorpool status | Q27 #3, Q10 (above) | D | `trucks` | motorpool value | None; nearest are `Under Maintenance` / `Unavailable` | — | No motorpool value in `trucks.status` (CQ-10) | — |
| Fleet utilization (% of trucks with ≥ 1 trip) | Q9: "Oo, pero hindi formal" *(radio)*; looks at number of trips, deployed days, available days, idle days, maintenance / downtime days; "**Walang formal computation**"; most useful: number of trips, idle days, deployed days | **D** | `trips`, `trucks` | — | Not calculated or displayed | n/a | **Not the client's measure.** The client uses days (deployed, idle, down). The app has no daily deployment record (CQ-11) | — |
| Fuel per truck | Q10 table: no computation, "records of fuel per truck", "SOA from Shell / Petron" | B | `expense_records` | truck link | Not reported per truck | — | Expense records are trip-referenced, not truck-referenced | — |
| Incidents per truck / driver | Q10 table: "M — Walang computation pero may records per truck per drive ang incidents — Incident reports". CC `TRIPS & DAMAGES` has cause, action taken, damaged unit, VIN columns | B | `trip_incidents` | trip → truck / driver | No trend analytics; operational open-incident records remain permission-gated | n/a | No per-truck / per-driver incident report | Existing incident permissions |

### Waybills and records

| Metric/report | Client evidence | Class. | Source records | Fields required | Implemented formula | Validation status | Known limitations | Authorized roles |
|---|---|---|---|---|---|---|---|---|
| Waybill submission / missing waybill | Q27 #4: "Waybill submission — para ma-verify ang completed trips at billing — **Follow up missing waybills**". Q26 #1 rule: "**if hindi naka pag submit sa cut off date**". Q15: outstanding counted "**15 days or 30 days after masubmit waybill**". CC: WAYBILL NO. per trip (blank on Jan 409/412, Feb 32, Mar 0, Apr 25, May 5, Jun 29, Jul 10 rows). CT: RPM waybill no. per trip (0 blank in 2026 lists) | B | `trip_deliveries`, `documents`, `document_links` | `waybill_number`, Waybill file, **submission date** | Not reported as a dated submission or missing-waybill metric | n/a | **No cut-off date** is defined, and the app stores no waybill **submission** date (only record / upload timestamps). The client's billing clock starts at submission (CQ-12) | — |
| Record completeness | Q26 #4 "Proper Records and Document Monitoring": "All records must be complete, updated, and organized"; formula "Not applicable"; by Department / Document type / Date | B | all | — | Only missing waybills are reported | — | No general completeness report (CQ-13) | — |

### Maintenance

| Metric/report | Client evidence | Class. | Source records | Fields required | Implemented formula | Validation status | Known limitations | Authorized roles |
|---|---|---|---|---|---|---|---|---|
| Maintenance expenses per truck | Q26 #3: name and formula "**Total maintenance expenses per truck**"; information "**Parts, labor, repair expenses**"; source Motorpool logbook, Accounting records; wanted **Monthly / Custom date**, per **Truck**; seen by Motorpool / Admin / Management. Q19: per-truck cost not monitored today ("Walang formal computation"); parts and labor included; outsourced shop not ticked | **A** | `maintenance_records`, `trucks` | `labor_cost`, `parts_cost`, `other_cost`, `total_cost`, dates | Analytics groups recorded maintenance costs by truck, dated by completion, start, scheduled date, then record creation date. Maintenance Reports redirects to Analytics | SQL static-verified. **Not validated against client data:** no maintenance records were provided | The record form stores its single cost in `other_cost`, so **labor is not separated**; parts reach `parts_cost` only through stock-out. The displayed value is a system-recorded cost, not client-reconciled accounting | Existing Analytics section gates |

### Documents

| Metric/report | Client evidence | Class. | Source records | Fields required | Implemented formula | Validation status | Known limitations | Authorized roles |
|---|---|---|---|---|---|---|---|---|
| Document expiry and renewal | Q26 #2 "Admin Monitoring": "All renewal ng permits, OR/CR, insurance, at other permits required ng client"; formula **"Expiry date − current date"**; information "Document type, expiry date, renewal status"; daily; per Truck / Expiry date; Admin / Management. Q21: OR/CR, Insurance, Permit / franchise, **Gate / Entry Pass & other passes required by client**, renewed by Admin / assigned staff, **alert 365 days** before expiry for each. CC `OFFICIAL RECEIPT REGISTRATION` (hidden): 97 units (tractor heads, trailer chassis, car carriers, wing vans, service vehicles) with REGISTRATION EXPIRATION and manual status **FOR RENEWAL (58) / UPDATED (39)**; no formula | **A** | `documents`, `truck_documents`, `employees` | `expiry_date`, document type, **renewal status** | `daysUntilExpiry()` = expiry date − today in whole days. **Positive = days left, 0 = expires today, negative = days past expiry** (same sign as `DATEDIFF(expiry, CURDATE())`). Amber within 30 days, red when negative; reminders at 60 / 30 / 15 / 7 days | Helper unit-tested. **Replayed on client records:** all 97 dates evaluated (see validation report) | **Alert lead mismatch:** the client answered 365 days, the app uses 30 (screen) and 60 / 30 / 15 / 7 (reminders) (CQ-14). **No renewal status** column. Trailer chassis are registered separately but are not trucks in the app. All 39 "UPDATED" rows still carry a past expiry date, so imported dates would show as expired unless updated (CQ-15) | Documents `documents.view`; Fleet Status `fleet.view` |

### Finance and payroll

| Metric/report | Client evidence | Class. | Source records | Fields required | Implemented formula | Validation status | Known limitations | Authorized roles |
|---|---|---|---|---|---|---|---|---|
| Revenue | Q16: "Amount na na-invoice o na-bill" *(radio opt1)*; "Based sa billing records ng external accounting." Q13: billed, collections and outstanding monitored | **A** (definition) | `billings` | `total_amount`, `billing_date` | Analytics shows date-based recorded billed totals; Billing retains individual billing records | SQL static-verified; no client billing records available | External accounting is the client's source of truth, so app totals cover only billings entered in the app | Existing financial section gates |
| Billing amount | Q14: fixed rate per trip, distance, contract rate per client; "May existing formula — Excel"; DR or waybill used | C | `client_rates` | basis, rate | Route / Distance / Fixed rate suggestion | Static-verified | Client formula not provided (only the trips-per-week line) | `billing.manage` |
| Outstanding / overdue | Q15: outstanding when "**15 days or 30 days after masubmit waybill**" per contract; computed from billed amount; payment terms vary per client (15 / 30) | **A** (rule) — app partial | `billings`, `collections` | waybill submission date, client terms | Billing shows each recorded balance and due date; Analytics does not calculate aging or overdue status | SQL static-verified | No client payment-terms field and no waybill submission date, so the client rule cannot be applied automatically (CQ-12) | `billing.view` |
| Billed vs collected / collection rate | Q15: billed vs collected compared *(radio opt1)*. No rate formula | C | `billings`, `collections` | — | Analytics shows billed and collected totals separately; no collection rate is calculated | SQL static-verified | Rate formula not client-provided; the date windows are separate billing-date and payment-date cohorts | Existing financial section gates |
| Profit / recorded margin | Q17: profit computed *(radio opt1)*, "Based sa external Accounting"; **income "Trucking revenue / total billed amount"; expenses "All Expenses"**; viewed per client, weekly, monthly. Expenses monitored: Fuel, Maintenance, Parts, Trip allowance / fund, Payroll, **RFID** | **A** (structure) — app C | billings and recorded costs | all expenses | Not displayed as an analytics or billing/trip-cost calculation | n/a | The app holds only part of "all expenses" (no RFID category; accounting entries outside the app). No per-client margin (CQ-16) | — |
| Operating expenses | Q17 list above | B | `expense_records` | type | Fuel, Toll*, Driver Allowance*, Operating, Other (*draft T-F1) | Static-verified | RFID not a named type (CQ-16) | `finance.view` |
| Budget variance | none | D | `budgets` (draft T-B1) | — | actual − budget for the budget months | Static-verified | No client evidence | Admin; Finance |
| Trip Pay | Q23: per destination and per trip; driver and helper "parehas ng computation pero iba ang rate"; "**Fixed trip pay rate per destination × number of trips**"; based on the driver-submitted summary. PR: weekly sheets have **Daily Rate × attendance days = regular wage**, a typed **TOTAL NO. OF TRIPS** and a typed **TOTAL ALLOWANCES** column; no per-destination formula | **A** (rule) — PR unclear | `trip_pay_records` | rate per destination, trips | Amount entered per trip per crew member | Static-verified | PR shows no separate trip-pay column. Whether trip pay sits inside "Total allowances" is unknown (CQ-17) | `payroll.manage` |
| Payroll totals | Q22: payroll cutoff every 15 days *(radio, office staff)*; Q1: drivers / contractors weekly, admins twice a month. Q24: net = "**Basic pay + OT − deductions**". PR: gross = SUM(regular wage, allowances, trade test, manual pullout, adjustment); net = gross − total deductions | **A** | `payroll_records` | — | Billing displays individual authorized payroll / Trip Pay records; no payroll aggregate is shown on this page or Analytics | SQL static-verified | Payroll formula and periods vary by employee group | Billing tab: `payroll.view` or `finance.view`; recording remains `payroll.manage` |

### Rule-based insights and recommendations

Fixed-threshold and trend-based analytical recommendations are not shown.
Legacy helper functions under `analytics/` still contain former developer
rules and are only called by `getAnalyticsAlerts()` in `includes/alerts.php`;
that orchestrator has no application page callers. Operational Past ETA,
maintenance-due, stock-level, approval, and incident alerts are separate
record-backed workflows with their existing permissions. No metric threshold
was introduced by this refactor.

## Report visibility (Q1, Q26)

| Report | Who the client says reviews it | App permission today | Gap |
|---|---|---|---|
| Trip reports | Operations Head / Management (Q1); + Dispatcher (Q26 #1) | Analytics sections are gated by existing `reports.view`, `operations.reports.view`, and role scope; Operations Performance checks `operations.reports.view` before redirecting | Existing grants remain unchanged (OD-1) |
| Truck / fleet reports | Operations Head / Management | `fleet.view` for Fleet Status; Analytics fleet section additionally requires its existing report and fleet permissions | — |
| Maintenance reports / cost per truck | Operations Head / Purchasing Office / Management (Q1); Motorpool / Admin / Management (Q26 #3) | Analytics section is gated by `maintenance.reports.view`; Maintenance Reports checks this permission before redirecting | Existing grants remain unchanged (OD-2) |
| Billing / collection reports | Billing & Collection Officer / Audit / Management | `billing.view`: Finance, Billing & Collection, Admin | Audit (OD-3) |
| Expense reports | Finance / Audit / Management | `finance.view`: Finance, Admin | Audit (OD-3) |
| Payroll reports | Finance / Audit / Management | Billing-page payroll / Trip Pay records require `payroll.view` or `finance.view`; recording requires `payroll.manage` | Audit (OD-3); individual pay visibility remains governed by existing permissions |
| Document expiry ("Admin Monitoring") | Admin / Management; Admin / HR (Q1 "Iba") | `documents.view` (most roles); `fleet.view` | Wider than requested; expiry dates are not sensitive |

## Analytics-page visualization contracts

These contracts describe only visualizations backed by fields and rules already
used by `pages/analytics.php`. Each chart retains its corresponding table so
exact values remain available without Chart.js. Date ranges are start-inclusive
and end-exclusive. No visualization below establishes a client-unconfirmed
business definition.

| Visualization / business purpose | Formula and source fields | Filters and reporting-date basis | Roles permitted | Limitations | Chart type and reason |
|---|---|---|---|---|---|
| Trips by recorded actual departure — monitor recorded trip volume by period, client, and truck | `COUNT(DISTINCT trips.trip_id)`; `trips.trip_id`, `trips.departure_time`; client breakdown additionally uses `trips.client_id`, `clients.client_name`; truck breakdown additionally uses `trips.truck_id`, `trucks.plate_number` | Selected period filters `trips.departure_time`; daily / Monday-start weekly / monthly buckets use that same field. Dispatcher results additionally use the existing submitted-by, truck-type, and shift scope. Client and truck charts show the top 8 by count; accompanying tables show the full breakdown. | Admin; users with `operations.reports.view`; or existing `reports.view` grant for the permitted operations-head / dispatcher roles. Dispatcher output is scoped by the page's existing dispatcher rules. | Proposed actual-departure basis, not the client's confirmed weekly waybill-submission basis. No actual departure means a trip is not in this count. Only one modeled client level exists; it may not represent company and end-client separately. Driver attribution, units, and cancellation causes are not inferred. | Bar for temporal and top-10 category comparisons; it makes count differences legible without combining unlike trip records. |
| Current trip-status breakdown — distinguish recorded workflow statuses, including pending and cancelled | `COUNT(DISTINCT trips.trip_id)` grouped by `trips.status`; one trip status per trip | Selected period filters `trips.scheduled_departure`; counts use the current `trips.status`, not a historical snapshot. Actual status values remain distinct and are not combined. Same operation and dispatcher scope as the trip-volume visualization. | Same operational roles and dispatcher scope as the trip-volume visualization. | Shows current statuses for trips scheduled in the selected period, not statuses as of that period. `Delivered`, `Returned`, or `Completed` does not verify required unit completion. | Doughnut chart for composition by distinct stored status; the adjacent table preserves exact status totals. |
| Current fleet by stored status — show the fleet registry's current status mix | `COUNT(trucks.truck_id)` grouped by `trucks.status` | Current snapshot; no reporting-period date. Existing dispatcher truck-type scope applies where relevant. | Admin; or the existing operations-view and `fleet.view` gates on the Analytics page. | Current status only; not utilization, truck-days, historical status, downtime, or inferred meanings for undocumented codes. | Doughnut chart for current status composition; the adjacent table preserves exact status totals. |
| Recorded billings and collections — show separate recorded financial totals | Billings: `SUM(billings.total_amount)` for statuses accepted by `billingRevenueSql()`; collections: `SUM(collections.amount)`. Identifiers and dates are `billings.billing_id`, `billings.billing_date`, `collections.collection_id`, and `collections.payment_date`. | Billings filter on `billing_date` and eligible statuses (Issued, Partially Paid, Paid, Overdue); collections filter on `payment_date`. Collection rows have no status field in the target schema. | Admin; or users with `billing.view`. | Two independent date-based totals; they are not allocated against the same invoices, and are not cash flow reconciliation, collection rate, receivables, or profit. A missing total is displayed as unavailable, not zero. | Paired bars show the two separately labeled period totals; their distinct date bases are stated next to the chart. |
| Recorded maintenance cost by truck — inspect entered maintenance spending | `SUM(maintenance_records.total_cost)` grouped by truck; `maintenance_records.record_id`, `truck_id`, `total_cost`, `status`, and the shared maintenance date expression (`actual_completion_date`, then `start_date`, `scheduled_date`, then `DATE(created_at)`); truck label from `trucks.plate_number` | Selected period on the shared fallback date expression; excludes `status = 'Cancelled'`. Chart shows the top 8 trucks with a non-null summed cost; full per-truck record counts and amounts remain in the table. | Admin; or users with `maintenance.reports.view`. | Recorded system cost only. Missing amounts are not plotted or treated as zero; maintenance records may be incomplete and costs are not reconciled to external records. Parts purchases are not added separately, avoiding double-counting parts already represented by the maintenance total. No downtime is inferred. | Bar for comparing recorded cost across trucks; full table retains the associated record counts and missing-amount indication. |

### Department layout and additional visuals

The page is grouped by department (Operations, Accounting, Maintenance), each
shown only to users holding that department's permission (same gates as above).
Every department shows KPI cards first, then charts, then a collapsible
"Detailed records" table set, then a "Not shown" note. Every chart card has a
"How this is calculated" panel (formula, source, date basis, limits). The
billed-vs-collected and maintenance-cost trends are zero-filled per bucket.

| Visual | Formula and source | Date basis | Roles |
|---|---|---|---|
| Billed amount by status (doughnut) | `SUM(billings.total_amount)` by `billings.status`, eligible statuses only | `billing_date` | `billing.view` / Admin |
| Top clients by billed amount (bar, top 8) | `SUM(total_amount)` by `clients.client_name` via `billings.client_id` | `billing_date` | `billing.view` / Admin |
| Collections by payment method (doughnut) | `SUM(collections.amount)` by `payment_method` | `payment_date` | `billing.view` / Admin |
| Maintenance cost over time (bar) | `SUM(maintenance_records.total_cost)` per bucket, excluding Cancelled | Shared maintenance date expression | `maintenance.reports.view` / Admin |
| Cost by maintenance type (doughnut) | `SUM(total_cost)` by `maintenance_type`, excluding Cancelled | Shared maintenance date expression | `maintenance.reports.view` / Admin |
| Maintenance records by status (doughnut) | `COUNT(*)` by `status`; Cancelled shown as its own slice | Shared maintenance date expression | `maintenance.reports.view` / Admin |

KPI "Overdue", "Completed", "In Progress" and "Waiting for Parts" are literal
stored statuses; no aging, open-work-order, or completion rule is inferred.

Active-trip counts, reported-delay totals, delivered-unit completion, fleet
utilization / truck-days, open work orders, downtime, allocated receivables,
overdue balances, collection rates, expense categories, and margin remain
unavailable on this page unless their required schema fields and client
definitions are validated. In particular, a past ETA is not counted as a
recorded delay; billing and collections are not presented as matched amounts;
and current truck status is not converted into utilization.
