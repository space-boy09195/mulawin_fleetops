-- Trip-report attachments are operational documents. They are visible to
-- Head Management, Dispatchers, and Accounting, but not Maintenance.
ALTER TABLE documents
  ADD COLUMN visibility_scope ENUM('all', 'operations', 'maintenance', 'accounting')
    NOT NULL DEFAULT 'all'
    AFTER description,
  ADD INDEX idx_documents_visibility (visibility_scope);
