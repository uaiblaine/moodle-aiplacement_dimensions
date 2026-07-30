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

use core_competency\competency;

/**
 * Fetches the competencies that are in scope for a suggestion request.
 *
 * @package    aiplacement_dimensions
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class candidates {
    /**
     * Fetch the competencies under the chosen roots.
     *
     * A subtree includes its root: each chosen root is a candidate alongside its
     * descendants, at any depth. An empty root list means the whole framework. The
     * full subtree is returned, at any depth: fetching only direct children makes
     * deep frameworks unusable, and excluding the root itself would leave a flat
     * framework (everything at parentid = 0) with zero candidates whenever a
     * branch is chosen, since a flat competency has no descendants at all.
     *
     * @param int $frameworkid The competency framework id.
     * @param array $rootids Competency ids whose subtrees are in scope.
     * @return array Rows with keys id, idnumber, shortname, sorted by shortname.
     */
    public static function fetch(int $frameworkid, array $rootids): array {
        global $DB;

        if (empty($rootids)) {
            $records = $DB->get_records(
                'competency',
                ['competencyframeworkid' => $frameworkid],
                'shortname ASC',
                'id, idnumber, shortname'
            );
            return array_values(array_map([self::class, 'row'], $records));
        }

        $ids = [];
        foreach ($rootids as $rootid) {
            $root = competency::get_record(['id' => (int) $rootid, 'competencyframeworkid' => $frameworkid]);
            if (!$root) {
                continue;
            }
            /*
             * get_descendants_ids() matches path LIKE '{path}{id}/%' (competency.php),
             * which excludes the root itself by construction. A subtree includes its
             * root, so it is added explicitly here. Without this line, checking any
             * branch always sends strictly fewer competencies than checking none, and
             * on a flat framework (every competency at parentid = 0, which has no
             * descendants) checking a branch yields zero candidates and makes the
             * framework unusable through the picker.
             */
            $ids[] = (int) $root->get('id');
            $ids = array_merge($ids, competency::get_descendants_ids($root));
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'cid');
        $params['frameworkid'] = $frameworkid;
        $records = $DB->get_records_select(
            'competency',
            "id {$insql} AND competencyframeworkid = :frameworkid",
            $params,
            'shortname ASC',
            'id, idnumber, shortname'
        );

        return array_values(array_map([self::class, 'row'], $records));
    }

    /**
     * Normalise a database record into a candidate row.
     *
     * @param \stdClass $record A competency record.
     * @return array Keys id, idnumber, shortname.
     */
    private static function row(\stdClass $record): array {
        return [
            'id' => (int) $record->id,
            'idnumber' => (string) ($record->idnumber ?? ''),
            'shortname' => (string) ($record->shortname ?? ''),
        ];
    }
}
