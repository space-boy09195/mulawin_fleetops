<?php
// ============================================================
// tests/bootstrap.php
// Loaded once before the test suite runs. Deliberately requires
// ONLY the pure, side-effect-free files being tested — never
// includes/session.php or an ajax/*.php handler script, since
// those run auth checks, start a session, and call header() as
// soon as they're loaded, none of which make sense outside a
// real HTTP request.
// ============================================================

require_once __DIR__ . '/../includes/date_helpers.php';
require_once __DIR__ . '/../includes/employee_validation.php';
require_once __DIR__ . '/../includes/login_throttle.php';
