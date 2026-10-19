# Mulawin FleetOps — Request for Clarification on Reports and Analytics

**To:** RP Mulawin Trucking Services — Management, Operations Head, Admin
**From:** Mulawin FleetOps project team
**Date:** 15 October 2026
**Re:** Follow-up to the Client Questionnaire (Requirements Gathering) and the
Car Carrier / Container monitoring workbooks

Thank you for completing the questionnaire and for sharing your Car Carrier
and Container monitoring workbooks. We compared the system's reports with
your answers and your workbooks:

- Your trip totals match: when we count your trip rows the same way the
  system does, we get the same monthly and weekly totals as your summary
  sheets.
- Your document expiry formula, **"Expiry date − current date"**, is used as
  written.
- A few rules are still not clear. In some places your answers and the
  system's current behaviour differ.

So that the system reports what you actually use, we would like to confirm
the points below. **Part A has the six questions that affect the main daily
reports.** Part B covers the remaining points. Part C lists the sample records
that would help us check the remaining figures.

You may answer directly under each question, in English or Filipino. If a
point needs discussion, we can cover it in the follow-up interview you offered.

> In this document, "your workbook" means *CAR CARRIER MONITORING 2026* or
> *CONTAINER MONITORING 2026*, and "the questionnaire" means your completed
> Client Questionnaire. We have not included any personal or salary details.

---

## Part A — Priority questions

### CQ-1. Which date decides the day or week of a trip?

**What we found.** Your sheets use different dates:
- The Container summaries are filtered by **ACTUAL DEPARTURE DATE**.
- The Car Carrier trip lists use a **DATE** column that does not say which
  event it is (loading, departure or delivery).
- In the questionnaire (Q14) you wrote: *"Total trips per week = Total ng
  waybills submitted by Operations per week."*

**What the system does now.** It counts a trip on its **scheduled departure**
date.

**Why it matters.** A trip that departs late at night, or whose waybill is
submitted days later, can fall in a different day or week. The daily and
weekly totals would then not match your sheets.

*Example:* a trip scheduled for Sunday 11:00 PM leaves at 1:00 AM Monday, and
its waybill is submitted on Wednesday. It counts in last week if the
scheduled date is used, but in this week if the actual departure or the
waybill submission date is used.

**Please answer:** Which date should decide the day or week of a trip?
- ☐ Actual departure
- ☐ Delivery / unloading
- ☐ Waybill submission
- ☐ Other: ______

Is it the same for Car Carrier and Container?

*Affects:* total trips per day / week / month (Operations Performance,
Analytics, dashboards). Using waybill submission would need a new **waybill
submission date** field.

### CQ-4. How should a trip be credited when it has two drivers?

**What we found.** In the Car Carrier workbook almost every trip (93–100 % of
rows each month) has a **DRIVER 1** and a **DRIVER 2**. Container trips list
a driver and a helper.

**What the system does now.** "Trips per driver" counts each trip only for the
**main driver**.

**Why it matters.** Driver 2 would show far fewer trips than they actually
drove, and trips per driver is one of the reports you asked for (Q26).

*Example:* a trip driven by Driver A (Driver 1) and Driver B (Driver 2)
currently counts 1 for A and 0 for B.

**Please answer:**
- Should the trip count once for **each** driver (1 for A and 1 for B) in trip reports?
- Should **both** drivers receive **trip pay** for the trip, or only one? (Please describe how you do it today.)
- For Container trips, should helpers also have a trip count?

*Affects:* trips per driver (Operations Performance) and Trip Pay. How a
second driver is stored is a separate question (CQ-19).

### CQ-5. Should the system record delivered units per trip?

**What we found.** You define a completed trip as *"Kapag na-deliver na ang
total units based sa requirements ng client"* (Q5). Your Car Carrier sheets
record **UNITS** on every trip and total them every month.

**What the system does now.** It marks a trip completed by its status
(delivered / returned / completed). It does **not** store how many units were
delivered.

**Why it matters.** The system cannot show units per day, week or client, as
your summaries do. It also cannot tell a fully delivered trip from a partial
one.

*Example:* a car-carrier trip booked for 5 units delivers 4. Your rule says
it is not yet completed; the system would show it as completed.

**Please answer:**
- Should the system record **delivered units** for each trip (at least for
  Car Carrier)?
- Should a trip count as completed **only** when all required units are
  delivered?

*Affects:* completed trips, units per period / client, trip status reports.
This needs a new **delivered units** field.

### CQ-7. What does "delayed" mean in your reports?

**What we found.**
- You define a delayed trip as *"Trip delayed due to pending documents or
  operational issues"* (Q5).
- You count "Delayed trips and reasons" (Q8).
- You do **not** compare scheduled and actual times (Q6).
- You want client-caused delays recorded separately, *"clearly identifying
  they are not on our side"* (Q8).
- Your workbook remarks include entries such as "PENDING DOCS".

