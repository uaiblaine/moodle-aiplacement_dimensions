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

use core_ai\aiactions\generate_text;

/**
 * Tests for the suggest_competencies web service.
 *
 * @package    aiplacement_dimensions
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiplacement_dimensions\external\suggest_competencies
 */
final class suggest_competencies_test extends \advanced_testcase {
    /**
     * Install a mocked AI manager returning the given generated content.
     *
     * @param string $generated The content the model is pretending to return.
     * @param bool $success Whether the provider call succeeded.
     * @return void
     */
    private function mock_manager(string $generated, bool $success = true): void {
        // response_base::__construct() (ai/classes/aiactions/responses/response_base.php:58) throws a
        // coding_exception when success is false unless BOTH errorcode and error (the error name) are
        // non-empty; error is required here for that reason even though this test only asserts errorcode.
        $response = new \core_ai\aiactions\responses\response_generate_text(
            success: $success,
            errorcode: $success ? 0 : 429,
            error: $success ? '' : 'error_ratelimited',
            errormessage: $success ? '' : 'Rate limited'
        );
        if ($success) {
            $response->set_response_data([
                'generatedcontent' => $generated,
                'finishreason' => 'stop',
            ]);
        }

        $mock = $this->createMock(\core_ai\manager::class);
        $mock->method('process_action')->willReturn($response);
        $mock->method('is_action_enabled')->willReturn(true);
        $mock->method('is_action_enabled_in_context')->willReturn(true);
        $mock->method('get_providers_for_actions')->willReturn([
            generate_text::class => ['aiprovider_openai'],
        ]);

        \core\di::set(\core_ai\manager::class, fn() => $mock);
    }

    /**
     * Accept the AI policy for the current user.
     *
     * get_user_policy_status() and user_policy_accepted() are public STATIC methods
     * on the manager (ai/classes/manager.php:242 and :219), so they are not reachable
     * through the DI container and cannot be mocked. The status lives in the
     * core/ai_policy cache, and the only way to satisfy it in a test is to accept the
     * policy for real, which is what core does in ai/tests/provider/provider_test.php:79.
     *
     * @param \context $context The context the policy is accepted in.
     * @return void
     */
    private function accept_policy(\context $context): void {
        global $USER;

        \core_ai\manager::user_policy_accepted((int) $USER->id, $context->id);
    }

