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
use local_wb_dashboard\local\filter\filter_factory;
use local_wb_dashboard\local\source\sources\reportbuilder\reportbuilder_source;

/**
 * Tests for the grouped select filter and the source's grouped options.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_wb_dashboard\local\filter\grouped_select_filter
 * @covers     \local_wb_dashboard\local\source\sources\reportbuilder\reportbuilder_source
 */
final class grouped_select_filter_test extends \advanced_testcase {
    /**
     * A users report with firstname (group) and lastname (value) columns, plus
     * three users so firstname "Ann" owns two lastnames and "Bob" owns one.
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
        return (int)$report->get('id');
    }

    /**
     * Reduce a grouped structure to group label => [option values].
     *
     * @param array $groups
     * @return array<string, string[]>
     */
    private function as_map(array $groups): array {
        $map = [];
        foreach ($groups as $group) {
            // Source options use 'group'; the rendered filter context uses 'label'.
            $key = $group['label'] ?? $group['group'];
            $map[$key] = array_map(static fn(array $o): string => $o['value'], $group['options']);
        }
        return $map;
    }

    /**
     * Render a groupedselect filter and return its groups context.
     *
     * @param array $config
     * @return array
     */
    private function export_groups(array $config): array {
        global $PAGE;
        $filter = filter_factory::create('groupedselect', 'asl', $config);
        return $filter->export_for_template($PAGE->get_renderer('core'))['groups'];
    }

    public function test_source_groups_values_by_the_group_field(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $reportid = $this->build_names_report();

        $source = new reportbuilder_source();
        $map = $this->as_map($source->get_grouped_filter_options(['report' => (string)$reportid], 'firstname', 'lastname'));

        $this->assertArrayHasKey('Ann', $map);
        $this->assertArrayHasKey('Bob', $map);
        $this->assertEqualsCanonicalizing(['Alpha', 'Beta'], $map['Ann']);
        $this->assertSame(['Gamma'], $map['Bob']);
    }

    public function test_source_scopevalue_returns_only_the_matching_group(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $reportid = $this->build_names_report();

        $source = new reportbuilder_source();
        $groups = $source->get_grouped_filter_options(['report' => (string)$reportid], 'firstname', 'lastname', 'Ann');
        $map = $this->as_map($groups);

        $this->assertSame(['Ann'], array_keys($map));
        $this->assertEqualsCanonicalizing(['Alpha', 'Beta'], $map['Ann']);
    }

    public function test_static_groups_are_parsed_into_optgroups(): void {
        $this->resetAfterTest();

        $groups = $this->export_groups(['groups' => 'North=1:ASL 1;2:ASL 2||South=3:ASL 3']);
        $map = $this->as_map($groups);

        $this->assertSame(['North', 'South'], array_keys($map));
        $this->assertSame(['1', '2'], $map['North']);
        $this->assertSame(['3'], $map['South']);
        // Two groups: both keep their optgroup labels.
        $this->assertSame('North', $groups[0]['label']);
    }

    public function test_dynamic_groups_come_from_the_source(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $reportid = $this->build_names_report();

        $map = $this->as_map($this->export_groups([
            'report' => (string)$reportid,
            'optionsfield' => 'lastname',
            'groupfield' => 'firstname',
        ]));

        $this->assertArrayHasKey('Ann', $map);
        $this->assertEqualsCanonicalizing(['Alpha', 'Beta'], $map['Ann']);
    }

    public function test_dependson_locked_key_scopes_to_one_flat_group(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text', 'shortname' => 'region', 'name' => 'Region',
        ]);
        set_config('lockedfilters', 'region=region', 'local_wb_dashboard');

        // A user locked to region "South" (no ignorelockedfilters capability).
        $user = $this->getDataGenerator()->create_user(['profile_field_region' => 'South']);
        $this->setUser($user);

        $groups = $this->export_groups([
            'groups' => 'North=1:ASL 1;2:ASL 2||South=3:ASL 3',
            'dependson' => 'region',
        ]);

        // Only the South group survives, and a single group renders flat (no label).
        $this->assertCount(1, $groups);
        $this->assertSame(['3'], $this->as_map($groups)['']);
        $this->assertSame('', $groups[0]['label']);
    }

    public function test_admin_sees_all_groups_despite_dependson(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text', 'shortname' => 'region', 'name' => 'Region',
        ]);
        set_config('lockedfilters', 'region=region', 'local_wb_dashboard');
        $this->setAdminUser(); // Admin holds ignorelockedfilters: region is not locked.

        $groups = $this->export_groups([
            'groups' => 'North=1:ASL 1;2:ASL 2||South=3:ASL 3',
            'dependson' => 'region',
        ]);

        $this->assertSame(['North', 'South'], array_keys($this->as_map($groups)));
    }

    public function test_locked_dependency_scopes_dynamic_groups_through_the_report_filter(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text', 'shortname' => 'fname', 'name' => 'First name lock',
        ]);
        set_config('lockedfilters', 'firstname=fname', 'local_wb_dashboard');
        $reportid = $this->build_names_report();
        /** @var generator $rbgenerator */
        $rbgenerator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $rbgenerator->create_filter(['reportid' => $reportid, 'uniqueidentifier' => 'user:firstname']);
        $rbgenerator->create_audience(['reportid' => $reportid, 'configdata' => []]);

        // A viewer locked to firstname "Ann" (no ignorelockedfilters capability).
        $this->setUser($this->getDataGenerator()->create_user(['profile_field_fname' => 'Ann']));

        $groups = $this->export_groups([
            'report' => (string)$reportid,
            'optionsfield' => 'lastname',
            'groupfield' => 'firstname',
            'cascadefrom' => 'firstname',
        ]);

        // Only Ann's group survives (flat), scoped via the report's own filter.
        $this->assertCount(1, $groups);
        $this->assertEqualsCanonicalizing(['Alpha', 'Beta'], $this->as_map($groups)['']);
    }
}
