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
use core_reportbuilder\local\filters\text;
use core_reportbuilder\manager;
use core_user\reportbuilder\datasource\users;
use local_wb_dashboard\local\source\pipeline;
use local_wb_dashboard\local\source\sources\reportbuilder\reportbuilder_source;
use local_wb_dashboard\local\source\sources\reportbuilder\reporthandler;

/**
 * Tests for the [downloadreport] shortcode and the filter translation the
 * download endpoint relies on.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_wb_dashboard\shortcodes
 * @covers     \local_wb_dashboard\local\source\pipeline
 * @covers     \local_wb_dashboard\local\source\sources\reportbuilder\reportbuilder_source
 */
final class downloadreport_test extends \advanced_testcase {
    /**
     * Create a users report with a fullname column and a firstname text filter.
     *
     * @return \core_reportbuilder\local\models\report
     */
    private function create_report(): \core_reportbuilder\local\models\report {
        /** @var generator $rbgenerator */
        $rbgenerator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgenerator->create_report(['name' => 'Users', 'source' => users::class, 'default' => 0]);
        $rbgenerator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:fullname']);
        $rbgenerator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:firstname']);
        return $report;
    }

    /**
     * Call the shortcode handler the way the filter would.
     *
     * @param array $args
     * @return string
     */
    private function render(array $args): string {
        return shortcodes::downloadreport('downloadreport', $args, null, (object)['context' => null], fn() => '');
    }

    /**
     * The button links to the plugin endpoint and carries the filter wiring.
     */
    public function test_shortcode_renders_filter_aware_button(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $report = $this->create_report();
        $html = $this->render([
            'report' => (string)$report->get('id'),
            'format' => 'csv',
            'label' => 'Export it',
            'pageid' => 'demo',
            'consumes' => 'firstname,period',
        ]);

        $this->assertStringContainsString('/local/wb_dashboard/download.php', $html);
        $this->assertStringContainsString('id=' . $report->get('id'), $html);
        $this->assertStringContainsString('download=csv', $html);
        $this->assertStringContainsString('Export it', $html);
        $this->assertStringContainsString('data-pageid="demo"', $html);
        $this->assertStringContainsString('firstname', $html);
        $this->assertStringContainsString(sesskey(), $html);
    }

    /**
     * Config errors surface as messages; missing permission hides the button.
     */
    public function test_shortcode_validation_and_permission(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $report = $this->create_report();

        $this->assertSame(
            get_string('error:invalidreportid', 'local_wb_dashboard'),
            $this->render([])
        );
        $this->assertSame(
            get_string('error:invalidreportid', 'local_wb_dashboard'),
            $this->render(['report' => '999999'])
        );
        $this->assertSame(
            get_string('error:unknowndownloadformat', 'local_wb_dashboard', 'nope'),
            $this->render(['report' => (string)$report->get('id'), 'format' => 'nope'])
        );

        // A user who may not view the report gets no button at all.
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertSame('', $this->render(['report' => (string)$report->get('id')]));
    }

    /**
     * The endpoint's translation chain: submitted page filter values become the
     * report's native filter values and actually filter the exported rows.
     */
    public function test_filter_values_filter_report_rows(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->getDataGenerator()->create_user(['firstname' => 'Alice']);
        $this->getDataGenerator()->create_user(['firstname' => 'Bob']);

        $reportid = $this->create_report()->get('id');
        $source = new reportbuilder_source();
        $sourceparams = ['report' => $reportid];

        // Unsupported keys and unknown filter types are dropped; the alias
        // "firstname" maps onto the report's "user:firstname" filter.
        $constraints = pipeline::build_constraints($source, $sourceparams, [
            ['key' => 'firstname', 'type' => 'text', 'value' => 'Alice'],
            ['key' => 'nosuchkey', 'type' => 'text', 'value' => 'x'],
            ['key' => 'firstname', 'type' => 'bogus', 'value' => 'x'],
        ]);
        $this->assertCount(1, $constraints);

        $report = manager::get_report_from_id($reportid);
        $values = $source->build_filter_values($report, $constraints);
        $this->assertSame(text::CONTAINS, $values['user:firstname_operator']);
        $this->assertSame('Alice', $values['user:firstname_value']);

        // Applying them (what download.php does) filters the report rows.
        $report->set_filter_values($values);
        $rows = (new reporthandler($reportid))->return_data();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Alice', json_encode(reset($rows)));
    }
}
