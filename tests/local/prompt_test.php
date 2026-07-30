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
 * Tests for the prompt builder.
 *
 * @package    aiplacement_dimensions
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiplacement_dimensions\local\prompt
 */
final class prompt_test extends \basic_testcase {
    /**
     * Build a competency row as the fetcher returns it.
     *
     * @param int $id Competency id.
     * @param string $short Shortname.
     * @return array
     */
    private function row(int $id, string $short): array {
        return ['id' => $id, 'idnumber' => 'K' . $id, 'shortname' => $short];
    }

    /**
     * Candidates keep fetch order and are numbered from one in the text.
     *
     * @return void
     */
    public function test_numbering_starts_at_one_and_follows_order(): void {
        $result = prompt::build([$this->row(7, 'Alpha'), $this->row(3, 'Beta')], 'some content');

        $this->assertSame(7, $result['candidates'][0]['id']);
        $this->assertSame(3, $result['candidates'][1]['id']);
        $this->assertStringContainsString('1. Alpha', $result['text']);
        $this->assertStringContainsString('2. Beta', $result['text']);
        $this->assertSame(2, $result['candidatecount']);
        $this->assertFalse($result['truncated']);
    }

    /**
     * Exceeding the budget truncates and says so.
     *
     * @return void
     */
    public function test_budget_truncates_and_reports(): void {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = $this->row($i, 'C' . $i);
        }

        $result = prompt::build($rows, 'some content', 3);

        $this->assertCount(3, $result['candidates']);
        $this->assertSame(5, $result['candidatecount']);
        $this->assertTrue($result['truncated']);
        $this->assertStringNotContainsString('C4', $result['text']);
    }

    /**
     * An empty candidate set is not an error.
     *
     * @return void
     */
    public function test_empty_candidates(): void {
        $result = prompt::build([], 'some content');

        $this->assertSame([], $result['candidates']);
        $this->assertSame(0, $result['candidatecount']);
        $this->assertFalse($result['truncated']);
    }
}
