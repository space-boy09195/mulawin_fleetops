<?php
require_once __DIR__ . '/../includes/session.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Dashboard</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light p-4">
  <div class="container">
    <h2>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?>!</h2>
    <p class="text-muted">Role: <?= htmlspecialchars($_SESSION['role_name']) ?></p>
    <p>✅ Auth is working. This page will be replaced in later phases.</p>
    <a href="../auth/login_handler.php?action=logout" class="btn btn-danger">Log Out</a>
  </div>
</body>
</html>
