<?php
// ============================================================
// pages/403.php
// Shown when a user tries to access a page their role can't see
// ============================================================
require_once __DIR__ . '/../includes/session.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Access Denied — Mulawin FleetOps</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
  <div class="text-center p-4">
    <i class="bi bi-shield-lock-fill text-danger" style="font-size: 4rem;"></i>
    <h1 class="mt-3 fw-bold">403 — Access Denied</h1>
    <p class="text-muted">You do not have permission to view this page.</p>
    <a href="../auth/login_handler.php?action=logout" class="btn btn-outline-danger mt-2">
      <i class="bi bi-box-arrow-left me-1"></i> Log Out
    </a>
  </div>
</body>
</html>
