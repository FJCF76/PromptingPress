<?php
/**
 * tests/CompositionResultTest.php — pp_get_composition_result() state classifier.
 *
 * Issue #144: distinguish absent / empty [] / undecodable / non-list JSON so an
 * agent relying on INSPECT before a mutation is warned about a corrupted page
 * instead of seeing it as a clean, blank one. Also pins pp_is_list() (the PHP 8.0
 * array_is_list() shim) and the pp_get_composition() delegation parity.
 */

use PHPUnit\Framework\TestCase;

class CompositionResultTest extends TestCase
{
    private int $postId = 77;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store']['post_meta'] = [];
    }

    private function setRawMeta($value): void
    {
        // Store the exact raw value (not json_encode) so we can exercise the
        // undecodable / non-string / already-array branches directly.
        update_post_meta($this->postId, '_pp_composition', $value);
    }

    // ── pp_is_list() shim ────────────────────────────────────────────────

    public function testIsListEmptyArrayIsAList(): void
    {
        // range(0, -1) would be [0], so [] must be special-cased.
        $this->assertTrue(pp_is_list([]));
    }

    public function testIsListSequentialIsAList(): void
    {
        $this->assertTrue(pp_is_list(['a', 'b', 'c']));
    }

    public function testIsListAssociativeIsNotAList(): void
    {
        $this->assertFalse(pp_is_list(['component' => 'hero']));
    }

    public function testIsListGappedKeysIsNotAList(): void
    {
        $this->assertFalse(pp_is_list([0 => 'a', 2 => 'b']));
    }

    // ── pp_get_composition_result(): the four+ states ────────────────────

    public function testAbsentMeta(): void
    {
        // No meta row at all.
        $result = pp_get_composition_result($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['composition']);
        $this->assertNull($result['error']);
        $this->assertNull($result['raw'], 'absent meta must have raw=null (distinguishes it from empty [])');
    }

    public function testStoredEmptyStringTreatedAsAbsent(): void
    {
        // A stored empty string is falsy; treated as absent/blank, not corrupt,
        // matching the pre-existing defensive contract.
        $this->setRawMeta('');
        $result = pp_get_composition_result($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['composition']);
        $this->assertNull($result['error']);
        $this->assertNull($result['raw']);
    }

    public function testEmptyJsonArrayIsDistinctFromAbsent(): void
    {
        $this->setRawMeta('[]');
        $result = pp_get_composition_result($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['composition']);
        $this->assertNull($result['error']);
        $this->assertSame('[]', $result['raw'], 'empty [] must carry raw so it is distinguishable from absent');
    }

    public function testValidJsonListDecodes(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['id' => 'pp-hero1']],
            ['component' => 'cta', 'props' => []],
        ];
        $this->setRawMeta(json_encode($composition));
        $result = pp_get_composition_result($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertSame($composition, $result['composition']);
        $this->assertNull($result['error']);
        $this->assertIsString($result['raw']);
    }

    public function testUndecodableJsonIsDecodeError(): void
    {
        $this->setRawMeta('{"component":');
        $result = pp_get_composition_result($this->postId);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['composition']);
        $this->assertSame('decode_error', $result['error']);
        $this->assertSame('{"component":', $result['raw']);
    }

    public function testMalformedUtf8IsDecodeError(): void
    {
        // Invalid UTF-8 byte inside an otherwise well-formed string → json error.
        $this->setRawMeta("[\"\xB1\x31\x30\"]");
        $result = pp_get_composition_result($this->postId);

        $this->assertFalse($result['ok']);
        $this->assertSame('decode_error', $result['error']);
    }

    public function testJsonObjectIsUnexpectedShape(): void
    {
        // Decodes to an associative PHP array — is_array() would accept it, so
        // pp_is_list() must reject it.
        $this->setRawMeta('{"component":"hero"}');
        $result = pp_get_composition_result($this->postId);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['composition']);
        $this->assertSame('unexpected_shape', $result['error']);
        $this->assertSame('{"component":"hero"}', $result['raw']);
    }

    public function testJsonScalarIsUnexpectedShape(): void
    {
        $this->setRawMeta('42');
        $result = pp_get_composition_result($this->postId);

        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_shape', $result['error']);
    }

    public function testFalsyJsonStringZeroIsUnexpectedShape(): void
    {
        // Guard against the PHP-falsy trap: the JSON string "0" is a valid JSON
        // scalar (decodes to int 0), NOT an absent page, so it must classify as
        // unexpected_shape rather than being swallowed by an `if (!$raw)` guard.
        $this->setRawMeta('0');
        $result = pp_get_composition_result($this->postId);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['composition']);
        $this->assertSame('unexpected_shape', $result['error']);
        $this->assertSame('0', $result['raw']);
    }

    public function testAlreadyDecodedEmptyArray(): void
    {
        // A stored empty array [] is a valid (empty) list — the falsy-but-present
        // case must route through the array branch, not the absent guard.
        $this->setRawMeta([]);
        $result = pp_get_composition_result($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['composition']);
        $this->assertNull($result['error']);
    }

    public function testJsonNullIsUnexpectedShape(): void
    {
        // Valid JSON, no decode error, but not a list.
        $this->setRawMeta('null');
        $result = pp_get_composition_result($this->postId);

        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_shape', $result['error']);
    }

    public function testAlreadyDecodedListArray(): void
    {
        // Defensive: a fixture/caller persisted an already-decoded list.
        $composition = [['component' => 'hero', 'props' => []]];
        $this->setRawMeta($composition);
        $result = pp_get_composition_result($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertSame($composition, $result['composition']);
        $this->assertNull($result['error']);
        $this->assertNull($result['raw']);
    }

    public function testAlreadyDecodedAssociativeArrayIsUnexpectedShape(): void
    {
        // A decoded object (associative array) must be flagged, not accepted.
        $this->setRawMeta(['component' => 'hero']);
        $result = pp_get_composition_result($this->postId);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['composition']);
        $this->assertSame('unexpected_shape', $result['error']);
        $this->assertNull($result['raw']);
    }

    public function testNonStringScalarMetaIsUnexpectedShape(): void
    {
        // A truthy non-string scalar (e.g. int) is neither a composition nor a
        // JSON payload we decode; raw must stay ?string (null here).
        $this->setRawMeta(42);
        $result = pp_get_composition_result($this->postId);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['composition']);
        $this->assertSame('unexpected_shape', $result['error']);
        $this->assertNull($result['raw']);
    }

    // ── pp_get_composition() delegation parity (render/consumer safety) ───

    public function testGetCompositionReturnsListForValid(): void
    {
        $composition = [['component' => 'hero', 'props' => []]];
        $this->setRawMeta(json_encode($composition));

        $this->assertSame($composition, pp_get_composition($this->postId));
    }

    public function testGetCompositionDegradesCorruptToEmpty(): void
    {
        // Regression (#144): a corrupt/undecodable row must degrade to [] via the
        // legacy accessor — never fatal — so render paths stay safe.
        $this->setRawMeta('{"component":');

        $this->assertSame([], pp_get_composition($this->postId));
    }

    public function testGetCompositionDegradesJsonObjectToEmpty(): void
    {
        // A JSON object previously slipped through is_array() as an associative
        // array; it must now degrade to [] for consumers.
        $this->setRawMeta('{"component":"hero"}');

        $this->assertSame([], pp_get_composition($this->postId));
    }

    public function testGetCompositionAbsentIsEmpty(): void
    {
        $this->assertSame([], pp_get_composition($this->postId));
    }
}
