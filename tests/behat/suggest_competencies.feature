@core_ai @aiplacement_dimensions
Feature: AI competency suggestions are offered only when they are allowed, and only take effect once saved
  In order to keep AI use under site control and to only ever change what the user explicitly saves
  As a teacher
  I need the suggestion button to appear only when every gate allows it, and applying a suggestion
  to take effect only through the activity form's own save

  # The Suggest -> Add selected -> Save path is NOT covered by Behat anywhere in this feature,
  # on purpose. Making the model return a specific pick would require installing a mocked
  # \core_ai\manager, and \core\di::set() cannot make that mock visible to the request that
  # actually runs it: the Behat CLI process and the Selenium-driven site process the browser
  # talks to are separate PHP processes, so a DI override made from a step definition never
  # reaches suggest_competencies::execute() when it runs under the browser-driven request (see
  # the "Don't use get_config ... between selenium and cli process" comment in
  # lib/behat/lib.php for the same process boundary affecting another kind of shared state).
  # Core hit this exact wall for its own AI placement and did not solve it either:
  # ai/placement/courseassist/tests/behat/course_assist_features.feature never triggers an
  # actual completion, and ai/tests/behat/behat_core_ai.php offers only provider enable/disable
  # and action-configuration steps, none of them a response stub. Following that precedent, the
  # single @javascript scenario below stops at the furthest point reachable without a live
  # provider reply: the drawer opening and its pickers rendering. The suggest-and-apply path
  # itself, including the save that turns an applied suggestion into a real module link, has
  # no automated browser coverage, for the same process-boundary reason: a stubbed AI provider
  # cannot reach the browser-driven site process from this Behat run. That path is covered at
  # the service level by tests/external/suggest_competencies_test.php (PHPUnit, which runs in
  # the same process as the code it tests, so its \core_ai\manager mock is genuinely in
  # effect). The browser path itself remains unverified pending a run against a real site with
  # a real AI provider.

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email             |
      | teacher1 | Teacher   | One      | teacher1@test.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name       | course | idnumber |
      | assign   | Assignment | C1     | assign1  |
    # Both of the rows below are the baseline every scenario in this feature is measured
    # against, mirroring core's own Background for the same kind of gate (ai/placement/
    # courseassist/tests/behat/course_assist_features.feature:29-33): a provider, so
    # get_providers_for_actions() is non-empty, and the placement's own admin toggle turned on.
    # Without both, lib.php's gate (aiplacement_dimensions_coursemodule_definition_after_data())
    # returns before the button is ever drawn, and every "should not exist" scenario below would
    # pass regardless of whether the gate it claims to test was reached at all.
    And the following "core_ai > ai providers" exist:
      | provider          | name            | enabled | apikey | orgid |
      | aiprovider_openai | OpenAI API test | 1       | 123    | abc   |
    And the following config values are set as admin:
      | enabled | 1 | aiplacement_dimensions |

  Scenario: The button is present when every gate allows it
    # The positive case every negative scenario below is a single deviation from: nothing here
    # overrides the Background, so every gate (provider present, placement enabled, capability,
    # course and activity AI toggles) is left open.
    When I log in as "teacher1"
    And I am on the "Assignment" "assign activity editing" page
    Then "Suggest competencies with AI" "button" should exist

  Scenario: The button is absent when the placement is disabled
    # This asserts the standard aiplacement on/off toggle (\core\plugininfo\aiplacement::
    # is_plugin_enabled(), config key "enabled") is honoured, mirroring core's own coverage of
    # the same gate for its placement (ai/placement/courseassist/tests/behat/
    # course_assist_features.feature:53, "AI features are not available if placement is not
    # enabled"). lib.php checks this explicitly, immediately before its is_action_enabled()
    # check (aiplacement_dimensions_coursemodule_definition_after_data()). The single deviation
    # from the positive scenario above is the override below; the Background's provider is
    # otherwise untouched.
    Given the following config values are set as admin:
      | enabled | 0 | aiplacement_dimensions |
    When I log in as "teacher1"
    And I am on the "Assignment" "assign activity editing" page
    Then "Suggest competencies with AI" "button" should not exist

  Scenario: The button is absent for a role that is prohibited
    # The single deviation from the positive scenario is the permission override below; the
    # Background's provider and enabled placement are otherwise untouched.
    Given the following "permission overrides" exist:
      | capability                     | permission | role           | contextlevel | reference |
      | aiplacement/dimensions:suggest | Prohibit   | editingteacher | Course       | C1        |
    When I log in as "teacher1"
    And I am on the "Assignment" "assign activity editing" page
    Then "Suggest competencies with AI" "button" should not exist

  Scenario: The button is absent when AI tools are disabled at course level
    # The single deviation from the positive scenario is turning off the course-level AI
    # toggle; the Background's provider and enabled placement are otherwise untouched.
    Given I log in as "teacher1"
    And I am on the "Course 1" "course editing" page
    When I set the following fields to these values:
      | Allow AI tools for this course | No |
    And I press "Save and display"
    And I am on the "Assignment" "assign activity editing" page
    Then "Suggest competencies with AI" "button" should not exist

  Scenario: The button is absent on a not-yet-created activity when the placement is disabled
    # lib.php resolves a course context for an activity that has no cmid yet
    # (see aiplacement_dimensions_coursemodule_definition_after_data()). This proves the same
    # gates that hide the button on an existing activity's edit page also hide it on the
    # add-activity page, where every capability and is_action_enabled_in_context() check runs
    # against a course context instead of a module context — including the plugin-enabled gate
    # asserted by the negative scenario above. The single deviation from the positive scenario
    # is the same "enabled | 0" override, applied on the add-activity path instead.
    Given the following config values are set as admin:
      | enabled | 0 | aiplacement_dimensions |
    When I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I add a "Page" to section "1"
    Then "Suggest competencies with AI" "button" should not exist

  @javascript
  Scenario: The picker renders the framework select and its branch competencies
    # This is the furthest point reachable without a live provider reply — see the feature
    # header for why the suggest-and-apply path is not exercised here. Opening the drawer and
    # populating its pickers calls only core_competency_list_competency_frameworks and
    # local_dimensions_browse_structure (amd/src/suggest.js), both real webservices that need
    # no AI provider response. The provider and the placement toggle both come from the
    # Background — the same baseline the positive scenario above already proved is enough for
    # the button to render at all — so only the framework and its competency are added here.
    Given the following "core_competency > frameworks" exist:
      | shortname | idnumber |
      | Framework | fw1      |
    And the following "core_competency > competencies" exist:
      | shortname | idnumber | competencyframework |
      | Root      | root1    | fw1                 |
    When I log in as "teacher1"
    And I am on the "Assignment" "assign activity editing" page
    And I press "Suggest competencies with AI"
    Then "#aiplacement-dimensions-framework" "css_element" should exist
    And I should see "Framework" in the "#aiplacement-dimensions-framework" "css_element"
    And "[data-region='branch']" "css_element" should exist
    And I should see "Root" in the ".aiplacement-dimensions-pickers" "css_element"
