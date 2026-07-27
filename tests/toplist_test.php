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

use core_reportbuilder\generator;
use core_user\reportbuilder\datasource\users;
use local_wb_dashboard\external\get_toplist_data;
use local_wb_dashboard\local\definition\toplist_definition;
use local_wb_dashboard\local\dto\chart_data;
use local_wb_dashboard\local\dto\chart_series;
use local_wb_dashboard\local\toplist\toplist_reducer;

/**
 * Tests for the [toplist] shortcode, its reducer and web service.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_wb_dashboard\shortcodes
 * @covers     \local_wb_dashboard\external\get_toplist_data
 * @covers     \local_wb_dashboard\local\definition\toplist_definition
 * @covers     \local_wb_dashboard\local\toplist\toplist_reducer
 */
final class toplist_test extends \advanced_testcase {
    /**
     * Build a DTO with one or two aligned series.
     *
     * @param string[] $labels
     * @param float[] $values
     * @param float[] $bars
     * @return chart_data
     */
    private function dto(array $labels, array $values, array $bars = []): chart_data {
        $dto = (new chart_data())->set_labels($labels);
        $dto->add_series(new chart_series('value', $values));
        if (!empty($bars)) {
            $dto->add_series(new chart_series('bar', $bars));
        }
        return $dto;
    }

    /**
     * Desc keeps the top N with relative bars; asc keeps the bottom N.
     */
    public function test_reducer_ranking_and_relative_bars(): void {
        $dto = $this->dto(['a', 'b', 'c', 'd'], [3, 1, 5, 2]);

        $rows = toplist_reducer::reduce($dto, 2, 'desc', toplist_reducer::BAR_RELATIVE);
        $this->assertSame(['c', 'a'], array_column($rows, 'label'));
        $this->assertSame([5.0, 3.0], array_column($rows, 'value'));
        $this->assertSame([100.0, 60.0], array_column($rows, 'percent'));

        $rows = toplist_reducer::reduce($dto, 2, 'asc', toplist_reducer::BAR_RELATIVE);
        $this->assertSame(['b', 'd'], array_column($rows, 'label'));
        // Relative bars scale to the largest KEPT value, also in asc order.
        $this->assertSame([50.0, 100.0], array_column($rows, 'percent'));
    }

    /**
     * Ties keep first-seen order, mirroring the shaping's apply_topn.
     */
    public function test_reducer_ties_keep_first_seen_order(): void {
        $dto = $this->dto(['a', 'b', 'c'], [2, 2, 1]);
        $rows = toplist_reducer::reduce($dto, 2, 'desc', toplist_reducer::BAR_RELATIVE);
        $this->assertSame(['a', 'b'], array_column($rows, 'label'));
    }

    /**
     * The three explicit bar modes, including zero divisors and clamping.
     */
    public function test_reducer_bar_modes(): void {
        // Percentfield: the second series IS the percent, clamped to 0-100.
        $dto = $this->dto(['a', 'b', 'c'], [30, 20, 10], [110, 72.5, -4]);
        $rows = toplist_reducer::reduce($dto, 3, 'desc', toplist_reducer::BAR_PERCENTFIELD);
        $this->assertSame([100.0, 72.5, 0.0], array_column($rows, 'percent'));

        // Totalfield: value / total; a zero total gives 0, never an error.
        $dto = $this->dto(['a', 'b'], [80, 5], [200, 0]);
        $rows = toplist_reducer::reduce($dto, 2, 'desc', toplist_reducer::BAR_TOTALFIELD);
        $this->assertSame([40.0, 0.0], array_column($rows, 'percent'));

        // Fixed max: value / max (4.3 of 5 = 86%).
        $dto = $this->dto(['a'], [4.3]);
        $rows = toplist_reducer::reduce($dto, 1, 'desc', toplist_reducer::BAR_MAX, 5.0);
        $this->assertSame([86.0], array_column($rows, 'percent'));
    }

