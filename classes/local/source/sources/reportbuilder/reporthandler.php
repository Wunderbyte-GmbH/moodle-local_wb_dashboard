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

namespace local_wb_dashboard\local\source\sources\reportbuilder;

use core_reportbuilder\local\aggregation\count as countaggregation;
use core_reportbuilder\local\aggregation\sum;
use core_reportbuilder\manager;
use core_reportbuilder\table\custom_report_table_view;

/**
 * Runs a Report Builder report without its UI and reads formatted rows.
 *
 * Adapted from local_agenas so local_wb_dashboard has no runtime dependency on it.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reporthandler {
    /** @var int Report ID. */
    public int $reportid;

    /** @var array|null Cached formatted rows. */
    private ?array $reportrows = null;

    /**
     * Constructor.
     *
     * @param int $reportid
     */
    public function __construct(int $reportid) {
        $this->reportid = $reportid;
    }

    /**
     * Return the report's formatted rows (cached).
     *
     * @return array
     */
    public function return_data(): array {
        if ($this->reportrows === null) {
            $table = custom_report_table_view::create($this->reportid);
            $table->setup();
            $table->query_db(0, false);

            $this->reportrows = [];
            foreach ($table->rawdata as $record) {
                $this->reportrows[] = $table->format_row($record);
            }

            $table->close_recordset();
        }
        return $this->reportrows;
    }

    /**
     * Return the report's rows grouped in the database instead of row by row.
     *
     * Report Builder already knows how to group: as soon as any active column
     * carries an aggregation, {@see \core_reportbuilder\table\custom_report_table}
     * groups by every column that does not. So grouping is a matter of setting
     * the right aggregation on each column before the table builds its SQL —
     * "sum" on the columns whose values are wanted, "count" on the ones nobody
     * reads (count is the only aggregation compatible with every column type,
     * and it takes them out of the GROUP BY), and none on the columns to group
     * by. The aggregations are restored afterwards, since the report instance is
     * cached for the rest of the request.
     *
     * Returns null when the report cannot be grouped safely, in which case the
     * caller should fall back to the ungrouped {@see self::return_data()}:
     *
     * - a column already carries an aggregation, or the report is set to show
     *   unique rows: the report author asked for a specific row semantics and
     *   ours would silently replace it;
     * - a wanted alias does not belong to any active column, or its column type
     *   cannot be summed.
     *
     * The report's own sorting is preserved by keeping every sorted column
     * grouped as well: an aggregated column would otherwise be ordered by its
     * aggregate (a row count) instead of its value, silently reshuffling the
     * chart. That can make the grouping finer than asked for — a report sorted
     * by a per-row column yields a group per distinct value of it — which stays
     * correct, since the caller finishes the summing itself.
     *
     * @param string[] $groupaliases Column aliases to group by.
     * @param string[] $sumaliases Column aliases to sum.
     * @return array|null Grouped rows keyed like return_data(), or null to fall back.
     */
    public function return_grouped_data(array $groupaliases, array $sumaliases): ?array {
        $report = manager::get_report_from_id($this->reportid);
        if ($report->get_report_persistent()->get('uniquerows')) {
            return null;
        }

        $columns = $report->get_active_columns_by_alias();
        if (empty($columns) || empty($sumaliases)) {
            return null;
        }
        foreach (array_merge($groupaliases, $sumaliases) as $alias) {
            if (!array_key_exists($alias, $columns)) {
                return null;
            }
        }

        // Columns the report sorts by have to keep their own value to sort on.
        foreach ($columns as $alias => $column) {
            if (
                !in_array($alias, $sumaliases, true)
                && $column->get_is_sortable()
                && $column->get_persistent()->get('sortenabled')
            ) {
                $groupaliases[] = $alias;
            }
        }

        // Decide each column's aggregation up front, so an incompatible column
        // aborts before anything has been changed.
        $wanted = [];
        foreach ($columns as $alias => $column) {
            if ($column->get_aggregation() !== null) {
                return null;
            }
            if (in_array($alias, $groupaliases, true)) {
                $wanted[$alias] = null;
            } else if (in_array($alias, $sumaliases, true)) {
                if (!sum::compatible($column->get_type())) {
                    return null;
                }
                $wanted[$alias] = sum::get_class_name();
            } else {
                $wanted[$alias] = countaggregation::get_class_name();
            }
        }

        try {
            foreach ($wanted as $alias => $aggregation) {
                $columns[$alias]->set_aggregation($aggregation);
            }
            $this->reportrows = null;
            return $this->return_data();
        } finally {
            foreach (array_keys($wanted) as $alias) {
                $columns[$alias]->set_aggregation(null);
            }
            $this->reportrows = null;
        }
    }

    /**
     * Resolve a logical field name (e.g. "durata") to the actual row key
     * (e.g. "c2_decvalue").
     *
     * @param int $reportid
     * @param string $field
     * @param array $row
     * @return string
     */
    public static function resolve_field_name(int $reportid, string $field, array $row): string {
        $field = \core_text::strtolower(trim($field));
        if (array_key_exists($field, $row)) {
            return $field;
        }

        $report = manager::get_report_from_id($reportid);

        foreach ($report->get_active_columns() as $column) {
            $alias = $column->get_column_alias();
            if (!array_key_exists($alias, $row)) {
                continue;
            }

            $columnname = \core_text::strtolower($column->get_name());
            $uniqueidentifier = \core_text::strtolower($column->get_unique_identifier());

            if ($columnname === $field || $uniqueidentifier === $field) {
                return $alias;
            }

            $customfieldprefix = ':customfield_';
            if (strpos($uniqueidentifier, $customfieldprefix) !== false) {
                $customfieldshortname = substr($uniqueidentifier, strrpos($uniqueidentifier, $customfieldprefix) + 13);
                if ($customfieldshortname === $field) {
                    return $alias;
                }
            }
        }
        return $field;
    }
}