**What the system does now.** The dashboards show "Delayed Trips" as trips
that are still on the road **after their scheduled arrival time**. Separately,
staff can record a delay with a reason on a trip, but these recorded delays
are **not** what the dashboards count.

**Why it matters.** The two methods give different numbers. A trip held for
pending documents but still within its schedule is delayed by your
definition, but not by the system's.

**Please answer:**
1. Should "Delayed" mean **a trip with a recorded delay and its reason**?
2. Should client-caused delays be counted **separately** from delays on your
   side?
3. Is an alert for trips past their expected arrival time still useful? If
   yes, we would rename it (for example "Past ETA") so it is not confused
   with "Delayed".

*Affects:* Delayed Trips on the Admin, Operations Head, Dispatcher and general
dashboards, and trip status reports. No new field is expected; delays and
reasons can already be recorded.

### CQ-12. Waybill cut-off, submission date and payment terms

**What we found.**
- For missing waybills you wrote: *"if hindi naka pag submit sa cut off
  date"* (Q26).
- For outstanding balances: *"Depends sa contract may 15 days or 30 days
  after masubmit waybill"*, with terms that differ per client (Q15).

**What the system does now.**
- It lists delivered trips with **no waybill number and no waybill file**,
  with no cut-off.
- It does not record **when** a waybill was submitted.
- Billing due dates are typed in by hand; client payment terms are not
  stored.

**Why it matters.** Without the cut-off, a trip delivered an hour ago already
appears as "missing waybill". Without the submission date and terms, the
system cannot tell when a balance becomes outstanding the way you count it.

*Example:* a trip delivered on Monday, waybill submitted Thursday, client
terms 15 days. By your rule the balance is due 15 days after Thursday.

**Please answer:**
1. What is the waybill cut-off (for example, within ___ days after delivery,
   or a fixed weekly cut-off day)?
2. Should the system record the **waybill submission date**?
3. Should each client's **payment terms** (15 / 30 days) be stored so the due
   date is computed automatically?

*Affects:* missing-waybill monitoring (Operations Performance), trips per
week counted by waybills, outstanding / overdue billing. This needs a
**waybill submission date** field and a **client payment terms** field.

### CQ-14. How early should document expiry alerts appear?

**What we found.**
- You use the formula *"Expiry date − current date"* (Q26).
- For OR/CR, insurance, permits / franchise and gate / entry passes you
  answered **365 days** as the alert period (Q21).
- In your registration list, units are marked **FOR RENEWAL** or **UPDATED**.

**What the system does now.**
- It shows the days left with your formula, and highlights a document when
  30 days or fewer remain.
- It can also send reminders at 60, 30, 15 and 7 days before expiry.

**Why it matters.** With a 365-day alert, every yearly document would always
show as "due". We want to confirm whether 365 days is the warning period or
the renewal cycle.

*Example:* an OR/CR expiring on 30 November 2026 is "46 days left" on 15 October 2026. With
a 365-day alert it has been flagged since 30 November 2025.

**Please answer:**
- Does "365 days" mean an alert **one year** before expiry, or that these
  documents are **renewed every year**?
- How many days before expiry should a document be highlighted? (May differ
  per document type: OR/CR ___, Insurance ___, Permit / franchise ___, Gate /
  entry pass ___.)

*Affects:* document expiry display (Documents, Fleet Status) and expiry
reminders. No new field is needed for this question (see CQ-15 for renewal
status).

---

## Part B — Other questions

### CQ-2. Do cancelled or pending trips count in total trips?

You wrote that total trips come from *"Scheduled trips"*. The system's "Trips
scheduled" figure currently includes trips still awaiting approval and
cancelled trips.

**Please answer:** Should total trips include ☐ cancelled trips ☐ trips not
yet approved?

*Affects:* total trips (Operations Performance).

### CQ-3. Which name is the "client" in reports?

Your sheets group trips first by **COMPANY NAME** (for example the forwarder
or broker) and then by **CLIENT** (for example the brand or consignee). The
system has one client level.

**Please answer:** Which level should reports call "client"? Do you need
totals by both company and client?

*Affects:* trips per client. Both levels would need a **company / parent
client** field.

### CQ-6. Cancelled trips by cause

You define cancelled as *"Trip cancelled by client or due to operational
issues."*

**Please answer:** Should reports show cancellations by client and by
operational issues separately?

*Affects:* cancelled trips report.

### CQ-8. On-time delivery rate

You indicated there is no formula for on-time delivery and no consistent
time record (Q8). The system currently shows an on-time rate where scheduled
and actual arrival times are recorded.

**Please answer:** Should management still see an on-time rate (once times
are recorded), or should it be removed from the reports?

*Affects:* Analytics, Operations Performance, Admin / Operations Head
dashboards.

### CQ-9. Schedule changes

You asked to *"Keep both original and updated schedules for tracking schedule
changes"* (Q8). The system keeps only the current schedule on the trip.

**Please answer:** Should trip reports show both the original and the updated
schedule?

