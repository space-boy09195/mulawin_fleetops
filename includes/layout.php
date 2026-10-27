<?php
// ============================================================
// includes/layout.php
// Shared shell: sidebar + topnav + announcements
// ============================================================

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../app/core/notifications.php';

// ---- Role → dashboard URL map ----------------------------
function roleDashboardUrl(): string {
    return dashboardUrlForRole(
        (string)($_SESSION['role_name'] ?? ''),
        currentRoleId()
    );
}

// ---- Navigation definition --------------------------------
// Grouped by business module. An item with 'section' => null is a top-level
// link shown under Home (Approvals serves several modules).
function getNavItems(): array {
    return [
        ['section' => null, 'label' => 'Approvals', 'href' => '/pages/requests.php', 'icon' => 'bi-inbox', 'permission' => 'approvals.review'],

        // 1. Fleet Management
        ['section' => 'Fleet Management', 'label' => 'Fleet Status', 'href' => '/pages/fleet_status.php', 'icon' => 'bi-truck', 'permission' => 'fleet.view'],

        // 2. Trip & Dispatch
        ['section' => 'Trip & Dispatch', 'label' => 'Dispatch Planning', 'href' => '/pages/dispatch_planning.php', 'icon' => 'bi-list-check', 'permission' => 'dispatch.instructions.manage', 'excluded_roles' => ['admin']],
        ['section' => 'Trip & Dispatch', 'label' => 'Dispatcher Queue', 'href' => '/pages/dispatch_inbox.php', 'icon' => 'bi-inbox', 'permission' => 'dispatch.instructions.encode', 'excluded_roles' => ['admin']],
        ['section' => 'Trip & Dispatch', 'label' => 'Dispatch', 'href' => '/pages/dispatch.php', 'icon' => 'bi-send', 'permission' => 'trips.view'],
        ['section' => 'Trip & Dispatch', 'label' => 'Trip Monitoring', 'href' => '/pages/trip_monitor.php', 'icon' => 'bi-map', 'permission' => 'trips.view'],
        ['section' => 'Trip & Dispatch', 'label' => 'Trip Problem Reports', 'href' => '/pages/incidents.php', 'icon' => 'bi-exclamation-triangle', 'permissions' => ['incidents.manage', 'legacy.role.3']],

        // 3. Maintenance & Inspection
        ['section' => 'Maintenance & Inspection', 'label' => 'Maintenance', 'href' => '/pages/maintenance.php', 'icon' => 'bi-tools', 'permission' => 'maintenance.view'],
        ['section' => 'Maintenance & Inspection', 'label' => 'Maintenance History', 'href' => '/pages/maintenance_records.php', 'icon' => 'bi-journal-text', 'permission' => 'maintenance.view'],
        ['section' => 'Maintenance & Inspection', 'label' => 'Parts Inventory', 'href' => '/pages/parts.php', 'icon' => 'bi-box-seam', 'permission' => 'parts.view'],
        ['section' => 'Maintenance & Inspection', 'label' => 'Purchasing', 'href' => '/pages/purchasing.php', 'icon' => 'bi-cart-check', 'permission' => 'purchasing.view', 'excluded_roles' => ['admin']],

        // 4. Financial & Billing
        ['section' => 'Financial & Billing', 'label' => 'Billing', 'href' => '/pages/billing.php', 'icon' => 'bi-receipt', 'permission' => 'billing.view'],
        ['section' => 'Financial & Billing', 'label' => 'Trip Costs', 'href' => '/pages/trip_costs.php', 'icon' => 'bi-fuel-pump', 'permission' => 'finance.view'],
        ['section' => 'Financial & Billing', 'label' => 'Finance', 'href' => '/pages/finance.php', 'icon' => 'bi-wallet2', 'permission' => 'finance.funds.view', 'excluded_roles' => ['admin']],
        ['section' => 'Financial & Billing', 'label' => 'Trip Pay', 'href' => '/pages/trip_pay.php', 'icon' => 'bi-person-workspace', 'permission' => 'payroll.manage'],
        ['section' => 'Financial & Billing', 'label' => 'Payroll', 'href' => '/pages/payroll.php', 'icon' => 'bi-cash-stack', 'permission' => 'payroll.view'],

        // 5. Employee & Records
        ['section' => 'Employee & Records', 'label' => 'Attendance', 'href' => '/pages/attendance.php', 'icon' => 'bi-calendar-check', 'permission' => 'hr.attendance.view', 'also_linked_employee' => true, 'excluded_roles' => ['operations_head', 'car_carrier_dispatcher', 'container_wing_van_dispatcher', 'maintenance', 'purchasing_officer', 'finance', 'billing_collection', 'audit', 'driver', 'helper']],
        ['section' => 'Employee & Records', 'label' => 'Clients', 'href' => '/pages/clients.php', 'icon' => 'bi-person-vcard', 'permission' => 'clients.view'],
        ['section' => 'Employee & Records', 'label' => 'Documents', 'href' => '/pages/documents.php', 'icon' => 'bi-folder2-open', 'permission' => 'documents.view'],
        ['section' => 'Employee & Records', 'label' => 'Office Supplies', 'href' => '/pages/office_supplies.php', 'icon' => 'bi-clipboard2-check', 'permissions' => ['admin.supplies.view', 'admin.supplies.manage'], 'excluded_roles' => ['admin']],

        // 6. Reports & Administration
        // Each analytics link uses the same permission check as its page.
        ['section' => 'Reports & Administration', 'label' => 'Fleet Overview', 'href' => '/pages/analytics_fleet.php', 'icon' => 'bi-graph-up-arrow', 'permissions' => ['operations.reports.view', 'reports.view']],
        ['section' => 'Reports & Administration', 'label' => 'Billing & Collections Analytics', 'href' => '/pages/analytics_expenses.php', 'icon' => 'bi-cash-coin', 'permission' => 'billing.view'],
        ['section' => 'Reports & Administration', 'label' => 'Maintenance Analytics', 'href' => '/pages/analytics_maintenance.php', 'icon' => 'bi-wrench', 'permission' => 'maintenance.reports.view'],
        ['section' => 'Reports & Administration', 'label' => 'Dispatcher Reports', 'href' => '/pages/dispatcher_reports.php', 'icon' => 'bi-bar-chart-line', 'permission' => 'dispatch.reports.view', 'also_roles' => ['operations_head']],
        ['section' => 'Reports & Administration', 'label' => 'Operations Maintenance Report', 'href' => '/pages/operations_maintenance_report.php', 'icon' => 'bi-wrench-adjustable', 'permission' => 'maintenance.reports.operations.view'],
        ['section' => 'Reports & Administration', 'label' => 'Audit History', 'href' => '/pages/audit_history.php', 'icon' => 'bi-journal-text', 'permission' => 'audit.view'],
        ['section' => 'Reports & Administration', 'label' => 'User Management', 'href' => '/pages/users.php', 'icon' => 'bi-people', 'permission' => 'users.manage'],
        ['section' => 'Reports & Administration', 'label' => 'Recycle Bin', 'href' => '/pages/recycle_bin.php', 'icon' => 'bi-trash3', 'admin_only' => true],
        ['section' => 'Reports & Administration', 'label' => 'Backup', 'href' => '/pages/backup.php', 'icon' => 'bi-hdd-network', 'admin_only' => true],
    ];
}

