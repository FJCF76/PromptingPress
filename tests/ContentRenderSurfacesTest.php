<?php
/**
 * tests/ContentRenderSurfacesTest.php — the surfaces around Layer 3A's render side
 * (#1242 T3b): the AI prompt's content contract derived from the predicate's own tables
 * (LAYER-3-CONTRACT.md §10 T-16, I43), the media inventory's core-parity gate, the §2.4
 * catch sites (T-12's render-side pins), and the render loops that must open a content
 * render context.
 */

use PHPUnit\Framework\TestCase;

class ContentRenderSurfacesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_user_caps']);
        parent::tearDown();
    }

    // ── T-16: the prompt's content contract is read from the predicate's tables ─────────

    public function testThePromptStatesTheContentContractFromThePredicatesOwnTables(): void
    {
        $prompt = pp_ai_system_prompt();
        $this->assertStringContainsString(pp_ai_content_contract_summary(), $prompt, 'the paragraph reaches the model');
        foreach (array_keys(pp_content_excluded_elements()) as $element) {
            $this->assertMatchesRegularExpression('/[ ,]' . preg_quote($element, '/') . '[,;]/', $prompt, "refused element {$element}");
        }
        foreach (array_keys(pp_content_excluded_attributes()) as $attr) {
            $this->assertStringContainsString($attr, $prompt, "refused attribute {$attr}");
        }
        foreach (pp_content_prop_contracts() as $component => $paths) {
            foreach ($paths as $path => $sink) {
                if ($sink === 'rich' || $sink === 'rich_cell' || $sink === 'inline') {
                    $this->assertStringContainsString($component . '.' . $path, $prompt, "{$sink} prop {$component}.{$path}");
                } elseif ($path !== 'title_accent') {
                    $this->assertStringContainsString('`' . $path . '`', $prompt, "heading field {$path}");
                }
            }
        }
        $this->assertStringContainsString('take only ' . implode(' ', array_keys(pp_content_inline_table())), $prompt);
        $this->assertStringContainsString('take only ' . implode(' ', array_keys(pp_content_heading_table())), $prompt);
        $this->assertStringContainsString(number_format(PP_CONTENT_PROP_MAX_BYTES), $prompt);
        foreach (['content_construct_excluded', 'content_too_large', 'content_stripped_at_render', 'content_duplicate_id'] as $code) {
            $this->assertStringContainsString('`' . $code . '`', $prompt);
        }
        foreach (pp_informational_finding_types() as $type) {
            if (str_starts_with($type, 'content_')) {
                $this->assertStringContainsString('`' . $type . '`', $prompt, "info code {$type} is named as info");
            }
        }
    }

    public function testATableRowAddedToThePredicateReachesThePromptWithoutAnEdit(): void
    {
        // The derivation, not a copy: the paragraph's element list IS the table's key list.
        $this->assertStringContainsString(implode(', ', array_keys(pp_content_excluded_elements())), pp_ai_content_contract_summary());
        $this->assertStringContainsString(implode(', ', array_keys(pp_content_excluded_attributes())), pp_ai_content_contract_summary());
    }

    // ── the media inventory: core parity on upload_files ───────────────────────────────

    private function seedImage(): void
    {
        $GLOBALS['_pp_test_store']['posts'][51] = [
            'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg',
        ];
        $GLOBALS['_pp_test_store']['attachment_is_image'][51] = true;
    }

    public function testAUserWhoCannotBrowseTheMediaLibraryGetsNoInventory(): void
    {
        $this->seedImage();
        $GLOBALS['_pp_test_user_caps'] = ['upload_files' => false];
        $prompt = pp_ai_system_prompt();
        $this->assertStringContainsString("## Media Library\nNot listed: this account cannot browse the media library.", $prompt);
        $this->assertStringNotContainsString('image-51.jpg', $prompt, 'no file name');
        $this->assertStringNotContainsString('Available images', $prompt);
    }

    public function testAUserWhoCanUploadSeesTheLibraryAsCoreShowsIt(): void
    {
        $this->seedImage();
        $GLOBALS['_pp_test_user_caps'] = ['upload_files' => true];
        $this->assertStringContainsString('"image-51.jpg"', pp_ai_system_prompt());
    }

    // ── §2.4 / T-12: every catch that can enclose a component render re-hooks pre_kses ──

    /** The body of a named function (or of the preview handler's closure), comments stripped. */
    private static function body(string $file, string $anchor): string
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
        $start = strpos($src, $anchor);
        self::assertNotFalse($start, "anchor {$anchor} in {$file}");
        $tokens = token_get_all('<?php ' . substr($src, $start));
        $out = '';
        $depth = 0;
        $opened = false;
        foreach ($tokens as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }
            $text = is_array($t) ? $t[1] : $t;
            $out .= $text;
            if ($text === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                $opened = true;
            } elseif ($text === '}') {
                $depth--;
                if ($opened && $depth === 0) {
                    break;
                }
            }
        }
        return $out;
    }

    public static function catchSites(): array
    {
        return [
            'editor preview render (lib/admin.php)' => ['lib/admin.php', "add_action('wp_ajax_pp_preview_composition'"],
            'post-apply validator (lib/post-apply-validate.php)' => ['lib/post-apply-validate.php', 'function pp_post_apply_validate('],
            'presence probe (lib/udc.php)' => ['lib/udc.php', 'function _pp_udc_rendered_roles('],
        ];
    }

    /**
     * @dataProvider catchSites
     *
     * The harness's add_filter() is a no-op, so the filter's state cannot be read back; the pin
     * is the one the presence probe's own test set (RoleInkOverOwnSurfaceTest): the catch that
     * encloses pp_get_component() has a finally that re-adds the filter.
     */
    public function testEachRenderCatchSiteReHooksPreKsesInItsFinally(string $file, string $anchor): void
    {
        $body = self::body($file, $anchor);
        $this->assertMatchesRegularExpression('/try\s*\{.*?pp_get_component\(.*?\}\s*catch\s*\(.*?\}\s*finally\s*\{(.*?)\}/s', $body);
        preg_match('/try\s*\{.*?pp_get_component\(.*?\}\s*catch\s*\(.*?\}\s*finally\s*\{(.*?)\n\s*\}/s', $body, $m);
        $this->assertMatchesRegularExpression("/pp_content_rehook_pre_kses\(\)|add_filter\('pre_kses',\s*'wp_pre_kses_block_attributes'/", $m[1] ?? '',
            "{$file}: the finally around the render re-adds pre_kses");
    }

    public function testTheReHookHelperReAddsCoresFilter(): void
    {
        $body = self::body('lib/content-render.php', 'function pp_content_rehook_pre_kses(');
        $this->assertStringContainsString("add_filter('pre_kses', 'wp_pre_kses_block_attributes', 10, 3)", $body,
            'core\'s own hook spelling (wp-includes/default-filters.php), so add_filter is idempotent with it');
    }

    // ── every loop that renders STORED bands opens a content render context ──────────────

    public static function storedBandLoops(): array
    {
        return [
            'the page loop' => ['lib/wp.php', 'function pp_render_composition_bands('],
            'the editor preview' => ['lib/admin.php', "add_action('wp_ajax_pp_preview_composition'"],
            'the post-apply validator' => ['lib/post-apply-validate.php', 'function pp_post_apply_validate('],
        ];
    }

    /** @dataProvider storedBandLoops */
    public function testEveryStoredBandLoopRendersInsideAContentContext(string $file, string $anchor): void
    {
        $body = self::body($file, $anchor);
        $begin = strpos($body, 'pp_content_render_begin(');
        $band = strpos($body, 'pp_content_render_band(');
        $render = strpos($body, 'pp_get_component(', (int) $band);
        $end = strrpos($body, 'pp_content_render_end(');
        $this->assertNotFalse($begin, "{$file}: begins a context");
        $this->assertNotFalse($band, "{$file}: selects the band");
        $this->assertNotFalse($render, "{$file}: the band is selected before the component renders");
        $this->assertNotFalse($end, "{$file}: closes the context");
        $this->assertLessThan($band, $begin);
    }

    public function testThePresenceProbeIsGivenTheComposition(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $this->assertMatchesRegularExpression('/_pp_udc_rendered_roles\(\$item, \$asked, \$presence_markup_left, \$presence_why, \$items, \$i, \$post_id\)/', $src);
        $body = self::body('lib/udc.php', 'function _pp_udc_rendered_roles(');
        $this->assertMatchesRegularExpression('/pp_content_render_begin\(\$composition, \$post_id\);\s*pp_content_render_band\(\$band_key\);/', $body);
    }

    public function testEveryComponentTemplateRendersContentPropsThroughTheRenderSide(): void
    {
        // Each content prop the predicate owns reaches its template through
        // pp_content_prop_html() (or the heading-with-accent renderer for `title`), never a
        // direct core sanitizer that would skip §2.3.
        foreach (pp_content_prop_contracts() as $component => $paths) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/components/' . $component . '/' . $component . '.php');
            foreach (array_keys($paths) as $path) {
                if ($path === 'title_accent') {
                    continue;
                }
                if ($path === 'title' && str_contains($src, 'pp_render_heading_with_accent(')) {
                    continue;
                }
                $this->assertStringContainsString("pp_content_prop_html('{$component}', '{$path}'", $src, "{$component}.{$path}");
            }
        }
    }
}
