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
     * The new drill-down args: "details" is a display option, "idfield" a
     * source param, "fixedfilters" parses into triples, consumes=none maps to
     * the isolation sentinel — and everything lands in the wsargs.
     */
    public function test_definition_details_fixedfilters_and_consumes_none(): void {
        $definition = toplist_definition::create_definition_from_shortcode_args([
            'source' => 'reportbuilder',
            'report' => '7',
            'categoryfield' => 'coursename',
            'valuefield' => 'completed',
            'idfield' => 'courseid',
            'details' => 'coursedetail',
            'fixedfilters' => 'region:west;courseid:5',
            'consumes' => 'none',
        ]);

        $this->assertSame('coursedetail', $definition->displayopts['details']);
        $this->assertArrayNotHasKey('details', $definition->sourceparams);
        $this->assertSame('courseid', $definition->sourceparams['idfield']);
        $this->assertArrayNotHasKey('fixedfilters', $definition->sourceparams);
        $this->assertSame([
            ['key' => 'region', 'type' => 'select', 'value' => 'west'],
            ['key' => 'courseid', 'type' => 'select', 'value' => '5'],
        ], $definition->fixedfilters);
        $this->assertSame(['__none__'], $definition->consumesfilters);
        $this->assertSame($definition->fixedfilters, $definition->to_wsargs()['fixedfilters']);

        // Malformed pairs are dropped, values may contain colons, first key wins.
        $definition = toplist_definition::create_definition_from_shortcode_args([
            'source' => 'reportbuilder',
            'fixedfilters' => 'nocolon;name:Mathematics 101: Basics; :empty;key:first;key:second',
        ]);
        $this->assertSame([
            ['key' => 'name', 'type' => 'select', 'value' => 'Mathematics 101: Basics'],
            ['key' => 'key', 'type' => 'select', 'value' => 'first'],
        ], $definition->fixedfilters);

        // Two lists differing only in fixedfilters get different DOM ids; a
        // list without them keeps its pre-existing id (identity unchanged).
        $plain = toplist_definition::create_definition_from_shortcode_args([
            'source' => 'reportbuilder', 'report' => '7',
        ]);
        $fixed = toplist_definition::create_definition_from_shortcode_args([
            'source' => 'reportbuilder', 'report' => '7', 'fixedfilters' => 'courseid:5',
        ]);
        $this->assertNotSame($plain->to_domid(), $fixed->to_domid());
    }

    /**
     * The reducer passes each row's raw id through, defaulting to ''.
     */
    public function test_reducer_passes_row_ids(): void {
        $dto = $this->dto(['a', 'b', 'c'], [3, 1, 5]);
        $dto->set_meta('rowids', ['10', '20', '30']);
        $rows = toplist_reducer::reduce($dto, 2, 'desc', toplist_reducer::BAR_RELATIVE);
        $this->assertSame(['c', 'a'], array_column($rows, 'label'));
        $this->assertSame(['30', '10'], array_column($rows, 'id'));

        // Without rowids meta the id is empty, never an error.
        $rows = toplist_reducer::reduce($this->dto(['a'], [1]), 1, 'desc', toplist_reducer::BAR_RELATIVE);
        $this->assertSame([''], array_column($rows, 'id'));
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
        // Without idfield the rowid is empty.
        $this->assertSame('', $result['rows'][0]['rowid']);
    }

    /**
     * With idfield, the web service returns each row's raw id.
     */
    public function test_webservice_returns_rowid_with_idfield(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $alpha1 = $this->getDataGenerator()->create_user(['firstname' => 'Alpha']);
        $alpha2 = $this->getDataGenerator()->create_user(['firstname' => 'Alpha']);
        $this->getDataGenerator()->create_user(['firstname' => 'Beta']);

        /** @var generator $rbgenerator */
        $rbgenerator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgenerator->create_report(['name' => 'Users', 'source' => users::class, 'default' => 0]);
        $rbgenerator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:firstname']);
        $rbgenerator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:username']);

        $result = get_toplist_data::execute('reportbuilder', [
            ['name' => 'report', 'value' => (string)$report->get('id')],
            ['name' => 'categoryfield', 'value' => 'user:firstname'],
            ['name' => 'idfield', 'value' => 'user:username'],
            ['name' => 'aggregation', 'value' => 'count'],
        ], [], 1);

        $this->assertSame('Alpha', $result['rows'][0]['label']);
        // The first-seen Alpha row supplies the id; either Alpha is acceptable
        // (report order is not asserted here), Beta is not.
        $this->assertContains($result['rows'][0]['rowid'], [$alpha1->username, $alpha2->username]);
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

    /**
     * details= renders one hidden detail button per row slot plus the wrapper
     * attributes the modal JS reads; an unknown template name is a clean
     * config error; without details nothing detail-related renders.
     */
    public function test_shortcode_renders_detail_buttons(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('detailtemplates', "=== coursedetail ===\n<h3>{{label}}</h3>", 'local_wb_dashboard');

        $args = [
            'source' => 'reportbuilder',
            'report' => '1',
            'categoryfield' => 'coursename',
            'valuefield' => 'completions',
            'idfield' => 'courseid',
            'top' => '3',
        ];

        $env = (object)['context' => null];
        $html = shortcodes::toplist('toplist', $args + ['details' => 'coursedetail'], null, $env, fn() => '');
        $this->assertSame(3, substr_count($html, 'data-action="wb-dashboard-detail"'));
        $this->assertStringContainsString('data-details="coursedetail"', $html);
        $this->assertStringContainsString('data-contextid="' . \context_system::instance()->id . '"', $html);

        // Unknown template name: config error, like other bad arguments.
        $this->assertSame(
            get_string('error:unknowndetailtemplate', 'local_wb_dashboard', 'nosuchtemplate'),
            shortcodes::toplist('toplist', $args + ['details' => 'nosuchtemplate'], null, $env, fn() => '')
        );

        // Without details: no buttons, no modal wiring.
        $html = shortcodes::toplist('toplist', $args, null, $env, fn() => '');
        $this->assertStringNotContainsString('wb-dashboard-detail', $html);
        $this->assertStringNotContainsString('data-details', $html);
    }

    /**
     * bars=0 renders the rows without the progress bar element; the default
     * keeps it.
     */
    public function test_shortcode_bars_argument(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $args = [
            'source' => 'reportbuilder',
            'report' => '1',
            'categoryfield' => 'coursename',
            'valuefield' => 'completions',
            'top' => '2',
        ];

        $env = (object)['context' => null];
        $html = shortcodes::toplist('toplist', $args, null, $env, fn() => '');
        $this->assertSame(2, substr_count($html, 'data-region="toplist-bar"'));

        $html = shortcodes::toplist('toplist', $args + ['bars' => '0'], null, $env, fn() => '');
        $this->assertStringNotContainsString('data-region="toplist-bar"', $html);
        // Rank, label and value slots still render.
        $this->assertSame(2, substr_count($html, 'data-region="toplist-row"'));
        $this->assertSame(2, substr_count($html, 'data-region="toplist-value"'));

        // The definition itself: default on, bars=0 off, not a source param.
        $definition = toplist_definition::create_definition_from_shortcode_args($args);
        $this->assertTrue($definition->displayopts['bars']);
        $definition = toplist_definition::create_definition_from_shortcode_args($args + ['bars' => '0']);
        $this->assertFalse($definition->displayopts['bars']);
        $this->assertArrayNotHasKey('bars', $definition->sourceparams);
    }
}
