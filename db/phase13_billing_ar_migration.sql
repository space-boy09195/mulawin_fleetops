-- Phase 13: Billing / AR completion
-- Normalizes the billing party against the clients table (kept alongside the
-- existing free-text client_name for backward compatibility / one-off
-- bill-to overrides) and adds optional invoice number/date fields so
-- Accounting can record a client's own invoice reference separately from
-- the internal billing_number.

ALTER TABLE billings
  ADD COLUMN IF NOT EXISTS client_id INT UNSIGNED NULL AFTER trip_id,
  ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(50) NULL AFTER billing_number,
  ADD COLUMN IF NOT EXISTS invoice_date DATE NULL AFTER invoice_number,
  ADD INDEX IF NOT EXISTS idx_billings_client (client_id),
  ADD UNIQUE KEY IF NOT EXISTS uq_billings_invoice_number (invoice_number),
  ADD CONSTRAINT fk_billings_client
    FOREIGN KEY (client_id) REFERENCES clients (client_id) ON DELETE SET NULL;

-- Best-effort backfill: link existing billings to their client record when
-- the free-text client_name exactly matches an existing active client.
UPDATE billings b
JOIN clients c ON c.client_name = b.client_name
SET b.client_id = c.client_id
WHERE b.client_id IS NULL AND b.client_name IS NOT NULL;
