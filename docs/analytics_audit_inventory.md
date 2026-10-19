# Analytics audit inventory

Pre-change inventory for the client-requirements refactor. Existing metric
definitions and known data gaps are documented in
[`analytics_metric_traceability.md`](analytics_metric_traceability.md).
Permission names below are enforced by the page or by the existing
permission-gated query; role names indicate current role paths, not new grants.

| Metric / analytical content | Current location and access | Database inputs; calculation and date basis | Decision |
|---|---|---|---|
| Trip totals, status counts, completion and trend | Analytics (`reports.view`: Admin, Operations Head, dispatchers, Maintenance, Finance / Billing & Collection as currently configured); Operations Performance (`operations.reports.view`: Admin, Operations Head); Admin, Operations Head and Dispatcher dashboards; generic Home | `trips.status`, `scheduled_departure`, `created_at`; counts/status groups or delivered status, trend by scheduled/created date. Client workbook uses actual departure in Container records; Car Carrier `DATE` is unlabeled; weekly waybill basis is unresolved. | Revise: keep only individually labeled system-status counts with a clearly stated date; don't combine pending/cancelled with completed. Flag actual reporting date and completion-by-units unresolved. Remove generic trip trend. |
| Delayed / late / past-ETA counts and on-time rate | Analytics; Operations Performance; Admin, Operations Head, Dispatcher and Home dashboards; trip monitor | `trips.scheduled_arrival`, actual arrival/completion fields; past expected arrival or scheduled-vs-actual comparison. A past ETA is not a client-reported delay; the client requires a recorded reason. | Revise past-ETA conditions to alerts named `Past ETA`; remove delay and on-time performance metrics unless client-reported reasons and an approved formula exist. |
| Trips by client, truck, driver and dispatcher; top trucks/drivers | Analytics and Operations Performance; Operations Head/Admin report permissions | `trips.client_id`, `truck_id`, `driver_id`, `dispatch_requests.submitted_by`, statuses and scheduled dates; grouped counts, delivered counts or rankings. Only one client level and primary driver are modeled; driver/helper attribution is unresolved. | Keep supported counts only with qualified labels; remove performance/ranking claims. Flag company/client level, second driver and helper attribution. |
| Waybill coverage / missing waybills | Operations Performance and trip records; Operations (`operations.reports.view`) | `trip_deliveries.waybill_number` and linked documents; presence of a number/file, not a submission timestamp. No cutoff is defined. | Revise to “waybill reference recorded” only if retained; do not call it submitted, overdue, or a dated event. Flag actual submission-date/cutoff reporting. |
| Fleet status, availability and utilization | Analytics; Admin and Operations Head dashboards; Dispatcher dashboard; fleet status page | `trucks.status`, `truck_type`; utilization is distinct trucks with a trip divided by current fleet size. No reliable historical truck-day statuses. | Keep current stored status labels where access permits; remove utilization percentages and inferred deployed/idle/down days. Do not map unknown client codes or motorpool status. |
| Revenue, collections, collection rate and receivables | Analytics (financial views); Billing dashboard; Admin dashboard; Billing page | `billings.total_amount`, `billing_date`, `due_date`, `status`; `collections.amount`, `payment_date`; sums, cohort balances, rates and overdue conditions. Payment terms vary and client billing basis is unresolved. | Keep only actual recorded billed/collected values with date and source labels; do not convert absent records to zero. Remove collection-rate and overdue analytics unless terms/due/payment data meet the approved rule. |
| Expenses, maintenance costs and margin/profit | Analytics; Accounting/Admin dashboards; Billing and Trip Costs pages; Maintenance Reports | `expense_records.amount/expense_type/expense_date`, `maintenance_records.total_cost` and record dates, payroll and Trip Pay records; sums or billed-minus-recorded-cost. Official profit is external; cost categories and overlap need validation. | Keep actual maintenance records as recorded cost where authorized. Remove margin/profit summaries unless specifically approved; avoid duplicate parts expenses. Do not imply official accounting profit. |
| Maintenance work orders, downtime and repair duration | Maintenance Reports; Maintenance dashboard; Analytics | `maintenance_records.created_at`, `closed_at`, `status`, `total_cost`; elapsed time from open to close/current time and status counts. Client maintenance samples are unavailable. | Flag downtime / repair duration pending records and client definitions; retain only operational work-order lists, not analytical duration claims, until validated. |
| Maintenance type and cost trends / cost by truck | Analytics; Maintenance Reports; Maintenance dashboard | `maintenance_records.maintenance_type`, `total_cost`, truck ID and maintenance date; period sums/groups. | Move supported recorded-cost breakdowns to Analytics for authorized roles; label as recorded maintenance cost and use its actual record date. |
| Fuel, RFID, cost-per-km, budget variance, revenue-per-trip | Analytics; Trip Costs page | `expense_records`, route distance, billing and generic expense fields; ratios or category totals. Fuel statements, RFID inclusion, expense methodology and client-required formulas are unverified. | Remove unsupported analysis; flag RFID and fuel inputs. Trip-level entered records remain operational source data, not validated financial analytics. |
| Incidents and incident trends | Analytics; Admin and Operations Head dashboards; Maintenance dashboard | `trip_incidents.incident_date`, `resolved_at`, `incident_type`, `severity`; counts and time series. Client requirements do not establish incident analytics as a KPI. | Keep actionable open-incident alerts on operational dashboards only where authorized; remove incident trend analytics. |
| Fixed-threshold recommendations and analytics alerts | Analytics; Home and Admin/department dashboards | Derived from utilization, collection rate, on-time rate, incident and maintenance counts; hard-coded thresholds / recommendation rules. | Remove unsupported thresholds and recommendations; retain actionable alerts only when backed by current reliable records and role access. |
| Expiring documents | Documents, Fleet Status and reminder notifications | Document expiry fields and `daysUntilExpiry`; remaining days / configured alert windows. Client's 365-day value may be renewal frequency, not warning threshold. | Keep current document records and established reminder configuration; flag threshold interpretation. Do not add expiry analytics or change reminder windows based on 365 alone. |
| Personal activity / dashboard summaries | Generic Home, Admin and department dashboards | Audit logs, dispatch requests, trips, billing, payroll, maintenance and inventory records; counts over recent periods or latest rows. | Remove analytical summaries from Home and move any retained analytics to Analytics. Keep only Home Alerts and Active Trips; preserve task queues on role workspaces where they are operational rather than analytical. |

