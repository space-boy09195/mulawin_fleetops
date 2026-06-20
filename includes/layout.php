<?php
// ============================================================
// includes/layout.php
// Shared shell: sidebar + topnav
//
// HOW TO USE on any protected page:
//   require_once __DIR__ . '/../includes/session.php';
//   require_once __DIR__ . '/../includes/layout.php';
//   requireRole([ROLE_DISPATCHER]);
//   layoutHead('Page Title');
//   // ... your page HTML ...
//   layoutFoot();
// ============================================================

require_once __DIR__ . '/../config/app.php';

// ---- Navigation items (role-filtered in layoutHead) --------
function getNavItems(): array {
    return [
        ['section' => 'Operations'],
        ['label' => 'Fleet Status',        'href' => '/pages/fleet_status.php', 'icon' => 'bi-truck',              'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER]],
        ['label' => 'Trip Monitoring',     'href' => '/pages/trip_monitor.php', 'icon' => 'bi-map',                'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER]],
        ['label' => 'Dispatch',            'href' => '/pages/dispatch.php',     'icon' => 'bi-send',               'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER]],
        ['label' => 'Incidents',           'href' => '/pages/incidents.php',    'icon' => 'bi-exclamation-triangle','roles' => [ROLE_HEAD_MANAGEMENT, ROLE_DISPATCHER]],
        ['section' => 'Maintenance'],
        ['label' => 'Checklists',          'href' => '/pages/checklists.php',   'icon' => 'bi-clipboard-check',    'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_MAINTENANCE]],
        ['label' => 'Maintenance Records', 'href' => '/pages/maintenance.php',  'icon' => 'bi-tools',              'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_MAINTENANCE]],
        ['label' => 'Parts Inventory',     'href' => '/pages/parts.php',        'icon' => 'bi-box-seam',           'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_MAINTENANCE]],
        ['section' => 'Accounting'],
        ['label' => 'Billing',             'href' => '/pages/billing.php',      'icon' => 'bi-receipt',            'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]],
        ['label' => 'Collections',         'href' => '/pages/collections.php',  'icon' => 'bi-cash-stack',         'roles' => [ROLE_HEAD_MANAGEMENT, ROLE_ACCOUNTING]],
        ['section' => 'Repository'],
        ['label' => 'Documents',           'href' => '/pages/documents.php',    'icon' => 'bi-folder2-open',       'roles' => []],
        ['section' => 'Admin'],
        ['label' => 'User Management',     'href' => '/pages/users.php',        'icon' => 'bi-people',             'roles' => [ROLE_HEAD_MANAGEMENT]],
        ['label' => 'Dashboard',           'href' => '/pages/dashboard_head.php','icon' => 'bi-speedometer2',      'roles' => [ROLE_HEAD_MANAGEMENT]],
    ];
}

// ---- User initials for avatar ------------------------------
function userInitials(): string {
    $name  = $_SESSION['full_name'] ?? 'U';
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

// ---- Build sidebar nav HTML --------------------------------
function buildSidebarNav(): string {
    $navItems    = getNavItems();
    $userRole    = currentRoleId();
    $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $html        = '';

    foreach ($navItems as $item) {
        if (isset($item['section'])) {
            $html .= '<div class="nav-section-label">' . htmlspecialchars($item['section']) . '</div>';
            continue;
        }
        if (!empty($item['roles']) && !in_array($userRole, $item['roles'], true)) {
            continue;
        }
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
    return $html;
}

// ============================================================
// layoutHead() — call at the top of every protected page
// Outputs everything from <!DOCTYPE> through the opening
// <main class="page-content"> tag
// ============================================================
// $extraCss : path to a page-specific CSS file e.g. APP_BASE.'/assets/css/fleet_status.css'
// $extraJs  : path to a page-specific JS  file e.g. APP_BASE.'/assets/js/fleet_status.js'
function layoutHead(string $pageTitle = 'Mulawin FleetOps', string $extraCss = '', string $extraJs = ''): void {
    $fullTitle   = htmlspecialchars($pageTitle . ' — Mulawin FleetOps');
    $navHtml     = buildSidebarNav();
    $initials    = userInitials();
    $fullName    = htmlspecialchars($_SESSION['full_name'] ?? '');
    $roleName    = htmlspecialchars($_SESSION['role_name'] ?? '');
    $logoutUrl   = htmlspecialchars(APP_BASE . '/auth/login_handler.php?action=logout');
    $base        = APP_BASE;
    $cssTag      = $extraCss ? "<link rel=\"stylesheet\" href=\"{$extraCss}\">" : '';
    $jsVar       = $extraJs  ? "<script>window.APP_BASE=\"{$base}\";window.PAGE_JS=\"{$extraJs}\";</script>" : '';

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
  {$jsVar}
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

  <aside class="sidebar" id="appSidebar">

    <div class="sidebar-brand">
      <div class="sidebar-brand-icon">
        <i class="bi bi-truck-front-fill"></i>
      </div>
      <div class="sidebar-brand-text">
        <div class="brand-title">FleetOps</div>
        <div class="brand-sub">RP Mulawin Trucking</div>
      </div>
    </div>

    <nav class="sidebar-nav">
      {$navHtml}
    </nav>

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

  <div class="main-wrapper">

    <header class="topnav">
      <button class="sidebar-toggle-btn" id="sidebarToggleBtn" type="button" title="Toggle sidebar">
        <i class="bi bi-list"></i>
      </button>
      <div class="topnav-breadcrumb">
        <span>Mulawin FleetOps</span>
        <i class="bi bi-chevron-right"></i>
        <span class="bc-current">{$pageTitle}</span>
      </div>
      <div class="topnav-actions">
        <button class="notif-btn" type="button" title="Notifications">
          <i class="bi bi-bell"></i>
          <span class="notif-badge d-none" id="notifBadge"></span>
        </button>
        <a href="{$logoutUrl}" class="logout-btn" onclick="return confirm('Log out of Mulawin FleetOps?')">
          <i class="bi bi-box-arrow-right"></i>
          <span>Log Out</span>
        </a>
      </div>
    </header>

    <main class="page-content">
HTML;
}

// ============================================================
// layoutFoot() — call at the bottom of every protected page
// Closes <main>, <div class="main-wrapper">, app-shell, body, html
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