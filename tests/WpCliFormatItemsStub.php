<?php
/**
 * tests/WpCliFormatItemsStub.php — a capturing stub for WP_CLI\Utils\format_items().
 *
 * WHY IT IS ITS OWN FILE. The function is NAMESPACED, and PHP only allows a braced
 * `namespace` block in a file where every statement lives in one — so it cannot be added to
 * tests/bootstrap.php or to a test file that already declares global-namespace stubs
 * alongside it. A one-purpose file is the smaller price.
 *
 * WHY IT EXISTS AT ALL. Until #647 no test could observe the table paths: `wp pp check page`
 * and `wp pp validate site` both hand rows straight to format_items(), which writes to
 * stdout, and an undefined function would simply fatal. That blind spot is a fair part of
 * why the reflected-text gap survived inside those two commands while every line-based sink
 * around them was covered.
 *
 * It captures ROWS, not rendered output. What the assertions care about is what the command
 * put in the cells; how WP-CLI draws a box around them is WP-CLI's business.
 *
 * Include it BEFORE lib/cli.php in any test file that drives a command which tables.
 */

namespace WP_CLI\Utils;

if (!function_exists('WP_CLI\Utils\format_items')) {
    /**
     * @param string $format One of WP-CLI's table formats; recorded, not honoured.
     * @param array  $items  The rows the command built.
     * @param array  $fields The columns it asked for.
     */
    function format_items($format, $items, $fields): void
    {
        $GLOBALS['_pp_test_format_items'][] = [
            'format' => $format,
            'items'  => $items,
            'fields' => $fields,
        ];
    }
}
