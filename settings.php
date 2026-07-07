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
 * Admin settings for local_wb_dashboard.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_wb_dashboard\local\palette\palette_manager;

if ($hassiteconfig) {
    // Group the dashboard's own settings and every palette's colour pickers under
    // a single category, so they read as one coherent area in the admin tree.
    $ADMIN->add('localplugins', new admin_category(
        'local_wb_dashboard',
        get_string('pluginname', 'local_wb_dashboard')
    ));

    $settings = new admin_settingpage('local_wb_dashboard_settings', get_string('generalsettings', 'admin'));

    // Choose the active palette from the installed palette subplugins. Each
    // palette supplies the chart colour scheme and (optionally) its own CSS.
    $settings->add(new admin_setting_configselect(
        'local_wb_dashboard/activepalette',
        get_string('settings:activepalette', 'local_wb_dashboard'),
        get_string('settings:activepalette_desc', 'local_wb_dashboard'),
        palette_manager::DEFAULT_PALETTE,
        palette_manager::available()
    ));

    $ADMIN->add('local_wb_dashboard', $settings);

    // Core does not auto-load settings for a custom subplugin type, so each
    // installed palette's own settings.php (its colour pickers) is added into
    // this category as its own page.
    foreach (\core_plugin_manager::instance()->get_plugins_of_type('wbdashboardpalette') as $palette) {
        $palette->load_settings($ADMIN, 'local_wb_dashboard', $hassiteconfig);
    }
}
