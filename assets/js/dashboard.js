/**
 * dashboard.js — Mulawin FleetOps
 * Shared by all role dashboards.
 * Handles: auto-refresh every 2 minutes, relative time updates.
 */

'use strict';

(function () {

  // ── Auto-refresh every 2 minutes ─────────────────────────────────────────
  const REFRESH_MS = 2 * 60 * 1000;
  setTimeout(() => window.location.reload(), REFRESH_MS);

  // ── Show last-refreshed time ──────────────────────────────────────────────
  const subtitle = document.querySelector('.dash-subtitle');
  if (subtitle) {
    const refreshedAt = new Date();
    const timeStr = refreshedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const refreshNote = document.createElement('span');
    refreshNote.className = 'dash-refresh-note';
    refreshNote.textContent = ` · Updated ${timeStr}`;
    subtitle.appendChild(refreshNote);
  }

})();