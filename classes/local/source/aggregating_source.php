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
 * Optional capability: a source that can pre-aggregate a dataset in the database.
 *
 * Shaping a categorised chart means summing a few numeric fields per category.
 * Done in PHP that costs one fetch-and-format per dataset row, which is linear
 * in the size of the dataset even though the chart only ever shows a handful of
 * bars. A source implementing this interface can push the grouping down to the
 * database instead, returning one row per group.
 *
 * The contract is deliberately narrow so the result stays interchangeable with
 * {@see shapable_source::load_rows()}:
 *
 * - Returned rows carry the *same keys* as load_rows() would, so callers can
 *   hand them to the same {@see shapable_source::resolve_field()} and read them
 *   the same way.
 * - Grouping may be *finer* than the caller asked for, never coarser. Summing is
 *   associative, so a caller that finishes the aggregation in PHP gets an
 *   identical result from partially grouped rows — which lets a source group by
 *   a raw database field even when the category label is produced by a PHP
 *   callback over that field (a timestamp rendered as a month name, say).
 * - Only summation is covered. Row counting is not, because a caller cannot tell
 *   from a grouped row how many source rows it stands for.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface aggregating_source extends shapable_source {
    /**
     * Load one dataset's rows grouped by the given fields, with the sum fields summed.
     *
     * Returning null means "cannot push this down" and is not an error: the
     * caller falls back to {@see shapable_source::load_rows()}. A source should
     * do that whenever it cannot guarantee the result matches the ungrouped one.
     *
     * @param int|string $datasetid Source-specific dataset id (e.g. a report id).
     * @param filter_constraint[] $constraints
     * @param string[] $groupfields Logical field names to group by, e.g. the category field.
     * @param string[] $sumfields Logical field names to sum within each group.
     * @return array|null Grouped rows keyed as load_rows() would key them, or null to fall back.
     */
    public function load_grouped_rows(
        int|string $datasetid,
        array $constraints,
        array $groupfields,
        array $sumfields
    ): ?array;
}
