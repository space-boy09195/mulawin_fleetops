<?php

// Self-service password change requests are disabled.
//
// The previous flow let an unauthenticated visitor choose a new password for
// any username and queue it for approval, which allowed account takeover if
// the request was approved. Users must now ask an administrator, who verifies
// their identity and sets a new password from User Management.
//
// The handler deliberately ignores all submitted fields and stores nothing,
// so every request gets the same response regardless of the username.

require_once __DIR__ . '/../includes/session.php';

header('Location: ' . APP_BASE . '/login.php?reset=disabled');
exit;
