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
use local_wb_dashboard\external\get_filter_options;

/**
 * Tests for the filter options web service (server half of a cascading select).
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_wb_dashboard\external\get_filter_options
 * @covers     \local_wb_dashboard\local\filter\dynamic_options
 */
final class external_get_filter_options_test extends \advanced_testcase {
    /**
     * A users report with firstname + lastname columns and a firstname filter,
     * viewable by everyone, plus three users: Ann owns two lastnames, Bob one.
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
        $rbgenerator->create_audience(['reportid' => $report->get('id'), 'configdata' => []]);
        return (int)$report->get('id');
    }

    /**
     * Option values of a flat WS result.
     *
     * @param array $result
     * @return string[]
     */
    private function values(array $result): array {
        return array_map(static fn(array $o): string => $o['value'], $result['options']);
    }

    public function test_options_are_scoped_by_filter_values(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $reportid = $this->build_names_report();
        $pairs = [['name' => 'report', 'value' => (string)$reportid]];

        // Unscoped: every lastname (site users such as admin included).
        $all = get_filter_options::execute('reportbuilder', $pairs, 'lastname');
        $this->assertSame([], array_diff(['Alpha', 'Beta', 'Gamma'], $this->values($all)));
        $this->assertSame([], $all['groups']);

        $ann = get_filter_options::execute('reportbuilder', $pairs, 'lastname', '', [
            ['key' => 'firstname', 'type' => 'select', 'value' => 'Ann'],
        ]);
        $this->assertEqualsCanonicalizing(['Alpha', 'Beta'], $this->values($ann));

        $bob = get_filter_options::execute('reportbuilder', $pairs, 'lastname', '', [
            ['key' => 'firstname', 'type' => 'select', 'value' => 'Bob'],
        ]);
        $this->assertSame(['Gamma'], $this->values($bob));
    }

    public function test_grouped_options_are_scoped_by_filter_values(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $reportid = $this->build_names_report();
        $pairs = [['name' => 'report', 'value' => (string)$reportid]];

        $result = get_filter_options::execute('reportbuilder', $pairs, 'lastname', 'firstname', [
            ['key' => 'firstname', 'type' => 'select', 'value' => 'Ann'],
        ]);

        $this->assertSame([], $result['options']);
        $this->assertCount(1, $result['groups']);
        $this->assertSame('Ann', $result['groups'][0]['label']);
        $this->assertEqualsCanonicalizing(
            ['Alpha', 'Beta'],
            array_map(static fn(array $o): string => $o['value'], $result['groups'][0]['options'])
        );
    }

    public function test_locked_key_beats_client_value(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text', 'shortname' => 'fname', 'name' => 'First name lock',
        ]);
        set_config('lockedfilters', 'firstname=fname', 'local_wb_dashboard');
        $reportid = $this->build_names_report();

        // A viewer locked to firstname "Ann" asks for Bob's lastnames.
        $viewer = $this->getDataGenerator()->create_user(['profile_field_fname' => 'Ann']);
        $this->setUser($viewer);
        $result = get_filter_options::execute(
            'reportbuilder',
            [['name' => 'report', 'value' => (string)$reportid]],
            'lastname',
            '',
            [['key' => 'firstname', 'type' => 'select', 'value' => 'Bob']]
        );

        $this->assertEqualsCanonicalizing(['Alpha', 'Beta'], $this->values($result));
    }

    public function test_user_without_report_access_throws(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var generator $rbgenerator */
        $rbgenerator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        // No audience: only the creator may view it.
        $report = $rbgenerator->create_report(['name' => 'Private', 'source' => users::class, 'default' => 0]);
        $rbgenerator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:lastname']);

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\moodle_exception::class);
        get_filter_options::execute('reportbuilder', [['name' => 'report', 'value' => (string)$report->get('id')]], 'lastname');
    }

    public function test_unknown_source_throws(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\moodle_exception::class);
        get_filter_options::execute('nosuchsource', [], 'lastname');
    }
}
