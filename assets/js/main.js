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
 *   4. Sticky header anchor offset (issue 63) — keeps --pp-header-height
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
    // Without JS, the menu is visible (progressive enhancement); the CSS
    // presents it as an absolutely-positioned panel below the header row so
    // the JS-less state does not distort the header either (issue 426).
    menu.hidden = true;

    // Single source of truth for open/closed. Every path (toggle, Escape,
    // link click, outside click, breakpoint reset) routes through here so
    // aria-expanded and `hidden` can never drift (issue 426). The open menu
    // is an out-of-flow panel, so it does NOT grow the sticky header — but we
    // keep --pp-header-height in sync anyway (cheap, and robust if header content
    // ever changes on open). A short dropdown panel, so no body scroll-lock.
    function setMenuOpen(open) {
      menu.hidden = !open;
      toggle.setAttribute('aria-expanded', String(open));
      setHeaderHeightVar();
    }

    toggle.addEventListener('click', function () {
      setMenuOpen(menu.hidden);
    });

    // ── 2. Escape key closes the menu ──────────────────────────────────────
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !menu.hidden) {
        setMenuOpen(false);
        toggle.focus();
      }
    });

    // ── 3. Close the menu on link click ────────────────────────────────────
    // A disclosure closes when the user navigates. Runs before the browser's
    // default anchor-scroll for the same click, so the menu is already closed
    // (and --pp-header-height already resynced) by the time the scroll to a
    // #section-id happens — keeping the issue-63 anchor offset correct.
    menu.addEventListener('click', function (e) {
      if (e.target.tagName === 'A') {
        setMenuOpen(false);
      }
    });

    // ── 4. Click/tap outside the header closes the menu ────────────────────
    // Mirrors the #381 submenu outside-close below. The toggle and menu both
    // live inside .site-header, so a click on either is "inside" and never
    // self-closes here; only a click on page content collapses the panel.
    document.addEventListener('click', function (e) {
      if (!menu.hidden && header && !header.contains(e.target)) {
        setMenuOpen(false);
      }
    });

    // ── 5. Reset state across the 768px breakpoint ─────────────────────────
    // At >=768px the CSS shows the desktop nav row and hides the hamburger, so
    // an open mobile panel must not leave a lingering open state (aria-expanded
    // / ✕ icon). Collapse on entering desktop; returning to mobile then lands
    // closed. matchMedia is absent in some test/older environments, so guard it.
    if (window.matchMedia) {
      var desktopQuery = window.matchMedia('(min-width: 768px)');
      var resetOnDesktop = function (e) {
        if (e.matches && !menu.hidden) {
          setMenuOpen(false);
        }
      };
      if (desktopQuery.addEventListener) {
        desktopQuery.addEventListener('change', resetOnDesktop);
      } else if (desktopQuery.addListener) {
        desktopQuery.addListener(resetOnDesktop); // Safari <14 / older engines
      }
    }

  }

  // ── 4. Sticky header anchor offset ─────────────────────────────────────────
  //
  // A jump to any #section-id (every content component's `id` prop renders
  // as one) must land with the target heading below the sticky header, not
  // covered by it (issue 63). components.css's --pp-header-height CSS variable
  // (consumed via scroll-margin-top) has a static fallback; this measures
  // the REAL rendered .site-header height — which varies by content, nav
  // menu open/closed state, breakpoint, and font loading — and keeps it
  // current on load and resize.

  var header = document.querySelector('.site-header');

  function setHeaderHeightVar() {
    if (!header) return;
    document.documentElement.style.setProperty('--pp-header-height', header.offsetHeight + 'px');
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
