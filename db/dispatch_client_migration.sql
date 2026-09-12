-- Add the client captured when a dispatcher creates a dispatch.
ALTER TABLE dispatch_requests
  ADD COLUMN client_name VARCHAR(150) NULL AFTER remarks;