*Affects:* trip reports. This needs an **original schedule** field or a
schedule-change history.

### CQ-10. Truck status codes and "motorpool"

Your Container *TRUCK STATUS* sheet uses daily codes **OK**, **DT**, **NT**
and **–**, and counts **DT** days per month as down-truck days. You also
check whether a truck is "available o nasa motorpool".

**Please answer:**
- What do **NT** and **–** mean?
- Is "nasa motorpool" the same as **DT**, or a separate status?

*Affects:* truck availability and motorpool status. Down-truck days would
need a **daily truck status** record.

### CQ-11. How should truck use be measured?

You told us you look at the number of trips, days deployed, available days,
idle days and maintenance / downtime days (Q9). The system currently shows
the percentage of trucks that had at least one trip.

**Please answer:** Should the system show **days deployed / idle / down per
truck per month** instead?

*Affects:* fleet utilization (Analytics, dashboards). Needs the daily truck
status record from CQ-10.

### CQ-13. Complete records

You asked that *"All records must be complete, updated, and organized"*, by
department, document type and date (Q26 #4).

**Please answer:** Which records and which details must be filled in for a
record to count as "complete" (for example: trip with waybill and delivery
receipt; truck with OR/CR and insurance dates)?

*Affects:* a record completeness report (not yet built).

### CQ-15. Renewal status and renewed dates

Your registration list marks units **FOR RENEWAL** or **UPDATED**. The units
marked UPDATED still show their old expiry date. It also lists trailer chassis
and tractor heads as separate registered units.

**Please answer:**
- When a document is renewed, will the **new expiry date** be recorded?
- Do you also need a **renewal status** (for example "For renewal", "In
  process", "Updated")?
- Should trailer chassis be tracked as separate units?

*Affects:* document expiry monitoring. A status would need a **renewal
status** field.

### CQ-16. Expense types and profit view

You monitor fuel, maintenance, parts, trip allowance, payroll and **RFID**
expenses. Profit is computed by your external accounting as *total billed
amount − all expenses*, viewed per client, weekly and monthly.

**Please answer:**
- Should RFID be its own expense type?
- Since the official profit comes from external accounting, should the system
  show its own margin figure (based only on costs recorded in the system), and
  if so, per client?

*Affects:* expense and margin reports (Billing, Analytics).

### CQ-17. Where trip pay appears in payroll

You described trip pay as *"Fixed trip pay rate per destination × number of
trips"*, the same method for driver and helper but with different rates
(Q23). In the payroll sheet, regular wage is computed from the daily rate and
days, and the number of trips and allowances are entered separately.

**Please answer:** Is per-destination trip pay included in **Total
Allowances**, or paid separately? Is it paid on top of the daily-rate wage?

*Affects:* Trip Pay and payroll reports.

### CQ-18. Container weekly totals (TARA grid)

In your *TARA CONTAINER* sheet, the weekly "other trip" total counts Bulacan,
Nueva Ecija, Pasig, Manggahan, Batino and Durant. Trips to **Monde, Cavite and
Parañaque** appear in the grid but are not in that count.

**Please answer:** Should those trips be part of the weekly total? (If yes,
your current sheet totals may be slightly low.)

*Affects:* comparison between the system's container totals and your sheet.

### CQ-19. How many crew can one trip have?

Your Car Carrier sheets show **two drivers** per trip, and Container trips
show **one driver and one helper**. We want to confirm the largest crew a
trip can have, so the system stores it correctly.

**Please answer:**
- Can a trip ever have **more than one second driver** or **more than one helper**?
- Can a **third or relief driver** be assigned to a trip?
- Can a car carrier have a **helper in addition to two drivers**?
- Can crew members **change partway through a trip**?

*Affects:* how drivers and helpers are recorded on a trip. This is separate
from how trips and pay are credited (CQ-4).

---

## Part C — Records that would help us check the remaining figures

We have **not** received the following yet. Blank forms or copies with names
and amounts covered are fine (as noted in Q25).

1. **Maintenance and motorpool records:** a sample of repair records or the
   motorpool logbook (truck, date, parts, labor, repair cost). Used to check
   *maintenance expenses per truck*.
2. **Billing and collection records:** a sample of billings and payments
   received for one month. Used to check *revenue*, *outstanding balance* and
   *billed vs collected*.
3. **Accounting and expense samples:** a sample expense report and how
   "all expenses" are grouped for profit. Used to check *expense* and *margin*
   figures.
4. **Fuel statements:** a sample Shell / Petron statement of account. Used to
   check *fuel per truck*.
5. **Questionnaire Section I (Positions and access):** your completed answers
   on which positions use the system and which reports each may see. Used to
   confirm *who can see each report*.

---

## How to reply

Please answer Part A first, as those six points affect the main daily
reports. Part B and Part C can follow. Until we receive your answers, the
system keeps its current calculations, and no new fields will be added.

Thank you for your time and support.