function currentUserHasActiveEmployee(): bool {
    static $linked = null;
    if ($linked !== null) {
        return $linked;
    }
    $stmt = getDBConnection()->prepare(
        "SELECT COUNT(*) FROM employees WHERE user_id = ? AND employment_status = 'Active'"
    );
    $stmt->execute([currentUserId()]);
    return $linked = (int)$stmt->fetchColumn() === 1;
}

// ---- User initials for avatar ----------------------------
function userInitials(): string {
    $name  = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'U';
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

// ---- Whether the current user may see a nav item ---------
function navItemVisible(array $item): bool {
    if (!empty($item['admin_only'])) {
        return isAdmin();
    }
    $roleKey = currentRoleKey();
    if ($roleKey !== null && in_array($roleKey, (array)($item['excluded_roles'] ?? []), true)) {
        return false;
    }

    if (isset($item['permission']) || isset($item['permissions'])) {
        $permissions = (array)($item['permissions'] ?? $item['permission']);
        return currentUserHasAnyPermission($permissions)
            || ($roleKey !== null && in_array($roleKey, (array)($item['also_roles'] ?? []), true))
            || (($item['also_linked_employee'] ?? false) && currentUserHasActiveEmployee());
    }
    // Transitional role-based links remain available for legacy pages.
    return empty($item['roles']) || in_array(currentRoleId(), $item['roles'], true);
}

// ---- Build sidebar nav HTML --------------------------------
function buildSidebarNav(): string {
    $navItems    = getNavItems();
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '';
    $sections = [
        'Fleet Management' => ['icon' => 'bi-truck', 'items' => []],
        'Trip & Dispatch' => ['icon' => 'bi-send', 'items' => []],
        'Maintenance & Inspection' => ['icon' => 'bi-tools', 'items' => []],
        'Financial & Billing' => ['icon' => 'bi-wallet2', 'items' => []],
        'Employee & Records' => ['icon' => 'bi-folder2-open', 'items' => []],
        'Reports & Administration' => ['icon' => 'bi-gear', 'items' => []],
    ];
    $topLevelHtml = '';

    foreach ($navItems as $item) {
        if (!navItemVisible($item)) {
            continue;
        }

        $section = $item['section'];
        $href     = APP_BASE . $item['href'];
        $active   = str_ends_with($currentPath, $item['href']) ? ' active' : '';
        $label    = htmlspecialchars($item['label']);
        $icon     = htmlspecialchars($item['icon']);
        $hrefSafe = htmlspecialchars($href);

        // Top-level link: same look as the Home link above the sections.
        if ($section === null) {
            $topLevelHtml .= '<a href="' . $hrefSafe . '" class="sidebar-home-nav' . $active . '" title="' . $label . '">'
                . '<i class="bi ' . $icon . ' nav-icon"></i>'
                . '<span class="nav-label">' . $label . '</span>'
                . '</a>';
            continue;
        }

        $sections[$section]['items'][] = '<div class="nav-item">'
            . '<a href="' . $hrefSafe . '" class="nav-link' . $active . '" title="' . $label . '">'
            . '<i class="bi ' . $icon . ' nav-icon"></i>'
            . '<span class="nav-label">' . $label . '</span>'
            . '</a></div>';
    }

    $html = $topLevelHtml;
    foreach ($sections as $name => $section) {
        if (!$section['items']) {
            continue;
        }

        $sectionId = 'navSection' . preg_replace('/[^A-Za-z0-9]/', '', $name);
        $expanded = str_contains(implode('', $section['items']), ' active');
        $expandedAttr = $expanded ? 'true' : 'false';
        $collapsedClass = $expanded ? '' : ' collapsed';
        $hiddenAttr = $expanded ? '' : ' hidden';
        $sectionLabel = htmlspecialchars($name);
        $sectionIcon = htmlspecialchars($section['icon']);
        $html .= '<section class="nav-section' . ($expanded ? ' has-active' : '') . '">'
            . '<button class="nav-section-toggle' . $collapsedClass . '" type="button"'
            . ' aria-expanded="' . $expandedAttr . '" aria-controls="' . $sectionId . '"'
            . ' title="' . $sectionLabel . '">'
            . '<i class="bi ' . $sectionIcon . ' nav-section-icon" aria-hidden="true"></i>'
            . '<span class="nav-section-label">' . $sectionLabel . '</span>'
            . '<i class="bi bi-chevron-down nav-section-chevron" aria-hidden="true"></i>'
            . '</button>'
            . '<div class="nav-section-items' . $collapsedClass . '" id="' . $sectionId . '"' . $hiddenAttr . '>'
            . implode('', $section['items'])
            . '</div></section>';
    }

    return $html;
}

// ---- Fetch latest active announcements for the current audience (max 3) ----
// Latest notifications of every type, read and unread, newest first.
function getLatestNotifications(): array {
    try {
        $stmt = getDBConnection()->prepare("
            SELECT notification_id, notification_type, title, message, entity_type, entity_id,
                   is_read, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC, notification_id DESC
            LIMIT 50
        ");
        $stmt->execute([currentUserId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Unable to load notifications: ' . $e->getMessage());
        return [];
    }
}

function getUnreadNotificationCount(): int {
    try {
        $stmt = getDBConnection()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([currentUserId()]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Unable to count notifications: ' . $e->getMessage());
        return 0;
    }
}

// Bell group for a notification, used to keep the list from being a flat mess.
function notificationCategory(array $n): string {
    $type = (string)($n['notification_type'] ?? '');
    if ($type === 'reminder' || str_starts_with($type, 'automation:')) {
        return 'Reminders';
    }
    return match ((string)($n['entity_type'] ?? '')) {
        'approval_request', 'route' => 'Approvals',
        'dispatch_request', 'trip', 'trip_incident', 'trip_completion' => 'Trips & Dispatch',
        'truck', 'truck_document', 'maintenance' => 'Fleet & Maintenance',
        'billing', 'fund_request', 'payment_voucher', 'purchase_request' => 'Billing & Finance',
        'document', 'employee' => 'Documents & Staff',
        default => 'Other',
    };
}

// Announcement audience for a role key: operations, maintenance or accounting.
// Null means the role has no department audience.
function announcementAudienceForRoleKey(?string $roleKey): ?string {
    return match ($roleKey) {
        'operations_head', 'car_carrier_dispatcher', 'container_wing_van_dispatcher' => 'operations',
        'maintenance' => 'maintenance',
        'finance', 'billing_collection' => 'accounting',
        default => null,
    };
}

// Audience of the signed-in user, from the validated role key. A role without
// a key (legacy database) falls back to its legacy department grants. Admin
// sees every announcement, so callers skip the audience filter for Admin.
function currentAnnouncementAudience(): ?string {
    $roleKey = currentRoleKey();
    if ($roleKey !== null) {
        return announcementAudienceForRoleKey($roleKey);
    }
    if (currentUserHasAnyPermission(['legacy.role.3'])) return 'maintenance';
    if (currentUserHasAnyPermission(['legacy.role.4'])) return 'accounting';
    if (currentUserHasAnyPermission(['legacy.role.2'])) return 'operations';
    return ($_SESSION['role_name'] ?? '') === 'Operations Head' ? 'operations' : null;
}

function getLatestAnnouncements(): array {
    try {
        $pdo  = getDBConnection();
        $where = [
            'a.is_active = 1',
            '(a.start_date IS NULL OR a.start_date <= NOW())',
            '(a.end_date IS NULL OR a.end_date >= NOW())',
        ];
        $params = [];
        if (!isAdmin()) {
            $audience = currentAnnouncementAudience() ?? 'all';
            $where[] = "(a.audience = 'all' OR a.audience = :audience)";
            $params[':audience'] = $audience;
        }

        $stmt = $pdo->prepare(
            "SELECT a.announcement_id, a.title, a.content AS body, a.is_pinned, a.priority,
                    a.audience, a.start_date AS starts_at, a.end_date AS ends_at, a.created_at,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(' ',
        NULLIF(TRIM(ue.first_name), ''),
        NULLIF(TRIM(ue.middle_name), ''),
        NULLIF(TRIM(ue.last_name), ''),
        NULLIF(TRIM(ue.suffix), '')
    )), ''), u.username) AS author
               FROM announcements a
               JOIN users u ON a.created_by = u.user_id
               LEFT JOIN employees ue ON ue.employee_id = u.employee_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY a.is_pinned DESC, FIELD(a.priority, 'high', 'medium', 'low'), a.created_at DESC
              LIMIT 3"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// ============================================================
// layoutHead()
// ============================================================
function layoutHead(string $pageTitle = 'Mulawin FleetOps', string $extraCss = ''): void {
    $fullTitle   = htmlspecialchars($pageTitle . ' — Mulawin FleetOps');
    $navHtml     = buildSidebarNav();
    $displayName = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
    $initials    = htmlspecialchars(userInitials());
    $fullName    = htmlspecialchars($displayName);
    $roleName    = htmlspecialchars($_SESSION['role_name'] ?? '');
    $homeUrl     = htmlspecialchars(roleDashboardUrl());
    $homePath    = parse_url(roleDashboardUrl(), PHP_URL_PATH) ?: '';
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '';
    $homeActive  = $homePath !== '' && $homePath === $currentPath ? ' active' : '';
    // On the role's home page the header always reads "Dashboard".
    $breadcrumbTitle = $homeActive !== '' ? 'Dashboard' : $pageTitle;
    $base        = APP_BASE;
    $csrfName    = htmlspecialchars(CSRF_TOKEN_NAME);
    $cssTag      = $extraCss ? "<link rel=\"stylesheet\" href=\"{$extraCss}\">" : '';
    $appBaseJs   = '<script>window.APP_BASE='
        . json_encode(APP_BASE, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ';window.CURRENT_USER_ID=' . currentUserId() . ';</script>';
    $csrfToken   = generateCsrfToken();
    $csrfJs      = "<script>window.CSRF_TOKEN=\"{$csrfToken}\";window.CSRF_TOKEN_NAME=\"" . CSRF_TOKEN_NAME . "\";</script>";

    // Announcements bell badge
    $announcements  = getLatestAnnouncements();
    $unreadCount    = count($announcements);
    $badgeClass     = $unreadCount > 0 ? '' : 'd-none';
    $isHead         = isAdmin() ? 'true' : 'false';
    $notifications  = getLatestNotifications();
    $notificationCount = getUnreadNotificationCount();
    $notificationGroups = [];
    foreach ($notifications as $notification) {
        $notificationGroups[notificationCategory($notification)][] = $notification;
    }
    $notificationItems = '';
    foreach ($notificationGroups as $groupName => $groupItems) {
        $groupUnread = count(array_filter($groupItems, static fn(array $n): bool => !(int)$n['is_read']));
        $notificationItems .= '<div class="notif-group-title">' . htmlspecialchars($groupName)
            . ($groupUnread ? ' <span class="notif-group-count">' . $groupUnread . '</span>' : '') . '</div>';
        foreach ($groupItems as $notification) {
            $isUnread = !(int)$notification['is_read'];
            $notificationItems .= '<a class="dropdown-item small notif-item' . ($isUnread ? ' unread' : '') . '" href="'
                . htmlspecialchars(notificationLinkFor($notification['entity_type'], $notification['entity_id']) ?? '#')
                . '"><strong>' . htmlspecialchars($notification['title']) . '</strong><br>'
                . htmlspecialchars($notification['message'])
                . '<span class="notif-time">' . htmlspecialchars(date('M j, g:i A', strtotime($notification['created_at']))) . '</span></a>';
        }
    }
    $notificationHtml = '<div class="dropdown">'
        . '<button class="notif-btn" type="button" title="Notifications" data-bs-toggle="dropdown">'
        . '<i class="bi bi-bell"></i><span class="notif-badge ' . ($notificationCount ? '' : 'd-none') . '" id="notifBellBadge">'
        . $notificationCount . '</span></button>'
        . '<div class="dropdown-menu dropdown-menu-end p-0 notif-menu">'
        . '<div class="notif-menu-head"><span class="fw-semibold">Notifications</span>'
        . '<button type="button" class="notif-clear-btn" data-notif-clear title="Mark all as read (nothing is deleted)"'
        . ($notificationCount ? '' : ' disabled') . '>Clear</button></div>'
        . '<div class="notif-menu-body">'
        . ($notificationItems ?: '<div class="text-muted small px-3 py-3">No notifications.</div>')
        . '</div></div></div>';
    $profileHtml = '<div class="dropdown profile-dropdown">'
        . '<button class="profile-trigger" type="button" data-bs-toggle="dropdown"'
        . ' aria-expanded="false" aria-label="Account menu for ' . $fullName . '">'
        . '<span class="profile-avatar">' . $initials . '</span>'
        . '<span class="profile-identity"><span class="profile-name">' . $fullName . '</span></span>'
        . '<i class="bi bi-chevron-down profile-chevron" aria-hidden="true"></i></button>'
        . '<div class="dropdown-menu dropdown-menu-end profile-menu">'
        . '<div class="profile-menu-user"><span class="profile-menu-name">' . $fullName . '</span>'
        . '<span class="profile-menu-role">' . $roleName . '</span></div>'
        . '<div class="dropdown-divider"></div>'
        . '<label class="profile-setting" for="autoLogoutToggle" title="Sign out automatically after ' . (int)round(SESSION_TIMEOUT / 60) . ' minutes of inactivity">'
        . '<span><i class="bi bi-clock-history" aria-hidden="true"></i> Auto log out when inactive</span>'
        . '<span class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="autoLogoutToggle" data-auto-logout-toggle'
        . ((($_COOKIE['fleetops_auto_logout'] ?? '') === '1') ? ' checked' : '') . '></span></label>'
        . '<div class="dropdown-divider"></div>'
        . '<form method="post" action="' . htmlspecialchars($base . '/auth/login_handler.php') . '"'
        . ' onsubmit="return confirm(\'Log out of Mulawin FleetOps?\')">'
        . '<input type="hidden" name="action" value="logout">'
        . '<input type="hidden" name="' . $csrfName . '" value="' . $csrfToken . '">'
        . '<button type="submit" class="dropdown-item profile-logout">'
        . '<i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Log Out</span>'
        . '</button></form></div></div>';

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$fullTitle}</title>
  <link rel="icon" type="image/png" href="{$base}/assets/images/logo.png">
  <link rel="stylesheet" href="{$base}/assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="{$base}/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="{$base}/assets/css/layout.css">
  {$cssTag}
  {$appBaseJs}
  {$csrfJs}
  <script>window.IS_HEAD_MANAGEMENT = {$isHead};</script>
  <script>
    // Marks <html> itself, before ANY body content parses. This is the
    // earliest possible point in the whole page load — nothing below this
    // depends on script execution order or element existence, which is
    // what made the previous attempt (a script placed after the sidebar
    // markup) still occasionally show a flash on real page loads.
    (function(){
      if (window.innerWidth > 768 && localStorage.getItem('mulawin_sidebar_collapsed_' + window.CURRENT_USER_ID) === '1') {
        document.documentElement.classList.add('sidebar-precollapsed');
      }
    })();
  </script>
  <style>
    /* Mirrors the real .sidebar.collapsed / .app-shell.sidebar-collapsed
       rules from layout.css exactly, just scoped under the <html> class
       set above. Pure CSS — applies the instant these elements exist in
       the DOM, with no dependency on any script running "in time." This
       is what actually eliminates the flash; the <html> class alone does
       nothing without these. If the real collapsed-state rules in
       layout.css ever change, these need to be updated to match. */
    html.sidebar-precollapsed #appSidebar { width: var(--sidebar-collapsed); }
    html.sidebar-precollapsed #appSidebar .nav-label { opacity: 0; width: 0; }
    html.sidebar-precollapsed #appSidebar .nav-link { padding: 10px 0; justify-content: center; }
    html.sidebar-precollapsed #appShell .main-wrapper { margin-left: var(--sidebar-collapsed); }
    html.sidebar-precollapsed #appSidebar .sidebar-home-icon-btn { display: none; }
  </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="app-shell" id="appShell">

  <!-- ======================================================
       SIDEBAR
       ====================================================== -->
  <aside class="sidebar" id="appSidebar">

    <!-- Brand + Home button -->
    <div class="sidebar-brand">
      <a href="{$homeUrl}" class="sidebar-home-btn" title="Go to Dashboard">
        <div class="sidebar-brand-icon">
          <img src="{$base}/assets/images/logo.png" alt="Logo" style="width:70%;height:70%;object-fit:contain;"
               onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
          <i class="bi bi-truck-front-fill" style="display:none;"></i>
        </div>
      </a>
      <div class="sidebar-brand-text">
        <div class="brand-title">FleetOps</div>
        <div class="brand-sub">RP Mulawin Trucking</div>
      </div>
      <a href="{$homeUrl}" class="sidebar-home-icon-btn ms-auto" title="Go to Dashboard">
        <i class="bi bi-house-fill"></i>
      </a>
    </div>

    <!-- Nav -->
    <nav class="sidebar-nav">
      <a href="{$homeUrl}" class="sidebar-home-nav{$homeActive}">
        <i class="bi bi-house-fill nav-icon"></i>
        <span class="nav-label">Home</span>
      </a>
      {$navHtml}
    </nav>

  </aside>

  <!-- ======================================================
       MAIN WRAPPER
       ====================================================== -->
  <div class="main-wrapper">

    <header class="topnav">
      <button class="sidebar-toggle-btn" id="sidebarToggleBtn" type="button" title="Toggle sidebar">
        <i class="bi bi-list"></i>
      </button>

      <!-- Home breadcrumb -->
      <div class="topnav-breadcrumb">
        <a href="{$homeUrl}" class="bc-home" title="Dashboard">
          <i class="bi bi-house"></i>
        </a>
        <i class="bi bi-chevron-right"></i>
        <span class="bc-current">{$breadcrumbTitle}</span>
      </div>

      <div class="topnav-actions">
        {$notificationHtml}
        <!-- Announcements bell -->
        <button class="notif-btn" type="button" title="Announcements"
                data-bs-toggle="offcanvas" data-bs-target="#announcementsPanel">
          <i class="bi bi-megaphone"></i>
          <span class="notif-badge {$badgeClass}" id="notifBadge">{$unreadCount}</span>
        </button>
        {$profileHtml}
      </div>
    </header>

    <main class="page-content">
HTML;

    // ---- Render announcements offcanvas panel inline ------
    self_announcements_panel($announcements);
}

// ---- Announcements offcanvas panel -----------------------
function self_announcements_panel(array $announcements): void {
    $isHead  = isAdmin(); // Admin-only, matching ajax/announcement_handler.php
    $base    = APP_BASE;
    $minDateTime = date('Y-m-d\TH:i');

    $itemsHtml = '';
    if (empty($announcements)) {
        $itemsHtml = '<p class="text-muted text-center py-4" style="font-size:.875rem;">No announcements yet.</p>';
    } else {
        foreach ($announcements as $a) {
            $pin     = $a['is_pinned'] ? '<span class="ann-pin"><i class="bi bi-pin-fill"></i> Pinned</span>' : '';
            $title   = htmlspecialchars($a['title']);
            $body    = nl2br(htmlspecialchars($a['body']));
            $author  = htmlspecialchars($a['author']);
            $date    = date('M j, Y', strtotime($a['created_at']));
            $id      = (int)$a['announcement_id'];
            $audienceLabel = [
                'all' => 'Everyone',
                'maintenance' => 'Maintenance',
                'accounting' => 'Accounting',
                'operations' => 'Operations',
            ][$a['audience'] ?? 'all'] ?? 'Everyone';
            $deleteBtn = $isHead
                ? "<button class='ann-delete-btn' onclick='deleteAnnouncement({$id})' title='Delete'><i class='bi bi-trash'></i></button>"
                : '';

            $itemsHtml .= <<<ITEM
<div class="ann-item" id="ann-{$id}">
  <div class="ann-header">
    <div>
      {$pin}
      <div class="ann-title">{$title}</div>
    </div>
    {$deleteBtn}
  </div>
  <div class="ann-body">{$body}</div>
  <div class="ann-meta">{$author} &middot; {$date} &middot; {$audienceLabel}</div>
</div>
ITEM;
        }
    }

    $addFormHtml = '';
    if ($isHead) {
        $addFormHtml = <<<FORM
<div class="ann-add-form mt-3" id="annAddForm" style="display:none;">
  <div class="mb-2">
    <input type="text" class="form-control form-control-sm" id="annTitle" placeholder="Title *" maxlength="180">
  </div>
  <div class="mb-2">
    <textarea class="form-control form-control-sm" id="annBody" rows="3" placeholder="Message *"></textarea>
  </div>
  <div class="row g-2 mb-2">
    <div class="col-6">
      <label class="form-label mb-1" for="annStartsAt" style="font-size:.75rem;">Starts</label>
      <input type="datetime-local" class="form-control form-control-sm" id="annStartsAt"
             min="{$minDateTime}" required>
    </div>
    <div class="col-6">
      <label class="form-label mb-1" for="annEndsAt" style="font-size:.75rem;">Ends</label>
      <input type="datetime-local" class="form-control form-control-sm" id="annEndsAt"
             min="{$minDateTime}" required>
    </div>
  </div>
  <div class="mb-2">
    <label class="form-label mb-1" for="annAudience" style="font-size:.75rem;">Audience</label>
    <select class="form-select form-select-sm" id="annAudience">
      <option value="all">Everyone</option>
      <option value="maintenance">Maintenance</option>
      <option value="accounting">Accounting</option>
      <option value="operations">Operations</option>
    </select>
  </div>
  <div class="mb-2 d-flex align-items-center gap-2">
    <input type="checkbox" id="annPinned" class="form-check-input">
    <label for="annPinned" class="form-check-label" style="font-size:.8rem;">Pin this announcement</label>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-primary btn-sm flex-grow-1" onclick="submitAnnouncement()">Post</button>
    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('annAddForm').style.display='none'">Cancel</button>
  </div>
  <div id="annError" class="text-danger mt-1" style="font-size:.8rem;display:none;"></div>
</div>
FORM;
    }

    $addBtnHtml = $isHead
        ? '<button class="btn btn-sm btn-primary" onclick="document.getElementById(\'annAddForm\').style.display=\'block\'"><i class="bi bi-plus-lg me-1"></i>Add</button>'
        : '';

    echo <<<HTML
<!-- Announcements Offcanvas -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="announcementsPanel"
     style="width:340px; background:var(--card-bg); color:var(--text-primary);">
  <div class="offcanvas-header" style="border-bottom:1px solid var(--card-border);">
    <h5 class="offcanvas-title d-flex align-items-center gap-2">
      <i class="bi bi-megaphone-fill text-warning"></i> Announcements
    </h5>
    <div class="d-flex align-items-center gap-2">
      {$addBtnHtml}
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas"
              style="filter: var(--btn-close-filter);"></button>
    </div>
  </div>
  <div class="offcanvas-body p-3">
    {$addFormHtml}
    <div id="annList">
      {$itemsHtml}
    </div>
    <a href="{$base}/pages/announcements.php" class="ann-view-all">
      View all announcements <i class="bi bi-arrow-right"></i>
    </a>
  </div>
</div>
HTML;
}

// ============================================================
// layoutFoot()
// ============================================================
function layoutFoot(): void {
    $base        = APP_BASE;
    $extraScript = '';
    // Chart.js is only loaded by pages that opt in via $GLOBALS['page_needs_chart'].
    $chartScript = !empty($GLOBALS['page_needs_chart'])
        ? '<script src="' . $base . '/assets/vendor/chartjs/chart.umd.min.js"></script>'
        : '';
    $layoutScriptPath = __DIR__ . '/../assets/js/layout.js';
    $layoutScriptVersion = is_file($layoutScriptPath) ? (string)filemtime($layoutScriptPath) : (string)time();
    $stateScriptPath = __DIR__ . '/../assets/js/state_persistence.js';
    $stateScriptVersion = is_file($stateScriptPath) ? (string)filemtime($stateScriptPath) : (string)time();
    $filterInputScriptPath = __DIR__ . '/../assets/js/filter_inputs.js';
    $filterInputScriptVersion = is_file($filterInputScriptPath) ? (string)filemtime($filterInputScriptPath) : (string)time();
    if (!empty($GLOBALS['page_js'])) {
        $scriptUrlPath = parse_url($GLOBALS['page_js'], PHP_URL_PATH);
        $scriptRelativePath = ltrim(str_replace(rtrim(APP_BASE, '/') . '/', '', $scriptUrlPath), '/');
        $scriptPath = __DIR__ . '/../' . $scriptRelativePath;
        $scriptVersion = is_file($scriptPath) ? (string)filemtime($scriptPath) : (string)time();
        $extraScript = '<script src="' . htmlspecialchars($GLOBALS['page_js'], ENT_QUOTES)
            . '?v=' . $scriptVersion . '"></script>';
    }
    echo <<<HTML

    </main>
  </div>
</div>

<script src="{$base}/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
{$chartScript}
<script src="{$base}/assets/js/filter_inputs.js?v={$filterInputScriptVersion}"></script>
<script src="{$base}/assets/js/state_persistence.js?v={$stateScriptVersion}"></script>
<script src="{$base}/assets/js/layout.js?v={$layoutScriptVersion}"></script>
{$extraScript}
</body>
</html>
HTML;
}