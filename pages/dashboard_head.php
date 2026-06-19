<?php
// ============================================================
// pages/dashboard_head.php
// Placeholder — Phase 6 will replace this with the real dashboard
// ============================================================
require_once __DIR__ . '/../includes/session.php';
requireRole([ROLE_HEAD_MANAGEMENT]);  // Only Head Management can access
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Dashboard — Head Management</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light p-4">
  <div class="container">
    <h2>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?>!</h2>
    <p class="text-muted">Role: <?= htmlspecialchars($_SESSION['role_name']) ?></p>
    <p>✅ Auth is working. This page will be replaced in Phase 6.</p>
    <a href="../auth/login_handler.php?action=logout" class="btn btn-danger">Log Out</a>
  </div>
</body>
</html>
