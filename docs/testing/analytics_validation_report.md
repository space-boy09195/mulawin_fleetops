# Analytics validation report

Analytics verification pass, 2026-10-15. Branch `system-hardening`, HEAD
`be5452194c0baeae4db1c5cec03bfed0995481b6`, uncommitted working tree.
No database was connected, no SQL was executed, `.env` was not read.

Source codes (Q, CC, CT, PR) are defined in
[`../analytics_metric_traceability.md`](../analytics_metric_traceability.md).
Client files were read in place from `C:\Users\acer\Downloads`. Their XML was
extracted to a session scratch folder (outside the repository), and the
probing scripts live there too. **No client data was copied into the
repository.** This report gives counts and structures only.

## 1. Source reliability (workbooks)

### 1.1 Car Carrier (CC)

| Sheet | Kind | Use for counts? | Notes |
|---|---|---|---|
| `SUMMARY OF TRIPS ( JAN.2026)` … `(JUL. 2026)` (all hidden) | **Transactions** — one row per trip | **Yes** | Columns: DATE, NO, PLATE NO., DRIVER 1, DRIVER 2, COMPANY NAME, CLIENT, TRP, WAYBILL NO., UNTS, PICKUP / DELIVERY POINT, REMARKS. Header totals `SUM(TRP)`, `SUM(UNITS)` |
| `SUMMARY OF TRIPS (SEPT. 2026)` | Transactions | Yes, **as August** | Holds 12 rows dated **27–28 Aug 2026**. No sheet labelled August exists |
| `Sheet13` (hidden) | **Consolidated copy** of Jan–Jun rows (plus 1 row dated Oct) with time columns | **No — duplicates** | 2,017 rows share date + plate + waybill + driver with the monthly sheets. Adding it would double-count |
| `Detail2-FUJITRANS -URVAN`, `Detail3-MDEX` (hidden) | Per-company extracts of January | **No — duplicates** | 42 and 58 rows; subsets of the January sheet |
| `SUMMARY PER MONTH`, `TRIPS SUMMARY PER WEEK`, `SUMMARY PER TRUCKMONTH` (hidden), `Sheet14` (hidden), `SUMMARY OF TRIPS (UNITS & TRIPS` | **Summaries** with typed values (no formulas, except an `IFERROR(FILTER…)` sum in the daily matrix) | No (compare only) | Grouped by COMPANY then CLIENT, units and trips per week |
| `TRIPS & DAMAGES`, `CLIENT CAR CARRIER` | Lookups (route by vehicle model; per-week company / client sample) and damage log columns | No | `CLIENT CAR CARRIER` also holds unrelated product price rows |
| `OFFICIAL RECEIPT REGISTRATION` (hidden) | Registration expiry list (97 units) | For expiry only | Status typed: FOR RENEWAL 58, UPDATED 39 |

Data quality, transaction sheets (counts):

| Month sheet | Trip rows | SUM(TRP) | Units | Blank waybill | Driver 2 filled | No plate | Rows with no date (carried from above) | `units/trips` text in DATE column |
|---|---|---|---|---|---|---|---|---|
| Jan | 412 | 412 | 1,814 | **409** | 408 | 0 | 368 | 43 |
| Feb | 475 | 475 | 2,076 | 32 | 475 | 0 | 437 | 27 |
| Mar | 401 | 401 | 1,776 | 0 | 395 | 6 | 352 | 42 |
| Apr | 396 | 396 | 1,494 | 25 | 367 | 29 | 359 | 31 |
| May | 357 | 357 | 1,494 | 5 | 353 | 4 | 2 | 0 |
| Jun | 587 | 587 | 2,310 | 29 | 571 | 14 | 527 | 34 |
| Jul | 729 | **722** (7 rows with blank TRP) | 2,857 | 10 | 703 | 9 | 680 | 40 |
| "Sept" (Aug 27–28) | 12 | 12 | 59 | 0 | 12 | 0 | 9 | 3 |

Observations:
- **Every non-blank TRP is 1**: a trip row is one trip, and trips = rows (July
  has 7 trip-like rows with no TRP).
