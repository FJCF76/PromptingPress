/**
 * main.js — PromptingPress
 *
 * Minimal vanilla JS. No framework, no dependencies.
 *
 * Behaviors:
 *   1. Hamburger nav toggle (mobile) — progressive enhancement:
 *      the menu is visible without JS; JS hides it and owns the toggle.
 *   2. Escape key closes the nav menu
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

})();
