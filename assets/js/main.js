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

  // ── 5. Dropdown submenus (issue 381) — accessible disclosure ───────────────
  //
  // WP's default nav walker emits <li class="menu-item-has-children"> wrapping a
  // nested <ul class="sub-menu">. We enhance each into a WAI-ARIA *disclosure*
  // (a button with aria-expanded controlling the submenu), NOT a menubar — the
  // parent link stays independently usable, and the injected button only owns
  // expand/collapse. Progressive enhancement: without JS, CSS still exposes the
  // submenu (expanded on mobile, hover on desktop).

  var navMenu = document.getElementById('pp-nav-menu');

  function ppDirectChild(parent, tagName) {
    for (var i = 0; i < parent.children.length; i++) {
      if (parent.children[i].tagName === tagName) {
        return parent.children[i];
      }
    }
    return null;
  }

  function ppSetSubmenuOpen(li, toggle, open) {
    if (open) {
      li.classList.add('is-open');
    } else {
      li.classList.remove('is-open');
    }
    if (toggle) {
      toggle.setAttribute('aria-expanded', String(open));
    }
  }

  if (navMenu) {
    var submenus = navMenu.querySelectorAll('li > ul');
    var submenuSeq = 0;

    Array.prototype.forEach.call(submenus, function (submenu) {
      var li = submenu.parentNode;
      submenuSeq += 1;
      if (!submenu.id) {
        submenu.id = 'pp-submenu-' + submenuSeq;
      }
      li.classList.add('pp-has-dropdown');

      var parentLink = ppDirectChild(li, 'A');
      var labelText = parentLink ? parentLink.textContent.trim() : 'submenu';

      var toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'nav__submenu-toggle';
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-controls', submenu.id);
      // Distinct accessible name so the button is not confused with the sibling
      // parent link (which navigates); the two are separate controls by design.
      toggle.setAttribute('aria-label', 'Toggle submenu for ' + labelText);
      toggle.innerHTML =
        '<svg class="nav__submenu-toggle-icon" width="16" height="16" viewBox="0 0 24 24" ' +
        'fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
        '<polyline points="6 9 12 15 18 9" stroke="currentColor" stroke-width="2" ' +
        'stroke-linecap="round" stroke-linejoin="round"/></svg>';

      // Insert the button AFTER the parent link, never inside it, and before the
      // submenu list.
      li.insertBefore(toggle, submenu);

      toggle.addEventListener('click', function () {
        ppSetSubmenuOpen(li, toggle, !li.classList.contains('is-open'));
        setHeaderHeightVar();
      });

      // ArrowDown opens the group and moves focus to its first link — the
      // keyboard affordance for the disclosure.
      toggle.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          ppSetSubmenuOpen(li, toggle, true);
          var firstLink = submenu.querySelector('a');
          if (firstLink) {
            firstLink.focus();
          }
          setHeaderHeightVar();
        }
      });
    });

    // Escape closes the open submenu (from anywhere inside it) and returns focus
    // to its toggle. Stop propagation so this Escape does not also collapse the
    // whole mobile menu — a second Escape does that.
    navMenu.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') {
        return;
      }
      var openLi = navMenu.querySelector('li.pp-has-dropdown.is-open');
      if (!openLi) {
        return;
      }
      var toggle = ppDirectChild(openLi, 'BUTTON');
      ppSetSubmenuOpen(openLi, toggle, false);
      if (toggle) {
        toggle.focus();
      }
      e.stopPropagation();
      setHeaderHeightVar();
    });

    // A click outside an open group closes it.
    document.addEventListener('click', function (e) {
      var openLis = navMenu.querySelectorAll('li.pp-has-dropdown.is-open');
      Array.prototype.forEach.call(openLis, function (li) {
        if (!li.contains(e.target)) {
          var toggle = ppDirectChild(li, 'BUTTON');
          if (toggle) {
            ppSetSubmenuOpen(li, toggle, false);
          }
        }
      });
    });
  }

})();
