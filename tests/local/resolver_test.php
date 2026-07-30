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

        $this->assertCount(1, $result['suggestions']);
        $this->assertSame(0, $result['discarded']);
    }

    /**
     * A fenced JSON payload is still readable.
     *
     * @return void
     */
    public function test_code_fence_is_tolerated(): void {
        $raw = "```json\n{\"picks\":[{\"n\":1}]}\n```";
        $result = resolver::resolve($raw, $this->candidates());

        $this->assertCount(1, $result['suggestions']);
    }

    /**
     * Unparseable output is an empty answer, not an exception.
     *
     * @return void
     */
    public function test_malformed_json_is_empty_not_fatal(): void {
        $result = resolver::resolve('I am afraid I cannot help with that.', $this->candidates());

        $this->assertSame([], $result['suggestions']);
        $this->assertSame(0, $result['discarded']);
    }

    /**
     * Missing confidence and why get safe defaults.
     *
     * @return void
     */
    public function test_missing_optional_fields(): void {
        $result = resolver::resolve('{"picks":[{"n":1}]}', $this->candidates());

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
             . "```json\n{\"picks\":[{\"n\":2,\"confidence\":0.8,\"why\":\"clear match\"}]}\n```\n"
             . "Let me know if you need more detail {smile}.";
        $result = resolver::resolve($raw, $this->candidates());

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

        $this->assertSame(0.0, $result['suggestions'][0]['confidence']);
    }
}
