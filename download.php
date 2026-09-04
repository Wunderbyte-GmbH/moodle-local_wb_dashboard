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
 * Download a custom report with the current dashboard page filters applied.
 *
 * The [downloadreport] shortcode links here; the filterbus appends the live
 * page filter values as a JSON "filters" parameter. The values are translated
 * into the report's native filters exactly like the chart pipeline does it
 * (including server-forced locked filters, which apply even without any
 * submitted values), applied on top of the user's stored filter state for the
 * export, and restored on shutdown so the report UI keeps its own state.
 *
 * @package   local_wb_dashboard
 * @copyright 2026 Wunderbyte GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

use core_reportbuilder\local\helpers\user_filter_manager;
use core_reportbuilder\local\report\base;
use core_reportbuilder\manager;
use core_reportbuilder\permission;
use core_reportbuilder\table\custom_report_table_view;
use core_reportbuilder\table\custom_report_table_view_filterset;
use core_table\local\filter\integer_filter;
use local_wb_dashboard\local\source\pipeline;
use local_wb_dashboard\local\source\sources\reportbuilder\reportbuilder_source;

require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

$reportid = required_param('id', PARAM_INT);
$download = required_param('download', PARAM_ALPHA);
$filtersjson = optional_param('filters', '', PARAM_RAW);

$reportpersistent = new \core_reportbuilder\local\models\report($reportid);
if ($reportpersistent->get('type') !== base::TYPE_CUSTOM_REPORT) {
    throw new \core_reportbuilder\exception\report_access_exception();
}
permission::require_can_view_report($reportpersistent);

$enabledformats = core_plugin_manager::instance()->get_enabled_plugins('dataformat');
if (!isset($enabledformats[$download])) {
    throw new moodle_exception('error:unknowndownloadformat', 'local_wb_dashboard', '', s($download));
}

$PAGE->set_context($reportpersistent->get_context());
$PAGE->set_url(new moodle_url('/local/wb_dashboard/download.php'));

// Decode the submitted {key, type, value} filter triples; anything malformed
// is dropped (the endpoint then simply exports less filtered, never broken).
$filtervalues = [];
$decoded = $filtersjson === '' ? [] : json_decode($filtersjson, true);
foreach (is_array($decoded) ? $decoded : [] as $fv) {
    if (!is_array($fv)) {
        continue;
    }
    $key = clean_param((string)($fv['key'] ?? ''), PARAM_ALPHANUMEXT);
    $type = clean_param((string)($fv['type'] ?? ''), PARAM_ALPHA);
    if ($key !== '' && $type !== '') {
        $filtervalues[] = ['key' => $key, 'type' => $type, 'value' => (string)($fv['value'] ?? '')];
    }
}

// Same translation the chart pipeline uses: locked filters first (fail
// closed), then the submitted values, mapped onto the report's own filters.
$source = new reportbuilder_source();
$sourceparams = ['report' => $reportid];
$constraints = pipeline::build_constraints($source, $sourceparams, $filtervalues);
$report = manager::get_report_from_id($reportid);
$values = $source->build_filter_values($report, $constraints);

if (!empty($values)) {
    // The export reads the user's stored filter state, so apply on top of it
    // and restore on shutdown (the dataformat writer may exit before we
    // regain control) — the report UI keeps showing the user's own filters.
    $previous = $report->get_filter_values();
    $report->set_filter_values(array_merge($previous, $values));
    core_shutdown_manager::register_function(static function () use ($reportid, $previous): void {
        user_filter_manager::set($reportid, $previous);
    });
}

// Build the download table directly, the same way core's exporter does in
// download mode, so the export filename can carry the generation date/time
// (core names the file after the report alone).
$filterset = new custom_report_table_view_filterset();
$filterset->add_filter(new integer_filter('pagesize', null, [$report->get_default_per_page()]));

// Deliberately created without the download format: passing it here would make
// the constructor instantiate the export class, which sends the HTTP headers
// (and with them the filename) straight away, before we can name the file.
$table = custom_report_table_view::create($reportid);
$table->set_filterset($filterset);

// Now declare the download, with the report name plus a timestamp. This is what
// creates the export class and sends the headers. The sheet title gets the bare
// timestamp: spreadsheet formats cap it at 31 characters, so a long report name
// would push the date out of it.
$timestamp = userdate(time(), '%d.%m.%Y-%H.%M', 99, false);
$table->is_downloading($download, $reportpersistent->get_formatted_name() . '_' . $timestamp, $timestamp);

echo $PAGE->get_renderer('core_reportbuilder')->render($table);
