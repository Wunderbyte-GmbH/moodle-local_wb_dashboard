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

use local_wb_dashboard\local\source\shapable_source;
use local_wb_dashboard\local\source\shaping\rows_shaping;

/**
 * Tests for rows shaping, focusing on the optional top-N sort+slice.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_wb_dashboard\local\source\shaping\rows_shaping
 */
final class rows_shaping_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        // rows_shaping runs category/series labels through format_string().
        $this->resetAfterTest(true);
    }

    /**
     * A source mock returning fixed rows, resolving each field to its own key.
     *
     * @param array $rows
     * @return shapable_source
     */
    private function source_returning(array $rows): shapable_source {
        $source = $this->createMock(shapable_source::class);
        $source->method('load_rows')->willReturn($rows);
        $source->method('resolve_field')->willReturnCallback(
            static fn(int|string $id, string $field, array $firstrow): string => $field
        );
        return $source;
    }

    /**
     * Build one row per category so each category's sum equals its value.
     *
     * @param array $pairs category => value
     * @return array
     */
    private function rows_from(array $pairs): array {
        $rows = [];
        foreach ($pairs as $cat => $value) {
            $rows[] = ['coursename' => (string)$cat, 'completions' => $value];
        }
        return $rows;
    }

    /**
     * top=5 keeps the five highest categories in descending order.
     */
    public function test_top_n_keeps_the_highest_categories_descending(): void {
        $source = $this->source_returning($this->rows_from([
            'A' => 10, 'B' => 50, 'C' => 30, 'D' => 70, 'E' => 20, 'F' => 60, 'G' => 40,
        ]));
        $data = (new rows_shaping())->shape($source, [
            'report' => 1, 'categoryfield' => 'coursename', 'valuefield' => 'completions',
            'top' => 5, 'order' => 'desc',
        ], []);

        $this->assertSame(['D', 'F', 'B', 'G', 'C'], $data->labels);
        $this->assertEquals([70.0, 60.0, 50.0, 40.0, 30.0], $data->series[0]->data);
    }

    /**
     * order=asc keeps the five lowest categories (a bottom-N).
     */
    public function test_order_asc_keeps_the_lowest_categories(): void {
        $source = $this->source_returning($this->rows_from([
            'A' => 10, 'B' => 50, 'C' => 30, 'D' => 70, 'E' => 20, 'F' => 60, 'G' => 40,
        ]));
        $data = (new rows_shaping())->shape($source, [
            'report' => 1, 'categoryfield' => 'coursename', 'valuefield' => 'completions',
            'top' => 5, 'order' => 'asc',
        ], []);

        $this->assertSame(['A', 'E', 'C', 'G', 'B'], $data->labels);
        $this->assertEquals([10.0, 20.0, 30.0, 40.0, 50.0], $data->series[0]->data);
    }

    /**
     * Without top, all categories stay in first-seen order (regression).
     */
    public function test_without_top_all_categories_stay_in_first_seen_order(): void {
        $source = $this->source_returning($this->rows_from([
            'A' => 10, 'B' => 50, 'C' => 30, 'D' => 70,
        ]));
        $data = (new rows_shaping())->shape($source, [
            'report' => 1, 'categoryfield' => 'coursename', 'valuefield' => 'completions',
        ], []);

        $this->assertSame(['A', 'B', 'C', 'D'], $data->labels);
        $this->assertEquals([10.0, 50.0, 30.0, 70.0], $data->series[0]->data);
    }

    /**
     * A top larger than the category count leaves the data untouched.
     */
    public function test_top_larger_than_category_count_is_a_noop(): void {
        $source = $this->source_returning($this->rows_from(['A' => 10, 'B' => 50]));
        $data = (new rows_shaping())->shape($source, [
            'report' => 1, 'categoryfield' => 'coursename', 'valuefield' => 'completions',
            'top' => 5,
        ], []);

        $this->assertSame(['A', 'B'], $data->labels);
    }

    /**
     * Categories that tie on value keep their first-seen order.
     */
    public function test_ties_keep_first_seen_order(): void {
        $source = $this->source_returning($this->rows_from([
            'A' => 30, 'B' => 30, 'C' => 10,
        ]));
        $data = (new rows_shaping())->shape($source, [
            'report' => 1, 'categoryfield' => 'coursename', 'valuefield' => 'completions',
            'top' => 2, 'order' => 'desc',
        ], []);

        // A and B tie on 30; the first-seen (A) must come first.
        $this->assertSame(['A', 'B'], $data->labels);
    }

    /**
     * With aggregation=count, top ranks by each category's row count.
     */
    public function test_count_aggregation_ranks_by_row_count(): void {
        // Three rows for B, two for A, one for C: count ranks B > A > C.
        $rows = [
            ['coursename' => 'A'], ['coursename' => 'B'], ['coursename' => 'C'],
            ['coursename' => 'A'], ['coursename' => 'B'], ['coursename' => 'B'],
        ];
        $data = (new rows_shaping())->shape($this->source_returning($rows), [
            'report' => 1, 'categoryfield' => 'coursename', 'aggregation' => 'count',
            'top' => 2,
        ], []);

        $this->assertSame(['B', 'A'], $data->labels);
        $this->assertEquals([3.0, 2.0], $data->series[0]->data);
    }

    /**
     * A stacked top ranks by total bar height and keeps every surviving stack.
     */
    public function test_stacked_top_ranks_by_total_height_and_keeps_stacks(): void {
        $rows = [
            ['coursename' => 'X', 'status' => 's1', 'completions' => 10],
            ['coursename' => 'X', 'status' => 's2', 'completions' => 5],
            ['coursename' => 'Y', 'status' => 's1', 'completions' => 30],
            ['coursename' => 'Y', 'status' => 's2', 'completions' => 40],
            ['coursename' => 'Z', 'status' => 's1', 'completions' => 20],
            ['coursename' => 'Z', 'status' => 's2', 'completions' => 5],
        ];
        $data = (new rows_shaping())->shape($this->source_returning($rows), [
            'report' => 1, 'categoryfield' => 'coursename', 'valuefield' => 'completions',
            'stackfield' => 'status', 'top' => 2, 'order' => 'desc',
        ], []);

        // Totals: X=15, Y=70, Z=25 -> keep Y, Z. Both stacks survive, aligned.
        $this->assertSame(['Y', 'Z'], $data->labels);
        $this->assertCount(2, $data->series);
        $this->assertSame('s1', $data->series[0]->label);
        $this->assertEquals([30.0, 20.0], $data->series[0]->data);
        $this->assertSame('s2', $data->series[1]->label);
        $this->assertEquals([40.0, 5.0], $data->series[1]->data);
    }
}
