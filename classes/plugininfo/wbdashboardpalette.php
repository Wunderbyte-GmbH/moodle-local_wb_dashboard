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

namespace local_wb_dashboard\plugininfo;

use core\plugininfo\base;
use part_of_admin_tree;
use admin_settingpage;

/**
 * Plugin-management metadata for the dashboard palette subplugin type.
 *
 * Core resolves this class for the `wbdashboardpalette` type. It supplies the
 * management behaviour; the palette's colours live in each subplugin's own class
 * (see {@see wbdashboardpalette_interface}). Core does not load settings for a
 * custom subplugin type automatically, so the parent plugin's settings.php walks
 * the installed palettes and calls {@see self::load_settings()} on each, which
 * includes that palette's settings.php (its colour pickers) into the admin tree.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wbdashboardpalette extends base {
    /** Name of the bundled palette that must always be present. */
    public const DEFAULT_PALETTE = 'standard';

    #[\Override]
    public function is_enabled() {
        return true;
    }

    #[\Override]
    public function is_uninstall_allowed() {
        // The bundled standard palette is the guaranteed fallback and cannot be removed.
        return $this->name !== self::DEFAULT_PALETTE;
    }

    #[\Override]
    public function get_settings_section_name() {
        return 'wbdashboardpalette_' . $this->name;
    }

    #[\Override]
    public function load_settings(part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig) {
        global $CFG, $USER, $DB, $OUTPUT, $PAGE; // In case settings.php wants to refer to them.
        /** @var \admin_root $ADMIN */
        $ADMIN = $adminroot; // May be used in settings.php.
        $plugininfo = $this; // Also can be used inside settings.php.

        if (!$this->is_installed_and_upgraded()) {
            return;
        }

        if (!$hassiteconfig || !file_exists($this->full_path('settings.php'))) {
            return;
        }

        $section = $this->get_settings_section_name();

        $settings = new admin_settingpage($section, $this->displayname, 'moodle/site:config', $this->is_enabled() === false);
        include($this->full_path('settings.php')); // This may also set $settings to null.

        if ($settings) {
            $ADMIN->add($parentnodename, $settings);
        }
    }
}
