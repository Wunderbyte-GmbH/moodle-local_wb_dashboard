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
 * Shortcode registration for local_wb_dashboard (filter_shortcodes).
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$shortcodes = [
    'chart' => [
        'callback'    => 'local_wb_dashboard\shortcodes::chart',
        'wraps'       => false,
        'description' => 'shortcode:chart',
    ],
    'chartfilter' => [
        'callback'    => 'local_wb_dashboard\shortcodes::chartfilter',
        'wraps'       => false,
        'description' => 'shortcode:chartfilter',
    ],
    'filterreset' => [
        'callback'    => 'local_wb_dashboard\shortcodes::filterreset',
        'wraps'       => false,
        'description' => 'shortcode:filterreset',
    ],
    'digits' => [
        'callback'    => 'local_wb_dashboard\shortcodes::digits',
        'wraps'       => false,
        'description' => 'shortcode:digits',
    ],
    'downloadreport' => [
        'callback'    => 'local_wb_dashboard\shortcodes::downloadreport',
        'wraps'       => false,
        'description' => 'shortcode:downloadreport',
    ],
    'toplist' => [
        'callback'    => 'local_wb_dashboard\shortcodes::toplist',
        'wraps'       => false,
        'description' => 'shortcode:toplist',
    ],
];
