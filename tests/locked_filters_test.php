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
use local_wb_dashboard\external\get_chart_data;
use local_wb_dashboard\local\filter\locked_filters;

/**
 * Tests for locked filters: mapping resolution and server-side enforcement.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_wb_dashboard\local\filter\locked_filters
 * @covers     \local_wb_dashboard\local\source\pipeline
 */
final class locked_filters_test extends \advanced_testcase {
    /**
     * Create the "region" profile field the mappings point at.
     */
    private function create_region_field(): void {
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'region',
            'name' => 'Region',
        ]);
    }

    /**
     * Turn an associative array into the WS name/value pair list.
     *
     * @param array $params
     * @return array
     */
    private function pairs(array $params): array {
        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs[] = ['name' => $name, 'value' => (string)$value];
        }
        return $pairs;
    }

    /**
     * Create a users report (fullname column, firstname filter) every user may view.
     *
     * @return int Report id.
     */
    private function create_users_report(): int {
        /** @var generator $rbgenerator */
        $rbgenerator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgenerator->create_report(['name' => 'Locked', 'source' => users::class, 'default' => 0]);
        $rbgenerator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:fullname']);
        $rbgenerator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:firstname']);
        $rbgenerator->create_audience(['reportid' => $report->get('id'), 'configdata' => []]);
        return (int)$report->get('id');
    }

    public function test_mappings_parses_setting_lines(): void {
        $this->resetAfterTest();

        set_config('lockedfilters', "region=region\n\njunkline\n dept = department \n?!=x\nnokey=", 'local_wb_dashboard');

        $this->assertSame([
            'region' => ['field' => 'region', 'roles' => []],
            'dept' => ['field' => 'department', 'roles' => []],
        ], locked_filters::mappings());
    }

    public function test_mappings_parses_role_scope_suffix(): void {
        $this->resetAfterTest();

        set_config(
            'lockedfilters',
            "region=region|regionalmanager\nasl=asl | aslmanager , supervisor \ndept=department|",
            'local_wb_dashboard'
        );

        $this->assertSame([
            'region' => ['field' => 'region', 'roles' => ['regionalmanager']],
            'asl' => ['field' => 'asl', 'roles' => ['aslmanager', 'supervisor']],
            // A trailing pipe with no roles behaves like no suffix.
            'dept' => ['field' => 'department', 'roles' => []],
        ], locked_filters::mappings());
    }

    public function test_role_scoped_lock_applies_only_to_assigned_roles(): void {
        global $DB;
        $this->resetAfterTest();
        $this->create_region_field();
        // Region is locked only for the "regionalmanager" role.
        set_config('lockedfilters', 'region=region|regionalmanager', 'local_wb_dashboard');

        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'regionalmanager', 'archetype' => '']);

        // A regional manager gets region locked to their own profile value.
        $manager = $this->getDataGenerator()->create_user(['profile_field_region' => 'south']);
        role_assign($roleid, $manager->id, \context_system::instance());
        $this->assertSame(['region' => 'south'], locked_filters::for_user((int)$manager->id));

        // A user without the role is not locked at all.
        $other = $this->getDataGenerator()->create_user(['profile_field_region' => 'north']);
        $this->assertSame([], locked_filters::for_user((int)$other->id));

        // A role name that does not exist locks nobody (fail: applies to no one).
        set_config('lockedfilters', 'region=region|ghostrole', 'local_wb_dashboard');
        $this->assertSame([], locked_filters::for_user((int)$manager->id));
    }

    public function test_mixed_role_scopes_lock_independent_keys_per_role(): void {
        $this->resetAfterTest();
        $this->create_region_field();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text', 'shortname' => 'asl', 'name' => 'ASL',
        ]);
        set_config(
            'lockedfilters',
            "region=region|regionalmanager\nasl=asl|aslmanager",
            'local_wb_dashboard'
        );
        $regionrole = $this->getDataGenerator()->create_role(['shortname' => 'regionalmanager', 'archetype' => '']);
        $this->getDataGenerator()->create_role(['shortname' => 'aslmanager', 'archetype' => '']);

        // Regional manager: region locked, ASL free (can still choose it).
        $manager = $this->getDataGenerator()->create_user([
            'profile_field_region' => 'south', 'profile_field_asl' => '3',
        ]);
        role_assign($regionrole, $manager->id, \context_system::instance());
        $this->assertSame(['region' => 'south'], locked_filters::for_user((int)$manager->id));
    }

    public function test_for_user_returns_profile_value_without_capability(): void {
        $this->resetAfterTest();
        $this->create_region_field();
        set_config('lockedfilters', 'region=region', 'local_wb_dashboard');

        $user = $this->getDataGenerator()->create_user(['profile_field_region' => 'south']);
        $this->assertSame(['region' => 'south'], locked_filters::for_user((int)$user->id));

        // An empty profile field stays in the map with '' (callers fail closed).
        $unset = $this->getDataGenerator()->create_user(['profile_field_region' => '']);
        $this->assertSame(['region' => ''], locked_filters::for_user((int)$unset->id));
    }

    public function test_capability_exempts_user(): void {
        $this->resetAfterTest();
        $this->create_region_field();
        set_config('lockedfilters', 'region=region', 'local_wb_dashboard');

        $user = $this->getDataGenerator()->create_user(['profile_field_region' => 'south']);
        $roleid = $this->getDataGenerator()->create_role(['archetype' => '']);
        assign_capability('local/wb_dashboard:ignorelockedfilters', CAP_ALLOW, $roleid, \context_system::instance());
        role_assign($roleid, $user->id, \context_system::instance());

        $this->assertSame([], locked_filters::for_user((int)$user->id));

        // Admins are exempt implicitly.
        $this->assertSame([], locked_filters::for_user((int)get_admin()->id));
    }

    public function test_pipeline_forces_locked_value_over_client_submission(): void {
        $this->resetAfterTest();
        $this->create_region_field();
        // Lock the report's "firstname" filter key to the region profile field.
        set_config('lockedfilters', 'firstname=region', 'local_wb_dashboard');

        $ann = $this->getDataGenerator()->create_user(
            ['firstname' => 'Ann', 'lastname' => 'One', 'profile_field_region' => 'Ann']
        );
        $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'Two']);
        $reportid = $this->create_users_report();

        $this->setUser($ann);
        // Ann tries to see Bob's rows: the submitted value must be discarded.
        $result = get_chart_data::execute(
            'reportbuilder',
            'bar',
            $this->pairs([
                'report' => $reportid,
                'categoryfield' => 'user:fullname',
                'valuefield' => 'user:fullname',
            ]),
            [['key' => 'firstname', 'type' => 'text', 'value' => 'Bob']],
            '',
            ''
        );

        $config = json_decode($result['payload'], true);
        $this->assertSame(['Ann One'], $config['data']['labels']);
    }

    public function test_pipeline_fails_closed_without_profile_value(): void {
        $this->resetAfterTest();
        $this->create_region_field();
        set_config('lockedfilters', 'firstname=region', 'local_wb_dashboard');

        $user = $this->getDataGenerator()->create_user();
        $reportid = $this->create_users_report();

        $this->setUser($user);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:lockedfilternovalue', 'local_wb_dashboard', 'firstname'));
        get_chart_data::execute(
            'reportbuilder',
            'bar',
            $this->pairs([
                'report' => $reportid,
                'categoryfield' => 'user:fullname',
                'valuefield' => 'user:fullname',
            ]),
            [],
            '',
            ''
        );
    }

    public function test_chartfilter_shortcode_renders_static_value_when_locked(): void {
        $this->resetAfterTest();
        $this->create_region_field();
        set_config('lockedfilters', 'region=region', 'local_wb_dashboard');

        $args = ['key' => 'region', 'type' => 'select', 'label' => 'Region', 'options' => 'north:North,south:South'];
        $env = (object)['context' => \context_system::instance()];
        $next = static fn(): string => '';

        // Locked user: static value with the option's label, no control.
        $user = $this->getDataGenerator()->create_user(['profile_field_region' => 'south']);
        $this->setUser($user);
        $html = shortcodes::chartfilter('chartfilter', $args, null, $env, $next);
        $this->assertStringContainsString('South', $html);
        $this->assertStringNotContainsString('<select', $html);

        // Locked user + hidewhenlocked: nothing at all.
        $hidden = shortcodes::chartfilter('chartfilter', $args + ['hidewhenlocked' => '1'], null, $env, $next);
        $this->assertSame('', $hidden);

        // Exempt user: the normal select.
        $this->setAdminUser();
        $html = shortcodes::chartfilter('chartfilter', $args, null, $env, $next);
        $this->assertStringContainsString('<select', $html);
    }

    public function test_chartfilter_shortcode_emits_cascade_only_when_parent_is_free(): void {
        $this->resetAfterTest();
        $this->create_region_field();
        set_config('lockedfilters', 'region=region', 'local_wb_dashboard');
        $this->setAdminUser();
        $reportid = $this->create_users_report();

        $args = [
            'key' => 'name', 'type' => 'select', 'label' => 'Name',
            'report' => (string)$reportid, 'optionsfield' => 'firstname', 'cascadefrom' => 'region',
        ];
        $env = (object)['context' => \context_system::instance()];
        $next = static fn(): string => '';

        // Exempt user: region is free, so the select cascades from it (the data
        // attributes are what the cascadeselect module boots from; the {{#js}}
        // block itself goes to $PAGE->requires, not into the returned HTML).
        $html = shortcodes::chartfilter('chartfilter', $args, null, $env, $next);
        $this->assertStringContainsString('data-cascadefrom="region"', $html);
        $this->assertStringContainsString('data-optionsargs=', $html);
        $this->assertStringContainsString('&quot;field&quot;:&quot;firstname&quot;', $html);

        // Locked user: region never changes on the client, so no cascade.
        $this->setUser($this->getDataGenerator()->create_user(['profile_field_region' => 'south']));
        $html = shortcodes::chartfilter('chartfilter', $args, null, $env, $next);
        $this->assertStringContainsString('<select', $html);
        $this->assertStringNotContainsString('data-cascadefrom', $html);
        $this->assertStringNotContainsString('data-optionsargs', $html);

        // Static options: nothing to re-fetch, so no cascade either.
        $this->setAdminUser();
        $static = ['key' => 'name', 'type' => 'select', 'options' => 'a:A,b:B', 'cascadefrom' => 'region'];
        $html = shortcodes::chartfilter('chartfilter', $static, null, $env, $next);
        $this->assertStringNotContainsString('data-cascadefrom', $html);
    }

    public function test_pipeline_leaves_exempt_users_untouched(): void {
        $this->resetAfterTest();
        $this->create_region_field();
        set_config('lockedfilters', 'firstname=region', 'local_wb_dashboard');

        $this->getDataGenerator()->create_user(['firstname' => 'Ann', 'lastname' => 'One']);
        $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'Two']);
        $reportid = $this->create_users_report();

        $this->setAdminUser();
        $result = get_chart_data::execute(
            'reportbuilder',
            'bar',
            $this->pairs([
                'report' => $reportid,
                'categoryfield' => 'user:fullname',
                'valuefield' => 'user:fullname',
            ]),
            [['key' => 'firstname', 'type' => 'text', 'value' => 'Bob']],
            '',
            ''
        );

        $config = json_decode($result['payload'], true);
        $this->assertSame(['Bob Two'], $config['data']['labels']);
    }
}
