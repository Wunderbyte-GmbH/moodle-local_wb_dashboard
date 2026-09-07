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

// The read functions declare 'readonlysession': they only read the database and
// application-mode caches, so they can run without the session write lock. A page
// fires one request per chart, digits field and cascading filter at once; without
// this every one of them queues behind the slowest report query on the lock, and
// whichever waits past the session lock acquire timeout fails with a session
// error. Takes effect only when $CFG->enable_read_only_sessions is set on the site.
$functions = [
    'local_wb_dashboard_get_chart_data' => [
        'classname'   => 'local_wb_dashboard\external\get_chart_data',
        'description' => 'Return the fully-built chart configuration for a chart definition.',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
    ],
    'local_wb_dashboard_get_digits_data' => [
        'classname'   => 'local_wb_dashboard\external\get_digits_data',
        'description' => 'Return a single reduced value (number, count or percentage) for a digits field.',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
    ],
    'local_wb_dashboard_get_toplist_data' => [
        'classname'   => 'local_wb_dashboard\external\get_toplist_data',
        'description' => 'Return the ranked rows (label, value, bar percent) for a top-N list.',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
    ],
    'local_wb_dashboard_get_filter_options' => [
        'classname'   => 'local_wb_dashboard\external\get_filter_options',
        'description' => 'Return the dynamic options of a select filter, scoped by the current page filter values.',
        'type'        => 'read',
        'ajax'        => true,
        'readonlysession' => true,
    ],
    'local_wb_dashboard_set_filter_state' => [
        'classname'   => 'local_wb_dashboard\external\set_filter_state',
        'description' => 'Persist the per-user page filter state.',
        'type'        => 'write',
        'ajax'        => true,
    ],
];
