<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace aiplacement_dimensions\local;

/**
 * Tests for resolving model output back to competencies.
 *
 * @package    aiplacement_dimensions
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiplacement_dimensions\local\resolver
 */
final class resolver_test extends \basic_testcase {
    /** @var string A triple-backtick fence, built from chr() so phpcs's forbidden-strings sniff does not fire. */
    private const FENCE = "\x60\x60\x60";

    /**
     * Two candidates, positions 1 and 2.
     *
     * @return array
     */
    private function candidates(): array {
        return [
            ['id' => 11, 'idnumber' => 'K11', 'shortname' => 'Alpha'],
            ['id' => 22, 'idnumber' => 'K22', 'shortname' => 'Beta'],
        ];
    }

    /**
     * A valid pick resolves to the competency at that position.
     *
     * @return void
     */
    public function test_valid_pick(): void {
        $raw = '{"picks":[{"n":2,"confidence":0.9,"why":"mentions Beta"}]}';
        $result = resolver::resolve($raw, $this->candidates());

        $this->assertFalse($result['undecodable']);
        $this->assertSame(0, $result['discarded']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame(22, $result['suggestions'][0]['id']);
        $this->assertSame('K22', $result['suggestions'][0]['idnumber']);
        $this->assertSame(0.9, $result['suggestions'][0]['confidence']);
        $this->assertSame('mentions Beta', $result['suggestions'][0]['why']);
    }

    /**
     * Out-of-range indices are counted, never silently dropped.
     *
     * @return void
     */
    public function test_out_of_range_is_counted(): void {
        $raw = '{"picks":[{"n":1},{"n":9},{"n":0},{"n":-3}]}';
        $result = resolver::resolve($raw, $this->candidates());

        $this->assertFalse($result['undecodable']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame(3, $result['discarded']);
    }

    /**
     * A repeated index yields one suggestion and no discard.
     *
     * @return void
     */
    public function test_duplicate_index(): void {
        $raw = '{"picks":[{"n":1},{"n":1}]}';
        $result = resolver::resolve($raw, $this->candidates());

        $this->assertFalse($result['undecodable']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame(0, $result['discarded']);
    }

    /**
     * A fenced JSON payload is still readable.
     *
     * @return void
     */
    public function test_code_fence_is_tolerated(): void {
        $raw = self::FENCE . "json\n{\"picks\":[{\"n\":1}]}\n" . self::FENCE;
        $result = resolver::resolve($raw, $this->candidates());

        $this->assertFalse($result['undecodable']);
        $this->assertCount(1, $result['suggestions']);
    }

    /**
     * Unparseable output is an empty answer, not an exception, and is flagged as such.
     *
     * @return void
     */
    public function test_malformed_json_is_empty_not_fatal(): void {
        $result = resolver::resolve('I am afraid I cannot help with that.', $this->candidates());

        $this->assertSame([], $result['suggestions']);
        $this->assertSame(0, $result['discarded']);
        $this->assertTrue($result['undecodable']);
    }

    /**
     * Missing confidence and why get safe defaults.
     *
     * @return void
     */
    public function test_missing_optional_fields(): void {
        $result = resolver::resolve('{"picks":[{"n":1}]}', $this->candidates());

        $this->assertFalse($result['undecodable']);
        $this->assertSame(0.0, $result['suggestions'][0]['confidence']);
        $this->assertSame('', $result['suggestions'][0]['why']);
    }

    /**
     * An empty picks array is a valid answer.
     *
     * @return void
     */
    public function test_empty_picks(): void {
        $result = resolver::resolve('{"picks":[]}', $this->candidates());

        $this->assertSame([], $result['suggestions']);
        $this->assertSame(0, $result['discarded']);
        $this->assertFalse($result['undecodable']);
    }

    /**
     * A fenced payload surrounded by brace-bearing prose still resolves.
     *
     * The brace-slicing fallback alone would span from the first brace in the
     * preamble to the last brace in the sign-off and decode nothing. The fence
     * must be tried first so the genuine answer inside it is not lost.
     *
     * @return void
     */
    public function test_fenced_payload_with_brace_prose_resolves(): void {
        $raw = "Sure, here is my analysis of the {activity} content.\n"
             . self::FENCE . "json\n{\"picks\":[{\"n\":2,\"confidence\":0.8,\"why\":\"clear match\"}]}\n"
             . self::FENCE . "\n"
             . "Let me know if you need more detail {smile}.";
        $result = resolver::resolve($raw, $this->candidates());

        $this->assertFalse($result['undecodable']);
        $this->assertSame(0, $result['discarded']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame(22, $result['suggestions'][0]['id']);
    }

    /**
     * An array-valued why is coerced to an empty string, never a PHP warning.
     *
     * @return void
     */
    public function test_array_why_yields_empty_string(): void {
        $raw = '{"picks":[{"n":1,"why":{"unexpected":"object"}}]}';
        $result = resolver::resolve($raw, $this->candidates());

        $this->assertFalse($result['undecodable']);
        $this->assertSame('', $result['suggestions'][0]['why']);
    }

    /**
     * A non-numeric confidence falls back to the safe default.
     *
     * @return void
     */
    public function test_non_numeric_confidence_yields_zero(): void {
        $raw = '{"picks":[{"n":1,"confidence":"high"}]}';
        $result = resolver::resolve($raw, $this->candidates());

        $this->assertFalse($result['undecodable']);
        $this->assertSame(0.0, $result['suggestions'][0]['confidence']);
    }

    /**
     * A literal triple-backtick inside the model's own "why" value must not truncate
     * the fenced capture. The closing fence is recognised only when the backticks
     * stand alone on their own line, so a mid-line backtick sequence inside a JSON
     * string value is not mistaken for the real closing fence.
     *
     * @return void
     */
    public function test_fenced_why_with_triple_backtick_resolves(): void {
        $raw = "Here is my analysis of the {content} provided.\n"
             . self::FENCE . "json\n"
             . "{\"picks\":[{\"n\":1,\"confidence\":0.75,\"why\":\"uses " . self::FENCE . " in a code sample\"}]}\n"
             . self::FENCE . "\n"
             . "Let me know if you need more {detail}.";
        $result = resolver::resolve($raw, $this->candidates());

        $this->assertFalse($result['undecodable']);
        $this->assertSame(0, $result['discarded']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame(11, $result['suggestions'][0]['id']);
        $this->assertSame('uses ' . self::FENCE . ' in a code sample', $result['suggestions'][0]['why']);
    }

    /**
     * Two fenced blocks: a worked PHP example first, the real answer second. Only
     * trying the first fence (as the previous implementation did) would capture the
     * example and fall through to a brace-slicing fallback that also fails.
     *
     * @return void
     */
    public function test_second_of_two_fenced_blocks_resolves(): void {
        $raw = "Here is a helper function first:\n"
             . self::FENCE . "php\n"
             . "function example() {\n"
             . "    return true;\n"
             . "}\n"
             . self::FENCE . "\n"
             . "And here is the actual answer:\n"
             . self::FENCE . "json\n"
             . "{\"picks\":[{\"n\":2,\"confidence\":0.6,\"why\":\"second fence\"}]}\n"
             . self::FENCE;
        $result = resolver::resolve($raw, $this->candidates());

        $this->assertFalse($result['undecodable']);
        $this->assertSame(0, $result['discarded']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame(22, $result['suggestions'][0]['id']);
    }

    /**
     * Unfenced JSON preceded by prose that itself contains a brace. Starting the
     * brace-span search from the first "{" would span from the prose's brace to the
     * payload's own closing brace and fail; trying successive opening braces finds
     * the real payload instead.
     *
     * @return void
     */
    public function test_unfenced_json_after_brace_bearing_prose_resolves(): void {
        $raw = 'Considering the {content} provided, here is my answer: {"picks":[{"n":1}]}';
        $result = resolver::resolve($raw, $this->candidates());

        $this->assertFalse($result['undecodable']);
        $this->assertSame(0, $result['discarded']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame(11, $result['suggestions'][0]['id']);
    }

    /**
     * A fenced block that is valid JSON but lacks a "picks" key must lose to a later
     * fence that has one. Without the shape check, a worked example that happens to
     * be valid JSON would win over the real answer.
     *
     * @return void
     */
    public function test_shape_check_skips_fence_without_picks_key(): void {
        $raw = self::FENCE . "json\n{\"example\":true}\n" . self::FENCE . "\n"
             . self::FENCE . "json\n{\"picks\":[{\"n\":2,\"confidence\":0.55,\"why\":\"shape check\"}]}\n" . self::FENCE;
        $result = resolver::resolve($raw, $this->candidates());

        $this->assertFalse($result['undecodable']);
        $this->assertSame(0, $result['discarded']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame(22, $result['suggestions'][0]['id']);
    }

    /**
     * A fenced decoy with {"picks":null} must not mask a real answer in a later fence.
     *
     * Without value checking, array_key_exists() accepts {"picks":null}, the decode
     * returns before trying the later fence, and the real answer is silently masked
     * while misreported as though the model picked nothing.
     *
     * @return void
     */
    public function test_decoy_picks_null_does_not_mask_later_answer(): void {
        $raw = self::FENCE . "json\n{\"picks\":null}\n" . self::FENCE . "\n"
             . self::FENCE . "json\n{\"picks\":[{\"n\":2,\"why\":\"real one\"}]}\n" . self::FENCE;
        $result = resolver::resolve($raw, $this->candidates());

        $this->assertFalse($result['undecodable']);
        $this->assertSame(0, $result['discarded']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame(22, $result['suggestions'][0]['id']);
        $this->assertSame('real one', $result['suggestions'][0]['why']);
    }

    /**
     * A standalone {"picks":null} answer with no better candidate is undecodable.
     *
     * {"picks":null} is not a well-formed answer; an array value is required.
     * This case must be reported as unreadable, not as though the model picked
     * nothing but was understood.
     *
     * @return void
     */
    public function test_standalone_picks_null_is_undecodable(): void {
        $result = resolver::resolve('{"picks":null}', $this->candidates());

        $this->assertTrue($result['undecodable']);
        $this->assertSame([], $result['suggestions']);
        $this->assertSame(0, $result['discarded']);
    }
}
