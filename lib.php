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

/**
 * Component callbacks for local_wb_dashboard.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_wb_dashboard\local\detail\detail_templates;

/**
 * Fragment callback: render one detail template as the body of a per-row
 * "see details" modal (see the [toplist] shortcode's "details" argument).
 *
 * The core fragment web service has already done require_login() and
 * validate_context() and injected the context into $args. The template body
 * is site-admin-authored (same trust level as the lockedfilters setting), so
 * it renders noclean; the row-supplied placeholder values are sanitized in
 * detail_templates::substitute() so they cannot alter the template's
 * shortcode syntax. Every display shortcode inside the rendered template
 * still enforces its own report-view permission when its data loads.
 *
 * Requires the Shortcodes text filter to be enabled for content in the
 * rendering context, exactly like dashboard shortcodes on a normal page.
 *
 * @param array $args Fragment arguments: name, value, label, context.
 * @return string
 */
function local_wb_dashboard_output_fragment_detail($args): string {
    $args = (array)$args;
    $context = $args['context'];
    $name = isset($args['name']) ? clean_param($args['name'], PARAM_ALPHANUMEXT) : '';
    $value = (string)($args['value'] ?? '');
    $label = (string)($args['label'] ?? '');

    $template = $name !== '' ? detail_templates::get($name) : null;
    if ($template === null) {
        // A misconfigured name must be debuggable for the author, not a stack trace.
        return get_string('error:unknowndetailtemplate', 'local_wb_dashboard', s($name));
    }

    $html = detail_templates::substitute($template, $value, $label);
    return format_text($html, FORMAT_HTML, ['context' => $context, 'noclean' => true]);
}
