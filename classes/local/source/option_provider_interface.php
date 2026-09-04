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

use local_wb_dashboard\local\dto\filter_constraint;

/**
 * Optional source capability: provide the options for a select filter control.
 *
 * A source implementing this can populate a [chartfilter type=select] dropdown
 * dynamically (shortcode arg optionsfield="...") instead of requiring a
 * hardcoded options="value:Label,..." list. Callers check instanceof and fall
 * back to static options when a source does not implement it.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface option_provider_interface {
    /**
     * The dropdown options for a filter bound to this source's data.
     *
     * Values must be usable as constraint values for the same logical key, so
     * that selecting an option actually filters the source's data.
     *
     * When constraints are given, only values present in the data matching them
     * are returned — this is how a cascading select scopes its options to the
     * current value of another filter. Empty constraints = every value.
     *
     * @param array $sourceparams Already allowlisted source params.
     * @param string $field Logical field/filter name to derive options from.
     * @param filter_constraint[] $constraints Constraints scoping the data the options come from.
     * @return array<int, array{value: string, label: string}>
     */
    public function get_filter_options(array $sourceparams, string $field, array $constraints = []): array;
}