    /**
     * The definition folds a bar field into "valuefields" and picks the mode.
     */
    public function test_definition_bar_field_folding(): void {
        $definition = toplist_definition::create_definition_from_shortcode_args([
            'source' => 'reportbuilder',
            'report' => '7',
            'categoryfield' => 'coursename',
            'valuefield' => 'completed',
            'barfield' => 'completedpercent',
            'top' => '99',
        ]);
        $this->assertSame('percentfield', $definition->displayopts['barmode']);
        $this->assertSame(toplist_definition::MAX_TOP, $definition->displayopts['top']);
        $this->assertSame('completed,completedpercent', $definition->sourceparams['valuefields']);
        $this->assertArrayNotHasKey('valuefield', $definition->sourceparams);
        $this->assertArrayNotHasKey('barfield', $definition->sourceparams);
        $this->assertArrayNotHasKey('top', $definition->sourceparams);

        $definition = toplist_definition::create_definition_from_shortcode_args([
            'source' => 'reportbuilder',
            'valuefield' => 'delivered',
            'bartotalfield' => 'sent',
        ]);
        $this->assertSame('totalfield', $definition->displayopts['barmode']);
        $this->assertSame('delivered,sent', $definition->sourceparams['valuefields']);

        $definition = toplist_definition::create_definition_from_shortcode_args([
            'source' => 'reportbuilder',
            'valuefield' => 'score',
            'max' => '5',
        ]);
        $this->assertSame('max', $definition->displayopts['barmode']);
        $this->assertSame('score', $definition->sourceparams['valuefield']);
    }

    /**
     * The web service ranks real report rows and formats the values.
     */
    public function test_webservice_returns_ranked_rows(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->getDataGenerator()->create_user(['firstname' => 'Alpha']);
        $this->getDataGenerator()->create_user(['firstname' => 'Alpha']);
        $this->getDataGenerator()->create_user(['firstname' => 'Beta']);

        /** @var generator $rbgenerator */
        $rbgenerator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgenerator->create_report(['name' => 'Users', 'source' => users::class, 'default' => 0]);
        $rbgenerator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:firstname']);

        $result = get_toplist_data::execute('reportbuilder', [
            ['name' => 'report', 'value' => (string)$report->get('id')],
            ['name' => 'categoryfield', 'value' => 'user:firstname'],
            ['name' => 'aggregation', 'value' => 'count'],
        ], [], 1);

        $this->assertCount(1, $result['rows']);
        $this->assertSame(1, $result['rows'][0]['rank']);
        $this->assertSame('Alpha', $result['rows'][0]['label']);
        $this->assertSame('2', $result['rows'][0]['formatted']);
        $this->assertSame(100.0, $result['rows'][0]['percent']);
    }

    /**
     * A wrong report id (nonexistent or not a custom report) surfaces as the
     * plugin's own config error, not a deep core exception.
     */
    public function test_webservice_invalid_report_id_is_a_clean_error(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:invalidreportid', 'local_wb_dashboard'));
        get_toplist_data::execute('reportbuilder', [
            ['name' => 'report', 'value' => '999999'],
            ['name' => 'categoryfield', 'value' => 'user:firstname'],
            ['name' => 'aggregation', 'value' => 'count'],
        ]);
    }

    /**
     * The shortcode renders the wrapper, title and N hidden row slots.
     */
    public function test_shortcode_renders_row_slots(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = shortcodes::toplist('toplist', [
            'source' => 'reportbuilder',
            'report' => '1',
            'categoryfield' => 'user:firstname',
            'aggregation' => 'count',
            'top' => '3',
            'title' => 'Top things',
            'pageid' => 'demo',
        ], null, (object)['context' => null], fn() => '');

        $this->assertStringContainsString('local-dashboard-toplist-', $html);
        $this->assertStringContainsString('Top things', $html);
        $this->assertStringContainsString('data-pageid="demo"', $html);
        $this->assertSame(3, substr_count($html, 'data-region="toplist-row"'));
        $this->assertStringContainsString('data-wsargs', $html);

        // Config errors surface as messages.
        $this->assertSame(
            get_string('error:missingsource', 'local_wb_dashboard'),
            shortcodes::toplist('toplist', [], null, (object)[], fn() => '')
        );
        $this->assertSame(
            get_string('error:unknownsource', 'local_wb_dashboard', 'nope'),
            shortcodes::toplist('toplist', ['source' => 'nope'], null, (object)[], fn() => '')
        );
    }
}
