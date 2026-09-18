<?php
/**
 * lib/nav-walker.php — the header menu's disclosure walker (#994, ruling D5).
 *
 * WHY THIS FILE EXISTS AT ALL, since the button it renders used to be free.
 *
 * `assets/js/main.js` built the dropdown disclosure button in the browser: it
 * created a `<button class="nav__submenu-toggle">`, filled it with an inline SVG
 * chevron, and inserted it between the parent link and its `<ul class="sub-menu">`.
 * That worked, and it made the button's class a thing NO PHP ever emitted.
 *
 * #994 retires nav's designable CSS into role defaults, and a role is a SELECTOR
 * plus values. `tests/UdcEngineTest.php`'s selector lint renders each component and
 * asserts every role selector matches an element the component actually renders —
 * its allowlist covers the classes WORDPRESS emits (`current-menu-item`,
 * `sub-menu`), which a JS-injected class is not. So the six declarations on that
 * button had exactly two honest homes: a role whose selector the server can prove,
 * or nowhere. Ruling D5 chose the former; #995's two chevron defects are what made
 * "nowhere" unacceptable.
 *
 *   BEFORE                                   AFTER
 *   nav.php  → <li><a>…</a><ul class=…>      nav.php  → <li><a>…</a>
 *   main.js  → creates <button> + <svg>,                    <button class="nav__submenu-toggle">
 *              inserts before the <ul>,                       <svg class="nav__submenu-toggle-icon">
 *              assigns the <ul> an id,                      </button>
 *              sets aria-controls,                          <ul class="sub-menu">
 *              adds .pp-has-dropdown,       main.js  → assigns the <ul> an id,
 *              attaches listeners                        sets aria-controls,
 *                                                        adds .pp-has-dropdown,
 *                                                        attaches listeners
 *
 * WHAT DELIBERATELY STAYED IN JAVASCRIPT, and why it is not an oversight:
 *
 *   - `.pp-has-dropdown` on the `<li>`. It is the PROGRESSIVE-ENHANCEMENT FLAG, not
 *     a description of the markup: the stylesheet collapses a submenu only once that
 *     class appears, so a visitor without JS keeps every submenu expanded and
 *     reachable. Rendering it server-side would collapse submenus on a page where
 *     nothing can ever expand them again.
 *   - The `<ul>`'s generated id and the button's `aria-controls`. The id is minted
 *     next to the collapse behaviour it labels. Emitting it here would mean either
 *     duplicating core's `start_lvl()` (dropping the `nav_menu_submenu_css_class`
 *     filter with it) or rewriting core's output string after the fact; both are
 *     worse than one attribute set by the same code that owns the disclosure.
 *
 * The button is `display: none` until `.pp-has-dropdown` appears (components.css),
 * so the no-JS render shows no control at all — byte-identical to the old no-JS
 * render, and never an inert button a keyboard user can reach and press to nothing.
 *
 * Pattern = WAI-ARIA *disclosure* navigation (button + aria-expanded), NOT a
 * menubar, exactly as before: no role="menu"/"menuitem" semantics are introduced.
 */

if (!class_exists('PP_Nav_Menu_Walker') && class_exists('Walker_Nav_Menu')) {
    /**
     * Core's nav walker, plus one disclosure button per submenu.
     *
     * `start_lvl()` is the single insertion point and it is the RIGHT one: core
     * calls `start_el()` for a parent item and then opens that item's child level,
     * so the string position at the top of `start_lvl()` is precisely where
     * `main.js` used to call `li.insertBefore(toggle, submenu)` — between the
     * parent `<a>` and its `<ul class="sub-menu">`. The DOM is the same DOM.
     */
    class PP_Nav_Menu_Walker extends Walker_Nav_Menu {

        /**
         * The item whose children are being opened next.
         *
         * `start_lvl()` receives a depth and the args, never the parent item, so the
         * accessible name would otherwise have to be "submenu" for every group. The
         * old JS read the parent link's text for the same reason; this records the
         * item object core just rendered, which is the same label one step earlier.
         *
         * @var object|null
         */
        private $parent_item = null;

        /**
         * @param string $output
         * @param object $data_object
         * @param int    $depth
         * @param mixed  $args
         * @param int    $current_object_id
         */
        public function start_el(&$output, $data_object, $depth = 0, $args = null, $current_object_id = 0) {
            $this->parent_item = $data_object;
            parent::start_el($output, $data_object, $depth, $args, $current_object_id);
        }

        /**
         * @param string $output
         * @param int    $depth
         * @param mixed  $args
         */
        public function start_lvl(&$output, $depth = 0, $args = null) {
            $title = '';
            if (is_object($this->parent_item) && isset($this->parent_item->title)) {
                $title = trim((string) $this->parent_item->title);
            }
            $output .= pp_nav_submenu_toggle_markup($title);
            parent::start_lvl($output, $depth, $args);
        }
    }
}

// `pp_nav_submenu_toggle_markup()` lives in lib/wp.php, not here, because this file is
// required LAZILY behind a class_exists('Walker_Nav_Menu') check and the markup builder
// must be reachable when that check fails.
//
// STATED PRECISELY, because the obvious version of this sentence is no longer true: the
// unit harness stubs `Walker_Nav_Menu` unconditionally, so the suite DOES have the class.
// The caller that still needs the builder without it is the degrade-path probe in
// NavDisclosureWalkerTest, which loads lib/wp.php in a bare subprocess to prove
// pp_nav_menu_walker() returns null rather than fataling.
