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
 * External function declarations for local_wb_dashboard.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_wb_dashboard_get_chart_data' => [
        'classname'   => 'local_wb_dashboard\external\get_chart_data',
        'description' => 'Return the fully-built chart configuration for a chart definition.',
        'type'        => 'read',
        'ajax'        => true,
    ],
    'local_wb_dashboard_get_digits_data' => [
        'classname'   => 'local_wb_dashboard\external\get_digits_data',
        'description' => 'Return a single reduced value (number, count or percentage) for a digits field.',
        'type'        => 'read',
        'ajax'        => true,
    ],
    'local_wb_dashboard_get_toplist_data' => [
        'classname'   => 'local_wb_dashboard\external\get_toplist_data',
        'description' => 'Return the ranked rows (label, value, bar percent) for a top-N list.',
        'type'        => 'read',
        'ajax'        => true,
    ],
    'local_wb_dashboard_set_filter_state' => [
        'classname'   => 'local_wb_dashboard\external\set_filter_state',
        'description' => 'Persist the per-user page filter state.',
        'type'        => 'write',
        'ajax'        => true,
    ],
];