- **Dates are written once per day.** Following rows inherit the date, and the
  second row of a day block often holds a typed day subtotal such as `95/26`
  (units / trips) in the DATE column. An import must carry dates down and
  ignore these subtotals.
- Waybill numbers are mostly recorded from February. **January has waybills on
  only 3 of 412 trips.** Waybill numbers repeat within a sheet only 2 times
  (Feb).
- Time-of-departure / arrival values exist only in `Sheet13` and a few March
  rows, as text like `1513H` (no date). There are **no scheduled times**
  anywhere.
- The date column is not labelled with an event. Whether it is the dispatch,
  loading or completion date is unknown (CQ-1).
- Typed summaries mostly agree with the transactions: January `SUMMARY PER
  MONTH` grand total 1,814 / 412 matches exactly; `Sheet14` matches Feb–Apr.
  **Exceptions:** `Sheet14` June shows 249 trips vs 587 transaction rows
  (stale), and the daily matrix January total 1,758 units / 413 trips differs
  from 1,814 / 412.

### 1.2 Container (CT)

| Sheet | Kind | Notes |
|---|---|---|
| `CJ SMART (JAN-APR)` (hidden), `CJ SMART (MAY-AUG)` | **Transactions** (Coca-Cola via CJ Smart): RPM waybill no., plate (empty), driver, helper, pickup, destination, loading / unloading times, **ACTUAL DEPARTURE DATE**, shipment no., backload, pallets | 300 + 13 rows. 0 blank waybills, 0 duplicate waybills or shipment numbers within a sheet; 2 numbers appear in two sheets. Time values on 75 of 300 rows. Dates Dec 2025 – May 2026 |
| `CJ SMART 2025` (hidden) | 2025 archive | Has the client's duplicate check `IF(COUNTIF(H:H,H2)>1,"Duplicate","Unique")` on shipment number |
| `MONTH SUMMARY`, `WEEKLY SUMMARY`, `PER WEEK CONTAINER SUMMARY` (hidden) | **Pivot summaries** by destination, filtered on **ACTUAL DEPARTURE DATE** | Company / client blocks: CJ Smart / Coca-Cola, Secured Logistics / Kopiko, JMSJ / Monde |
| `TARA CONTAINER`, `TARA CONTAINER -2` (hidden) | **Truck × day grid**; each cell is a destination (one trip) | Two rows per truck (driver row, helper row) with the same destinations. The client's formulas count PAMPANGA on row 1 and a fixed list of "other" destinations on row 2: `COUNTIF(…,"BULACAN")+COUNTIF(…,"NUEVA ECIJA")+COUNTIF(…,"PASIG")+COUNTIF(…,"MANGGAHAN")+COUNTIF(…,"BATINO")+COUNTIF(…,"DURANT")`. **MONDE (8 cells), CAVITE (4) and PARAÑAQUE (4) are not in that list**, so those trips are left out of the client's weekly total (CQ-18). January uses codes KP / KD / KB (meaning unknown) |
| `TRUCK STATUS` (hidden) | Daily status per truck: `OK`, `DT`, `NT`, `-`; `COUNTIF(D4:AH4,"DT")` = down-truck days | Two units share plate `CCE3646`; one plate is stored as the number 44808. The sheet also holds unrelated personal notes (not reproduced) |
| `Client 1`, `Client 2`, `KOPIKO PLAN`, `Client & Destination` | Client-specific lists and a destination master | `Client & Destination` lookups return `#REF!` in the CJ sheets |

### 1.3 Payroll (PR)

Structure only. Weekly sheets per fleet: Daily Rate, Attendance, Total No. of
Days, **Total No. of Trips (typed)**, Total Regular Wage = **Daily Rate × Days**,
Total Allowances (typed), Trade Test, Manual Pullout, Adjustment, Cash Advance,
Gross = SUM(wage … adjustment), deductions (SSS = 775 / 24 × days,
PhilHealth = 75 when attended, Pag-IBIG, loans, late / absence, AR items,
savings), Net = Gross − Total Deduction. Payslips reference the payroll rows.
Hidden sheets hold balances, damages, uniforms and government contributions
with typed arithmetic. Period shown: 28 Sep – 4 Oct 2026 (weekly).

