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

namespace aiplacement_dimensions;

/**
 * Tests for plugin registration.
 *
 * @package    aiplacement_dimensions
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiplacement_dimensions\placement
 */
final class plugin_test extends \advanced_testcase {
    /**
     * The placement declares exactly the generate_text action.
     *
     * @return void
     */
    public function test_action_list(): void {
        $this->assertSame(
            [\core_ai\aiactions\generate_text::class],
            placement::get_action_list()
        );
    }

    /**
     * The suggest capability is declared at module level.
     *
     * @return void
     */
    public function test_capability_is_declared(): void {
        $this->resetAfterTest();
        $caps = get_all_capabilities();
        $this->assertArrayHasKey('aiplacement/dimensions:suggest', $caps);
        $this->assertSame(CONTEXT_MODULE, (int) $caps['aiplacement/dimensions:suggest']['contextlevel']);
    }
}
