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

namespace aiplacement_dimensions\external;

use aiplacement_dimensions\local\candidates;
use aiplacement_dimensions\local\prompt;
use aiplacement_dimensions\local\resolver;
use core_ai\aiactions\generate_text;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Suggest competencies for the given activity content.
 *
 * @package    aiplacement_dimensions
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class suggest_competencies extends external_api {
    /** @var int Maximum characters of activity content sent to the model. */
    public const MAX_CONTENT = 20000;

    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Context id of the activity or course'),
            'frameworkid' => new external_value(PARAM_INT, 'Competency framework id'),
            'rootids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Competency id whose subtree is in scope'),
                'Chosen subtree roots; empty means the whole framework',
                VALUE_DEFAULT,
                []
            ),
            'content' => new external_value(PARAM_RAW, 'Activity content to classify'),
        ]);
    }

    /**
     * Suggest competencies.
     *
     * @param int $contextid Context id.
     * @param int $frameworkid Competency framework id.
     * @param array $rootids Chosen subtree roots.
     * @param string $content Activity content.
     * @return array The structure described by execute_returns().
     */
    public static function execute(int $contextid, int $frameworkid, array $rootids, string $content): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'frameworkid' => $frameworkid,
            'rootids' => $rootids,
            'content' => $content,
        ]);

        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);
        require_capability('aiplacement/dimensions:suggest', $context);
        require_capability('moodle/competency:coursecompetencymanage', $context);

        $manager = \core\di::get(\core_ai\manager::class);

        if (!$manager->is_action_enabled_in_context($context, generate_text::class)) {
            throw new \moodle_exception('error_actiondisabled', 'aiplacement_dimensions');
        }

        /*
         * Static, not an instance call: get_user_policy_status() is a public
         * static method (ai/classes/manager.php:242) reading the core/ai_policy
         * cache. It is therefore not reachable through the DI container, which
         * is also why the test accepts the policy for real instead of mocking it.
         */
        if (!\core_ai\manager::get_user_policy_status((int) $USER->id)) {
            throw new \moodle_exception('error_policynotaccepted', 'aiplacement_dimensions');
        }

        $rows = candidates::fetch($params['frameworkid'], $params['rootids']);
        $built = prompt::build($rows, \core_text::substr($params['content'], 0, self::MAX_CONTENT));

        if (empty($built['candidates'])) {
            return self::empty_result($built);
        }

        $action = new generate_text($context->id, (int) $USER->id, $built['text']);
        $response = $manager->process_action($action);

        if (!$response->get_success()) {
            return [
                'success' => false,
                'errorcode' => $response->get_errorcode(),
                'errormessage' => $response->get_errormessage(),
                'suggestions' => [],
                'discarded' => 0,
                'undecodable' => false,
                'candidatecount' => $built['candidatecount'],
                'truncated' => $built['truncated'],
            ];
        }

        $data = $response->get_response_data();
        $resolved = resolver::resolve((string) ($data['generatedcontent'] ?? ''), $built['candidates']);

        return [
            'success' => true,
            'errorcode' => 0,
            'errormessage' => '',
            'suggestions' => $resolved['suggestions'],
            'discarded' => $resolved['discarded'],
            'undecodable' => $resolved['undecodable'],
            'candidatecount' => $built['candidatecount'],
            'truncated' => $built['truncated'],
        ];
    }

    /**
     * Build the result for a request with no candidates in scope.
     *
     * @param array $built The output of prompt::build().
     * @return array The structure described by execute_returns().
     */
    private static function empty_result(array $built): array {
        return [
            'success' => true,
            'errorcode' => 0,
            'errormessage' => '',
            'suggestions' => [],
            'discarded' => 0,
            'undecodable' => false,
            'candidatecount' => $built['candidatecount'],
            'truncated' => $built['truncated'],
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the model was reached successfully'),
            'errorcode' => new external_value(PARAM_INT, 'Provider error code, zero when successful'),
            'errormessage' => new external_value(PARAM_TEXT, 'Provider error message, empty when successful'),
            'suggestions' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Competency id'),
                    'idnumber' => new external_value(PARAM_TEXT, 'Competency idnumber'),
                    'shortname' => new external_value(PARAM_TEXT, 'Competency shortname'),
                    'confidence' => new external_value(PARAM_FLOAT, 'Model confidence between 0 and 1'),
                    'why' => new external_value(PARAM_TEXT, 'One-sentence rationale'),
                ]),
                'Resolved suggestions'
            ),
            'discarded' => new external_value(PARAM_INT, 'Model answers that could not be resolved'),
            'undecodable' => new external_value(PARAM_BOOL, 'True when the model answer could not be parsed at all'),
            'candidatecount' => new external_value(PARAM_INT, 'Competencies in scope before truncation'),
            'truncated' => new external_value(PARAM_BOOL, 'Whether the candidate list was truncated'),
        ]);
    }
}
