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
 * Optional source capability: provide grouped options for a select control.
 *
 * A source implementing this can populate a [chartfilter type=groupedselect]
 * dropdown whose options are visually grouped by a second field (e.g. ASL
 * options grouped under their REGION). Values must still be usable as a
 * constraint on the control's own key, exactly like {@see option_provider_interface}.
 *
 * When a scope value is supplied, only the matching group is returned — this is
 * how a viewer locked to one group (e.g. a regional manager) sees just their
 * own group's options. Callers check instanceof and fall back to static/flat
 * options when a source does not implement it.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface grouped_option_provider_interface {
    /**
     * Dropdown options grouped by a second field, optionally scoped to one group.
     *
     * Option values follow the same rules as {@see option_provider_interface}:
     * they are the formatted, distinct values of the value field as they appear
     * in the source's data, so selecting one filters that data.
     *
     * @param array $sourceparams Already allowlisted source params.
     * @param string $groupfield Logical field to group options by (e.g. "region").
     * @param string $valuefield Logical field providing option values (e.g. "asl").
     * @param string $scopevalue When non-empty, return only the group whose label matches.
     * @param filter_constraint[] $constraints Constraints scoping the data the options come from.
     * @return array<int, array{group: string, options: array<int, array{value: string, label: string}>}>
     */
    public function get_grouped_filter_options(
        array $sourceparams,
        string $groupfield,
        string $valuefield,
        string $scopevalue = '',
        array $constraints = []
    ): array;
}
