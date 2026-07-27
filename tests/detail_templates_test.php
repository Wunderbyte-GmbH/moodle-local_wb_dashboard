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

namespace local_wb_dashboard;

use local_wb_dashboard\local\detail\detail_templates;

/**
 * Tests for the named detail templates (parsing and placeholder substitution).
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_wb_dashboard\local\detail\detail_templates
 */
final class detail_templates_test extends \advanced_testcase {
    /**
     * Sections split at "=== name ===" markers; whitespace around markers is
     * tolerated, leading content is ignored, empty bodies are dropped and a
     * repeated name keeps the last section.
     */
    public function test_parse_config_sections(): void {
        $raw = <<<EOT
        ignored preamble

        === coursedetail ===
        <h3>{{label}}</h3>
        [digits report=1]

          ===  userdetail  ===
        [chart report=2]

        === emptyone ===

        === coursedetail ===
        last wins
        EOT;

        $templates = detail_templates::parse_config($raw);
        $this->assertSame(['coursedetail', 'userdetail'], array_keys($templates));
        $this->assertSame('last wins', $templates['coursedetail']);
        $this->assertSame('[chart report=2]', $templates['userdetail']);
    }

    /**
     * Multi-line bodies keep their inner lines and are trimmed at the edges.
     */
    public function test_parse_config_keeps_multiline_bodies(): void {
        $templates = detail_templates::parse_config(
            "=== d ===\n<div>\n[digits report=1]\n</div>\n"
        );
        $this->assertSame("<div>\n[digits report=1]\n</div>", $templates['d']);
    }

    /**
     * get() reads the admin setting; an unknown name is null.
     */
    public function test_get_reads_the_setting(): void {
        $this->resetAfterTest();
        set_config('detailtemplates', "=== coursedetail ===\nbody here", 'local_wb_dashboard');

        $this->assertSame('body here', detail_templates::get('coursedetail'));
        $this->assertNull(detail_templates::get('nosuch'));
    }

    /**
     * Placeholders substitute; quotes, brackets, semicolons and control
     * characters are stripped from the values so row data can never alter the
     * template's shortcode syntax — while real-world names survive.
     */
    public function test_substitute_sanitizes_values(): void {
        $template = '<h3>{{label}}</h3>[digits fixedfilters="courseid:{{id}}"]';

        // An injection attempt cannot break out of the shortcode argument.
        $out = detail_templates::substitute($template, '5" report=999 x="', 'a[chart]b; c');
        $this->assertSame(
            '<h3>achartb c</h3>[digits fixedfilters="courseid:5 report=999 x="]',
            $out
        );
        $this->assertStringNotContainsString('"courseid:5"', $out);

        // Real names keep umlauts, commas, apostrophes and colons.
        $out = detail_templates::substitute('{{label}}', '1', "Mathematik für Anfänger: Teil 1, O'Brien");
        $this->assertSame("Mathematik für Anfänger: Teil 1, O'Brien", $out);
    }
}
