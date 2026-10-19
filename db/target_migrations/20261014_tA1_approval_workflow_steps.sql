-- ============================================================
-- T-A1: approval workflow step templates, workflow definitions and
-- database-enforced pending-approval uniqueness (DRAFT FOR REVIEW)
-- File: db/target_migrations/20261014_tA1_approval_workflow_steps.sql
-- Option B phase: B3b. Related decisions: D2 (trip created at submission),
-- D18 (step templates; per-user assignment dropped).
--
-- STATUS: NOT EXECUTED and NOT APPROVED FOR EXECUTION.
--   Saving this file is NOT approval to run it. The workflow list and the
--   default approver of each step (section 3) are PROPOSED values that need
--   business review before execution.
--
-- TARGET DATABASE: the Option B target only (mulawin_fleetops_test for
--   testing). Do NOT apply to the legacy database (different approval
--   tables). No USE statement: select the database explicitly first.
--
-- PURPOSE:
--   1. The target has approval_workflows / approval_requests /
--      approval_steps / approval_history but no TEMPLATE of which roles
--      approve which workflow. The legacy app keeps that in
--      approval_role_steps (managed on pages/permissions.php). This file
--      adds approval_workflow_steps for the same purpose.
--   2. Neither schema enforces "at most one Pending approval request per
--      workflow and entity". Phase 4A only mitigates this in code. This
--      file adds a stored generated column pending_key plus a UNIQUE index,
--      so a second Pending request for the same workflow+entity is rejected
--      by the database (duplicate-key error 1062) even under concurrency.
--      Decided rows (Approved/Rejected/Cancelled) get NULL and never collide.
--
-- SCOPE:
--   1. CREATE TABLE approval_workflow_steps.
--   2. ALTER TABLE approval_requests ADD pending_key (STORED generated)
--      + UNIQUE KEY uq_approval_pending.
--   3. Seed rows: approval_workflows (6 workflows) and their step-1
--      approvers, resolved by roles.system_key - never by numeric role ID.
--   No DROP, TRUNCATE, DELETE or UPDATE.
--
-- PROPOSED WORKFLOWS (review; legacy request types mapped to target entities):
--   workflow_key      entity_type        step 1 approver (system_key)
--   dispatch          dispatch_request   operations_head (holds approvals.review)
--   route_request     route              operations_head (holds routes.approve)
--   purchase_request  purchase_request   admin
--   fund_request      fund_request       admin
--   payment_voucher   payment_voucher    admin
--   payroll           payroll_period     admin
--   Legacy 'trip_request' has no target entity and is not seeded.
--   Legacy default approver was role 1 for every type; 'dispatch' and
--   'route_request' are assigned to Operations Head here because the seed
--   grants it approvals.review and routes.approve. Admin can still act on any
--   step through the audited Admin override.
--
-- DEPENDENCIES (must be applied first):
--   - Target schema baseline (approval_workflows, approval_requests, roles).
--   - P21-7M (roles.system_key) and the reviewed RBAC seed (roles with keys
--     'admin' and 'operations_head'). Without them the section-3 step INSERT
--     inserts nothing for the missing roles (it never guesses a role).
--
-- COMPATIBILITY:
--   - STORED generated columns with a UNIQUE index: MySQL 8 and MariaDB 10.4
--     (MariaDB accepts STORED as a synonym of PERSISTENT). Hostinger NOT
--     verified - confirm the server version first.
--   - Adding a STORED column rebuilds approval_requests (table copy). On a
--     fresh test database the table is empty.
--
-- NOTE: SQL examples in comments have no statement terminators on purpose.
--
-- READ-ONLY PRE-CHECKS (expected result in brackets):
--   a) SELECT DATABASE()                                  -- [the target db]
--   b) SELECT VERSION()                                   -- [record it]
--   c) SELECT TABLE_NAME FROM information_schema.TABLES
--       WHERE TABLE_SCHEMA = DATABASE()
--         AND TABLE_NAME = 'approval_workflow_steps'       -- [no rows]
--   d) SELECT COLUMN_NAME FROM information_schema.COLUMNS
--       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'approval_requests'
--         AND COLUMN_NAME = 'pending_key'                  -- [no rows]
--   e) Existing duplicate Pending requests - ANY ROW MEANS STOP (the UNIQUE
--      index would fail); resolve the duplicates first:
--        SELECT workflow_id, entity_type, entity_id, COUNT(*)
--          FROM approval_requests WHERE status = 'Pending'
--         GROUP BY workflow_id, entity_type, entity_id HAVING COUNT(*) > 1
--   f) Roles used by section 3 exist with these keys      -- [2 rows]
--        SELECT system_key, role_id, is_active FROM roles
--         WHERE system_key IN ('admin','operations_head')
--   g) Existing workflow keys (information; existing rows are kept)
--        SELECT workflow_key, entity_type, is_active FROM approval_workflows
--
-- TRANSACTIONS: CREATE/ALTER commit implicitly. The seed INSERTs run in one
--   transaction at the end and are guarded by NOT EXISTS (rerun-safe). A
--   rerun of the whole file fails at CREATE TABLE and changes nothing.
--
-- ROLLBACK (only with explicit approval):
--     ALTER TABLE approval_requests DROP INDEX uq_approval_pending,
--       DROP COLUMN pending_key
--     DROP TABLE approval_workflow_steps
--     DELETE FROM approval_workflows WHERE workflow_key IN ('dispatch',
--       'route_request','purchase_request','fund_request',
--       'payment_voucher','payroll')
--   Limitations: approval_workflows rows cannot be deleted once any
--   approval_requests row references them (fk_approval_request_workflow is
--   ON DELETE RESTRICT). Dropping uq_approval_pending removes the database
--   guarantee; code-level checks (Phase 4A) remain.
-- ============================================================

