<?php
/**
 * tests/RawHexGuardTest.php
 *
 * Both-directions proof for the shared raw-hex guard (issue #289):
 *   - a genuine raw hex color still FAILS (detection not weakened),
 *   - an issue reference inside a `/* ... *\/` comment PASSES.
 *
 * Covers both the in-process function (pp_find_raw_hex — used by
 * tests/InvariantTest.php) and the CLI entrypoint (php scripts/check-raw-hex.php
 * — used by the `ai-ready` CI workflow), so the two guards are proven aligned.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class RawHexGuardTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        $this->script = dirname(__DIR__) . '/scripts/check-raw-hex.php';
        require_once $this->script;
    }

    // ── Detection is NOT weakened: real raw hex still fails ────────────────

    public function testDetectsSixDigitHexInDeclaration(): void
    {
        $hits = \pp_find_raw_hex('.a { color: #ff0000; }');
        $this->assertCount(1, $hits);
        $this->assertSame('#ff0000', $hits[0]['match']);
    }

    public function testDetectsThreeDigitHexInDeclaration(): void
    {
        // `#226` as an actual color value must still fail — only comment-context
        // refs are exempt, per the recorded decision on #289.
        $hits = \pp_find_raw_hex('.a { color: #226; }');
        $this->assertCount(1, $hits);
        $this->assertSame('#226', $hits[0]['match']);
    }

    public function testDetectsHexEvenWhenLineAlsoCarriesACommentRef(): void
    {
        // A real hex on a line that also has a trailing comment must still be
        // caught. The old crude line-skip missed this class entirely.
        $hits = \pp_find_raw_hex('.a { color: #fff; } /* tweak (#226) */');
        $this->assertCount(1, $hits);
        $this->assertSame('#fff', $hits[0]['match']);
    }

    // ── False-positive class fixed: comment refs pass ─────────────────────

    public function testIssueRefInSingleLineCommentPasses(): void
    {
        $this->assertSame([], \pp_find_raw_hex('/* fixes overflow (#226) */'));
    }

    public function testIssueRefInMultiLineCommentPasses(): void
    {
        $css = "/*\n * Per-instance slots survive this rule (#226).\n * Also (#238), (#243).\n */\n.a { color: var(--fg); }";
        $this->assertSame([], \pp_find_raw_hex($css));
    }

    public function testTrailingIssueRefCommentPasses(): void
    {
        $this->assertSame([], \pp_find_raw_hex('.a { color: var(--fg); } /* (#226) */'));
    }

    public function testTwoDigitIssueRefStaysBelowThreshold(): void
    {
        // e.g. `#24`, `#56` — under the {3,6} minimum, so never a match,
        // in a comment or not. Preserves the original guard's behavior.
        $this->assertSame([], \pp_find_raw_hex('/* noisy arrow (#56) */'));
        $this->assertSame([], \pp_find_raw_hex('.a { width: calc(1px + 2px); } /* #24 */'));
    }

    // ── Line numbers stay accurate across comment shapes ──────────────────

    public function testReportsCorrectLineNumberAfterMultiLineComment(): void
    {
        $css = "/*\n multi\n line (#226)\n */\n.a { color: #abc123; }";
        $hits = \pp_find_raw_hex($css);
        $this->assertCount(1, $hits);
        $this->assertSame(5, $hits[0]['line']);
        $this->assertSame('#abc123', $hits[0]['match']);
    }

    public function testCommentDoesNotFuseTokensAcrossItsBoundary(): void
    {
        // `#22` + comment + `6` must NOT be read as `#226`. Blanking the comment
        // to spaces (not to nothing) keeps the tokens apart.
        $this->assertSame([], \pp_find_raw_hex('#22/* x */6'));
    }

    public function testHandlesCrlfLineEndings(): void
    {
        $css = "/* ref (#226) */\r\n.a { color: #ff0000; }\r\n";
        $hits = \pp_find_raw_hex($css);
        $this->assertCount(1, $hits);
        $this->assertSame('#ff0000', $hits[0]['match']);
        $this->assertSame(2, $hits[0]['line']);
    }

    // ── CLI entrypoint (the exact command CI runs) ────────────────────────

    public function testCliExitsOneOnRealHex(): void
    {
        [$code] = $this->runCli(".a { color: #ff0000; }\n");
        $this->assertSame(1, $code, 'CLI must exit 1 when a real raw hex is present.');
    }

    public function testCliExitsZeroOnCommentRefOnly(): void
    {
        [$code, $out] = $this->runCli("/* fixes overflow (#226) */\n.a { color: var(--fg); }\n");
        $this->assertSame(0, $code, "CLI must exit 0 for a comment-only issue ref. Output: {$out}");
    }

    public function testCliExitsTwoOnMissingFile(): void
    {
        $missing = sys_get_temp_dir() . '/pp-no-such-file-' . uniqid() . '.css';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->script) . ' ' . escapeshellarg($missing);
        exec($cmd . ' 2>/dev/null', $out, $code);
        $this->assertSame(2, $code);
    }

    /**
     * Write $css to a temp fixture, run the CLI against it, return [exitCode, output].
     *
     * @return array{0:int,1:string}
     */
    private function runCli(string $css): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pp-hex-') . '.css';
        file_put_contents($tmp, $css);
        try {
            $cmd = escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg($this->script) . ' '
                . escapeshellarg($tmp) . ' 2>&1';
            exec($cmd, $out, $code);
            return [$code, implode("\n", $out)];
        } finally {
            @unlink($tmp);
        }
    }
}
