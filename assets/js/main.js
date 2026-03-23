/**
 * main.js — PromptingPress
 *
 * Minimal vanilla JS. No framework, no dependencies.
 *
 * Behaviors:
 *   1. Hamburger nav toggle (mobile)
 *   2. Escape key closes the nav menu
 *   3. Active nav link highlight via aria-current
 */

(function () {
  'use strict';

  // ── 1. Hamburger nav toggle ───────────────────────────────────────────────

  var toggle = document.querySelector('.nav__toggle');
  var menu   = document.getElementById('pp-nav-menu');

  if (toggle && menu) {

    toggle.addEventListener('click', function () {
      var expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!expanded));
      menu.hidden = expanded;
    });

    // ── 2. Escape key closes the menu ──────────────────────────────────────
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !menu.hidden) {
        menu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        toggle.focus();
      }
    });

  }

  // ── 3. Highlight active nav link ─────────────────────────────────────────
  // Sets aria-current="page" on the nav link that matches the current URL.
  // This runs after wp_footer() has rendered the nav menu into the DOM.

  var currentUrl = window.location.href;

  document.querySelectorAll('.nav__menu a').forEach(function (link) {
    if (link.href === currentUrl) {
      link.setAttribute('aria-current', 'page');
    }
  });

})();
