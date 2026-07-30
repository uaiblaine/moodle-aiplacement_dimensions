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
 * Resolves the model's answer into competencies.
 *
 * The model answers with positions in the candidate list built by
 * {@see prompt::build()}. Resolution is therefore an array lookup, and matching
 * the wrong competency is not possible. Anything the model returns that is not
 * a usable position is counted in "discarded" and surfaced to the user.
 *
 * @package    aiplacement_dimensions
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resolver {
    /**
     * Resolve raw model output against the candidate list.
     *
     * @param string $raw The model's generated content.
     * @param array $candidates The list returned by prompt::build(), in order.
     * @return array Keys: suggestions (list), discarded (int).
     */
    public static function resolve(string $raw, array $candidates): array {
        $decoded = self::decode($raw);
        $picks = is_array($decoded['picks'] ?? null) ? $decoded['picks'] : [];

        $suggestions = [];
        $seen = [];
        $discarded = 0;

        foreach ($picks as $pick) {
            if (!is_array($pick) || !isset($pick['n']) || !is_numeric($pick['n'])) {
                $discarded++;
                continue;
            }

            $position = (int) $pick['n'];
            $index = $position - 1;

            if ($index < 0 || !isset($candidates[$index])) {
                $discarded++;
                continue;
            }

            if (isset($seen[$index])) {
                continue;
            }
            $seen[$index] = true;

            $candidate = $candidates[$index];
            $suggestions[] = [
                'id' => (int) $candidate['id'],
                'idnumber' => (string) $candidate['idnumber'],
                'shortname' => (string) $candidate['shortname'],
                'confidence' => isset($pick['confidence']) && is_numeric($pick['confidence'])
                    ? (float) $pick['confidence']
                    : 0.0,
                'why' => isset($pick['why']) && is_scalar($pick['why'])
                    ? clean_param((string) $pick['why'], PARAM_TEXT)
                    : '',
            ];
        }

        return ['suggestions' => $suggestions, 'discarded' => $discarded];
    }

    /**
     * Decode the model output, tolerating a surrounding code fence or prose.
     *
     * @param string $raw The model's generated content.
     * @return array Decoded payload, or an empty array when unreadable.
     */
    private static function decode(string $raw): array {
        $text = trim($raw);

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        /*
         * Prefer a fenced block. Models routinely wrap the JSON in prose, and that
         * prose often contains its own braces, so the brace-slicing fallback below
         * would span from a brace in the preamble to one in the sign-off and decode
         * nothing. Extracting the fence first keeps a genuine answer readable.
         */
        if (preg_match('/```(?:[a-z]*)\s*(.+?)\s*```/is', $text, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $open = strpos($text, '{');
        $close = strrpos($text, '}');
        if ($open === false || $close === false || $close <= $open) {
            return [];
        }

        $decoded = json_decode(substr($text, $open, $close - $open + 1), true);
        return is_array($decoded) ? $decoded : [];
    }
}
