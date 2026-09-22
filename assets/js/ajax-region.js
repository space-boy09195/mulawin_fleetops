// ============================================================
// assets/js/ajax-region.js
// Lightweight partial-page-refresh helper.
//
// Problem it solves: most forms in this app call
// window.location.reload() after every successful save, which
// reloads the entire page (sidebar, topnav, everything) just to
// show one updated table. Marking a container with
// data-ajax-region="name" and calling
// AjaxRegion.refresh('name') instead re-fetches only that
// region's markup from the current page and swaps it in place —
// no full navigation, no flash, no lost scroll position.
//
// This does NOT require WebSockets/Reverb or any server push —
// it's a plain fetch() of the current page's HTML, then picking
// out the matching data-ajax-region element from the response
// and replacing the one on screen. Works with the existing
// server-rendered PHP pages as-is.
//
// Usage:
//   <div data-ajax-region="parts-table"> ... table markup ... </div>
//   ...
//   AjaxRegion.refresh('parts-table');           // re-fetch current URL
//   AjaxRegion.refresh('parts-table', otherUrl);  // or a specific URL
//
// No jQuery — vanilla JS only, matching the rest of this app's JS.
// ============================================================

(function () {
  'use strict';

  const cache = new Map(); // url -> { html, ts } — short-lived, avoids duplicate fetches on rapid re-refresh

  function findRegion(doc, name) {
    return doc.querySelector(`[data-ajax-region="${name}"]`);
  }

  /**
   * Re-fetch `url` (defaults to the current page) and replace the
   * on-screen element with data-ajax-region="name" with the freshly
   * rendered version from the response.
   *
   * Returns a Promise<boolean> — true if the region was found and
   * swapped, false if the region wasn't present in the response
   * (caller should fall back to a full reload in that case).
   */
  function refresh(name, url) {
    const targetUrl = url || window.location.href;

    return fetch(targetUrl, {
      headers: { 'X-Requested-With': 'AjaxRegion' },
      credentials: 'same-origin',
    })
      .then((r) => {
        if (!r.ok) throw new Error(`AjaxRegion: fetch failed (${r.status})`);
        return r.text();
      })
      .then((html) => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const freshRegion = findRegion(doc, name);
        const currentRegion = findRegion(document, name);

        if (!freshRegion || !currentRegion) {
          return false; // caller decides whether to fall back to reload
        }

        // Fade out, swap, fade in — a small visual cue that something
        // updated, without a jarring instant replace.
        currentRegion.style.transition = 'opacity 0.12s ease';
        currentRegion.style.opacity = '0';

        window.setTimeout(() => {
          currentRegion.replaceWith(freshRegion);
          freshRegion.style.opacity = '0';
          // force a reflow so the transition below actually runs
          void freshRegion.offsetWidth;
          freshRegion.style.transition = 'opacity 0.18s ease';
          freshRegion.style.opacity = '1';
        }, 120);

        return true;
      })
      .catch((err) => {
        console.error(err);
        return false;
      });
  }

  /**
   * Convenience wrapper: try a partial refresh, and if the region
   * isn't found (e.g. this page doesn't mark that region, or the
   * fetch failed), fall back to the old behavior so nothing breaks.
   */
  function refreshOrReload(name, url) {
    return refresh(name, url).then((ok) => {
      if (!ok) window.location.reload();
      return ok;
    });
  }

  window.AjaxRegion = { refresh, refreshOrReload };
})();
