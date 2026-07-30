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
 * Builds the numbered candidate list and the instruction text sent to the model.
 *
 * This class is deliberately free of $DB and core_ai so it can be unit tested
 * without a site. The candidate array it returns is the single source of truth
 * for resolution: the model answers with positions in this list, never names.
 *
 * @package    aiplacement_dimensions
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prompt {
    /** @var int Default maximum number of candidates sent to the model. */
    public const DEFAULT_BUDGET = 200;

    /**
     * Build the candidate list and prompt text.
     *
     * @param array $competencies Rows with keys id, idnumber, shortname, in fetch order.
     * @param string $content The activity content to classify.
     * @param int $budget Maximum candidates to send.
     * @return array Keys: candidates, text, candidatecount, truncated.
     */
    public static function build(array $competencies, string $content, int $budget = self::DEFAULT_BUDGET): array {
        $all = array_values($competencies);
        $total = count($all);
        $candidates = array_slice($all, 0, $budget);
        $truncated = $total > count($candidates);

        $lines = [];
        foreach ($candidates as $index => $candidate) {
            $lines[] = ($index + 1) . '. ' . $candidate['shortname'];
        }

        $text = get_string('promptinstruction', 'aiplacement_dimensions', (object) [
            'list' => implode("\n", $lines),
            'content' => $content,
        ]);

        return [
            'candidates' => $candidates,
            'text' => $text,
            'candidatecount' => $total,
            'truncated' => $truncated,
        ];
    }
}
