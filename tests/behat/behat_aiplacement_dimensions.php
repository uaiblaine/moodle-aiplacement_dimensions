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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

/**
 * Step definitions specific to the aiplacement_dimensions plugin.
 *
 * @package    aiplacement_dimensions
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_aiplacement_dimensions extends behat_base {
    /**
     * Install a mocked \core_ai\manager that answers with a single competency pick.
     *
     * Mirrors tests/external/suggest_competencies_test.php::mock_manager(), but as a real
     * subclass rather than a PHPUnit mock: PHPUnit's mock generator is not available outside
     * a TestCase, and core's own Behat precedent for swapping a DI service (the frozen clock
     * in lib/tests/behat/behat_general.php) does the same thing, a genuine object handed to
     * \core\di::set().
     *
     * IMPORTANT CAVEAT, left here rather than silently assumed away: \core\di's container
     * (lib/classes/di.php) is a `protected static` in-process object with no persistence.
     * core's frozen-clock step works around exactly this by ALSO writing $CFG->behat_frozen_clock,
     * which lib/classes/di.php reads back (guarded by BEHAT_SITE_RUNNING) when the container is
     * rebuilt on each fresh request. There is no equivalent config-backed hook for
     * \core_ai\manager::class in core, and adding one is out of this task's scope (it would be
     * production code, in core or in classes/external/suggest_competencies.php, not a test file).
     * So this override is confirmed to reach any step code that calls \core\di::get() directly in
     * the Behat CLI process, but is NOT confirmed to reach the suggest_competencies external
     * function when it runs inside the separate, browser-driven "selenium" site process — see the
     * "Don't use get_config ... between selenium and cli process" comment in lib/behat/lib.php for
     * the same process boundary affecting config caching. Written to match this task's brief and
     * core's DI-override convention; whether it actually intercepts the live @javascript request is
     * for the first CI run to confirm.
     *
     * @Given /^a mocked AI provider returns competency pick (?P<n>\d+)$/
     *
     * @param int $n The 1-based position, in the candidate list, that the model "picks".
     */
    public function a_mocked_ai_provider_returns_competency_pick(int $n): void {
        global $DB;

        $response = new \core_ai\aiactions\responses\response_generate_text(success: true);
        $response->set_response_data([
            'generatedcontent' => json_encode([
                'picks' => [
                    ['n' => $n, 'confidence' => 0.9, 'why' => 'Behat stub'],
                ],
            ]),
            'finishreason' => 'stop',
        ]);

        $mock = new class ($DB, $response) extends \core_ai\manager {
            /**
             * @param \moodle_database $db The database instance, forwarded to the real manager.
             * @param \core_ai\aiactions\responses\response_base $stubresponse The canned response.
             */
            public function __construct(
                \moodle_database $db,
                private readonly \core_ai\aiactions\responses\response_base $stubresponse,
            ) {
                parent::__construct($db);
            }

            #[\Override]
            public function is_action_enabled(string $plugin, string $actionclass, int $instanceid = 0): bool {
                return true;
            }

            #[\Override]
            public function is_action_enabled_in_context(\context $context, string $actionclass): bool {
                return true;
            }

            #[\Override]
            public function get_providers_for_actions(array $actions, bool $enabledonly = false): array {
                return [\core_ai\aiactions\generate_text::class => ['aiprovider_openai']];
            }

            #[\Override]
            public function process_action(\core_ai\aiactions\base $action): \core_ai\aiactions\responses\response_base {
                return $this->stubresponse;
            }
        };

        \core\di::set(\core_ai\manager::class, $mock);
    }

    /**
     * Accept the AI acceptable use policy for a user, without going through the policy dialogue.
     *
     * \core_ai\manager::user_policy_accepted() and get_user_policy_status() are the same static
     * methods tests/external/suggest_competencies_test.php::accept_policy() calls directly, for
     * the same reason given there: both are public STATIC methods (ai/classes/manager.php:219 and
     * :242), so they are not reachable through the DI container and cannot be mocked. Unlike the
     * provider mock above, this genuinely reaches the browser-driven request: user_policy_accepted()
     * writes a row to the ai_policy_register table and a value into the core/ai_policy cache, both
     * real shared storage the live site process reads on its own, not in-process container state.
     *
     * get_user_policy_status(), the read side suggest_competencies::execute() calls, is keyed only
     * by userid (ai/classes/manager.php:242-245) — the context id recorded here is an audit detail,
     * not something the gate itself checks, so context_system is as good as any other context.
     *
     * @Given /^the AI acceptable use policy has been accepted by "(?P<username>(?:[^"]|\\")*)"$/
     *
     * @param string $username The username to accept the policy for.
     */
    public function the_ai_acceptable_use_policy_has_been_accepted_by(string $username): void {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        \core_ai\manager::user_policy_accepted((int) $user->id, \context_system::instance()->id);
    }
}
