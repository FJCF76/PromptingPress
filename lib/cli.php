<?php
/**
 * lib/cli.php — PromptingPress WP-CLI Commands
 *
 * Loaded conditionally in functions.php when WP_CLI is active.
 * Provides `wp pp action` subcommands: list, preview, execute.
 */

if (!class_exists('WP_CLI') || !class_exists('WP_CLI_Command')) {
    return;
}

class PP_Action_Command extends WP_CLI_Command {

    /**
     * Lists all registered actions.
     *
     * ## EXAMPLES
     *
     *     wp pp action list
     *
     * @subcommand list
     */
    public function list_actions($args, $assoc_args) {
        $actions = pp_get_registered_actions();
        if (empty($actions)) {
            WP_CLI::warning('No actions registered.');
            return;
        }

        $rows = [];
        foreach ($actions as $name => $def) {
            $params = [];
            foreach ($def['params'] as $pname => $pdef) {
                $label = $pname . ' (' . ($pdef['type'] ?? 'string') . ')';
                if (!empty($pdef['required'])) {
                    $label .= ' *';
                }
                $params[] = $label;
            }
            $rows[] = [
                'name'        => $name,
                'scope'       => $def['scope'],
                'description' => $def['description'] ?? '',
                'params'      => implode(', ', $params),
            ];
        }

        WP_CLI\Utils\format_items('table', $rows, ['name', 'scope', 'description', 'params']);
    }

    /**
     * Previews an action (validates and shows diff, never writes).
     *
     * ## OPTIONS
     *
     * <name>
     * : The action name.
     *
     * --params=<json>
     * : JSON object of action parameters.
     *
     * ## EXAMPLES
     *
     *     wp pp action preview update_component --params='{"post_id":4,"component_index":0,"props":{"title":"New Title"}}'
     *
     */
    public function preview($args, $assoc_args) {
        list($name) = $args;
        $params = $this->parse_params($assoc_args);

        $result = pp_preview_action($name, $params);

        if (is_wp_error($result)) {
            WP_CLI::line(json_encode(['ok' => false, 'error' => $result->get_error_message()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            WP_CLI::halt(1);
            return;
        }

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Executes an action (validates first, then applies).
     *
     * ## OPTIONS
     *
     * <name>
     * : The action name.
     *
     * --params=<json>
     * : JSON object of action parameters.
     *
     * ## EXAMPLES
     *
     *     wp pp action execute create_page --params='{"title":"New Page"}'
     *     wp pp action execute add_component --params='{"post_id":4,"component":"hero","props":{"title":"Hello"}}'
     *
     */
    public function execute($args, $assoc_args) {
        list($name) = $args;
        $params = $this->parse_params($assoc_args);

        $result = pp_execute_action($name, $params);

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($result['ok']) {
            WP_CLI::success('Action "' . $name . '" executed.');
        } else {
            WP_CLI::halt(1);
        }
    }

    /**
     * Parses the --params JSON argument.
     */
    private function parse_params(array $assoc_args): array {
        $raw = $assoc_args['params'] ?? '{}';
        $params = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            WP_CLI::error('Invalid JSON in --params: ' . json_last_error_msg());
        }
        return $params;
    }
}

WP_CLI::add_command('pp action', 'PP_Action_Command');
