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

use local_wb_dashboard\local\definition\filter_definition;

/**
 * Tests for the filter definition, in particular the multi-key parsing that
 * lets one control publish its value under several logical keys.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_wb_dashboard\local\definition\filter_definition
 */
final class filter_definition_test extends \advanced_testcase {
    public function test_single_key_backwards_compatible(): void {
        $definition = filter_definition::create_definition_from_shortcode_args([
            'key' => 'usercreated',
            'type' => 'daterange',
        ]);

        $this->assertSame('usercreated', $definition->key);
        $this->assertSame(['usercreated'], $definition->keys);
        $this->assertSame('daterange', $definition->type);
    }

    public function test_comma_separated_keys(): void {
        $definition = filter_definition::create_definition_from_shortcode_args([
            'key' => 'usercreated,coursecompleted',
            'type' => 'daterange',
        ]);

        $this->assertSame(['usercreated', 'coursecompleted'], $definition->keys);
        // The first key is the primary one (display / prefill / factory).
        $this->assertSame('usercreated', $definition->key);
    }

    public function test_key_list_is_cleaned_and_deduped(): void {
        $definition = filter_definition::create_definition_from_shortcode_args([
            'key' => ' usercreated , ,usercreated,course completed,',
            'type' => 'daterange',
        ]);

        // Whitespace/empty segments dropped, duplicates removed, each segment
        // cleaned with PARAM_ALPHANUMEXT (inner space stripped).
        $this->assertSame(['usercreated', 'coursecompleted'], $definition->keys);
    }

    public function test_missing_key_yields_empty(): void {
        $definition = filter_definition::create_definition_from_shortcode_args(['type' => 'text']);

        $this->assertSame('', $definition->key);
        $this->assertSame([], $definition->keys);
    }

    public function test_non_reserved_args_become_config(): void {
        $definition = filter_definition::create_definition_from_shortcode_args([
            'key' => 'a,b',
            'type' => 'daterange',
            'label' => 'Period',
            'default' => 'last6months',
        ]);

        $this->assertSame('Period', $definition->config['label']);
        $this->assertSame('last6months', $definition->config['default']);
        $this->assertArrayNotHasKey('key', $definition->config);
    }
}
