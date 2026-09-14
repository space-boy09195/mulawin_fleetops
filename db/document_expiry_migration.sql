-- Run once if document expiry reminders are needed.
ALTER TABLE documents
  ADD COLUMN expiry_date DATE NULL AFTER description,
  ADD INDEX idx_documents_expiry (expiry_date);
