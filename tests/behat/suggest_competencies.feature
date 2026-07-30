@core_ai @aiplacement_dimensions
Feature: AI competency suggestions are offered only when they are allowed, and only take effect once saved
  In order to keep AI use under site control and to only ever change what the user explicitly saves
  As a teacher
  I need the suggestion button to appear only when every gate allows it, and applying a suggestion
  to take effect only through the activity form's own save

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

  Scenario: The button is absent when the placement is disabled
    # This asserts the standard aiplacement on/off toggle (\core\plugininfo\aiplacement::is_plugin_enabled(),
    # config key "enabled") is honoured. lib.php currently has no such check anywhere — unlike
    # ai/placement/courseassist, whose utils.php::is_course_assist_available() calls
    # is_plugin_enabled() explicitly before rendering its UI, aiplacement_dimensions_
    # coursemodule_definition_after_data() only checks core_competency's own "enabled" config
    # (a different, unrelated flag) and never consults its own plugin's enabled state. Written
    # to match the intended admin control per the brief; if it fails at the "Then", the fix is
    # a missing gate in lib.php (a Task 6/7 finding), not this test.
    Given the following config values are set as admin:
      | enabled | 0 | aiplacement_dimensions |
    When I log in as "teacher1"
    And I am on the "Assignment" "assign activity editing" page
    Then "Suggest competencies with AI" "button" should not exist

  Scenario: The button is absent for a role that is prohibited
    Given the following "permission overrides" exist:
      | capability                     | permission | role           | contextlevel | reference |
      | aiplacement/dimensions:suggest | Prohibit   | editingteacher | Course       | C1        |
    When I log in as "teacher1"
    And I am on the "Assignment" "assign activity editing" page
    Then "Suggest competencies with AI" "button" should not exist

  Scenario: The button is absent when AI tools are disabled at course level
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
    # against a course context instead of a module context. Subject to the same caveat as the
    # very first scenario in this file: this config key is not currently read anywhere in lib.php.
    Given the following config values are set as admin:
      | enabled | 0 | aiplacement_dimensions |
    When I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I add a "Page" to section "1"
    Then "Suggest competencies with AI" "button" should not exist

  @javascript
  Scenario: Applying a suggestion links the competency once the form is saved
    # The plugin never writes the module competency link itself: tool_lp_coursemodule_edit_post_actions
    # (admin/tool/lp/lib.php) diffs the submitted form against the module's existing competencies and
    # would undo a link made while the form was still open. Applying a suggestion links the competency
    # to the COURSE and pre-selects it in the form's own hidden competencies[] select; only the form's
    # own save, handled entirely by core, is what actually creates the module link. Stopping this
    # scenario at "Add selected" would prove nothing about that path, so it saves and reopens the form.
    # lib.php's own button-rendering gate (aiplacement_dimensions_coursemodule_definition_after_data())
    # calls the real, unmocked manager to check for an enabled provider — the mock below only ever
    # substitutes what process_action() returns, so a real provider record is still required for the
    # button to render at all. Configured the same way ai/placement/courseassist's own Behat coverage
    # does it (ai/placement/courseassist/tests/behat/course_assist_features.feature).
    Given the following "core_ai > ai providers" exist:
      | provider          | name            | enabled | apikey | orgid |
      | aiprovider_openai | OpenAI API test | 1       | 123    | abc   |
    And a mocked AI provider returns competency pick 1
    And the AI acceptable use policy has been accepted by "teacher1"
    And the following "core_competency > frameworks" exist:
      | shortname | idnumber |
      | Framework | fw1      |
    And the following "core_competency > competencies" exist:
      | shortname | idnumber | competencyframework |
      | Root      | root1    | fw1                 |
      | Alpha     | alpha1   | fw1                 |
    When I log in as "teacher1"
    And I am on the "Assignment" "assign activity editing" page
    And I press "Suggest competencies with AI"
    And I select "Framework" from the "Competency framework" singleselect
    And I press "Suggest"
    # candidates are fetched "shortname ASC" (classes/local/candidates.php), so with "Alpha" and
    # "Root" in scope, pick 1 resolves to "Alpha" — and the suggestions.mustache checkbox for it is
    # checked by default, so no click is needed before applying it.
    And I press "Add selected"
    And I press "Save and return to course"
    And I am on the "Assignment" "assign activity editing" page
    Then I should see "Alpha" in the "#id_competenciessectioncontainer" "css_element"

  @javascript
  Scenario: Suggestions work on an activity that has never been saved
    # The design this plugin replaced could not offer suggestions before the activity itself was
    # saved. This design can, because it links to the course rather than the module — proved here
    # end to end, on an activity that is only ever created once, by this scenario's own save.
    Given the following "core_ai > ai providers" exist:
      | provider          | name            | enabled | apikey | orgid |
      | aiprovider_openai | OpenAI API test | 1       | 123    | abc   |
    And a mocked AI provider returns competency pick 1
    And the AI acceptable use policy has been accepted by "teacher1"
    And the following "core_competency > frameworks" exist:
      | shortname | idnumber |
      | Framework | fw1      |
    And the following "core_competency > competencies" exist:
      | shortname | idnumber | competencyframework |
      | Alpha     | alpha1   | fw1                 |
    When I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I add a "Page" to section "1"
    And I set the field "Name" to "Brand new page"
    And I set the field "Description" to "Teaches the Alpha competency."
    And I press "Suggest competencies with AI"
    And I select "Framework" from the "Competency framework" singleselect
    And I press "Suggest"
    And I press "Add selected"
    And I press "Save and return to course"
    And I am on the "Brand new page" "page activity editing" page
    Then I should see "Alpha" in the "#id_competenciessectioncontainer" "css_element"
