<?php
/**
 * 404.php — WordPress 404 Error Template
 *
 * Rendered when WordPress cannot find the requested page.
 * No WordPress functions are called here — only pp_* wrappers from lib/wp.php.
 */

require_once get_template_directory() . '/templates/base.php';

pp_base_template(function () {

    pp_get_component('hero', [
        'title'    => 'Page Not Found',
        'subtitle' => 'The page you\'re looking for doesn\'t exist or has been moved.',
        'variant'  => 'left',
    ]);

    pp_get_component('cta', [
        'title'       => 'Lost? Head back home.',
        'button_text' => 'Go to Homepage',
        'button_url'  => pp_site_url(),
        'variant'     => 'inline',
    ]);

});
