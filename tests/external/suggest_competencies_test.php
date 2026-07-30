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
     * @return void
     */
    private function mock_manager(string $generated): void {
        $response = new \core_ai\aiactions\responses\response_generate_text(success: true);
        $response->set_response_data([
            'generatedcontent' => $generated,
            'finishreason' => 'stop',
        ]);

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
     * get_user_policy_status() and user_policy_accepted() are public STATIC
     * methods on the manager (ai/classes/manager.php:219 and :242), so they are
     * not reachable through the DI container and cannot be mocked. The status
     * lives in the core/ai_policy cache, and the only way to satisfy it in a
     * test is to accept the policy for real, which is what core does in
     * ai/tests/provider/provider_test.php:79.
     *
     * @param \context $context The context the policy is accepted in.
     * @return void
     */
    private function accept_policy(\context $context): void {
        global $USER;

        \core_ai\manager::user_policy_accepted((int) $USER->id, $context->id);
    }

    /**
     * Build a course, module and framework with one competency.
     *
     * @return array Keys: context, frameworkid, competencyid.
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
            'context' => \context_module::instance($module->cmid),
            'frameworkid' => $framework->get('id'),
            'competencyid' => $competency->get('id'),
        ];
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

        $_POST['sesskey'] = sesskey();
        $result = \core_external\external_api::call_external_function(
            'aiplacement_dimensions_suggest_competencies',
            [
                'contextid' => $scenario['context']->id,
                'frameworkid' => $scenario['frameworkid'],
                'rootids' => [],
                'content' => 'Some activity description.',
            ]
        );

        $this->assertFalse($result['error']);
        $this->assertTrue($result['data']['success']);
        $this->assertCount(1, $result['data']['suggestions']);
        $this->assertSame($scenario['competencyid'], $result['data']['suggestions'][0]['id']);
        $this->assertSame(0, $result['data']['discarded']);
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

        $_POST['sesskey'] = sesskey();
        $result = \core_external\external_api::call_external_function(
            'aiplacement_dimensions_suggest_competencies',
            [
                'contextid' => $scenario['context']->id,
                'frameworkid' => $scenario['frameworkid'],
                'rootids' => [],
                'content' => 'Some activity description.',
            ]
        );

        $this->assertSame([], $result['data']['suggestions']);
        $this->assertSame(1, $result['data']['discarded']);
    }

    /**
     * A student cannot ask for suggestions.
     *
     * @return void
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();
        $scenario = $this->scenario();
        $this->mock_manager('{"picks":[]}');

        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $_POST['sesskey'] = sesskey();
        $result = \core_external\external_api::call_external_function(
            'aiplacement_dimensions_suggest_competencies',
            [
                'contextid' => $scenario['context']->id,
                'frameworkid' => $scenario['frameworkid'],
                'rootids' => [],
                'content' => 'Some activity description.',
            ]
        );

        $this->assertTrue($result['error']);
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

        $response = new \core_ai\aiactions\responses\response_generate_text(success: true);
        $response->set_response_data(['generatedcontent' => '{"picks":[]}', 'finishreason' => 'stop']);
        $this->accept_policy($scenario['context']);

        $mock = $this->createMock(\core_ai\manager::class);
        $mock->method('process_action')->willReturn($response);
        $mock->method('is_action_enabled')->willReturn(true);
        $mock->method('is_action_enabled_in_context')->willReturn(false);
        \core\di::set(\core_ai\manager::class, fn() => $mock);

        $_POST['sesskey'] = sesskey();
        $result = \core_external\external_api::call_external_function(
            'aiplacement_dimensions_suggest_competencies',
            [
                'contextid' => $scenario['context']->id,
                'frameworkid' => $scenario['frameworkid'],
                'rootids' => [],
                'content' => 'Some activity description.',
            ]
        );

        $this->assertTrue($result['error']);
    }
}
