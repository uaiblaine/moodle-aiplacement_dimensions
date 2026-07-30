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
 * Tests for the competency subtree fetch.
 *
 * @package    aiplacement_dimensions
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiplacement_dimensions\local\candidates
 */
final class candidates_test extends \advanced_testcase {
    /**
     * The whole subtree is returned, not only direct children.
     *
     * @return void
     */
    public function test_fetch_returns_the_whole_subtree(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $root = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'shortname' => 'Root',
        ]);
        $child = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'parentid' => $root->get('id'),
            'shortname' => 'Child',
        ]);
        $grandchild = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'parentid' => $child->get('id'),
            'shortname' => 'Grandchild',
        ]);

        $rows = candidates::fetch($framework->get('id'), [$root->get('id')]);
        $ids = array_column($rows, 'id');

        $this->assertContains($child->get('id'), $ids);
        $this->assertContains($grandchild->get('id'), $ids, 'depth 3 must be reachable');
        $this->assertNotContains($root->get('id'), $ids, 'the chosen root is scope, not a candidate');
    }

    /**
     * With no roots the whole framework is in scope.
     *
     * @return void
     */
    public function test_empty_rootids_returns_the_framework(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $one = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'shortname' => 'Solo',
        ]);

        $rows = candidates::fetch($framework->get('id'), []);

        $this->assertSame([$one->get('id')], array_column($rows, 'id'));
    }

    /**
     * A root from another framework contributes nothing.
     *
     * @return void
     */
    public function test_foreign_root_is_ignored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $frameworka = $generator->create_framework();
        $frameworkb = $generator->create_framework();
        $foreign = $generator->create_competency([
            'competencyframeworkid' => $frameworkb->get('id'),
            'shortname' => 'Foreign',
        ]);

        $rows = candidates::fetch($frameworka->get('id'), [$foreign->get('id')]);

        $this->assertSame([], $rows);
    }
}
