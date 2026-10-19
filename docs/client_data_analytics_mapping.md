# Client data → analytics mapping

Mulawin FleetOps — Fleet Management System with Accounting and Inventory
Management for RP Mulawin Trucking Services. Revalidated 2026-10-15 against
the actual client sources:

- **Q** — `Mulawin_FleetOps_Client_Questionnaire-2.pdf` (filled in; Sections B–H)
- **CC** — `CAR CARRIER MONITORING 2026 (1).xlsx`
- **CT** — `CONTAINER MONITORING  2026 (1).xlsx`
- **PR** — `DRIVERS PAYROLL.xlsx` (structure and formulas only)

All are in `C:\Users\acer\Downloads`. Unavailable sources (maintenance,
billing, expense, accounting records; Section I of the questionnaire) are
listed in
[`analytics_data_gaps_and_client_questions.md`](analytics_data_gaps_and_client_questions.md).
Per-metric formulas, classifications and roles are in
[`analytics_metric_traceability.md`](analytics_metric_traceability.md).
Reconciliation results are in
[`testing/analytics_validation_report.md`](testing/analytics_validation_report.md).

## The client's priorities

Q27, the five things management wants to see daily, in the client's order:
1. Total trips per day
2. Trip status (completed, cancelled, delayed)
3. Truck availability (available or in the motorpool)
4. Waybill submission (to verify completed trips and billing; follow up missing waybills)
5. Document expiry monitoring

Q26, the computations the client named: (1) Total trips per week / client /
truck / driver; (2) Admin monitoring of renewals, formula `Expiry date −
current date`; (3) Total maintenance expenses per truck (parts, labor,
repair); (4) Proper records and document monitoring.

Q1: trip, fleet, maintenance, billing / collection, expense and payroll
reports are all used now (Excel and paper), all ranked 1 = Very Important.

## Coverage against client sources

| Client need | Client records | App screen | Coverage |
|---|---|---|---|
| Total trips per day / week / month / custom | CC: one row per trip, weekly / monthly summaries by company → client. CT: trip lists by actual departure date, weekly / monthly pivots | Operations Performance (custom range); Analytics (day / week / month); dashboards | **Counting rule and Monday weeks reproduced** from client rows. **Open:** date basis (scheduled vs actual departure vs waybill submission), company → client grouping, units |
| Trips per client / truck / driver / dispatcher | CC / CT company, client, plate, Driver 1 + Driver 2 (CC), driver + helper (CT) | Operations Performance tables | Truck: yes. Client: one level only. **Driver: second driver not credited.** Dispatcher: yes (no client data to compare) |
| Trip status: completed, cancelled, delayed | Q5 definitions; CC remarks such as "PENDING DOCS"; no status column in CC / CT | Operations Performance, dashboards | Completed: status-based (units not stored). Cancelled: yes. **Delayed: app definition (time) contradicts the client's (reported reason)** |
| Truck availability / motorpool | CT `TRUCK STATUS` daily codes, `COUNTIF(…,"DT")` down-truck days | Fleet Status, dashboard fleet counts | Live availability: yes. **Down-truck days and motorpool state: not available** |
| Waybill submission / missing | CC waybill per trip (Jan mostly blank); CT RPM waybill per trip | Operations Performance (count + per-trip status) | Missing-waybill count: yes. **Cut-off and submission date: not available** |
| Document expiry | CC registration list (97 units, expiry date, FOR RENEWAL / UPDATED) | Documents, Fleet Status, Users (licences), reminders | Formula matches the client's (`daysUntilExpiry`). **Alert lead (client: 365 days) and renewal status differ** |
| Maintenance expenses per truck | Not provided (motorpool logbook / accounting) | Maintenance Reports (any range, per truck) | Formula matches (parts + labor + other). **Not compared to client data.** Labor not entered separately |
| Revenue, outstanding, profit | Not provided (external accounting) | Billing, Analytics, accounting dashboard | Definitions match in structure (revenue = billed; profit = billed − all expenses). The app's margin covers only recorded costs, as labelled |
| Trip Pay / payroll | PR weekly payroll; Q23 trip pay rule | Trip Pay, Payroll, Billing (payroll tab) | Trip Pay amount per trip per person; how the client's sheet carries trip pay is unclear (CQ-17) |

## Date fields in the client sources

| Source | Date used | Event |
|---|---|---|
| CC trip lists | DATE (written once per day, carried down) | Not labelled (dispatch / loading assumed, unconfirmed) |
| CT CJ Smart lists and all CT pivots | ACTUAL DEPARTURE DATE | Actual departure |
| CT TARA grid, TRUCK STATUS | Calendar day columns | Day of the trip / status |
| Q14 | Waybill submission week | Waybill submitted by Operations |
| Q15 | Waybill submission + 15 / 30 days | Billing due |
| CC registration list | Registration expiration | Document expiry |

The application dates trips by `trips.scheduled_departure`. Actual departure
(`trips.departure_time`) exists but is not used for counts (CQ-1).

## Metrics the client did not ask for

On-time rate, fleet utilization (as % of trucks), average trip duration,
collection rate, average revenue per trip, budget variance and all insight /
recommendation thresholds have no client source. Utilization and on-time
**conflict** with how the client works (days-based utilization; no time
comparison). They remain existing features, labelled where shown, classed D.
