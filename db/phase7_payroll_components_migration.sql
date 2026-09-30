ALTER TABLE payroll_records
  ADD COLUMN IF NOT EXISTS base_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER pay_period_end,
  ADD COLUMN IF NOT EXISTS allowance_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER base_amount,
  ADD COLUMN IF NOT EXISTS deduction_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER allowance_amount;

UPDATE payroll_records
SET base_amount = amount_paid
WHERE base_amount = 0 AND allowance_amount = 0 AND deduction_amount = 0;
