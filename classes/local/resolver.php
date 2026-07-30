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
     * @return array Keys: suggestions (list), discarded (int), undecodable (bool).
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

        return [
            'suggestions' => $suggestions,
            'discarded' => $discarded,
            'undecodable' => $decoded === null,
        ];
    }

    /** @var int Ceiling on brace-span attempts, so a brace-heavy answer cannot spin. */
    private const MAX_SPANS = 20;

    /**
     * Decode the model output, tolerating fences and surrounding prose.
     *
     * Tries a list of candidate substrings in priority order and accepts the first
     * that decodes to an array whose "picks" value is itself an array. Checking the
     * value and not merely the key matters: a decoy fence carrying {"picks":null}
     * satisfies key-presence, is accepted, and masks a real answer in a later fence.
     *
     * @param string $raw The model's generated content.
     * @return array|null Decoded payload, or null when no payload could be found.
     */
    private static function decode(string $raw): ?array {
        foreach (self::candidate_payloads($raw) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded) && is_array($decoded['picks'] ?? null)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Build the candidate substrings that might hold the payload, best first.
     *
     * @param string $raw The model's generated content.
     * @return array Candidate strings, in the order they should be tried.
     */
    private static function candidate_payloads(string $raw): array {
        $text = trim($raw);
        $candidates = [$text];

        /*
         * Every fenced block, not just the first: models put a worked example in one
         * fence and the answer in another. The closing fence is anchored to its own
         * line so a triple-backtick inside a string value cannot truncate the capture.
         */
        if (preg_match_all('/^```[a-z]*[ \t]*\R(.*?)\R```[ \t]*$/ims', $text, $matches)) {
            foreach ($matches[1] as $block) {
                $candidates[] = trim($block);
            }
        }

        /*
         * Last resort for unfenced JSON: every span from a brace to the final brace.
         * Trying successive opening braces means a brace in the preamble no longer
         * swallows the payload, because a later start eventually lands on it.
         */
        $close = strrpos($text, '}');
        if ($close !== false) {
            $offset = 0;
            $tries = 0;
            while ($tries < self::MAX_SPANS) {
                $open = strpos($text, '{', $offset);
                if ($open === false || $open >= $close) {
                    break;
                }
                $candidates[] = substr($text, $open, $close - $open + 1);
                $offset = $open + 1;
                $tries++;
            }
        }

        return $candidates;
    }
}
