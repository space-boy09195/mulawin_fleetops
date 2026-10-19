-- ============================================================
-- T-N1: inspection templates and items seed (DRAFT FOR REVIEW - NEW IN B4)
-- File: db/target_migrations/20261014_tN1_inspection_templates_seed.sql
-- Option B phase: B4.
--
-- STATUS: NOT EXECUTED and NOT APPROVED FOR EXECUTION.
--   Saving this file is NOT approval to run it. The item lists below are the
--   lists hard-coded in the current application (pre-trip checklist and the
--   24 standard truck parts); review them with Maintenance before running.
--
-- TARGET DATABASE: the Option B target only (mulawin_fleetops_test for
--   testing). Do NOT apply to the legacy database (no inspection_templates
--   table there). No USE statement.
--
-- PURPOSE:
--   Trip inspections are stored in the target vehicle_inspections /
--   inspection_findings tables, whose findings reference inspection_items.
--   The target ships with no templates, and B3/B4 code fails closed (no
--   departure, no completion) until active templates exist. This file seeds
--   the three templates the application uses:
--     'Pre-trip checklist'          Departure  8 items (legacy checklist)
--     'Standard vehicle inspection' Departure  24 items (legacy parts)
--     'Standard vehicle inspection' Arrival    24 items (legacy return parts)
--   Every item is required. A trip may depart only when every required
--   Departure item has a 'Good' finding in a completed, Passed inspection;
--   it may complete only when every required Arrival item has a finding.
--   minimum_duration_seconds = 300 matches the current
--   MAINTENANCE_FORM_MIN_DURATION_SECONDS (config/app.php); the target
--   default is 900. Review the value.
--
-- SCOPE: INSERT only, guarded by NOT EXISTS (rerun-safe). No UPDATE,
--   DELETE, DROP or TRUNCATE. Existing templates and items are never changed.
--
-- DEPENDENCIES: target schema baseline (inspection_templates,
--   inspection_items; unique (template_name, inspection_stage)).
-- COMPATIBILITY: MariaDB 10.4 / MySQL 8. Hostinger NOT verified.
-- NOTE: SQL examples in comments have no statement terminators on purpose.
--
-- READ-ONLY PRE-CHECKS (expected result in brackets):
--   a) SELECT DATABASE()                                  -- [the target db]
--   b) Existing templates (information; rows with the same name and stage
--      are kept and their items are only added when missing)
--        SELECT template_id, template_name, inspection_stage, is_active,
--               minimum_duration_seconds FROM inspection_templates
--
-- POST-CHECKS:
--   SELECT it.template_name, it.inspection_stage, COUNT(ii.inspection_item_id)
--     FROM inspection_templates it
--     LEFT JOIN inspection_items ii ON ii.template_id = it.template_id
--    GROUP BY it.template_id                    -- [8, 24, 24]
--
-- ROLLBACK (only with explicit approval, before any inspection is saved;
--   inspection_findings references items ON DELETE RESTRICT):
--     DELETE FROM inspection_templates WHERE template_name IN
--       ('Pre-trip checklist','Standard vehicle inspection')
--   (items cascade with their template)
-- ============================================================

START TRANSACTION;

INSERT INTO inspection_templates (template_name, inspection_stage, minimum_duration_seconds, is_active)
SELECT v.template_name, v.inspection_stage, 300, 1
FROM (
              SELECT 'Pre-trip checklist' AS template_name, 'Departure' AS inspection_stage
    UNION ALL SELECT 'Standard vehicle inspection', 'Departure'
    UNION ALL SELECT 'Standard vehicle inspection', 'Arrival'
) v
WHERE NOT EXISTS (
    SELECT 1 FROM inspection_templates t
    WHERE t.template_name = v.template_name AND t.inspection_stage = v.inspection_stage
);

-- Pre-trip checklist items (labels of pages/maintenance.php $checklistItems).
INSERT INTO inspection_items (template_id, item_name, category, is_required, sort_order, is_active)
SELECT t.template_id, v.item_name, 'Checklist', 1, v.sort_order, 1
FROM inspection_templates t
JOIN (
              SELECT 'Lights' AS item_name, 1 AS sort_order
    UNION ALL SELECT 'Tires', 2
    UNION ALL SELECT 'Tools', 3
    UNION ALL SELECT 'Medical Kit', 4
    UNION ALL SELECT 'Driver''s License', 5
    UNION ALL SELECT 'OR/CR', 6
    UNION ALL SELECT 'Waybill', 7
    UNION ALL SELECT 'Fuel PO', 8
) v
WHERE t.template_name = 'Pre-trip checklist' AND t.inspection_stage = 'Departure'
  AND NOT EXISTS (
      SELECT 1 FROM inspection_items i
      WHERE i.template_id = t.template_id AND i.category = 'Checklist' AND i.item_name = v.item_name
  );

-- 24 standard truck parts per view (the required parts of the current
-- vehicle inspection), for both the departure and the arrival template.
INSERT INTO inspection_items (template_id, item_name, category, is_required, sort_order, is_active)
SELECT t.template_id, v.item_name, v.category, 1, v.sort_order, 1
FROM inspection_templates t
JOIN (
              SELECT 'Front' AS category, 'Windshield' AS item_name, 1 AS sort_order
    UNION ALL SELECT 'Front', 'Left Headlight', 2
    UNION ALL SELECT 'Front', 'Right Headlight', 3
    UNION ALL SELECT 'Front', 'Front Bumper', 4
    UNION ALL SELECT 'Front', 'Left Front Tire', 5
    UNION ALL SELECT 'Front', 'Right Front Tire', 6
    UNION ALL SELECT 'Side', 'Left Mirror', 7
    UNION ALL SELECT 'Side', 'Driver Door', 8
    UNION ALL SELECT 'Side', 'Cargo Body', 9
    UNION ALL SELECT 'Side', 'Fuel Tank', 10
    UNION ALL SELECT 'Side', 'Left Rear Tire', 11
    UNION ALL SELECT 'Side', 'Exhaust', 12
    UNION ALL SELECT 'Rear', 'Rear Doors', 13
    UNION ALL SELECT 'Rear', 'Left Tail Light', 14
    UNION ALL SELECT 'Rear', 'Right Tail Light', 15
    UNION ALL SELECT 'Rear', 'Rear Bumper', 16
    UNION ALL SELECT 'Rear', 'Left Rear Tire', 17
    UNION ALL SELECT 'Rear', 'Right Rear Tire', 18
    UNION ALL SELECT 'Top', 'Cab Roof', 19
    UNION ALL SELECT 'Top', 'Cargo Roof', 20
    UNION ALL SELECT 'Top', 'Left Side Panel', 21
    UNION ALL SELECT 'Top', 'Right Side Panel', 22
    UNION ALL SELECT 'Top', 'Fuel Tank', 23
    UNION ALL SELECT 'Top', 'Rear Undercarriage', 24
) v
WHERE t.template_name = 'Standard vehicle inspection'
  AND t.inspection_stage IN ('Departure', 'Arrival')
  AND NOT EXISTS (
      SELECT 1 FROM inspection_items i
      WHERE i.template_id = t.template_id AND i.category = v.category AND i.item_name = v.item_name
  );

COMMIT;
