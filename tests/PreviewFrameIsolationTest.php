<?php

/**
 * The editor preview renders content in an isolated origin (LAYER-3-CONTRACT §8.3).
 *
 * The preview is an iframe loaded through srcdoc. A srcdoc document inherits the
 * origin of the page that embeds it UNLESS the sandbox withholds
 * `allow-same-origin`, in which case it gets an opaque origin. With
 * `allow-scripts` present as well, adding `allow-same-origin` back leaves the
 * frame sharing the admin screen's origin, which is no isolation at all. So the
 * attribute is the whole guarantee, and this file pins it.
 *
 * Read as source, the established idiom for markup that only a full admin-screen
 * render reaches (pp_composition_workspace_page() needs a post, its meta, the
 * component registry and capability checks). The rendered half — the frame
 * really reports an opaque origin, and the preview still renders — lives in
 * tests/e2e/preview-isolation.spec.ts.
 */
class PreviewFrameIsolationTest extends \PHPUnit\Framework\TestCase
{
    /** The opening tag of the preview iframe, as written in lib/admin.php. */
    private function previewFrameTag(): string
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/admin.php');
        $this->assertIsString($source);

        $this->assertSame(
            1,
            substr_count($source, 'id="pp-preview-frame"'),
            'exactly one preview iframe must be declared'
        );

        $id    = strpos($source, 'id="pp-preview-frame"');
        $start = strrpos(substr($source, 0, $id), '<iframe');
        $this->assertNotFalse($start, 'the preview frame id must sit on an <iframe>');
        $end = strpos($source, '>', $id);
        $this->assertNotFalse($end);

        return substr($source, $start, $end - $start + 1);
    }

    /** @return string[] the sandbox tokens, lower-cased, in source order. */
    private function sandboxTokens(string $tag): array
    {
        $count = preg_match_all('/\ssandbox\s*=\s*"([^"]*)"/i', $tag, $m);
        $this->assertSame(1, $count, 'the preview iframe must carry exactly one sandbox attribute');

        $tokens = preg_split('/\s+/', strtolower(trim($m[1][0])), -1, PREG_SPLIT_NO_EMPTY);
        return $tokens === false ? [] : $tokens;
    }

    public function testThePreviewFrameIsSandboxed(): void
    {
        // A missing sandbox attribute is the worst regression of all: no sandbox
        // means the srcdoc document runs with the admin screen's full origin.
        $this->assertMatchesRegularExpression('/\ssandbox\s*=/i', $this->previewFrameTag());
    }

    public function testThePreviewFrameNeverGetsTheAdminOrigin(): void
    {
        $tokens = $this->sandboxTokens($this->previewFrameTag());

        $this->assertNotContains(
            'allow-same-origin',
            $tokens,
            'the preview must render in an opaque origin; allow-same-origin gives it the admin origin'
        );
    }

    /**
     * Exactly `allow-scripts`, nothing more. allow-scripts is needed by the
     * theme's own scroll bridge (and keeps embedded output behaving as it does on
     * the page). Every other token widens what the preview can do to the admin
     * screen around it — navigate it (allow-top-navigation*), open windows
     * (allow-popups*), submit forms, raise dialogs — and none is needed.
     */
    public function testThePreviewFrameGrantsOnlyScripts(): void
    {
        $this->assertSame(['allow-scripts'], $this->sandboxTokens($this->previewFrameTag()));
    }

    // ── The preview document's own content policy ───────────────────────────

    /** @return array<string,string> directive => value, from the emitted CSP meta. */
    private function previewPolicy(string $head): array
    {
        $count = preg_match_all('/<meta http-equiv="Content-Security-Policy" content="([^"]*)">/i', $head, $m);
        $this->assertSame(1, $count, 'the preview head must carry exactly one content policy');

        $policy = [];
        foreach (array_filter(array_map('trim', explode(';', $m[1][0]))) as $directive) {
            $parts = preg_split('/\s+/', $directive, 2);
            $policy[strtolower($parts[0])] = $parts[1] ?? '';
        }
        return $policy;
    }

    private function head(): string
    {
        $GLOBALS['_pp_test_store']['options'] = [];
        return pp_preview_document_head([], 'https://example.test/theme');
    }

    /**
     * Script inside the preview may open no connection and submit no form. These
     * two directives are the whole policy: anything wider (script-src, img-src,
     * style-src, font-src) would stop the preview rendering what the page renders.
     */
    public function testThePreviewDocumentRefusesConnectionsAndFormSubmission(): void
    {
        $this->assertSame(
            ['connect-src' => "'none'", 'form-action' => "'none'"],
            $this->previewPolicy($this->head())
        );
    }

    /**
     * FIRST in the head, ahead of every stylesheet, font and inline block, so no
     * element of the document is parsed before the policy applies.
     */
    public function testThePreviewPolicyIsTheFirstElementOfTheHead(): void
    {
        $this->assertStringStartsWith(
            '<meta http-equiv="Content-Security-Policy"',
            $this->head()
        );
    }
}
