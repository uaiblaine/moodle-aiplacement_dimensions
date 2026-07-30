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

namespace aiplacement_dimensions;

/**
 * Every referenced language string is defined.
 *
 * @package    aiplacement_dimensions
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class lang_test extends \advanced_testcase {
    /**
     * Collect every key referenced with this component and assert it exists.
     *
     * @return void
     */
    public function test_every_referenced_string_exists(): void {
        global $CFG;

        $root = $CFG->dirroot . '/ai/placement/dimensions';
        $patterns = [
            '/\{\{#str\}\}\s*([a-z0-9_]+)\s*,\s*aiplacement_dimensions\s*\{\{\/str\}\}/i',
            '/get_string\(\s*[\'"]([a-z0-9_:]+)[\'"]\s*,\s*[\'"]aiplacement_dimensions[\'"]/i',
            '/moodle_exception\(\s*[\'"]([a-z0-9_:]+)[\'"]\s*,\s*[\'"]aiplacement_dimensions[\'"]/i',
        ];

        $keys = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !preg_match('/\.(php|mustache|js)$/', $file->getFilename())) {
                continue;
            }
            if (str_contains($file->getPathname(), '/lang/') || str_contains($file->getPathname(), '/amd/build/')) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $contents, $matches)) {
                    $keys = array_merge($keys, $matches[1]);
                }
            }
        }

        $keys = array_unique($keys);
        $this->assertNotEmpty($keys, 'the scanner found no keys, so it is broken');

        $missing = [];
        foreach ($keys as $key) {
            if (!get_string_manager()->string_exists($key, 'aiplacement_dimensions')) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, 'undefined language strings: ' . implode(', ', $missing));
    }
}
