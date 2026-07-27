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

use local_wb_dashboard\local\dto\filter_constraint;
use local_wb_dashboard\local\filter\daterange_filter;
use local_wb_dashboard\local\filter\filter_factory;

/**
 * Tests for the date range filter: "from|to" wire value normalization and
 * neutral between-constraint production.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_wb_dashboard\local\filter\daterange_filter
 */
final class daterange_filter_test extends \advanced_testcase {
    public function test_both_sides_normalize_to_day_bounds(): void {
        $filter = new daterange_filter('period', []);
        [$from, $to] = $filter->normalize_value('2026-01-15|2026-01-15');
        $this->assertGreaterThan(0, $from);
        // Same day: from 00:00:00 to 23:59:59.
        $this->assertSame($from + DAYSECS - 1, $to);
    }

    public function test_open_ended_and_empty_ranges(): void {
        $filter = new daterange_filter('period', []);

        [$from, $to] = $filter->normalize_value('2026-01-15|');
        $this->assertGreaterThan(0, $from);
        $this->assertSame(0, $to);

        [$from, $to] = $filter->normalize_value('|2026-06-30');
        $this->assertSame(0, $from);
        $this->assertGreaterThan(0, $to);

        $this->assertSame([0, 0], $filter->normalize_value(''));
        $this->assertSame([0, 0], $filter->normalize_value('|'));
        $this->assertSame([0, 0], $filter->normalize_value('abc|xyz'));
    }

    public function test_value_without_pipe_is_from_side(): void {
        $filter = new daterange_filter('period', []);
        [$from, $to] = $filter->normalize_value('2026-01-15');
        $this->assertGreaterThan(0, $from);
        $this->assertSame(0, $to);
    }

    public function test_raw_timestamps_pass_verbatim(): void {
        $filter = new daterange_filter('period', []);
        $this->assertSame([1750000000, 1760000000], $filter->normalize_value('1750000000|1760000000'));
    }

    public function test_inverted_iso_range_is_swapped(): void {
        $filter = new daterange_filter('period', []);
        [$from, $to] = $filter->normalize_value('2026-06-30|2026-01-01');
        $this->assertGreaterThan(0, $from);
        $this->assertGreaterThan($from, $to);
    }

    public function test_produces_between_constraint(): void {
        $filter = new daterange_filter('period', []);
        $constraint = $filter->to_constraint($filter->normalize_value('2026-01-01|2026-06-30'));
        $this->assertSame('period', $constraint->key);
        $this->assertSame(filter_constraint::OP_BETWEEN, $constraint->operator);
        $this->assertIsArray($constraint->value);
        $this->assertCount(2, $constraint->value);
        $this->assertGreaterThan($constraint->value[0], $constraint->value[1]);
    }

    public function test_export_splits_default_into_sides(): void {
        global $PAGE;
        $filter = new daterange_filter('period', ['default' => '2026-01-01|2026-06-30']);
        $context = $filter->export_for_template($PAGE->get_renderer('core'));
        $this->assertSame('daterange', $context['type']);
        $this->assertSame('2026-01-01|2026-06-30', $context['value']);
        $this->assertSame('2026-01-01', $context['valuefrom']);
        $this->assertSame('2026-06-30', $context['valueto']);
    }

    public function test_relative_default_resolves_to_last_n_months(): void {
        global $PAGE;
        $filter = new daterange_filter('period', ['default' => 'last12months']);
        $context = $filter->export_for_template($PAGE->get_renderer('core'));

        $today = new \DateTimeImmutable('now', \core_date::get_user_timezone_object());
        $this->assertSame($today->modify('-12 months')->format('Y-m-d'), $context['valuefrom']);
        $this->assertSame($today->format('Y-m-d'), $context['valueto']);
        $this->assertSame($context['valuefrom'] . '|' . $context['valueto'], $context['value']);

        // The resolved range normalizes like any literal one.
        [$from, $to] = $filter->normalize_value($context['value']);
        $this->assertGreaterThan(0, $from);
        $this->assertGreaterThan($from, $to);
    }

    public function test_relative_default_keyword_variants(): void {
        global $PAGE;

        // Case-insensitive, singular "month" accepted, any month count.
        foreach (['Last6Months', 'last6month', 'LAST6MONTHS'] as $keyword) {
            $filter = new daterange_filter('period', ['default' => $keyword]);
            $context = $filter->export_for_template($PAGE->get_renderer('core'));
            $today = new \DateTimeImmutable('now', \core_date::get_user_timezone_object());
            $this->assertSame($today->modify('-6 months')->format('Y-m-d'), $context['valuefrom'], $keyword);
            $this->assertSame($today->format('Y-m-d'), $context['valueto'], $keyword);
        }

        // Anything else stays verbatim.
        foreach (['lastmonths', 'last12weeks', '2026-01-01|'] as $literal) {
            $filter = new daterange_filter('period', ['default' => $literal]);
            $context = $filter->export_for_template($PAGE->get_renderer('core'));
            $this->assertSame($literal, $context['value'], $literal);
        }
    }

    public function test_factory_round_trip(): void {
        $this->assertTrue(filter_factory::exists('daterange'));
        $this->assertSame('daterange', filter_factory::create('daterange', 'period', [])->get_type());
    }
}
