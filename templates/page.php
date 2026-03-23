<?php
/**
 * templates/page.php — Generic Page Template
 *
 * Renders a single WP page with a hero (page title) and the page content.
 * No WordPress functions are called here — only pp_* wrappers from lib/wp.php.
 */

require_once get_template_directory() . '/templates/base.php';

pp_base_template(function () {

    pp_get_component('hero', [
        'title'   => pp_page_title(),
        'variant' => 'left',
    ]);

    pp_get_component('section', [
        'body'   => pp_page_content(),
        'layout' => 'text-only',
    ]);

});