## Surfaces checked

- `pages/analytics.php` and `assets/js/analytics.js`
- `pages/dashboard.php`, `dashboard_head.php`,
  `dashboard_operations_head.php`, `dashboard_dispatcher.php`,
  `dashboard_accounting.php`, and `dashboard_maintenance.php`
- `pages/operations_performance.php`, `maintenance_reports.php`,
  `billing.php`, `trip_costs.php`, `fleet_status.php`, and document expiry
  surfaces
- Shared query/definition files `includes/metrics.php`,
  `includes/dashboard_metrics.php`, `includes/finance_records.php`,
  `includes/analytics_period.php`, and role/permission checks in
  `includes/session.php`

The role paths are based on current server-side checks: `reports.view`,
`operations.reports.view`, `maintenance.reports.view`, billing/finance view
permissions, dashboard permissions, and dispatcher scoping. Unresolved
permissions remain unchanged; this inventory does not authorize new access.

## Refactor outcome

- `pages/analytics.php` is the consolidated destination for supported,
  permission-gated record summaries. Date bases and provisional definitions
  are labeled; unsupported percentages, utilization, delivery claims, and
  inferred waybill events are omitted.
- Home now contains only permission-scoped Alerts and Active Trips. The Admin,
  Operations Head, Dispatcher, Maintenance, and Accounting dashboards retain
  operational lists, not analytics cards or charts.
- Operations Performance and Maintenance Reports preserve their existing
  permission checks, then redirect to Analytics.
- Billing retains billings, collection records, and permission-gated
  disbursement records. It no longer calculates summary totals, receivables
  aging, overdue-by-client, or expenses/margin. Due dates remain displayed as
  record fields, without a derived overdue label.
- Trip Costs retains per-trip expense records and separately identified Trip
  Pay. Revenue, recorded margin, and cost-per-kilometer calculations have been
  removed.
- Trip Monitoring retains trip-level workflows and an active-trip Past ETA
  filter; trip-count, completed-count, and late-count cards have been removed.
- Fleet Status uses the database's stored status values in its filters and
  registry. Deployed/Inactive remapping and aggregate status cards have been
  removed.
- Static SQL capture now loads the dispatcher-scope test stub for Analytics;
  redirect-only report pages are excluded from SQL capture. Billing-period
  checks cover the remaining billing, collections, payroll, and Trip Pay
  queries and guard against reinstating the removed summary/aging calculations.

No database schema, permission grant, or client definition was added. Static
and unit checks do not substitute for live role/browser/database validation.
