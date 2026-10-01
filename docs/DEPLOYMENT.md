# Deployment: Applying the migration chain

The production `mulawin_fleetops` database has always been kept on the Phase 1
baseline schema (`db/Mulawin_DB-Phase 1`) — every phase in this project was
built and validated against disposable/isolated schemas only, per the
project's "never write to production" safety rule. This means the full
migration chain below has never actually been run end-to-end against a real
deployment target. Before using it, run it once against a copy of production
data in a staging environment and confirm the app still behaves as expected.

## Apply order

Run `db/Mulawin_DB-Phase 1` first (the base schema), then the files below in
this order. This order was derived from each file's `ALTER`/`CREATE TABLE`
targets and their foreign-key dependencies; it has been checked for the
internally-referenced tables but has **not** been exhaustively verified
against every column reference in the codebase, so treat it as a strong
starting point rather than a guarantee.

1. `clients_migration.sql`
2. `phase1_foundations_migration.sql`
3. `phase2_master_data_migration.sql`
4. `phase3_roles_migration.sql`
5. `phase3_dispatch_handoff_migration.sql`
6. `dispatch_client_migration.sql`
7. `phase3_trip_operations_migration.sql`
8. `phase3_dispatch_batches_migration.sql`
9. `phase4_trip_inspections_migration.sql`
10. `phase4_maintenance_form_timers_migration.sql`
11. `phase4_preventive_maintenance_schedules_migration.sql`
12. `phase4_repair_work_orders_migration.sql`
13. `phase4_truck_status_history_migration.sql`
14. `vehicle_inspection_migration.sql`
15. `phase5_rbac_roles_migration.sql`
16. `phase5_purchasing_migration.sql`
17. `phase6_finance_migration.sql`
18. `trip_costs_migration.sql`
19. `phase7_hr_attendance_migration.sql`
20. `phase7_payroll_announcements_base_migration.sql` — **creates the
    `payroll_records` and `announcements` base tables.** These were referenced
    by later migrations/app code but never actually created by any migration
    file before Phase 14; this file closes that gap. Must run before #21, #22,
    and #25.
21. `phase7_payroll_components_migration.sql`
22. `phase7_payroll_deduction_items_migration.sql`
23. `phase8_admin_officer_migration.sql`
24. `phase9_truck_warranty_migration.sql`
25. `truck_image_migration.sql`
26. `document_expiry_migration.sql`
27. `trip_report_document_visibility_migration.sql`
28. `route_approval_and_notifications_migration.sql`
29. `automation_settings_migration.sql`
30. `password_reset_requests_migration.sql`
31. `system_hardening_migration.sql`
32. `announcement_duration_audience_migration.sql`
33. `phase10_trip_workflow_migration.sql`
34. `phase11_inventory_controls_migration.sql`
35. `phase13_billing_ar_migration.sql`

## Notes

- All migration files use `IF NOT EXISTS` / `IF NOT EXISTS`-style guards where
  the project's existing style already did so, so re-running an already-applied
  file is safe. A few base `CREATE TABLE` statements (e.g. `clients_migration.sql`)
  do not, matching their original pre-existing style — do not re-run those
  a second time against the same database.
- After applying the chain, verify `payroll_records` and `announcements` exist
  with the columns the app expects (see `pages/payroll.php`, `pages/announcements.php`)
  before relying on payroll or announcements features in production.
- This file documents *order*, not a replacement for a proper staging
  dry-run. Always rehearse the full chain against a disposable copy of
  production data first.
