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

namespace local_wb_dashboard\local\definition;

/**
 * Parser for the "fixedfilters" shortcode argument shared by the display
 * shortcodes ([chart], [digits], [toplist]).
 *
 * A fixed filter pins a literal filter value on one shortcode instance,
 * independent of the page's filter controls: fixedfilters="courseid:5" or
 * fixedfilters="courseid:5;region:west". Pairs are separated by ";", key and
 * value by the FIRST ":" (values may contain further colons). Each pair ships
 * to the web services as an ordinary filtervalues entry and is applied by the
 * source like any other constraint — keys the report maps no filter for are
 * ignored, and server-side locked filters still take precedence.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fixed_filters {
    /**
     * Parse a raw fixedfilters argument into filtervalues triples.
     *
     * Malformed pairs (no colon, empty key or value, key not surviving
     * PARAM_ALPHANUMEXT) are dropped; the first occurrence of a key wins.
     *
     * @param string $raw The raw shortcode argument value.
     * @return array<int, array{key: string, type: string, value: string}>
     */
    public static function parse(string $raw): array {
        $result = [];
        foreach (explode(';', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || strpos($pair, ':') === false) {
                continue;
            }
            [$key, $value] = explode(':', $pair, 2);
            $key = clean_param(trim($key), PARAM_ALPHANUMEXT);
            $value = trim($value);
            if ($key === '' || $value === '' || isset($result[$key])) {
                continue;
            }
            // "select" maps to an equality constraint, which the report builder
            // source applies natively via its select/text/number filters.
            $result[$key] = ['key' => $key, 'type' => 'select', 'value' => $value];
        }
        return array_values($result);
    }
}
