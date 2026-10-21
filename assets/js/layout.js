// ============================================================
// assets/js/layout.js
// Handles: sidebar collapse, active nav highlighting, mobile overlay
// No jQuery — vanilla JS only
// ============================================================

(function () {
  'use strict';

  // ---- DOM refs (all grabbed once on DOMContentLoaded) -----
  let sidebar, appShell, toggleBtn, overlay;

  const USER_SCOPE = String(window.CURRENT_USER_ID ?? 'guest');

  // ---- Sidebar Collapse ------------------------------------
  const COLLAPSED_KEY = `mulawin_sidebar_collapsed_${USER_SCOPE}`;
  const SCROLL_KEY = `mulawin_sidebar_scroll_top_${USER_SCOPE}`;

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
    const links = document.querySelectorAll('.nav-link[href], .sidebar-home-nav[href]');

    links.forEach((link) => {
      const linkPath = new URL(link.href, window.location.origin).pathname;
      if (linkPath === currentPath) {
        link.classList.add('active');
      } else {
        link.classList.remove('active');
      }
    });

    const activeLink = document.querySelector('.sidebar-nav .active[href]');
    const activeSection = activeLink?.closest('.nav-section');
    if (activeSection) {
      const toggle = activeSection.querySelector('.nav-section-toggle');
      const sectionId = toggle?.getAttribute('aria-controls');
      const items = sectionId ? document.getElementById(sectionId) : null;
      if (toggle && items) {
        toggle.classList.remove('collapsed');
        toggle.setAttribute('aria-expanded', 'true');
        items.classList.remove('collapsed');
        items.hidden = false;
        activeSection.classList.add('has-active');
      }
    }
  }

  function setNavSectionExpanded(toggle, expanded) {
    const sectionId = toggle.getAttribute('aria-controls');
    const items = sectionId ? document.getElementById(sectionId) : null;
    if (!items) return;

    toggle.classList.toggle('collapsed', !expanded);
    toggle.setAttribute('aria-expanded', String(expanded));
    items.classList.toggle('collapsed', !expanded);
    items.hidden = !expanded;
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

    sidebar.querySelectorAll('.nav-section-toggle[aria-controls]').forEach((toggle) => {
      toggle.addEventListener('click', () => {
        setNavSectionExpanded(toggle, toggle.getAttribute('aria-expanded') !== 'true');
      });
    });

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

    // Set active nav
    setActiveNav();
  });

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

