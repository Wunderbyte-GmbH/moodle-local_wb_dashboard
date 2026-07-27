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

/**
 * Tests for the detail-modal fragment callback.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_wb_dashboard_output_fragment_detail
 */
final class fragment_detail_test extends \advanced_testcase {
    /**
     * Invoke the fragment callback the way core's fragment web service does.
     *
     * @param array $args
     * @return string
     */
    private function render_fragment(array $args): string {
        $args['context'] = $args['context'] ?? \context_system::instance();
        return component_callback('local_wb_dashboard', 'output_fragment_detail', [$args]);
    }

    /**
     * The configured template renders with both placeholders substituted.
     */
    public function test_renders_substituted_template(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $template = "=== coursedetail ===\n<h3>{{label}}</h3><span data-id=\"{{id}}\"></span>";
        set_config('detailtemplates', $template, 'local_wb_dashboard');

        $html = $this->render_fragment(['name' => 'coursedetail', 'value' => '5', 'label' => 'Course A']);
        $this->assertStringContainsString('<h3>Course A</h3>', $html);
        $this->assertStringContainsString('data-id="5"', $html);
    }

    /**
     * With the shortcodes filter enabled, an inner display shortcode expands
     * and carries the substituted fixed filter in its wsargs.
     */
    public function test_inner_shortcode_expands_with_fixed_filter(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        filter_set_global_state('shortcodes', TEXTFILTER_ON);
        $template = "=== coursedetail ===\n"
            . '[digits source=reportbuilder display=count report=1 consumes=none fixedfilters="courseid:{{id}}"]';
        set_config('detailtemplates', $template, 'local_wb_dashboard');

        $html = $this->render_fragment(['name' => 'coursedetail', 'value' => '5', 'label' => 'Course A']);
        // The digits wrapper rendered (shortcode expanded)...
        $this->assertStringContainsString('local-dashboard-digits-', $html);
        // ...isolated from page filters and pinned to the clicked id.
        $this->assertStringContainsString('__none__', $html);
        $this->assertStringContainsString('courseid', $html);
        $this->assertStringContainsString(s('"value":"5"'), $html);
    }

    /**
     * An unknown or missing template name returns a localized message, not an
     * exception (admin typos must be debuggable in place).
     */
    public function test_unknown_template_is_a_clean_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertSame(
            get_string('error:unknowndetailtemplate', 'local_wb_dashboard', 'nosuch'),
            $this->render_fragment(['name' => 'nosuch', 'value' => '1', 'label' => 'x'])
        );
        $this->assertSame(
            get_string('error:unknowndetailtemplate', 'local_wb_dashboard', ''),
            $this->render_fragment(['value' => '1', 'label' => 'x'])
        );
    }
}