    /**
     * Build a course, module, framework and one competency.
     *
     * @return array Keys: course, cmid, frameworkid, competencyid, context.
     */
    private function scenario(): array {
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'shortname' => 'Alpha',
        ]);

        return [
            'course' => $course,
            'cmid' => $module->cmid,
            'frameworkid' => $framework->get('id'),
            'competencyid' => $competency->get('id'),
            'context' => \context_module::instance($module->cmid),
        ];
    }

    /**
     * Call the web service with the given overrides.
     *
     * @param array $scenario The scenario array.
     * @param array $overrides Parameter overrides.
     * @return array The call_external_function result.
     */
    private function call(array $scenario, array $overrides = []): array {
        $_POST['sesskey'] = sesskey();

        return \core_external\external_api::call_external_function(
            'aiplacement_dimensions_suggest_competencies',
            $overrides + [
                'cmid' => $scenario['cmid'],
                'courseid' => $scenario['course']->id,
                'frameworkid' => $scenario['frameworkid'],
                'rootids' => [],
                'content' => 'Some activity description.',
            ]
        );
    }

    /**
     * A valid pick comes back resolved to a real competency id.
     *
     * @return void
     */
    public function test_execute_resolves_a_pick(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = $this->scenario();
        $this->accept_policy($scenario['context']);
        $this->mock_manager('{"picks":[{"n":1,"confidence":0.7,"why":"covers it"}]}');

        $result = $this->call($scenario);

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertCount(1, $result['data']['suggestions']);
        $this->assertSame($scenario['competencyid'], $result['data']['suggestions'][0]['id']);
        $this->assertSame(0, $result['data']['discarded']);
        $this->assertFalse($result['data']['undecodable']);
        $this->assertFalse($result['data']['contenttruncated']);
        $this->assertSame(1, $result['data']['candidatecount']);
    }

    /**
     * An out-of-range index is reported, not dropped.
     *
     * @return void
     */
    public function test_execute_reports_discards(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = $this->scenario();
        $this->accept_policy($scenario['context']);
        $this->mock_manager('{"picks":[{"n":99}]}');

        $result = $this->call($scenario);

        $this->assertSame([], $result['data']['suggestions']);
        $this->assertSame(1, $result['data']['discarded']);
    }

    /**
     * An unreadable answer is distinguished from an empty one.
     *
     * @return void
     */
    public function test_execute_reports_undecodable(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = $this->scenario();
        $this->accept_policy($scenario['context']);
        $this->mock_manager('I am afraid I cannot help with that.');

        $result = $this->call($scenario);

        $this->assertTrue($result['data']['undecodable']);
        $this->assertSame([], $result['data']['suggestions']);
    }

    /**
     * A provider failure returns state, not an exception.
     *
     * @return void
     */
    public function test_execute_returns_provider_failure_as_state(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = $this->scenario();
        $this->accept_policy($scenario['context']);
        $this->mock_manager('', false);

        $result = $this->call($scenario);

        $this->assertFalse($result['error']);
        $this->assertFalse($result['data']['success']);
        $this->assertSame(429, $result['data']['errorcode']);
    }

    /**
     * A framework with no competencies in scope returns the empty result cleanly.
     *
     * @return void
     */
    public function test_execute_with_no_candidates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = $this->scenario();
        $this->accept_policy($scenario['context']);
        $this->mock_manager('{"picks":[]}');

        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $empty = $generator->create_framework();

        $result = $this->call($scenario, ['frameworkid' => $empty->get('id')]);

        $this->assertTrue($result['data']['success']);
        $this->assertSame(0, $result['data']['candidatecount']);
        $this->assertSame([], $result['data']['suggestions']);
    }

    /**
     * Content beyond the cap is truncated and the response says so.
     *
     * @return void
     */
    public function test_execute_flags_truncated_content(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = $this->scenario();
        $this->accept_policy($scenario['context']);
        $this->mock_manager('{"picks":[]}');

        $result = $this->call($scenario, [
            'content' => str_repeat('a', suggest_competencies::MAX_CONTENT + 1),
        ]);

        $this->assertTrue($result['data']['contenttruncated']);
    }

    /**
     * An enrolled user without the capability is refused by the capability gate.
     *
     * The user is enrolled deliberately: an unenrolled user is stopped earlier by
     * validate_context()'s require_login(), which would make this test pass even if
     * both require_capability() calls were deleted.
     *
     * @return void
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $scenario = $this->scenario();
        $this->mock_manager('{"picks":[]}');

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $scenario['course']->id, 'student');
        $this->setUser($student);
        $this->accept_policy($scenario['context']);

        $result = $this->call($scenario);

        $this->assertTrue($result['error']);
        $this->assertSame('nopermissions', $result['exception']->errorcode);
    }

    /**
     * The acceptable use policy blocks the call when it has not been accepted.
     *
     * @return void
     */
    public function test_execute_requires_policy_acceptance(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = $this->scenario();
        $this->mock_manager('{"picks":[]}');

        \cache::make('core', 'ai_policy')->purge();

        $result = $this->call($scenario);

        $this->assertTrue($result['error']);
        $this->assertSame('error_policynotaccepted', $result['exception']->errorcode);
    }

    /**
     * The per-context AI opt-out is honoured.
     *
     * @return void
     */
    public function test_execute_honours_context_opt_out(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = $this->scenario();
        $this->accept_policy($scenario['context']);

        $response = new \core_ai\aiactions\responses\response_generate_text(success: true);
        $response->set_response_data(['generatedcontent' => '{"picks":[]}', 'finishreason' => 'stop']);
        $mock = $this->createMock(\core_ai\manager::class);
        $mock->method('process_action')->willReturn($response);
        $mock->method('is_action_enabled')->willReturn(true);
        $mock->method('is_action_enabled_in_context')->willReturn(false);
        $mock->method('get_providers_for_actions')->willReturn([
            generate_text::class => ['aiprovider_openai'],
        ]);
        \core\di::set(\core_ai\manager::class, fn() => $mock);

        $result = $this->call($scenario);

        $this->assertTrue($result['error']);
        $this->assertSame('error_actiondisabled', $result['exception']->errorcode);
    }

    /**
     * The placement's own admin toggle is honoured.
     *
     * @return void
     */
    public function test_execute_honours_action_toggle(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = $this->scenario();
        $this->accept_policy($scenario['context']);

        $mock = $this->createMock(\core_ai\manager::class);
        $mock->method('is_action_enabled')->willReturn(false);
        $mock->method('is_action_enabled_in_context')->willReturn(true);
        \core\di::set(\core_ai\manager::class, fn() => $mock);

        $result = $this->call($scenario);

        $this->assertTrue($result['error']);
        $this->assertSame('error_actiondisabled', $result['exception']->errorcode);
    }

    /**
     * A framework the user may not read is refused.
     *
     * @return void
     */
    public function test_execute_requires_framework_read_capability(): void {
        global $DB;

        $this->resetAfterTest();
        $scenario = $this->scenario();
        $this->mock_manager('{"picks":[]}');

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $scenario['course']->id, 'editingteacher');
        $this->setUser($teacher);
        $this->accept_policy($scenario['context']);

        $frameworkcontext = \core_competency\competency_framework::get_record(
            ['id' => $scenario['frameworkid']]
        )->get_context();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'moodle/competency:competencyview',
            CAP_PROHIBIT,
            $roleid,
            $frameworkcontext->id,
            true
        );

        $result = $this->call($scenario);

        $this->assertTrue($result['error']);
    }

    /**
     * A cmid that does not belong to the given course is refused.
     *
     * @return void
     */
    public function test_execute_rejects_mismatched_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = $this->scenario();
        $this->accept_policy($scenario['context']);
        $this->mock_manager('{"picks":[]}');

        $other = $this->getDataGenerator()->create_course();

        $result = $this->call($scenario, ['courseid' => $other->id]);

        $this->assertTrue($result['error']);
    }
}
