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
use local_wb_dashboard\local\source\pipeline;

/**
 * Tests for the pipeline's shaped-chart-data cache.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_wb_dashboard\local\source\pipeline
 */
final class pipeline_cache_test extends \advanced_testcase {
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
     * An identical request is served from cache; different params and a purge
     * bypass it.
     */
    public function test_identical_requests_are_cached(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->getDataGenerator()->create_user();

        /** @var generator $rbgenerator */
        $rbgenerator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgenerator->create_report(['name' => 'Users', 'source' => users::class, 'default' => 0]);
        $rbgenerator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:username']);

        $params = $this->pairs([
            'report' => $report->get('id'),
            'categoryfield' => 'user:username',
            'aggregation' => 'count',
        ]);

        $first = pipeline::fetch('reportbuilder', $params, []);
        $countbefore = count($first->labels);

        // A new user is invisible to an identical request: it hits the cache.
        $this->getDataGenerator()->create_user();
        $cached = pipeline::fetch('reportbuilder', $params, []);
        $this->assertCount($countbefore, $cached->labels);

        // A request with different params misses the cache and sees the user.
        $fresh = pipeline::fetch('reportbuilder', array_merge($params, $this->pairs(['top' => 100])), []);
        $this->assertCount($countbefore + 1, $fresh->labels);

        // After a purge the original request sees the new user too.
        \cache::make('local_wb_dashboard', 'chartdata')->purge();
        $purged = pipeline::fetch('reportbuilder', $params, []);
        $this->assertCount($countbefore + 1, $purged->labels);
    }

    /**
     * Different submitted filter values key separate cache entries.
     */
    public function test_filter_values_key_separate_entries(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->getDataGenerator()->create_user(['username' => 'chartuserone']);
        $this->getDataGenerator()->create_user(['username' => 'chartusertwo']);

        /** @var generator $rbgenerator */
        $rbgenerator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgenerator->create_report(['name' => 'Users', 'source' => users::class, 'default' => 0]);
        $rbgenerator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:username']);
        $rbgenerator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:username']);

        $params = $this->pairs([
            'report' => $report->get('id'),
            'categoryfield' => 'user:username',
            'aggregation' => 'count',
        ]);

        $one = pipeline::fetch('reportbuilder', $params, [
            ['key' => 'user:username', 'type' => 'text', 'value' => 'chartuserone'],
        ]);
        $two = pipeline::fetch('reportbuilder', $params, [
            ['key' => 'user:username', 'type' => 'text', 'value' => 'chartusertwo'],
        ]);

        $this->assertSame(['chartuserone'], $one->labels);
        $this->assertSame(['chartusertwo'], $two->labels);
    }
}
