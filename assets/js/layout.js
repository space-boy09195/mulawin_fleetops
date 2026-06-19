// ============================================================
// assets/js/layout.js
// Handles: sidebar collapse, dark/light mode toggle,
//          active nav highlighting, mobile overlay
// No jQuery — vanilla JS only
// ============================================================

(function () {
  'use strict';

  // ---- DOM refs (all grabbed once on DOMContentLoaded) -----
  let sidebar, appShell, toggleBtn, themeToggleBtn,
      themeIcon, themeLabel, overlay;

  // ---- Theme -----------------------------------------------
  const THEME_KEY = 'mulawin_theme';
  const THEMES    = { light: 'light', dark: 'dark' };

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem(THEME_KEY, theme);

    // Update toggle button icon + label
    if (themeIcon && themeLabel) {
      if (theme === THEMES.dark) {
        themeIcon.className  = 'bi bi-sun-fill toggle-icon';
        themeLabel.textContent = 'Light Mode';
      } else {
        themeIcon.className  = 'bi bi-moon-fill toggle-icon';
        themeLabel.textContent = 'Dark Mode';
      }
    }
  }

  function toggleTheme() {
    const current = localStorage.getItem(THEME_KEY) || THEMES.light;
    applyTheme(current === THEMES.dark ? THEMES.light : THEMES.dark);
  }

  // ---- Sidebar Collapse ------------------------------------
  const COLLAPSED_KEY = 'mulawin_sidebar_collapsed';

  function setSidebarCollapsed(collapsed) {
    if (collapsed) {
      sidebar.classList.add('collapsed');
      appShell.classList.add('sidebar-collapsed');
    } else {
      sidebar.classList.remove('collapsed');
      appShell.classList.remove('sidebar-collapsed');
    }
    localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0');
  }

  function toggleSidebar() {
    // On mobile — use overlay mode
    if (window.innerWidth <= 768) {
      const isOpen = sidebar.classList.contains('mobile-open');
      sidebar.classList.toggle('mobile-open', !isOpen);
      overlay.classList.toggle('active', !isOpen);
      return;
    }
    // On desktop — collapse to icon-only
    const isCollapsed = sidebar.classList.contains('collapsed');
    setSidebarCollapsed(!isCollapsed);
  }

  // ---- Active nav link -------------------------------------
  // Marks the nav link whose href matches the current page URL
  function setActiveNav() {
    const currentPath = window.location.pathname;
    const links = document.querySelectorAll('.nav-link[href]');

    links.forEach((link) => {
      const linkPath = new URL(link.href, window.location.origin).pathname;
      if (linkPath === currentPath) {
        link.classList.add('active');
      } else {
        link.classList.remove('active');
      }
    });
  }

  // ---- Init ------------------------------------------------
  document.addEventListener('DOMContentLoaded', () => {
    sidebar         = document.getElementById('appSidebar');
    appShell        = document.getElementById('appShell');
    toggleBtn       = document.getElementById('sidebarToggleBtn');
    themeToggleBtn  = document.getElementById('themeToggleBtn');
    themeIcon       = document.getElementById('themeIcon');
    themeLabel      = document.getElementById('themeLabel');
    overlay         = document.getElementById('sidebarOverlay');

    if (!sidebar || !appShell) return;

    // Restore theme preference (before paint to avoid flash)
    const savedTheme = localStorage.getItem(THEME_KEY) || THEMES.light;
    applyTheme(savedTheme);

    // Restore sidebar collapsed state (desktop only)
    if (window.innerWidth > 768) {
      const wasCollapsed = localStorage.getItem(COLLAPSED_KEY) === '1';
      setSidebarCollapsed(wasCollapsed);
    }

    // Toggle sidebar button
    if (toggleBtn) {
      toggleBtn.addEventListener('click', toggleSidebar);
    }

    // Close sidebar on overlay click (mobile)
    if (overlay) {
      overlay.addEventListener('click', () => {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
      });
    }

    // Theme toggle button
    if (themeToggleBtn) {
      themeToggleBtn.addEventListener('click', toggleTheme);
    }

    // Set active nav
    setActiveNav();
  });

  // Prevent flash of wrong theme — run immediately before DOM ready
  // (Sets attribute on <html> before body renders)
  const savedThemeEarly = localStorage.getItem(THEME_KEY);
  if (savedThemeEarly === 'dark') {
    document.documentElement.setAttribute('data-theme', 'dark');
  }

})();