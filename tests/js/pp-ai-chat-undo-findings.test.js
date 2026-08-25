/**
 * Tests for ppChatAppendUndoFindings() — restore_composition findings on the
 * chat's "Undo these changes" card (issue #233, severity rendering #622).
 *
 * Restore is never blocked by current validation rules: undo is the user's safety
 * net, so a snapshot today's validators reject still restores. The findings therefore
 * describe a write that SUCCEEDED — the HEADING stays a warning and still says
 * "Restored", because telling the user undo broke when it worked is its own trust bug.
 *
 * The ITEMS carry their own severity and are styled from it (#622). Every finding used
 * to render as a warning regardless; post-#604 an undo of any pre-vocabulary-freeze
 * snapshot produces error-severity findings ("a normal write of this would be
 * rejected"), and showing those in the advisory grey understates the fix.
 */

const { JSDOM } = require('jsdom');
const dom = new JSDOM('<!DOCTYPE html><html><body>' +
    '<div id="pp-ai-messages"></div>' +
    '<textarea id="pp-ai-input"></textarea>' +
    '<button id="pp-ai-send"></button>' +
    '</body></html>', { url: 'http://localhost' });

global.window = dom.window;
global.document = dom.window.document;
global.HTMLElement = dom.window.HTMLElement;

const {
    appendUndoFindings,
    findingClass,
    findingBand,
    findingLocator,
    undoFindingsTail
} = require('../../assets/js/pp-ai-chat.js');

const CHROME_FINDING = {
    type: 'template_owned_component',
    severity: 'error',
    message: '"nav" at index 0 is site chrome rendered by the page template.',
    index: 0
};

const SMELL_FINDING = {
    type: 'consecutive_text_sections',
    severity: 'warning',
    message: 'Three consecutive text-only sections.',
    index: 3
};

function newCard() {
    return document.createElement('div');
}