### 1.4 Identifiers that connect records

| Link | Reliability |
|---|---|
| Trip ↔ waybill | CC: good from February (≤ 32 blanks a month), poor in January. CT: complete (RPM waybill) |
| Trip ↔ truck | Plate per row, but blanks (0–29 a month in CC), shared plates and a numeric plate in CT |
| Trip ↔ driver | Names as free text, "SURNAME, NAME" with spacing variants; no employee code |
| Trip ↔ client | Two levels (company, client); spelling variants (e.g. trailing spaces such as `FUJITRANS `) |
| CT shipment no. | Unique within each 2026 list; the client already checks duplicates on it |

## 2. Can the application reproduce the client's reports?

| Client report | Reproducible? | Why |
|---|---|---|
| Trips per day / week / month, per company → client | **Partly.** Counts and Monday weeks yes; two-level company → client grouping no; date basis differs (scheduled vs actual departure) | Schema has one client level and uses `scheduled_departure` |
| Units per period | **No** | No delivered-units field on trips |
| Trips per truck | Yes (count) | — |
| Trips per driver | **No for car carrier** (second driver not credited) | Only `driver_id` counted |
| Delayed trips and reasons | **No** with the current "delayed" definition | App uses a time rule; the client uses reported reasons |
| Down-truck days | **No** | No daily truck status history |
| Missing waybills | Yes, without a cut-off | No submission date or cut-off rule |
| Registration expiry list | Yes for dates and days left; no renewal status | Schema has no renewal status; trailer chassis are not trucks |
| Maintenance cost per truck | Formula yes (parts + labor + other) | **Not compared**: no client maintenance records provided |
| Revenue, outstanding, profit | Definitions match in structure | **Not compared**: no client billing / accounting records provided |

## 3. Tests performed

| Category | What was run | Result |
|---|---|---|
| **Static checks** | `php -l pages/billing.php` after this pass's edit; SQL capture of the Billing page data layer (roles finance, billing_collection, payroll) + `scripts/schema_check.php --drafts=all` | PASS; CONFIRMED 0, UNCERTAIN 2 (the known `IF(pay_type = …, 'Helper', 'Driver')` display labels) |
| **Unit tests** | Not re-run: no unit-tested helper changed. Last result 96 / 0 on 2026-10-14 | (unchanged) |
| **Mocked / stubbed** | Billing capture above uses the stub PDO; `tests/static/run.php` not re-run (its target pages did not change) | see above |
| **Tests using actual client records** | Session-scratch script `replay_client_records.php` (outside the repository, reads the workbooks) replays CT and CC rows through the app's own `buildBuckets()`, a PHP mirror of `bucketExpr('week')` (`WEEKDAY`, Monday start) and `daysUntilExpiry()` | **8 passed, 0 failed**: CT January weekly trips 4, 12, 14, 6, 8 = client weekly summary; app week keys Monday-start; CT March 69 = client monthly summary; CC trips / units Jan 412 / 1,814, Feb 475 / 2,076, Mar 401 / 1,776, Apr 396 / 1,494, Jul 722 / 2,857 = client totals. Expiry (info): as of 2026-10-15 the 97 registration dates fall in FOR RENEWAL → 44 expired, 14 within 0–30 days; UPDATED → **39 expired** (date not updated after renewal) |
| **Isolated MariaDB integration** | **Not run** | Test database not approved. `tests/sql/analytics_known_dataset.sql` is **synthetic test data**, not client data, and has not been run |
| **Browser / end-to-end** | **Not run** | — |

What the replay does **not** prove: that the database queries return these
numbers. It shows that the app's counting and week rules give the client's
totals when fed the client's own rows. Running the real SQL would need the
client records imported into the approved test database.

## 4. Conclusion

The analytics are **not fully validated.** Trip counting, weekly bucketing and
the expiry formula match client evidence. Trips by driver, "delayed",
on-time / late, utilization, units and the expiry alert lead contradict the
client sources or cannot be supported by them, and are listed as questions in
[`../analytics_data_gaps_and_client_questions.md`](../analytics_data_gaps_and_client_questions.md).
