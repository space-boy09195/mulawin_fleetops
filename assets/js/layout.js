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
    window.dispatchEvent(new CustomEvent('mulawin:themechange', {
      detail: { theme: theme }
    }));

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
  const SCROLL_KEY = 'mulawin_sidebar_scroll_top';

  function setSidebarCollapsed(collapsed) {
    if (!sidebar || !appShell) return;
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
      // A desktop collapsed state must never carry into the mobile drawer.
      sidebar.classList.remove('collapsed');
      appShell.classList.remove('sidebar-collapsed');
      const isOpen = sidebar.classList.contains('mobile-open');
      sidebar.classList.toggle('mobile-open', !isOpen);
      if (overlay) overlay.classList.toggle('active', !isOpen);
      return;
    }
    sidebar.classList.remove('mobile-open');
    if (overlay) overlay.classList.remove('active');
    // On desktop — collapse to icon-only
    const isCollapsed = sidebar.classList.contains('collapsed');
    setSidebarCollapsed(!isCollapsed);
  }

  function syncSidebarViewport() {
    if (window.innerWidth <= 768) {
      sidebar.classList.remove('collapsed');
      appShell.classList.remove('sidebar-collapsed');
      return;
    }
    sidebar.classList.remove('mobile-open');
    if (overlay) overlay.classList.remove('active');
    setSidebarCollapsed(localStorage.getItem(COLLAPSED_KEY) === '1');
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

  function showTableText(title, text) {
    let modal = document.getElementById('tableTextModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'tableTextModal';
      modal.className = 'modal fade';
      modal.tabIndex = -1;
      modal.setAttribute('aria-hidden', 'true');
      modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h2 class="modal-title fs-5"></h2>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body table-text-modal-body"></div>
          </div>
        </div>`;
      document.body.append(modal);
    }

    modal.querySelector('.modal-title').textContent = title;
    modal.querySelector('.table-text-modal-body').textContent = text;
    bootstrap.Modal.getOrCreateInstance(modal).show();
  }

  function enhanceTable(table) {
    if (table.classList.contains('bil-print-table')) return;

    const responsiveWrapper = table.closest('.table-responsive');
    if (responsiveWrapper) {
      responsiveWrapper.classList.add('app-table-scroll');
    } else if (!table.parentElement?.classList.contains('app-table-scroll')) {
      const wrapper = document.createElement('div');
      wrapper.className = 'table-responsive app-table-scroll';
      table.before(wrapper);
      wrapper.append(table);
    }

    const headerRow = table.tHead?.rows[0];
    if (!headerRow?.cells.length) return;

    const lastHeader = headerRow.cells[headerRow.cells.length - 1];
    const lastHeaderText = lastHeader.textContent.trim();
    const hasRowActions = Array.from(table.tBodies).some((body) =>
      Array.from(body.rows).some((row) => {
        const cell = row.cells[row.cells.length - 1];
        return cell?.querySelector('button, a[href], input[type="submit"]');
      })
    );

    if (/^action$/i.test(lastHeaderText) || (!lastHeaderText && hasRowActions)) {
      lastHeader.textContent = 'Actions';
    }
    if (/^actions?$/i.test(lastHeader.textContent.trim())) {
      lastHeader.classList.add('table-actions-column');
      Array.from(table.tBodies).forEach((body) => {
        Array.from(body.rows).forEach((row) => {
          row.cells[row.cells.length - 1]?.classList.add('table-actions-column');
        });
      });
    }

    const expandableColumns = Array.from(headerRow.cells)
      .map((header, index) => ({
        index,
        title: header.textContent.trim(),
      }))
      .filter(({ title }) => /description|details?|notes?|remarks?|messages?|explanations?|addresses?/i.test(title));

    expandableColumns.forEach(({ index, title }) => {
      Array.from(table.tBodies).forEach((body) => {
        Array.from(body.rows).forEach((row) => {
          const cell = row.cells[index];
          if (!cell || cell.dataset.textEnhanced || cell.querySelector('button, a, input, select, textarea')) return;
          const fullText = cell.textContent.trim();
          if (fullText.length <= 120) return;

          cell.dataset.textEnhanced = 'true';
          const preview = document.createElement('span');
          preview.className = 'table-text-preview';
          preview.textContent = fullText.slice(0, 112).trimEnd();

          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'table-read-more';
          button.textContent = '...';
          button.setAttribute('aria-label', `Read full ${title.toLowerCase()}`);
          button.addEventListener('click', () => showTableText(title, fullText));

          cell.replaceChildren(preview, button);
        });
      });
    });
  }

  function enhanceTables() {
    document.querySelectorAll('.page-content table').forEach(enhanceTable);
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

    const pageContent = document.querySelector('.page-content');
    if (pageContent) {
      enhanceTables();
      const tableObserver = new MutationObserver((mutations) => {
        const changedTables = new Set();
        mutations.forEach((mutation) => {
          if (mutation.target instanceof Element) {
            const ownerTable = mutation.target.closest('table');
            if (ownerTable) changedTables.add(ownerTable);
          }
          mutation.addedNodes.forEach((node) => {
            if (!(node instanceof Element)) return;
            const ownerTable = node.closest('table');
            if (ownerTable) changedTables.add(ownerTable);
            if (node.matches('table')) changedTables.add(node);
            node.querySelectorAll('table').forEach((table) => changedTables.add(table));
          });
        });
        changedTables.forEach(enhanceTable);
      });
      tableObserver.observe(pageContent, { childList: true, subtree: true });
    }

    if (!sidebar || !appShell) return;

    // Restore theme preference (before paint to avoid flash)
    const savedTheme = localStorage.getItem(THEME_KEY) || THEMES.light;
    applyTheme(savedTheme);

    // Restore the appropriate state for the current viewport.
    syncSidebarViewport();

    const savedScrollTop = Number.parseInt(localStorage.getItem(SCROLL_KEY) || '0', 10);
    if (Number.isFinite(savedScrollTop) && savedScrollTop > 0) {
      sidebar.querySelector('.sidebar-nav')?.scrollTo(0, savedScrollTop);
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

    window.addEventListener('resize', syncSidebarViewport);

    const sidebarNav = sidebar.querySelector('.sidebar-nav');
    sidebarNav?.addEventListener('scroll', () => {
      localStorage.setItem(SCROLL_KEY, String(sidebarNav.scrollTop));
    }, { passive: true });

    // Theme toggle button
    if (themeToggleBtn) {
      themeToggleBtn.addEventListener('click', toggleTheme);
    }

    // Set active nav
    setActiveNav();
  });

  // Prevent flash of wrong theme — run immediately before DOM ready
  const savedThemeEarly = localStorage.getItem(THEME_KEY);
  if (savedThemeEarly === 'dark') {
    document.documentElement.setAttribute('data-theme', 'dark');
  }

})();

// ============================================================
// Announcements — global functions (called from layout.php HTML)
// ============================================================

async function submitAnnouncement() {
  const title   = document.getElementById('annTitle').value.trim();
  const body    = document.getElementById('annBody').value.trim();
  const pinned  = document.getElementById('annPinned').checked ? 1 : 0;
  const audience = document.getElementById('annAudience').value;
  const startsAt = document.getElementById('annStartsAt').value;
  const endsAt   = document.getElementById('annEndsAt').value;
  const errEl   = document.getElementById('annError');

  errEl.style.display = 'none';

  if (!title || !body) {
    errEl.textContent   = 'Title and message are required.';
    errEl.style.display = 'block';
    return;
  }

  const start = new Date(startsAt);
  const end = new Date(endsAt);
  const now = new Date();
  if (!startsAt || !endsAt || Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())
      || start < now || end < now || end <= start) {
    errEl.textContent = !startsAt || !endsAt
      ? 'Start and end date/time are required.'
      : 'Use future dates, with the end after the start.';
    errEl.style.display = 'block';
    return;
  }

  const fd = new FormData();
  fd.append('title',     title);
  fd.append('body',      body);
  fd.append('is_pinned', pinned);
  fd.append('audience',  audience);
  fd.append('starts_at', startsAt);
  fd.append('ends_at',   endsAt);
  fd.append(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);

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
  fd.append(window.CSRF_TOKEN_NAME, window.CSRF_TOKEN);

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