CREATE TABLE approval_workflow_steps (
    workflow_id INT UNSIGNED NOT NULL,
    step_number INT UNSIGNED NOT NULL,
    approver_role_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (workflow_id, step_number),
    KEY idx_approval_workflow_steps_role (approver_role_id),
    CONSTRAINT fk_approval_workflow_steps_workflow FOREIGN KEY (workflow_id)
        REFERENCES approval_workflows (workflow_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_approval_workflow_steps_role FOREIGN KEY (approver_role_id)
        REFERENCES roles (role_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_approval_workflow_step_number CHECK (step_number >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE approval_requests
    ADD COLUMN pending_key VARCHAR(100)
        GENERATED ALWAYS AS (
            CASE WHEN status = 'Pending'
                 THEN CONCAT(workflow_id, ':', entity_type, ':', entity_id)
                 ELSE NULL
            END
        ) STORED,
    ADD UNIQUE KEY uq_approval_pending (pending_key);

START TRANSACTION;

INSERT INTO approval_workflows (workflow_key, workflow_name, entity_type, is_active)
SELECT v.workflow_key, v.workflow_name, v.entity_type, 1
FROM (
              SELECT 'dispatch' AS workflow_key, 'Dispatch request' AS workflow_name, 'dispatch_request' AS entity_type
    UNION ALL SELECT 'route_request', 'Route request', 'route'
    UNION ALL SELECT 'purchase_request', 'Purchase request', 'purchase_request'
    UNION ALL SELECT 'fund_request', 'Fund request', 'fund_request'
    UNION ALL SELECT 'payment_voucher', 'Payment voucher', 'payment_voucher'
    UNION ALL SELECT 'payroll', 'Payroll', 'payroll_period'
) v
WHERE NOT EXISTS (
    SELECT 1 FROM approval_workflows w WHERE w.workflow_key = v.workflow_key
);

INSERT INTO approval_workflow_steps (workflow_id, step_number, approver_role_id)
SELECT w.workflow_id, 1, r.role_id
FROM (
              SELECT 'dispatch' AS workflow_key, 'operations_head' AS role_key
    UNION ALL SELECT 'route_request', 'operations_head'
    UNION ALL SELECT 'purchase_request', 'admin'
    UNION ALL SELECT 'fund_request', 'admin'
    UNION ALL SELECT 'payment_voucher', 'admin'
    UNION ALL SELECT 'payroll', 'admin'
) s
JOIN approval_workflows w ON w.workflow_key = s.workflow_key
JOIN roles r ON r.system_key = s.role_key
WHERE NOT EXISTS (
    SELECT 1 FROM approval_workflow_steps x
    WHERE x.workflow_id = w.workflow_id AND x.step_number = 1
);

COMMIT;
