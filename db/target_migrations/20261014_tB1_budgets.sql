-- ============================================================
-- T-B1: budgets support table (DRAFT FOR REVIEW - DECISION-GATED)
-- File: db/target_migrations/20261014_tB1_budgets.sql
-- Option B phase: B5b. GATED ON DECISION D7 (keep the budget feature).
--
-- STATUS: NOT EXECUTED and NOT APPROVED FOR EXECUTION.
--
-- TARGET DATABASE: the Option B target only. Do NOT apply to the legacy
--   database (budgets already exists there). No USE statement.
--
-- PURPOSE:
--   ajax/budgets_handler.php (budgets.manage, Phase 1) and the analytics
--   budget-variance section store one monthly amount per category. The
--   target schema has no budget table. Definition ported from legacy with a
--   CHECK on the amount added.
--   Category values are unchanged from legacy and must stay equal to
--   BUDGET_CATEGORIES in config/enums.php. Note: 'Payroll' compares with
--   periodic payroll only; Trip Pay has no budget category (Phase 4D).
--
-- CONCURRENCY: UNIQUE (category, period_month) - the handler's upsert can
--   never create two budgets for the same month and category.
--
-- DEPENDENCIES: target schema baseline (users).
-- COMPATIBILITY: MariaDB 10.4 / MySQL 8 syntax. Hostinger NOT verified.
-- NOTE: SQL examples in comments have no statement terminators on purpose.
--
-- READ-ONLY PRE-CHECKS (expected result in brackets):
--   a) SELECT DATABASE()                                  -- [the target db]
--   b) SELECT TABLE_NAME FROM information_schema.TABLES
--       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budgets' -- [no rows]
--
-- TRANSACTIONS: CREATE TABLE commits implicitly; a rerun fails and changes
--   nothing.
--
-- ROLLBACK (only with explicit approval, before B5b code uses the table):
--     DROP TABLE budgets
--   Data loss: every budget figure.
-- ============================================================

CREATE TABLE budgets (
    budget_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category ENUM('Revenue','Maintenance','Fuel','Toll','Driver Allowance','Other','Payroll') NOT NULL,
    period_month DATE NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    set_by INT UNSIGNED NOT NULL,
    PRIMARY KEY (budget_id),
    UNIQUE KEY uq_budgets_category_period_month (category, period_month),
    KEY idx_budgets_period_month (period_month),
    KEY idx_budgets_set_by (set_by),
    CONSTRAINT fk_budgets_set_by FOREIGN KEY (set_by) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_budgets_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
