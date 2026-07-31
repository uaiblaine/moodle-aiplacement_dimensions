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
 * Portuguese (Brazil) language strings for the aiplacement_dimensions plugin.
 *
 * Keys and their order must stay identical to lang/en/aiplacement_dimensions.php: the
 * lang_test PHPUnit test asserts every referenced key exists in this component, and the
 * validate CI step enforces alphabetical key order independently for each language file.
 *
 * The 'promptinstruction' string below is deliberately left in English, unlike every
 * other string in this file. This value is not displayed to any user: it is the literal
 * instruction text sent to the AI model, and the model's reply is parsed against a JSON
 * schema ("picks", "n", "confidence", "why") that stays in English regardless of locale
 * (see classes/local/resolver.php). Translating the prose around that schema would risk
 * drifting the instruction from the parser's expectations without any of it ever being
 * tested, for a string no teacher or student ever sees.
 *
 * @package    aiplacement_dimensions
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['appliedheading'] = 'Adicionado ao curso e selecionado abaixo. Salve o formulário para vinculá-las a esta atividade.';
$string['applybutton'] = 'Adicionar selecionadas';
$string['brancheslabel'] = 'Limitar a estes ramos';
$string['branchestruncated'] = 'Mostrando {$a->shown} de {$a->total} competências raiz.';
$string['contenttruncatednotice'] = 'O conteúdo da atividade era extenso, então apenas a primeira parte foi enviada ao modelo.';
$string['dimensions:suggest'] = 'Sugerir competências com IA';
$string['discardednotice'] = 'O modelo retornou {$a} resposta(s) que não puderam ser associadas a nenhuma competência.';
$string['error_actiondisabled'] = 'As sugestões de competências por IA estão desativadas para esta atividade ou curso.';
$string['error_nosuchframework'] = 'Essa estrutura de competências não está disponível.';
$string['error_policynotaccepted'] = 'Você precisa aceitar a política de uso aceitável de IA antes de pedir sugestões.';
$string['error_provider'] = 'O provedor de IA não conseguiu concluir a solicitação (código {$a}).';
$string['error_toomanyroots'] = 'Foram selecionados ramos de competências demais de uma só vez.';
$string['failedheading'] = 'Não foi possível adicionar:';
$string['frameworklabel'] = 'Estrutura de competências';
$string['nocandidates'] = 'A estrutura de competências selecionada não tem competências para classificar.';
$string['nosuggestions'] = 'O modelo não encontrou uma correspondência clara nesta estrutura.';
$string['pluginname'] = 'Sugestões de competências com IA';
$string['privacy:metadata'] = 'O posicionamento de sugestões de competências com IA não armazena nenhum dado pessoal. O conteúdo da atividade é enviado ao provedor de IA configurado, que registra a solicitação no subsistema de IA do núcleo.';
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
$string['runbutton'] = 'Sugerir';
$string['suggestbutton'] = 'Sugerir competências com IA';
$string['truncatednotice'] = 'Apenas as primeiras {$a->sent} de {$a->total} competências foram enviadas ao modelo.';
$string['undecodablenotice'] = 'O provedor de IA respondeu, mas não foi possível ler a resposta. Nada foi sugerido. Tente novamente.';
