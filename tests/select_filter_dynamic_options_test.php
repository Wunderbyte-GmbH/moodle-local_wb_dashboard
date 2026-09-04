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
use local_wb_dashboard\local\dto\filter_constraint;
use local_wb_dashboard\local\filter\dynamic_options;
use local_wb_dashboard\local\filter\filter_factory;
use local_wb_dashboard\local\source\sources\reportbuilder\reportbuilder_source;

/**
 * Tests for dynamic select-filter options (optionsfield config).
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_wb_dashboard\local\filter\select_filter
 * @covers     \local_wb_dashboard\local\source\sources\reportbuilder\reportbuilder_source
 */
final class select_filter_dynamic_options_test extends \advanced_testcase {
    /**
     * Render the filter and return its options as a value => label map.
     *
     * @param array $config
     * @return array
     */
    private function export_options(array $config): array {
        global $PAGE;
        $filter = filter_factory::create('select', 'testkey', $config);
        $context = $filter->export_for_template($PAGE->get_renderer('core'));

        $options = [];
        foreach ($context['options'] as $option) {
            $options[$option['value']] = $option['label'];
        }
        return $options;
    }

    public function test_options_come_from_the_reports_select_filter(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var generator $rbgenerator */
        $rbgenerator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgenerator->create_report(['name' => 'Users', 'source' => users::class, 'default' => 0]);
        $rbgenerator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:country']);

        $options = $this->export_options([
            'report' => (string)$report->get('id'),
            'optionsfield' => 'country',
            // Static options are ignored when the dynamic lookup succeeds.
            'options' => 'x:Static',
        ]);

        $this->assertArrayHasKey('AT', $options);
        $this->assertArrayHasKey('DE', $options);
        $this->assertArrayNotHasKey('x', $options);
    }

    public function test_options_fall_back_to_distinct_row_values(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->getDataGenerator()->create_user(['firstname' => 'Ann']);
        $this->getDataGenerator()->create_user(['firstname' => 'Bob']);

        /** @var generator $rbgenerator */
        $rbgenerator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgenerator->create_report(['name' => 'Users', 'source' => users::class, 'default' => 0]);
        $rbgenerator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:firstname']);
        // A text filter: no declared options, so row values are scanned.
        $rbgenerator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:firstname']);

        $config = ['report' => (string)$report->get('id'), 'optionsfield' => 'firstname'];
        $options = $this->export_options($config);

        $this->assertArrayHasKey('Ann', $options);
        $this->assertArrayHasKey('Bob', $options);

        // The option list is cached: a user created afterwards does not appear.
        $this->getDataGenerator()->create_user(['firstname' => 'Carl']);
        $options = $this->export_options($config);
        $this->assertArrayNotHasKey('Carl', $options);
    }

    public function test_no_report_access_falls_back_to_static_options(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var generator $rbgenerator */
        $rbgenerator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgenerator->create_report(['name' => 'Users', 'source' => users::class, 'default' => 0]);
        $rbgenerator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:country']);

        $this->setUser($this->getDataGenerator()->create_user());
        $options = $this->export_options([
            'report' => (string)$report->get('id'),
            'optionsfield' => 'country',
            'options' => 'a:Fallback A,b:Fallback B',
        ]);

        $this->assertSame(['a' => 'Fallback A', 'b' => 'Fallback B'], $options);
    }

    public function test_static_options_without_optionsfield_are_unchanged(): void {
        $this->resetAfterTest();

        $options = $this->export_options(['options' => 'one:One,two:Two']);

        $this->assertSame(['one' => 'One', 'two' => 'Two'], $options);
    }

