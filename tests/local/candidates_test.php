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
        $this->assertContains(
            $root->get('id'),
            $ids,
            'a subtree includes its root: the chosen branch itself is a candidate too'
        );
    }

    /**
     * On a flat framework every competency sits at parentid 0, so a chosen root has
     * no descendants at all. If the root were excluded from its own subtree, checking
     * that root would return zero candidates and make the framework unusable through
     * the picker, even though it has competencies.
     *
     * @return void
     */
    public function test_fetch_flat_framework_returns_the_chosen_root(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $chosen = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'shortname' => 'Chosen',
        ]);
        $other = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'shortname' => 'Other',
        ]);

        $rows = candidates::fetch($framework->get('id'), [$chosen->get('id')]);
        $ids = array_column($rows, 'id');

        $this->assertContains(
            $chosen->get('id'),
            $ids,
            'a flat root with no descendants must still return itself as a candidate'
        );
        $this->assertNotContains($other->get('id'), $ids, 'a sibling outside the chosen root is not in scope');
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
