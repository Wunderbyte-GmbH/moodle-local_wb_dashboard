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

namespace local_wb_dashboard\local\source;

use local_wb_dashboard\local\source\sources\reportbuilder\reportbuilder_source;
use moodle_exception;

/**
 * Factory + allowlist for chart data sources.
 *
 * Internal registry (no cross-plugin hook in v1): to add a source, register its
 * class here (or call register() at runtime).
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source_registry {
    /** @var array<string,class-string<source_interface>> */
    private static array $sources = [
        'reportbuilder' => reportbuilder_source::class,
    ];

    /**
     * Register (or override) a source.
     *
     * @param string $name
     * @param class-string<source_interface> $class
     * @return void
     */
    public static function register(string $name, string $class): void {
        self::$sources[$name] = $class;
    }

    /**
     * Whether a source with this name exists.
     *
     * @param string $name
     * @return bool
     */
    public static function exists(string $name): bool {
        return isset(self::$sources[$name]);
    }

    /**
     * Instantiate a source by name.
     *
     * @param string $name
     * @return source_interface
     * @throws moodle_exception If the source is unknown.
     */
    public static function get(string $name): source_interface {
        if (!self::exists($name)) {
            throw new moodle_exception('error:unknownsource', 'local_wb_dashboard', '', $name);
        }
        $class = self::$sources[$name];
        return new $class();
    }
}
