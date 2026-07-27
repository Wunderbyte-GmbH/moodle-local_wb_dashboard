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

use local_wb_dashboard\local\definition\chart_definition;

/**
 * Tests for the chart definition and its stable chart id.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_wb_dashboard\local\definition\chart_definition
 */
final class chart_definition_test extends \advanced_testcase {
    public function test_colour_args_are_ignored(): void {
        $definition = chart_definition::create_definition_from_shortcode_args([
            'source' => 'reportbuilder',
            'type' => 'bar',
            'report' => '3',
            'color1' => '#ff0000',
            'color2' => '#00ff00',
        ]);

        // Colours are no longer parsed from the shortcode at all.
        $this->assertArrayNotHasKey('colors', $definition->displayopts);
        $this->assertArrayNotHasKey('colors', $definition->to_wsargs());
        // The color1/color2 keys are not smuggled in as source params either.
        $this->assertArrayNotHasKey('color1', $definition->sourceparams);
        $this->assertArrayNotHasKey('color2', $definition->sourceparams);
        $this->assertSame(['report' => '3'], $definition->sourceparams);
    }

    public function test_target_is_a_display_option_not_a_source_param(): void {
        $definition = chart_definition::create_definition_from_shortcode_args([
            'source' => 'reportbuilder',
            'type' => 'progress',
            'report' => '3',
            'target' => '1000',
        ]);

        $this->assertSame(1000.0, $definition->displayopts['target']);
        $this->assertSame(1000.0, $definition->to_wsargs()['target']);
        // Not smuggled into the source params (and thus not into the chart id).
        $this->assertArrayNotHasKey('target', $definition->sourceparams);

        // Absent or invalid values fall back to 0 (= automatic).
        $auto = chart_definition::create_definition_from_shortcode_args(
            ['source' => 'reportbuilder', 'type' => 'progress', 'report' => '3']
        );
        $this->assertSame(0.0, $auto->displayopts['target']);
        $negative = chart_definition::create_definition_from_shortcode_args(
            ['source' => 'reportbuilder', 'type' => 'progress', 'report' => '3', 'target' => '-5']
        );
        $this->assertSame(0.0, $negative->displayopts['target']);
    }

    public function test_chartid_is_deterministic(): void {
        $args = ['source' => 'reportbuilder', 'type' => 'bar', 'report' => '3'];
        $a = chart_definition::create_definition_from_shortcode_args($args)->chartid_base(42);
        $b = chart_definition::create_definition_from_shortcode_args($args)->chartid_base(42);

        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^c[0-9a-f]{12}$/', $a);
    }

    public function test_chartid_namespaced_by_context(): void {
        $args = ['source' => 'reportbuilder', 'type' => 'bar', 'report' => '3'];
        $incontext1 = chart_definition::create_definition_from_shortcode_args($args)->chartid_base(1);
        $incontext2 = chart_definition::create_definition_from_shortcode_args($args)->chartid_base(2);

        $this->assertNotSame($incontext1, $incontext2);
    }

    public function test_chartid_ignores_cosmetic_opts(): void {
        $base = ['source' => 'reportbuilder', 'type' => 'bar', 'report' => '3'];
        $plain = chart_definition::create_definition_from_shortcode_args($base)->chartid_base(7);
        $decorated = chart_definition::create_definition_from_shortcode_args(
            $base + ['title' => 'Renamed', 'width' => '50', 'height' => '10']
        )->chartid_base(7);

        // Renaming/resizing keeps the id (and therefore stored settings) stable.
        $this->assertSame($plain, $decorated);
    }

    public function test_chartid_changes_with_data_params(): void {
        $three = chart_definition::create_definition_from_shortcode_args(
            ['source' => 'reportbuilder', 'type' => 'bar', 'report' => '3']
        )->chartid_base(7);
        $four = chart_definition::create_definition_from_shortcode_args(
            ['source' => 'reportbuilder', 'type' => 'bar', 'report' => '4']
        )->chartid_base(7);

        $this->assertNotSame($three, $four);
    }
}
