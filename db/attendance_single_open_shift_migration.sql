-- Enforce at most one open attendance shift per employee across all dates.
-- The per-day UNIQUE (employee_id, attendance_date) key cannot do this.
-- PREFLIGHT: this fails if an employee already has more than one open shift.
-- Find them first and have HR add the missing Time Out values (nothing is auto-changed):
--   SELECT employee_id, COUNT(*) AS open_shifts, GROUP_CONCAT(attendance_date) AS dates
--   FROM employee_attendance
--   WHERE time_in IS NOT NULL AND time_out IS NULL
--   GROUP BY employee_id HAVING COUNT(*) > 1;

ALTER TABLE employee_attendance
  ADD COLUMN IF NOT EXISTS open_shift_employee_id INT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN time_in IS NOT NULL AND time_out IS NULL THEN employee_id ELSE NULL END) VIRTUAL,
  ADD UNIQUE INDEX IF NOT EXISTS uq_employee_attendance_single_open (open_shift_employee_id);