// ============================================================
// Soft refresh: re-fetch the current page and update only the data on
// screen (tables, cards, counts, charts' containers) instead of reloading
// the whole document. Existing elements are updated in place, so event
// listeners bound by page scripts keep working. If the new markup would add
// interactive elements that scripts have not bound, it falls back to a full
// reload so behaviour is never worse than before.
// ============================================================
(function () {
  const KEEP_CLASSES = ['hidden-row', 'active', 'collapsed', 'show'];
  const INTERACTIVE = 'button, a, input, select, textarea, form, [data-bs-toggle], [onclick]';
  let inFlight = null;

  class UnsafeMorph extends Error {}

  function isPreserved(node) {
    return node.nodeType === 1 && (
      node.matches('.modal, .modal-backdrop, .offcanvas, script, canvas, [data-no-refresh]')
    );
  }

  function significantChildren(parent) {
    return Array.from(parent.childNodes).filter((node) => {
      if (node.nodeType === 8) return false;
      if (node.nodeType === 3) return node.nodeValue.trim() !== '';
      return node.nodeType === 1 && !isPreserved(node);
    });
  }

  function assertSafeToInsert(node) {
    if (node.nodeType !== 1) return;
    if (node.matches(INTERACTIVE) || node.querySelector(INTERACTIVE) || node.querySelector('canvas')) {
      throw new UnsafeMorph('New interactive markup needs a full page load.');
    }
  }

  function syncAttributes(oldEl, newEl) {
    const isField = /^(INPUT|TEXTAREA|SELECT)$/.test(oldEl.tagName);
    const keepWhenMissing = ['class', 'style', 'aria-expanded'];
    Array.from(oldEl.attributes).forEach((attr) => {
      if (newEl.hasAttribute(attr.name) || keepWhenMissing.includes(attr.name)) return;
      if (isField && ['value', 'checked', 'selected'].includes(attr.name)) return;
      oldEl.removeAttribute(attr.name);
    });
    Array.from(newEl.attributes).forEach((attr) => {
      if (isField && ['value', 'checked', 'selected'].includes(attr.name)) return;
      if (attr.name === 'class') {
        const kept = KEEP_CLASSES.filter((name) => oldEl.classList.contains(name));
        const merged = new Set(attr.value.split(/\s+/).filter(Boolean));
        kept.forEach((name) => merged.add(name));
        const next = Array.from(merged).join(' ');
        if (oldEl.className !== next) oldEl.className = next;
      } else if (oldEl.getAttribute(attr.name) !== attr.value) {
        oldEl.setAttribute(attr.name, attr.value);
      }
    });
  }

  function morphElement(oldEl, newEl) {
    syncAttributes(oldEl, newEl);
    if (/^(INPUT|TEXTAREA|SELECT|CANVAS)$/.test(oldEl.tagName)) return;
    morphChildren(oldEl, newEl);
  }

  function sameKind(oldNode, newNode) {
    if (oldNode.nodeType !== newNode.nodeType) return false;
    if (oldNode.nodeType === 3) return true;
    return oldNode.tagName === newNode.tagName && (oldNode.id || '') === (newNode.id || '');
  }

  function morphChildren(oldParent, newParent) {
    const oldKids = significantChildren(oldParent);
    const newKids = significantChildren(newParent);
    let anchor = null;

    newKids.forEach((newKid, index) => {
      const oldKid = oldKids[index];
      if (oldKid && sameKind(oldKid, newKid)) {
        if (newKid.nodeType === 3) {
          if (oldKid.nodeValue !== newKid.nodeValue) oldKid.nodeValue = newKid.nodeValue;
        } else {
          morphElement(oldKid, newKid);
        }
        anchor = oldKid;
        return;
      }
      assertSafeToInsert(newKid);
      const fresh = document.importNode(newKid, true);
      if (oldKid) {
        oldParent.replaceChild(fresh, oldKid);
      } else if (anchor && anchor.parentNode === oldParent) {
        oldParent.insertBefore(fresh, anchor.nextSibling);
      } else {
        oldParent.appendChild(fresh);
      }
      anchor = fresh;
    });

    oldKids.slice(newKids.length).forEach((extra) => {
      if (extra.parentNode === oldParent) oldParent.removeChild(extra);
    });
  }

  function resetClosedModalForms() {
    document.querySelectorAll('.modal:not(.show) form').forEach((form) => form.reset());
  }

  async function softRefresh(url, options = {}) {
    const target = url || window.location.href;
    const container = document.querySelector('.page-content');
    if (!container) return false;
    if (inFlight) return inFlight;

    inFlight = (async () => {
      try {
        const response = await fetch(target, {
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'fleetops-soft-refresh', Accept: 'text/html' }
        });
        const finalPath = new URL(response.url, window.location.origin).pathname;
        const wantedPath = new URL(target, window.location.origin).pathname;
        if (!response.ok || finalPath !== wantedPath) throw new UnsafeMorph('Page changed.');
        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        const fresh = doc.querySelector('.page-content');
        if (!fresh) throw new UnsafeMorph('No page content.');
        morphChildren(container, fresh);
        if (options.history === 'push') {
          window.history.pushState({}, '', target);
        } else if (options.history === 'replace') {
          window.history.replaceState({}, '', target);
        }
        if (options.resetModals !== false) resetClosedModalForms();
        document.dispatchEvent(new CustomEvent('fleetops:refreshed'));
        return true;
      } catch (error) {
        if (!(error instanceof UnsafeMorph)) console.error('Soft refresh failed:', error);
        return false;
      } finally {
        inFlight = null;
      }
    })();
    return inFlight;
  }

  // Drop-in for window.location.reload() after a successful action.
  window.softReload = async function () {
    if (!(await softRefresh(window.location.href))) window.location.reload();
  };

  // Filter forms: <form data-soft-filter> with controls using data-soft-submit.
  async function applyFilterForm(form) {
    const params = new URLSearchParams(new FormData(form));
    const base = form.getAttribute('action') || window.location.pathname;
    const url = new URL(base, window.location.href);
    url.search = params.toString();
    const ok = await softRefresh(url.toString(), { history: 'replace', resetModals: false });
    if (!ok) window.location.href = url.toString();
  }

  document.addEventListener('change', (event) => {
    const control = event.target.closest?.('[data-soft-submit]');
    const form = control?.form;
    if (form) applyFilterForm(form);
  });

  document.addEventListener('submit', (event) => {
    if (event.target.matches?.('form[data-soft-filter]')) {
      event.preventDefault();
      applyFilterForm(event.target);
    }
  });
})();