describe('ppChatAppendUndoFindings', () => {
    it('renders nothing when the restored snapshot is clean', () => {
        const card = newCard();
        appendUndoFindings(card, []);
        expect(card.children.length).toBe(0);
    });

    it('renders nothing when findings is null or undefined', () => {
        const card = newCard();
        appendUndoFindings(card, null);
        appendUndoFindings(card, undefined);
        expect(card.children.length).toBe(0);
    });

    it('keeps the heading a warning — the undo itself succeeded', () => {
        const card = newCard();
        appendUndoFindings(card, [CHROME_FINDING]);

        expect(card.children.length).toBe(1);
        const heading = card.firstChild.firstChild;

        expect(heading.className).toBe('pp-ai-step-warning');
        expect(heading.textContent).toContain('Restored');
        expect(card.innerHTML).not.toContain('pp-ai-link-error');
        expect(card.innerHTML).not.toContain('Undo failed');
    });

    it('styles an error-severity finding as an error, not a warning (#622)', () => {
        const card = newCard();
        appendUndoFindings(card, [CHROME_FINDING]);

        const items = card.querySelectorAll('div.pp-ai-step-warning, div.pp-ai-step-failed');
        // [0] is the heading; [1] is the finding.
        expect(items[1].className).toBe('pp-ai-step-failed');
        expect(items[1].textContent).toContain('site chrome');
    });

    it('styles a warning-severity finding as a warning (#622)', () => {
        const card = newCard();
        appendUndoFindings(card, [SMELL_FINDING]);

        const items = card.querySelectorAll('div.pp-ai-step-warning, div.pp-ai-step-failed');
        expect(items[1].className).toBe('pp-ai-step-warning');
        expect(card.innerHTML).not.toContain('pp-ai-step-failed');
    });

    it('styles a mixed list per item, not per list (#622)', () => {
        const card = newCard();
        appendUndoFindings(card, [SMELL_FINDING, CHROME_FINDING]);

        expect(card.querySelectorAll('.pp-ai-step-failed').length).toBe(1);
        // Heading + the one warning-severity finding.
        expect(card.querySelectorAll('.pp-ai-step-warning').length).toBe(2);
    });

    it('degrades an unknown or missing severity to the warning class (#622)', () => {
        expect(findingClass({ severity: 'error' })).toBe('pp-ai-step-failed');
        expect(findingClass({ severity: 'warning' })).toBe('pp-ai-step-warning');
        expect(findingClass({ type: 'x' })).toBe('pp-ai-step-warning');
        expect(findingClass({ severity: 'ERROR' })).toBe('pp-ai-step-warning');
        expect(findingClass(null)).toBe('pp-ai-step-warning');
    });

    it('states the restore succeeded and names the issue count', () => {
        const card = newCard();
        appendUndoFindings(card, [CHROME_FINDING]);

        const text = card.textContent;
        expect(text).toContain('Restored');
        expect(text).toContain('1 issue');
        expect(text).toContain('"nav" at index 0 is site chrome');
    });

    it('pluralizes the issue count', () => {
        const card = newCard();
        appendUndoFindings(card, [
            CHROME_FINDING,
            // index 1, not null: `Unknown component` is a per-item rule, so since #622 the
            // backend cannot emit it without a locator. `null` is reserved for the one
            // cross-item rule, duplicate_component_id.
            { type: 'invalid_composition', severity: 'error', message: 'Unknown component: "ghost".', index: 1 }
        ]);

        expect(card.textContent).toContain('2 issues');
        expect(card.textContent).toContain('ghost');
    });

    it('is announced to assistive tech as a status, not an alert', () => {
        const card = newCard();
        appendUndoFindings(card, [CHROME_FINDING]);

        const section = card.firstChild;
        expect(section.getAttribute('role')).toBe('status');
        expect(section.getAttribute('aria-live')).toBe('polite');
    });

    it('collapses overflow beyond five findings into a disclosure', () => {
        const card = newCard();
        const many = [];
        for (let i = 0; i < 7; i++) {
            many.push({ type: 'invalid_composition', severity: 'error', message: 'problem ' + i, index: i });
        }
        appendUndoFindings(card, many);

        // Reuses ppChatAppendValidationItems: 5 inline, the rest behind <details>.
        expect(card.querySelectorAll('details').length).toBe(1);
        // "errors", not "warnings": the noun comes from the hidden items' own severities
        // now that the list carries per-item severity (#622).
        expect(card.textContent).toContain('Show 2 more errors');
    });

    it('names the overflow from the hidden items own severities (#622)', () => {
        const warn = (n) => ({ type: 'consecutive_text_sections', severity: 'warning', message: 'smell ' + n, index: n });
        const err = (n) => ({ type: 'invalid_composition', severity: 'error', message: 'broken ' + n, index: n });

        const allWarnings = newCard();
        appendUndoFindings(allWarnings, [warn(0), warn(1), warn(2), warn(3), warn(4), warn(5), warn(6)]);
        expect(allWarnings.textContent).toContain('Show 2 more warnings');

        const mixed = newCard();
        appendUndoFindings(mixed, [warn(0), warn(1), warn(2), warn(3), warn(4), warn(5), err(6)]);
        expect(mixed.textContent).toContain('Show 2 more issues');

        const oneError = newCard();
        appendUndoFindings(oneError, [warn(0), warn(1), warn(2), warn(3), warn(4), err(5)]);
        expect(oneError.textContent).toContain('Show 1 more error');
    });

    it('styles overflow findings by severity too (#622)', () => {
        const card = newCard();
        const many = [];
        for (let i = 0; i < 5; i++) {
            many.push({ type: 'consecutive_text_sections', severity: 'warning', message: 'smell ' + i, index: i });
        }
        many.push(CHROME_FINDING);
        appendUndoFindings(card, many);

        const details = card.querySelector('details');
        expect(details.querySelectorAll('.pp-ai-step-failed').length).toBe(1);
    });
    /**
     * THE COUNT MUST BE THE SERVER'S (#654).
     *
     * Restore's report is bounded at 100 findings plus one `findings_truncated` entry, so
     * `findings.length` is what was DELIVERED, not what exists. Before this fix the heading
     * read it directly and a snapshot with 20,001 real problems announced "101 issues" —
     * and counted the truncation advisory itself as one of them. The card is the only
     * non-CLI view of what an undo brought back, so understating it by two orders of
     * magnitude is a diagnostic-truth bug, not a cosmetic one.
     */
    describe('a bounded report (#654)', () => {
        const truncated = (total, shown) => {
            const list = [];
            for (let i = 0; i < shown; i++) {
                list.push({ type: 'unknown_prop', severity: 'error', message: 'bad ' + i, index: i });
            }
            list.push({
                type: 'findings_truncated',
                severity: 'warning',
                index: null,
                total: total,
                message: 'Showing ' + shown + ' of ' + total + ' findings and ' + (total - shown)
                    + ' more were omitted. Run `wp pp check page --post_id=7` for the complete report.'
            });
            return list;
        };

        it('reports the TRUE total from the server, not the delivered count', () => {
            const card = newCard();
            appendUndoFindings(card, truncated(20001, 100));

            expect(card.textContent).toContain('20001 issues');
            expect(card.textContent).not.toContain('101 issues');
        });

        it('says it is showing a subset, and does not count the tail as an issue', () => {
            const card = newCard();
            appendUndoFindings(card, truncated(20001, 100));

            // 100 real findings delivered — the truncation advisory is about the report.
            expect(card.textContent).toContain('showing the first 100');
        });

        it('leaves an unbounded report exactly as it read before', () => {
            const card = newCard();
            appendUndoFindings(card, [CHROME_FINDING, SMELL_FINDING]);

            expect(card.textContent).toContain('2 issues');
            expect(card.textContent).not.toContain('showing the first');
        });

        it('ignores a malformed total rather than trusting it', () => {
            const card = newCard();
            const bad = [CHROME_FINDING, {
                type: 'findings_truncated', severity: 'warning', index: null,
                total: 'lots', message: 'malformed'
            }];
            appendUndoFindings(card, bad);

            // Falls back to the array length rather than rendering "lots issues".
            expect(card.textContent).toContain('2 issues');
            expect(card.textContent).not.toContain('lots');
        });

        it('still renders the singular form for exactly one finding', () => {
            const card = newCard();
            appendUndoFindings(card, [CHROME_FINDING]);

            expect(card.textContent).toContain('1 issue ');
            expect(card.textContent).not.toContain('1 issues');
        });

        /**
         * THE TRUNCATION NOTICE IS HOISTED OUT OF THE DISCLOSURE (#655).
         *
         * On a truncated report the tail is entry 101 of 101, so before this it was
         * always inside the collapsed <details> — the one sentence naming
         * `wp pp check page --post_id=N`, the only route to the complete report, buried
         * inside the thing it exists to escape. It now renders under the heading it
         * qualifies, in the open, and takes no part in the band-aware selection.
         */
        it('renders the truncation notice outside the disclosure', () => {
            const card = newCard();
            appendUndoFindings(card, truncated(20001, 100));

            const details = card.querySelector('details');
            expect(details.textContent).not.toContain('Run `wp pp check page');
            expect(card.textContent).toContain('Run `wp pp check page --post_id=7`');
        });

        it('does not let the tail consume an inline row or an overflow slot', () => {
            const card = newCard();
            appendUndoFindings(card, truncated(20001, 100));

            const section = card.firstChild;
            // heading + hoisted notice + 5 inline band rows + the disclosure.
            expect(section.children.length).toBe(8);
            // 100 delivered findings, 5 shown inline: the tail is not one of the 95.
            expect(card.textContent).toContain('Show 95 more errors');
        });

        it('hoists the tail wherever it sits in the array, not only last', () => {
            const card = newCard();
            const list = truncated(20001, 100);
            const tail = list.pop();
            list.unshift(tail);
            appendUndoFindings(card, list);

            expect(card.querySelector('details').textContent).not.toContain('Run `wp pp check page');
            expect(card.textContent).toContain('Show 95 more errors');
        });

        it('renders a tail-only report as heading plus notice, and nothing else', () => {
            // Not reachable from the server (_pp_bounded_findings only appends a tail
            // after 100 real findings), but the disclosure-never-opens-empty invariant
            // (#667) has to hold on whatever arrives.
            //
            // Asserted as EXACT STRUCTURE, not "no details": with one entry there is
            // never overflow, so a `toBeNull()` on the disclosure is true whether the
            // entry was hoisted or left in the list, and the test would survive the hoist
            // being deleted. Naming the notice as the section's second and last child is
            // what makes it die.
            const card = newCard();
            appendUndoFindings(card, [{
                type: 'findings_truncated', severity: 'warning', index: null,
                total: 20001, message: 'Showing 0 of 20001 findings.'
            }]);

            const section = card.firstChild;
            expect(section.children.length).toBe(2);
            expect(section.children[1].textContent).toBe('Showing 0 of 20001 findings.');
            expect(card.querySelector('details')).toBeNull();
        });

        it('keeps the count reader and the hoist reader on one predicate', () => {
            const good = { type: 'findings_truncated', severity: 'warning', index: null, total: 3, message: 'm' };
            expect(undoFindingsTail([CHROME_FINDING, good])).toBe(good);
            // Everything the counter refuses, the hoist refuses too — a tail recognized
            // by one and not the other would be subtracted from neither count while
            // taking a line of its own.
            expect(undoFindingsTail([{ type: 'findings_truncated', total: 'lots', message: 'm' }])).toBeNull();
            expect(undoFindingsTail([{ type: 'findings_truncated', total: 0, message: 'm' }])).toBeNull();
            expect(undoFindingsTail([{ type: 'unknown_prop', total: 9, message: 'm' }])).toBeNull();
            expect(undoFindingsTail([CHROME_FINDING])).toBeNull();
        });
    });

    /**
     * BAND-AWARE INLINE SELECTION (#655, ruling 2026-08-25 — D1 clause 5).
     *
     * MAX_INLINE = 5 was calibrated when a band could contribute at most ONE finding.
     * #621 made a band report every problem its rules can locate, so a single broken
     * band routinely fills all five rows and every other affected band hides behind
     * "Show N more issues" — an exhaustive report reading as one band's worth, in the
     * one view a non-CLI operator has of what an undo brought back.
     */
    describe('band-aware inline selection', () => {
        const at = (index, n) => ({
            type: 'unknown_prop', severity: 'error', index: index,
            message: 'band ' + index + ' problem ' + n
        });

        it('shows a row for each affected band instead of five rows of the first (#655)', () => {
            const card = newCard();
            // The #621 shape: one badly broken band ahead of two others.
            appendUndoFindings(card, [
                at(0, 1), at(0, 2), at(0, 3), at(0, 4), at(0, 5), at(0, 6),
                at(2, 1),
                at(5, 1)
            ]);

            const section = card.firstChild;
            const inline = Array.prototype.filter.call(section.children, (el) => el.tagName === 'DIV');
            // heading + one row per distinct band.
            expect(inline.length).toBe(4);
            expect(inline[1].textContent).toContain('index 0');
            expect(inline[2].textContent).toContain('index 2');
            expect(inline[3].textContent).toContain('index 5');
            // Band 2 and band 5 used to be invisible without opening the disclosure.
            expect(section.querySelector('details').textContent).toContain('band 0 problem 6');
        });

        it('sends the later findings of an already-shown band to the disclosure', () => {
            const card = newCard();
            appendUndoFindings(card, [at(0, 1), at(0, 2), at(0, 3), at(0, 4), at(0, 5), at(0, 6), at(0, 7)]);

            const section = card.firstChild;
            // Heading, ONE inline row, one disclosure. The cost of the rule, pinned:
            // a single-band page draws one row where it used to draw five.
            expect(section.children.length).toBe(3);
            expect(section.textContent).toContain('Show 6 more errors');
        });

        it('still caps the inline rows at five when more bands are affected', () => {
            const card = newCard();
            const many = [];
            for (let i = 0; i < 12; i++) many.push(at(i, 1));
            appendUndoFindings(card, many);

            const section = card.firstChild;
            expect(section.children.length).toBe(7); // heading + 5 rows + details
            expect(section.textContent).toContain('Show 7 more errors');
        });

        it('treats each unlocated finding as its own band, never as one pool', () => {
            // The regression this guards: pooling every `index: null` finding under one
            // key would collapse a list of unlocated items into a single inline row —
            // which is exactly what the post-apply validation lists are.
            const card = newCard();
            const none = (n) => ({ type: 'duplicate_component_id', severity: 'error', index: null, message: 'dup ' + n });
            appendUndoFindings(card, [none(1), none(2), none(3), none(4), none(5), none(6)]);

            const section = card.firstChild;
            expect(section.children.length).toBe(7); // heading + 5 rows + details
            expect(section.textContent).toContain('Show 1 more error');
        });

        /**
         * FIRST-PER-BAND IS WORST-PER-BAND, BY INHERITANCE (#622 + #655).
         *
         * The selection picks a band's FIRST finding, not its most severe one. That is
         * only safe because `_pp_composition_findings()` (lib/actions.php) appends every
         * error before any advisory, so a band with a blocker meets the selector at the
         * blocker.
         *
         * WHAT THIS TEST PROVES, exactly: that the selector is order-preserving, so an
         * error-then-advisory list gives the error the inline row. It does NOT and cannot
         * police the assembler's order — the fixture below sets that order itself, so a
         * change in lib/actions.php leaves this green. The ordering half is pinned where
         * the dependency actually lives, by
         * CompositionFindingsBoundsTest::testEveryErrorPrecedesEveryAdvisorySoFirstPerBandIsWorstPerBand.
         * Two tests, because it takes two files to make the claim true.
         */
        it('gives the inline row to a band error over that band advisory, in server order', () => {
            const card = newCard();
            appendUndoFindings(card, [
                // Errors first, then advisories — the assembler's order.
                { type: 'unknown_prop', severity: 'error', index: 4, message: 'band 4 is broken' },
                { type: 'inert_slot', severity: 'warning', index: 4, message: 'band 4 has a dead slot' }
            ]);

            const section = card.firstChild;
            expect(section.children[1].className).toBe('pp-ai-step-failed');
            expect(section.children[1].textContent).toContain('band 4 is broken');
            expect(section.querySelector('details').textContent).toContain('band 4 has a dead slot');
        });

        it('mixes located and unlocated findings without either starving the other', () => {
            const card = newCard();
            appendUndoFindings(card, [
                at(0, 1),
                { type: 'duplicate_component_id', severity: 'error', index: null, message: 'dup A' },
                at(0, 2),
                { type: 'duplicate_component_id', severity: 'error', index: null, message: 'dup B' },
                at(1, 1)
            ]);

            const section = card.firstChild;
            const rows = Array.prototype.filter.call(section.children, (el) => el.tagName === 'DIV').slice(1);
            expect(rows.map((el) => el.textContent)).toEqual([
                '[unknown_prop] index 0: band 0 problem 1',
                '[duplicate_component_id]: dup A',
                '[duplicate_component_id]: dup B',
                '[unknown_prop] index 1: band 1 problem 1'
            ]);
            // Only band 0's SECOND finding was displaced.
            expect(section.querySelector('details').textContent).toBe('Show 1 more error[unknown_prop] index 0: band 0 problem 2');
        });
    });

    /**
     * THE LOCATOR IS RENDERED, IN THE CLI'S IDIOM (#655).
     *
     * `index` has been first-class since #622 and `_pp_cli_finding_line()` (lib/cli.php)
     * renders it as `  - [unknown_prop] index 0: message`. The card rendered `message`
     * alone, so AI_CONTEXT.md's claim that the index is what makes "two same-type bands
     * distinguishable" was true of the CLI and false here. One vocabulary — a fourth
     * spelling of a band locator is the #650/#652 lesson.
     */
    describe('the locator on a row', () => {
        it('renders type and index exactly as the CLI line does, minus its bullet', () => {
            expect(findingLocator({ type: 'unknown_prop', index: 0 })).toBe('[unknown_prop] index 0: ');
            expect(findingLocator({ type: 'inert_slot', index: 12 })).toBe('[inert_slot] index 12: ');
        });

        it('omits the locator rather than faking one when no band owns the finding', () => {
            expect(findingLocator({ type: 'duplicate_component_id', index: null })).toBe('[duplicate_component_id]: ');
            expect(findingLocator({ type: 'duplicate_component_id' })).toBe('[duplicate_component_id]: ');
        });

        it('draws no prefix at all for an item that is not a finding', () => {
            // The post-apply validation paths share this renderer and pass
            // {check, message} — no `type`, no locator. `[]: ` in front of those would
            // be a locator-shaped prefix that locates nothing.
            expect(findingLocator({ check: 'composition_readback', message: 'x' })).toBe('');
            expect(findingLocator({ type: '', index: 3 })).toBe('');
            expect(findingLocator(null)).toBe('');
        });

        it('accepts only what the server declares an index to be', () => {
            // pp_composition_error_index() (lib/admin.php) is an `is_int` read returning
            // ?int, so anything else is not a locator and must not print as one.
            expect(findingBand({ index: 0 })).toBe(0);
            expect(findingBand({ index: 7 })).toBe(7);
            expect(findingBand({ index: null })).toBeNull();
            expect(findingBand({ index: '2' })).toBeNull();
            expect(findingBand({ index: 1.5 })).toBeNull();
            expect(findingBand({ index: -1 })).toBeNull();
            expect(findingBand({ index: NaN })).toBeNull();
            expect(findingBand({ index: Infinity })).toBeNull();
            expect(findingBand({})).toBeNull();
            expect(findingBand(null)).toBeNull();
        });

        it('carries the locator into the disclosure too', () => {
            // Where it matters most: the disclosure is where the second and third
            // findings of an already-shown band land.
            const card = newCard();
            const at = (index, n) => ({
                type: 'unknown_prop', severity: 'error', index: index, message: 'p' + n
            });
            appendUndoFindings(card, [at(0, 1), at(0, 2)]);

            expect(card.querySelector('details').textContent).toContain('[unknown_prop] index 0: p2');
        });

        it('makes two same-type bands distinguishable on the card (#622 premise)', () => {
            const card = newCard();
            const sameMessage = 'Component "cta" prop "heading" is required.';
            appendUndoFindings(card, [
                { type: 'missing_required_prop', severity: 'error', index: 1, message: sameMessage },
                { type: 'missing_required_prop', severity: 'error', index: 4, message: sameMessage }
            ]);

            const section = card.firstChild;
            expect(section.children[1].textContent).toBe('[missing_required_prop] index 1: ' + sameMessage);
            expect(section.children[2].textContent).toBe('[missing_required_prop] index 4: ' + sameMessage);
        });
    });
});
