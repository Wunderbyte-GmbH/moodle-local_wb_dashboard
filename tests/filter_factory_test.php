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

use local_wb_dashboard\local\dto\chart_data;
use local_wb_dashboard\local\dto\filter_constraint;
use local_wb_dashboard\local\filter\filter_factory;
use local_wb_dashboard\local\source\pipeline;
use local_wb_dashboard\local\source\source_interface;

/**
 * Tests for the filter Factory: creation, value normalization and neutral
 * constraint production.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_wb_dashboard\local\filter\filter_factory
 * @covers     \local_wb_dashboard\local\filter\select_filter
 * @covers     \local_wb_dashboard\local\filter\date_filter
 * @covers     \local_wb_dashboard\local\filter\daterange_filter
 * @covers     \local_wb_dashboard\local\filter\number_filter
 * @covers     \local_wb_dashboard\local\filter\text_filter
 * @covers     \local_wb_dashboard\local\source\pipeline
 */
final class filter_factory_test extends \advanced_testcase {
    public function test_select_produces_equal_constraint(): void {
        $filter = filter_factory::create('select', 'courseid', ['options' => '1:A,2:B']);
        $this->assertSame('select', $filter->get_type());
        $constraint = $filter->to_constraint($filter->normalize_value('2'));
        $this->assertSame('courseid', $constraint->key);
        $this->assertSame(filter_constraint::OP_EQUAL, $constraint->operator);
        $this->assertSame('2', $constraint->value);
    }

    public function test_text_produces_contains_constraint(): void {
        $filter = filter_factory::create('text', 'name', []);
        $constraint = $filter->to_constraint($filter->normalize_value('  hi '));
        $this->assertSame(filter_constraint::OP_CONTAINS, $constraint->operator);
        $this->assertSame('hi', $constraint->value);
    }

    public function test_number_operator_from_config(): void {
        $filter = filter_factory::create('number', 'score', ['operator' => 'gte']);
        $constraint = $filter->to_constraint($filter->normalize_value('5'));
        $this->assertSame(filter_constraint::OP_GREATER_EQUAL, $constraint->operator);
        $this->assertSame(5.0, $constraint->value);
    }

    public function test_date_normalizes_iso_to_timestamp(): void {
        $filter = filter_factory::create('date', 'period', []);
        $value = $filter->normalize_value('2026-01-15');
        $this->assertIsInt($value);
        $this->assertGreaterThan(0, $value);
        $constraint = $filter->to_constraint($value);
        $this->assertSame(filter_constraint::OP_GREATER_EQUAL, $constraint->operator);
        $this->assertSame($value, $constraint->value);
    }

    public function test_daterange_produces_between_constraint(): void {
        $filter = filter_factory::create('daterange', 'period', []);
        $this->assertSame('daterange', $filter->get_type());
        $constraint = $filter->to_constraint($filter->normalize_value('2026-01-01|2026-06-30'));
        $this->assertSame(filter_constraint::OP_BETWEEN, $constraint->operator);
        $this->assertIsArray($constraint->value);
        $this->assertCount(2, $constraint->value);
    }

    public function test_unknown_type_throws(): void {
        $this->expectException(\moodle_exception::class);
        filter_factory::create('slider', 'x', []);
    }

    /**
     * The server contract a multi-key filter control relies on: the client
     * submits the same daterange value under N keys, and build_constraints
     * turns them into N independent BETWEEN constraints.
     */
    public function test_same_value_under_two_keys_yields_two_constraints(): void {
        $source = new class implements source_interface {
            #[\Override]
            public static function get_name(): string {
                return 'stub';
            }
            #[\Override]
            public function required_params(): array {
                return [];
            }
            #[\Override]
            public function get_supported_filter_keys(array $sourceparams): array {
                return ['usercreated', 'coursecompleted'];
            }
            #[\Override]
            public function require_access(array $sourceparams): void {
            }
            #[\Override]
            public function fetch(array $sourceparams, array $constraints): chart_data {
                return new chart_data();
            }
        };

        $constraints = pipeline::build_constraints($source, [], [
            ['key' => 'usercreated', 'type' => 'daterange', 'value' => '2026-01-01|2026-06-30'],
            ['key' => 'coursecompleted', 'type' => 'daterange', 'value' => '2026-01-01|2026-06-30'],
        ]);

        $this->assertCount(2, $constraints);
        $this->assertSame('usercreated', $constraints[0]->key);
        $this->assertSame('coursecompleted', $constraints[1]->key);
        foreach ($constraints as $constraint) {
            $this->assertSame(filter_constraint::OP_BETWEEN, $constraint->operator);
        }
        // Both carry the identical normalized [from, to] pair.
        $this->assertSame($constraints[0]->value, $constraints[1]->value);
    }
}
