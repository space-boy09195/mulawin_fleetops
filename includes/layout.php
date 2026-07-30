<?php
// ============================================================
// includes/layout.php
// Shared shell: sidebar + topnav + announcements
// ============================================================

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

// ---- Role → dashboard URL map ----------------------------
function roleDashboardUrl(): string {
    $map = [
        ROLE_HEAD_MANAGEMENT => '/pages/dashboard_head.php',
        ROLE_DISPATCHER      => '/pages/dashboard_dispatcher.php',
        ROLE_MAINTENANCE     => '/pages/dashboard_maintenance.php',
        ROLE_ACCOUNTING      => '/pages/dashboard_accounting.php',
    ];
    return APP_BASE . ($map[currentRoleId()] ?? '/pages/dashboard_head.php');
}

// ---- Navigation definition --------------------------------
// roles: [] means ALL roles can see it
// sections are only rendered if at least one child is visible
function getNavItems(): array {
    return [
        ['section' => 'Insights'],
        ['label' => 'Analytics',           'href' => '/pages/analytics.php',    'icon' => 'bi-graph-up-arrow',      'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER, ROLE_MAINTENANCE, ROLE_ACCOUNTING]],
        ['section' => 'Operations'],
        ['label' => 'Fleet Status',        'href' => '/pages/fleet_status.php', 'icon' => 'bi-truck',               'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER, ROLE_MAINTENANCE]],
        ['label' => 'Trip Monitoring',     'href' => '/pages/trip_monitor.php', 'icon' => 'bi-map',                 'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER]],
        ['label' => 'Dispatch',            'href' => '/pages/dispatch.php',     'icon' => 'bi-send',                'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER]],
        ['label' => 'Incidents',           'href' => '/pages/incidents.php',    'icon' => 'bi-exclamation-triangle','roles' => [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER]],
        ['section' => 'Maintenance'],
        //['label' => 'Checklists',          'href' => '/pages/checklists.php',   'icon' => 'bi-clipboard-check',     'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_MAINTENANCE]],
        ['label' => 'Maintenance Records', 'href' => '/pages/maintenance.php',  'icon' => 'bi-tools',               'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_MAINTENANCE]],
        ['label' => 'Parts Inventory',     'href' => '/pages/parts.php',        'icon' => 'bi-box-seam',            'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_MAINTENANCE]],
        ['section' => 'Accounting'],
        ['label' => 'Billing',             'href' => '/pages/billing.php',      'icon' => 'bi-receipt',             'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]],
        //['label' => 'Collections',         'href' => '/pages/collections.php',  'icon' => 'bi-cash-stack',          'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]],
        ['section' => 'Repository'],
        ['label' => 'Documents',           'href' => '/pages/documents.php',    'icon' => 'bi-folder2-open',        'roles' => []],
        ['section' => 'Admin'],
        ['label' => 'User Management',     'href' => '/pages/users.php',        'icon' => 'bi-people',              'roles' => [ROLE_HEAD_MANAGEMENT]],
    ];
}

// ---- User initials for avatar ----------------------------
function userInitials(): string {
    $name  = $_SESSION['full_name'] ?? 'U';
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

// ---- Build sidebar nav HTML (sections hidden if no visible children)
function buildSidebarNav(): string {
    $navItems    = getNavItems();
    $userRole    = currentRoleId();
    $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $html        = '';

    $pendingSection = '';   // buffer current section label
    $sectionHasItems = false;

    foreach ($navItems as $item) {
        // Section header — buffer it, only flush when a visible item follows
        if (isset($item['section'])) {
            if ($pendingSection !== '' && $sectionHasItems) {
                $html .= $pendingSection;
            } elseif ($pendingSection !== '' && !$sectionHasItems) {
                // previous section had no visible items — discard it
            }
            $pendingSection  = '<div class="nav-section-label">' . htmlspecialchars($item['section']) . '</div>';
            $sectionHasItems = false;
            continue;
        }

        // Skip if user's role isn't allowed
        if (!empty($item['roles']) && !in_array($userRole, $item['roles'], true)) {
            continue;
        }

        // Flush buffered section label now that we have a visible item
        if ($pendingSection !== '') {
            $html .= $pendingSection;
            $pendingSection = '';
        }
        $sectionHasItems = true;

        $href     = APP_BASE . $item['href'];
        $active   = str_ends_with($currentPath, $item['href']) ? ' active' : '';
        $label    = htmlspecialchars($item['label']);
        $icon     = htmlspecialchars($item['icon']);
        $hrefSafe = htmlspecialchars($href);

        $html .= '<div class="nav-item">'
               . '<a href="' . $hrefSafe . '" class="nav-link' . $active . '" title="' . $label . '">'
               . '<i class="bi ' . $icon . ' nav-icon"></i>'
               . '<span class="nav-label">' . $label . '</span>'
               . '</a></div>';
    }

    // Flush last section if it had items
    if ($pendingSection !== '' && $sectionHasItems) {
        // already flushed inline above — nothing to do
    }

    return $html;
}

// ---- Fetch latest announcements (max 3, pinned first) ----
function getLatestAnnouncements(): array {
    try {
        $pdo  = getDBConnection();
        $stmt = $pdo->query(
            "SELECT a.announcement_id, a.title, a.body, a.is_pinned, a.created_at,
                    u.full_name AS author
               FROM announcements a
               JOIN users u ON a.created_by = u.user_id
              ORDER BY a.is_pinned DESC, a.created_at DESC
              LIMIT 3"
        );
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
    $initials    = userInitials();
    $fullName    = htmlspecialchars($_SESSION['full_name'] ?? '');
    $roleName    = htmlspecialchars($_SESSION['role_name'] ?? '');
    $logoutUrl   = htmlspecialchars(APP_BASE . '/auth/login_handler.php?action=logout');
    $homeUrl     = htmlspecialchars(roleDashboardUrl());
    $base        = APP_BASE;
    $cssTag      = $extraCss ? "<link rel=\"stylesheet\" href=\"{$extraCss}\">" : '';
    $appBaseJs   = "<script>window.APP_BASE=\"{$base}\";</script>";

    // Announcements bell badge
    $announcements  = getLatestAnnouncements();
    $unreadCount    = count($announcements);
    $badgeClass     = $unreadCount > 0 ? '' : 'd-none';
    $isHead         = currentRoleId() === ROLE_HEAD_MANAGEMENT ? 'true' : 'false';

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$fullTitle}</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="{$base}/assets/css/layout.css">
  {$cssTag}
  {$appBaseJs}
  <script>window.IS_HEAD_MANAGEMENT = {$isHead};</script>
  <script>
    (function(){
      var t = localStorage.getItem('mulawin_theme');
      if (t === 'dark') document.documentElement.setAttribute('data-theme','dark');
    })();
  </script>
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
          <i class="bi bi-truck-front-fill"></i>
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
      {$navHtml}
    </nav>

    <!-- Footer: theme toggle + user -->
    <div class="sidebar-footer">
      <button class="theme-toggle-btn" id="themeToggleBtn" type="button">
        <i class="bi bi-moon-fill toggle-icon" id="themeIcon"></i>
        <span class="theme-toggle-label" id="themeLabel">Dark Mode</span>
      </button>
      <div class="sidebar-user mt-2">
        <div class="sidebar-avatar">{$initials}</div>
        <div class="sidebar-user-info">
          <div class="sidebar-user-name">{$fullName}</div>
          <div class="sidebar-user-role">{$roleName}</div>
        </div>
      </div>
    </div>

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
        <span class="bc-current">{$pageTitle}</span>
      </div>

      <div class="topnav-actions">
        <!-- Announcements bell -->
        <button class="notif-btn" type="button" title="Announcements"
                data-bs-toggle="offcanvas" data-bs-target="#announcementsPanel">
          <i class="bi bi-megaphone"></i>
          <span class="notif-badge {$badgeClass}" id="notifBadge">{$unreadCount}</span>
        </button>

        <!-- Logout -->
        <a href="{$logoutUrl}" class="logout-btn"
           onclick="return confirm('Log out of Mulawin FleetOps?')">
          <i class="bi bi-box-arrow-right"></i>
          <span>Log Out</span>
        </a>
      </div>
    </header>

    <main class="page-content">
HTML;

    // ---- Render announcements offcanvas panel inline ------
    self_announcements_panel($announcements);
}

// ---- Announcements offcanvas panel -----------------------
function self_announcements_panel(array $announcements): void {
    $isHead  = currentRoleId() === ROLE_HEAD_MANAGEMENT;
    $base    = APP_BASE;

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
  <div class="ann-meta">{$author} &middot; {$date}</div>
</div>
ITEM;
        }
    }

    $addFormHtml = '';
    if ($isHead) {
        $addFormHtml = <<<FORM
<div class="ann-add-form mt-3" id="annAddForm" style="display:none;">
  <div class="mb-2">
    <input type="text" class="form-control form-control-sm" id="annTitle" placeholder="Title *" maxlength="200">
  </div>
  <div class="mb-2">
    <textarea class="form-control form-control-sm" id="annBody" rows="3" placeholder="Message *"></textarea>
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
    if (!empty($GLOBALS['page_js'])) {
        $extraScript = '<script src="' . $GLOBALS['page_js'] . '"></script>';
    }
    echo <<<HTML

    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="{$base}/assets/js/layout.js"></script>
{$extraScript}
</body>
</html>
HTML;
}