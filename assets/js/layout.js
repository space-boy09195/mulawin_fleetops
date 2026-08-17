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
        themeLabel.textContent = 'RPM Identity Mode';
      } else {
        themeIcon.className  = 'bi bi-moon-fill toggle-icon';
        themeLabel.textContent = 'RPM Grid Mode';
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

    // The <html>.sidebar-precollapsed class + matching CSS in layout.php is
    // only a temporary bridge to prevent a flash before this script runs —
    // setSidebarCollapsed() above has now applied the real classes on
    // #appSidebar/#appShell, which is what toggleSidebar() actually reads
    // and updates from here on. Without removing the bridge class, it would
    // stay on <html> for the rest of the page's life (nothing else ever
    // clears it), permanently forcing the collapsed CSS regardless of what
    // the real classes say — which is exactly why the toggle stopped being
    // able to re-expand the sidebar. The bridge's job ends here.
    document.documentElement.classList.remove('sidebar-precollapsed');

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
  const savedThemeEarly = localStorage.getItem(THEME_KEY);
  document.documentElement.setAttribute('data-theme', savedThemeEarly === 'dark' ? 'dark' : 'light');

})();

// ============================================================
// Announcements — global functions (called from layout.php HTML)
// ============================================================

async function submitAnnouncement() {
  const title   = document.getElementById('annTitle').value.trim();
  const body    = document.getElementById('annBody').value.trim();
  const pinned  = document.getElementById('annPinned').checked ? 1 : 0;
  const errEl   = document.getElementById('annError');

  errEl.style.display = 'none';

  if (!title || !body) {
    errEl.textContent   = 'Title and message are required.';
    errEl.style.display = 'block';
    return;
  }

  const fd = new FormData();
  fd.append('title',     title);
  fd.append('body',      body);
  fd.append('is_pinned', pinned);

  try {
    const res    = await fetch(window.APP_BASE + '/ajax/announcement_handler.php?action=add', { method: 'POST', body: fd });
    const result = await res.json();
    if (result.success) {
      window.location.reload();
    } else {
      errEl.textContent   = result.message || 'Could not post announcement.';
      errEl.style.display = 'block';
    }
  } catch (e) {
    errEl.textContent   = 'Network error. Please try again.';
    errEl.style.display = 'block';
  }
}

async function deleteAnnouncement(id) {
  if (!confirm('Delete this announcement?')) return;

  const fd = new FormData();
  fd.append('announcement_id', id);

  try {
    const res    = await fetch(window.APP_BASE + '/ajax/announcement_handler.php?action=delete', { method: 'POST', body: fd });
    const result = await res.json();
    if (result.success) {
      const el = document.getElementById('ann-' + id);
      if (el) el.remove();
    } else {
      alert(result.message || 'Could not delete.');
    }
  } catch (e) {
    alert('Network error.');
  }
}