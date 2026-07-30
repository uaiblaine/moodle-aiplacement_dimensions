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

/**
 * Language strings for the aiplacement_dimensions plugin.
 *
 * @package    aiplacement_dimensions
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['brancheslabel'] = 'Limit to these branches';
$string['dimensions:suggest'] = 'Suggest competencies with AI';
$string['error_actiondisabled'] = 'AI competency suggestions are turned off for this activity or course.';
$string['error_nosuchframework'] = 'That competency framework is not available.';
$string['error_policynotaccepted'] = 'You need to accept the AI acceptable use policy before asking for suggestions.';
$string['error_toomanyroots'] = 'Too many competency branches were selected at once.';
$string['frameworklabel'] = 'Competency framework';
$string['pluginname'] = 'AI competency suggestions';
$string['privacy:metadata'] = 'The AI competency suggestions placement does not store any personal data. Activity content is sent to the configured AI provider, which records the request under the core AI subsystem.';
$string['promptinstruction'] = 'You are mapping educational content to competencies.

CANDIDATE COMPETENCIES (choose only from this numbered list):
{$a->list}

CONTENT TO CLASSIFY:
{$a->content}

Return JSON only, with no prose and no markdown fence:
{"picks": [{"n": 1, "confidence": 0.0, "why": "one short sentence"}]}

Rules:
1) "n" must be a number from the list above. Never invent a number outside it.
2) Do not invent competency names or codes. You are choosing positions, not writing names.
3) If you are not confident a competency genuinely applies, leave it out.
4) Return {"picks": []} if nothing clearly applies. An empty answer is a valid and useful answer.
5) "confidence" is between 0 and 1. "why" is one short sentence naming the evidence in the content.';
$string['runbutton'] = 'Suggest';
$string['suggestbutton'] = 'Suggest competencies with AI';
