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

    /**
     * Multiple value fields plot one (unstacked) series per field.
     */
    public function test_valuefields_plots_one_series_per_field(): void {
        $rows = [
            ['month' => 'Jan', 'sent' => 10, 'delivered' => 8],
            ['month' => 'Jan', 'sent' => 5, 'delivered' => 5],
            ['month' => 'Feb', 'sent' => 20, 'delivered' => 15],
        ];
        $data = (new rows_shaping())->shape($this->source_returning($rows), [
            'report' => 1, 'categoryfield' => 'month', 'valuefields' => 'sent,delivered',
        ], []);

        $this->assertSame(['Jan', 'Feb'], $data->labels);
        $this->assertCount(2, $data->series);
        $this->assertSame('sent', $data->series[0]->label);
        $this->assertEquals([15.0, 20.0], $data->series[0]->data);
        $this->assertSame('delivered', $data->series[1]->label);
        $this->assertEquals([13.0, 15.0], $data->series[1]->data);
        // Grouped, not stacked.
        $this->assertNull($data->series[0]->stack);
        $this->assertArrayNotHasKey('stacked', $data->meta);
    }

    /**
     * remainderof stacks the listed fields plus a computed remainder, so the
     * bar total equals the remainder field.
     */
    public function test_remainderof_stacks_a_part_of_whole_bar(): void {
        $rows = [
            ['month' => 'Jan', 'sent' => 10, 'delivered' => 8],
            ['month' => 'Jan', 'sent' => 5, 'delivered' => 5],
            ['month' => 'Feb', 'sent' => 20, 'delivered' => 15],
        ];
        $data = (new rows_shaping())->shape($this->source_returning($rows), [
            'report' => 1, 'categoryfield' => 'month', 'valuefields' => 'delivered', 'remainderof' => 'sent',
        ], []);

        $this->assertCount(2, $data->series);
        $this->assertSame('delivered', $data->series[0]->label);
        $this->assertEquals([13.0, 15.0], $data->series[0]->data);
        // Remainder = sent - delivered per category.
        $this->assertEquals([2.0, 5.0], $data->series[1]->data);
        // Stacked so the total bar height equals "sent".
        $this->assertSame('group', $data->series[0]->stack);
        $this->assertSame('group', $data->series[1]->stack);
        $this->assertTrue($data->meta['stacked']);
    }

    /**
     * The remainder segment is clamped at zero and its label is overridable.
     */
    public function test_remainder_is_clamped_and_label_overridable(): void {
        $rows = [
            // Listed field exceeds the whole: remainder clamps to 0.
            ['month' => 'Jan', 'sent' => 5, 'delivered' => 8],
        ];
        $data = (new rows_shaping())->shape($this->source_returning($rows), [
            'report' => 1, 'categoryfield' => 'month', 'valuefields' => 'delivered', 'remainderof' => 'sent',
            'remainderlabel' => 'Not delivered',
        ], []);

        $this->assertEquals([0.0], $data->series[1]->data);
        $this->assertSame('Not delivered', $data->series[1]->label);
    }

    /**
     * A remainderof top-N ranks by the whole (the remainder field's total).
     */
    public function test_remainderof_top_ranks_by_the_whole(): void {
        $rows = [
            ['month' => 'Jan', 'sent' => 10, 'delivered' => 9],
            ['month' => 'Feb', 'sent' => 30, 'delivered' => 12],
            ['month' => 'Mar', 'sent' => 20, 'delivered' => 19],
        ];
        $data = (new rows_shaping())->shape($this->source_returning($rows), [
            'report' => 1, 'categoryfield' => 'month', 'valuefields' => 'delivered', 'remainderof' => 'sent',
            'top' => 2, 'order' => 'desc',
        ], []);

        // Stacked heights are the sent totals: Feb=30, Mar=20, Jan=10.
        $this->assertSame(['Feb', 'Mar'], $data->labels);
        $this->assertEquals([12.0, 19.0], $data->series[0]->data);
        $this->assertEquals([18.0, 1.0], $data->series[1]->data);
    }

    /**
     * A one-entry valuefields without remainder behaves like valuefield.
     */
    public function test_single_valuefields_entry_equals_valuefield(): void {
        $source = $this->source_returning($this->rows_from(['A' => 10, 'B' => 50]));
        $data = (new rows_shaping())->shape($source, [
            'report' => 1, 'categoryfield' => 'coursename', 'valuefields' => 'completions',
        ], []);

        $this->assertCount(1, $data->series);
        $this->assertEquals([10.0, 50.0], $data->series[0]->data);
        $this->assertNull($data->series[0]->stack);
    }

    /**
     * normalize=percent scales each remainder stack to 100 and pins the axis.
     */
    public function test_normalize_percent_scales_remainder_stacks(): void {
        $rows = [
            ['month' => 'Jan', 'sent' => 20, 'delivered' => 15],
            ['month' => 'Feb', 'sent' => 200, 'delivered' => 150],
        ];
        $data = (new rows_shaping())->shape($this->source_returning($rows), [
            'report' => 1, 'categoryfield' => 'month', 'valuefields' => 'delivered', 'remainderof' => 'sent',
            'normalize' => 'percent',
        ], []);

        // Both months: 75% delivered / 25% remainder, despite 10x volumes.
        $this->assertEquals([75.0, 75.0], $data->series[0]->data);
        $this->assertEquals([25.0, 25.0], $data->series[1]->data);
        $this->assertSame(100, $data->meta['axismax']);
        $this->assertTrue($data->meta['stacked']);
    }

    /**
     * normalize=percent also applies to stackfield stacks, skips zero-total
     * categories, and is ignored for non-stacked data.
     */
    public function test_normalize_percent_stackfield_zero_and_unstacked(): void {
        // Stackfield stack: Jan 10/30 -> 25/75; Feb all zero stays zero.
        $rows = [
            ['month' => 'Jan', 'status' => 'a', 'v' => 10],
            ['month' => 'Jan', 'status' => 'b', 'v' => 30],
            ['month' => 'Feb', 'status' => 'a', 'v' => 0],
        ];
        $data = (new rows_shaping())->shape($this->source_returning($rows), [
            'report' => 1, 'categoryfield' => 'month', 'valuefield' => 'v', 'stackfield' => 'status',
            'normalize' => 'percent',
        ], []);
        $this->assertEquals([25.0, 0.0], $data->series[0]->data);
        $this->assertEquals([75.0, 0.0], $data->series[1]->data);
        $this->assertSame(100, $data->meta['axismax']);

        // A plain single series is not normalized.
        $source = $this->source_returning($this->rows_from(['A' => 10, 'B' => 30]));
        $data = (new rows_shaping())->shape($source, [
            'report' => 1, 'categoryfield' => 'coursename', 'valuefield' => 'completions',
            'normalize' => 'percent',
        ], []);
        $this->assertEquals([10.0, 30.0], $data->series[0]->data);
        $this->assertArrayNotHasKey('axismax', $data->meta);
    }

    /**
     * idfield carries one raw id per category, aligned with the labels: the
     * first-seen row supplies the id, markup in the cell is stripped.
     */
    public function test_idfield_carries_rowids_aligned_with_labels(): void {
        $rows = [
            ['coursename' => 'A', 'courseid' => '<a href="#">11</a>', 'completions' => 10],
            ['coursename' => 'B', 'courseid' => '22', 'completions' => 50],
            // Duplicate category: the first-seen id must win.
            ['coursename' => 'A', 'courseid' => '99', 'completions' => 5],
        ];
        $data = (new rows_shaping())->shape($this->source_returning($rows), [
            'report' => 1, 'categoryfield' => 'coursename', 'valuefield' => 'completions',
            'idfield' => 'courseid',
        ], []);

        $this->assertSame(['A', 'B'], $data->labels);
        $this->assertSame(['11', '22'], $data->meta['rowids']);
    }

    /**
     * A top-N remaps the rowids with the same slice as labels and series.
     */
    public function test_idfield_topn_remaps_rowids_with_labels(): void {
        $rows = [
            ['coursename' => 'A', 'courseid' => '1', 'completions' => 10],
            ['coursename' => 'B', 'courseid' => '2', 'completions' => 50],
            ['coursename' => 'C', 'courseid' => '3', 'completions' => 30],
        ];
        $data = (new rows_shaping())->shape($this->source_returning($rows), [
            'report' => 1, 'categoryfield' => 'coursename', 'valuefield' => 'completions',
            'idfield' => 'courseid', 'top' => 2, 'order' => 'desc',
        ], []);

        $this->assertSame(['B', 'C'], $data->labels);
        $this->assertSame(['2', '3'], $data->meta['rowids']);
    }

    /**
     * Without idfield no rowids meta is set (regression).
     */
    public function test_without_idfield_no_rowids_meta(): void {
        $source = $this->source_returning($this->rows_from(['A' => 10, 'B' => 50]));
        $data = (new rows_shaping())->shape($source, [
            'report' => 1, 'categoryfield' => 'coursename', 'valuefield' => 'completions',
        ], []);

        $this->assertArrayNotHasKey('rowids', $data->meta);
    }

    /**
     * Invalid combinations (stackfield, count, or no value field) throw.
     */
    public function test_invalid_multifield_combinations_throw(): void {
        $rows = [['month' => 'Jan', 'sent' => 5, 'delivered' => 3, 'tag' => 't']];
        $invalid = [
            ['valuefields' => 'sent,delivered', 'stackfield' => 'tag'],
            ['valuefields' => 'delivered', 'remainderof' => 'sent', 'stackfield' => 'tag'],
            ['valuefields' => 'sent,delivered', 'aggregation' => 'count'],
            ['remainderof' => 'sent', 'aggregation' => 'count'],
            ['remainderof' => 'sent'],
        ];
        foreach ($invalid as $extra) {
            try {
                (new rows_shaping())->shape($this->source_returning($rows),
                    $extra + ['report' => 1, 'categoryfield' => 'month'], []);
                $this->fail('Expected moodle_exception for: ' . json_encode($extra));
            } catch (\moodle_exception $e) {
                $this->assertStringContainsString('invalidfieldcombination', $e->errorcode);
            }
        }
    }
}
