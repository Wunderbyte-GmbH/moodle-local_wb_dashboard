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
