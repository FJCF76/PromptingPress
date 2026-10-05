<?php
/**
 * components/custom/custom.php
 *
 * The custom band (LAYER-3-CONTRACT.md §7, #1242 T5): the author's own markup with its
 * content islands rendered in. Props: see schema.json.
 *
 * EVERYTHING INSIDE THE BAND COMES FROM ONE FUNCTION. pp_content_custom_band_html() runs the
 * content predicate (lib/content.php, the one owner) over the markup and every island, renders
 * each island into its host, verifies the composed band as a whole, and passes the result
 * through the E6 emission belt, which removes every data-pp-* attribute and every engine-shaped
 * id from those bytes. The only engine identity on the page is the band root below, which this
 * template writes itself.
 *
 * NO do_shortcode(): custom markup is the author's bytes, not plugin output (§7.5). A
 * shortcode belongs in the embed band, which is the plugin boundary.
 *
 * NO .container: the author's markup owns the band's inner layout.
 *
 * @var array $props
 */

$id  = $props['id'] ?? '';
$id  = is_string($id) ? $id : '';

// The band's styling identity, as every v2 band (canonical reasoning: components/cta/cta.php).
$raw_band  = $props['__pp_udc_band'] ?? '';
$band_id   = (is_scalar($raw_band) && pp_udc_valid_band_id((string) $raw_band)) ? (string) $raw_band : '';
$band_attr = $band_id !== '' ? ' data-pp-band="' . esc_attr($band_id) . '"' : '';
// NO data-pp-band-overlay: the overlay focus-ring marker is emitted by the seven components
// its ruling names (tests/OverlayTierDefaultsTest.php); extending it to the custom band is a
// ruling of its own, recorded as a follow-up rather than taken here.

$inner = pp_content_custom_band_html($props);
?>
<section<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="custom" data-pp-component="custom"<?php echo $band_attr; ?>>
<?php echo $inner; // Verified by the content predicate and passed through the E6 emission belt (lib/content.php). ?>
</section>