    /**
     * A users report with firstname + lastname columns and a firstname filter;
     * Ann owns two lastnames, Bob one.
     *
     * @return int Report id.
     */
    private function build_names_report(): int {
        $this->getDataGenerator()->create_user(['firstname' => 'Ann', 'lastname' => 'Alpha']);
        $this->getDataGenerator()->create_user(['firstname' => 'Ann', 'lastname' => 'Beta']);
        $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'Gamma']);

        /** @var generator $rbgenerator */
        $rbgenerator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgenerator->create_report(['name' => 'Names', 'source' => users::class, 'default' => 0]);
        $rbgenerator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:firstname']);
        $rbgenerator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:lastname']);
        $rbgenerator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:firstname']);
        return (int)$report->get('id');
    }

    public function test_source_constraints_scope_options_to_matching_rows(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $reportid = $this->build_names_report();
        $source = new reportbuilder_source();
        $values = static fn(array $options): array => array_map(static fn(array $o): string => $o['value'], $options);

        // Unscoped: every lastname (site users such as admin included).
        $all = $source->get_filter_options(['report' => (string)$reportid], 'lastname');
        $this->assertSame([], array_diff(['Alpha', 'Beta', 'Gamma'], $values($all)));

        $ann = $source->get_filter_options(['report' => (string)$reportid], 'lastname', [
            new filter_constraint('firstname', filter_constraint::OP_EQUAL, 'Ann'),
        ]);
        $this->assertEqualsCanonicalizing(['Alpha', 'Beta'], $values($ann));
    }

    public function test_source_constraints_narrow_declared_select_options(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->create_user(['firstname' => 'Ann', 'country' => 'AT']);
        $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'country' => 'DE']);

        /** @var generator $rbgenerator */
        $rbgenerator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgenerator->create_report(['name' => 'Users', 'source' => users::class, 'default' => 0]);
        $rbgenerator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:country']);
        $rbgenerator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:country']);
        $rbgenerator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:firstname']);

        $options = (new reportbuilder_source())->get_filter_options(['report' => (string)$report->get('id')], 'country', [
            new filter_constraint('firstname', filter_constraint::OP_EQUAL, 'Ann'),
        ]);

        // Only Ann's country survives, still keyed by the declared option code
        // (the cell shows the country name) so it remains a valid filter value.
        $this->assertCount(1, $options);
        $this->assertSame('AT', $options[0]['value']);
        $this->assertSame(get_string('AT', 'countries'), $options[0]['label']);
    }

    public function test_constrained_and_unconstrained_options_are_cached_separately(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $reportid = $this->build_names_report();
        $values = static fn(array $options): array => array_map(static fn(array $o): string => $o['value'], $options);

        $resolved = dynamic_options::resolve_source(['report' => (string)$reportid]);
        $this->assertNotNull($resolved);
        [$name, $source, $params] = [$resolved['name'], $resolved['source'], $resolved['params']];
        $ann = [new filter_constraint('firstname', filter_constraint::OP_EQUAL, 'Ann')];

        $all = dynamic_options::options($name, $source, $params, 'lastname');
        $annonly = dynamic_options::options($name, $source, $params, 'lastname', $ann);
        $allagain = dynamic_options::options($name, $source, $params, 'lastname');
        $annagain = dynamic_options::options($name, $source, $params, 'lastname', $ann);

        $this->assertSame([], array_diff(['Alpha', 'Beta', 'Gamma'], $values($all)));
        $this->assertEqualsCanonicalizing(['Alpha', 'Beta'], $values($annonly));
        $this->assertSame($all, $allagain);
        $this->assertSame($annonly, $annagain);
    }

    public function test_cascading_select_exports_wsargs_and_parent_key(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $PAGE;
        $reportid = $this->build_names_report();

        $filter = filter_factory::create('select', 'lastname', [
            'report' => (string)$reportid,
            'optionsfield' => 'lastname',
            'cascadefrom' => 'firstname',
        ]);
        $context = $filter->export_for_template($PAGE->get_renderer('core'));

        $this->assertSame('firstname', $context['cascadefrom']);
        $wsargs = json_decode($context['optionsargs'], true);
        $this->assertSame('reportbuilder', $wsargs['source']);
        $this->assertSame('lastname', $wsargs['field']);
        $this->assertSame('', $wsargs['groupfield']);
        $this->assertSame([['name' => 'report', 'value' => (string)$reportid]], $wsargs['sourceparams']);

        // A static-only select has nothing to re-fetch, and cannot cascade from itself.
        $static = filter_factory::create('select', 'x', ['options' => 'a:A', 'cascadefrom' => 'x']);
        $context = $static->export_for_template($PAGE->get_renderer('core'));
        $this->assertSame('', $context['optionsargs']);
        $this->assertSame('', $context['cascadefrom']);
    }
}
