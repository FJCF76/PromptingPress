/**
 * main.js — PromptingPress
 *
 * Minimal vanilla JS. No framework, no dependencies.
 *
 * Behaviors:
 *   1. Hamburger nav toggle (mobile) — progressive enhancement:
 *      the menu is visible without JS; JS hides it and owns the toggle.
 *   2. Escape key closes the nav menu
 *   3. Closes the mobile menu when a nav link is clicked
 *   4. Sticky header anchor offset (issue 63) — keeps --header-height
 *      (components.css) in sync with the real rendered header height
 *
 * Active nav link: handled server-side by WordPress (current-menu-item CSS class).
 * No JS needed — see .nav__menu li.current-menu-item > a in components.css.
 */

(function () {
  'use strict';

  // ── 1. Hamburger nav toggle ───────────────────────────────────────────────

  var toggle = document.querySelector('.nav__toggle');
  var menu   = document.getElementById('pp-nav-menu');

  if (toggle && menu) {

    // JS is running — take ownership of the menu visibility.
    // Without JS, the menu is visible (progressive enhancement).
    menu.hidden = true;

    toggle.addEventListener('click', function () {
      var expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!expanded));
      menu.hidden = expanded;
      setHeaderHeightVar();
    });

    // ── 2. Escape key closes the menu ──────────────────────────────────────
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !menu.hidden) {
        menu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        toggle.focus();
        setHeaderHeightVar();
      }
    });

    // ── 3. Close the menu on link click ────────────────────────────────────
    // Without this, an in-page anchor link clicked while the mobile menu is
    // expanded would scroll while the sticky header is still in its taller,
    // menu-open state — the exact scenario --header-height can't correct
    // for after the fact (issue 63). Runs before the browser's default
    // anchor-scroll for the same click, so --header-height is already
    // updated to the collapsed height by the time the scroll happens.
    menu.addEventListener('click', function (e) {
      if (e.target.tagName === 'A') {
        menu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        setHeaderHeightVar();
      }
    });

  }

  // ── 4. Sticky header anchor offset ─────────────────────────────────────────
  //
  // A jump to any #section-id (every content component's `id` prop renders
  // as one) must land with the target heading below the sticky header, not
  // covered by it (issue 63). components.css's --header-height CSS variable
  // (consumed via scroll-margin-top) has a static fallback; this measures
  // the REAL rendered .site-header height — which varies by content, nav
  // menu open/closed state, breakpoint, and font loading — and keeps it
  // current on load and resize.

  var header = document.querySelector('.site-header');

  function setHeaderHeightVar() {
    if (!header) return;
    document.documentElement.style.setProperty('--header-height', header.offsetHeight + 'px');
  }

  if (header) {
    setHeaderHeightVar();
    window.addEventListener('resize', setHeaderHeightVar);
  }

})();
