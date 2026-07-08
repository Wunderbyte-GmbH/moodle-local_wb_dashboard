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

use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\manager;
use core_reportbuilder\permission;
use local_wb_dashboard\local\dto\chart_data;
use local_wb_dashboard\local\dto\filter_constraint;
use local_wb_dashboard\local\source\option_provider_interface;
use local_wb_dashboard\local\source\shapable_source;
use local_wb_dashboard\local\source\shaping\shaper;

/**
 * Report Builder data source.
 *
 * A dataset is a Report Builder report; this class only provides data access
 * (load rows, resolve field names, label datasets). The shaping itself lives in
 * the shared shaping strategies — see {@see shaper} — so this source supports
 * every shaping mode (multi-report totals, two-report delta, rows) without
 * shaping code of its own.
 *
 * Filters are applied the report-native way: constraints are translated into the
 * report's own filter values (via Report Builder's user_filter_manager), applied
 * around the query and then restored so no state leaks into the report UI.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reportbuilder_source implements option_provider_interface, shapable_source {
    /** @var int Cap for options derived by scanning report rows. */
    private const MAX_DYNAMIC_OPTIONS = 500;

    #[\Override]
    public static function get_name(): string {
        return 'reportbuilder';
    }

    #[\Override]
    public function required_params(): array {
        return [
            'idbase', 'fieldbase', 'idtotal', 'fieldtotal',
            'report', 'categoryfield', 'valuefield', 'stackfield', 'aggregation',
            'reports',
        ];
    }

    #[\Override]
    public function get_supported_filter_keys(array $sourceparams): array {
        $keys = [];
        foreach ($this->report_ids($sourceparams) as $reportid) {
            $report = manager::get_report_from_id($reportid);
            foreach ($report->get_active_filters() as $filter) {
                foreach (self::filter_aliases($filter->get_unique_identifier()) as $alias) {
                    $keys[$alias] = $alias;
                }
            }
        }
        return array_values($keys);
    }

    /**
     * All logical keys a report filter answers to: the full unique identifier,
     * the friendly name after the entity prefix and — for custom field and
     * user profile field filters — the bare field shortname, mirroring how
     * {@see reporthandler::resolve_field_name} resolves columns.
     *
     * @param string $uid The filter's unique identifier (e.g. "course:customfield_region").
     * @return string[] Aliases in original case (e.g. the uid, "customfield_region", "region").
     */
    private static function filter_aliases(string $uid): array {
        $aliases = [$uid];
        if (($pos = strpos($uid, ':')) !== false) {
            $short = substr($uid, $pos + 1);
            $aliases[] = $short;
            foreach (['customfield_', 'profilefield_'] as $prefix) {
                if (strpos($short, $prefix) === 0 && strlen($short) > strlen($prefix)) {
                    $aliases[] = substr($short, strlen($prefix));
                }
            }
        }
        return $aliases;
    }

    #[\Override]
    public function get_filter_options(array $sourceparams, string $field): array {
        // Preferred: the report's own select filter declares its options. Those
        // values are guaranteed to pass core's select-filter validation.
        $options = [];
        foreach ($this->report_ids($sourceparams) as $reportid) {
            foreach ($this->declared_filter_options($reportid, $field) as $value => $label) {
                $options[(string)$value] = format_string((string)$label);
            }
        }

        if (empty($options)) {
            // Fallback (e.g. text filters): distinct formatted values of the
            // column in the report rows.
            foreach ($this->report_ids($sourceparams) as $reportid) {
                foreach ($this->distinct_column_values($reportid, $field) as $value) {
                    $options[$value] = $value;
                }
            }
            \core_collator::asort($options);
        }

        $result = [];
        foreach ($options as $value => $label) {
            $result[] = ['value' => (string)$value, 'label' => (string)$label];
        }
        return $result;
    }

    /**
     * Options declared by the report's own filter for the given key, if that
     * filter is (a subclass of) core's select filter.
     *
     * @param int $reportid
     * @param string $field Logical filter key (full identifier or short name).
     * @return array value => label ('' if the key maps to no select filter).
     */
    private function declared_filter_options(int $reportid, string $field): array {
        $report = manager::get_report_from_id($reportid);
        $needle = strtolower($field);

        foreach ($report->get_active_filters() as $filter) {
            $aliases = array_map('strtolower', self::filter_aliases($filter->get_unique_identifier()));
            if (!in_array($needle, $aliases, true)) {
                continue;
            }
            if (!is_a($filter->get_filter_class(), select::class, true)) {
                return [];
            }
            $options = (array)$filter->get_options();
            // Flatten grouped (selectgroups-style) options.
            if (count($options) !== count($options, COUNT_RECURSIVE)) {
                $options = array_merge(...array_values($options));
            }
            return $options;
        }
        return [];
    }

    /**
     * Distinct formatted values of one report column, capped.
     *
     * Formatted cell output may contain markup (e.g. linked names); tags are
     * stripped so the option value matches what a text filter compares against.
     *
     * @param int $reportid
     * @param string $field Logical field name.
     * @return string[]
     */
    private function distinct_column_values(int $reportid, string $field): array {
        $rows = (new reporthandler($reportid))->return_data();
        if (empty($rows)) {
            return [];
        }

        $rowkey = reporthandler::resolve_field_name($reportid, $field, (array)reset($rows));
        $values = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            $value = trim(strip_tags((string)($row[$rowkey] ?? '')));
            if ($value === '' || isset($values[$value])) {
                continue;
            }
            $values[$value] = $value;
            if (count($values) >= self::MAX_DYNAMIC_OPTIONS) {
                break;
            }
        }
        return array_values($values);
    }

    #[\Override]
    public function require_access(array $sourceparams): void {
        foreach ($this->report_ids($sourceparams) as $reportid) {
            $report = manager::get_report_from_id($reportid);
            permission::require_can_view_report($report->get_report_persistent());
        }
    }

    #[\Override]
    public function fetch(array $sourceparams, array $constraints): chart_data {
        return shaper::shape($this, $sourceparams, $constraints);
    }

    /**
     * Run a report with the recognized constraints applied as report-native
     * filter values, restoring the user's prior filter state afterwards.
     *
     * @param int|string $datasetid Report id.
     * @param filter_constraint[] $constraints
     * @return array Formatted report rows.
     */
    #[\Override]
    public function load_rows(int|string $datasetid, array $constraints): array {
        $reportid = (int)$datasetid;
        $report = manager::get_report_from_id($reportid);
        $filtervalues = $this->build_filter_values($report, $constraints);

        if (empty($filtervalues)) {
            return (new reporthandler($reportid))->return_data();
        }

        // Apply our values on top of the user's current ones, query, then restore.
        $previous = $report->get_filter_values();
        try {
            $report->set_filter_values(array_merge($previous, $filtervalues));
            return (new reporthandler($reportid))->return_data();
        } finally {
            $report->set_filter_values($previous);
        }
    }

    /**
     * Resolve a logical field name to the report's row key.
     *
     * @param int|string $datasetid Report id.
     * @param string $field Logical field name.
     * @param array $firstrow A sample row.
     * @return string
     */
    #[\Override]
    public function resolve_field(int|string $datasetid, string $field, array $firstrow): string {
        return reporthandler::resolve_field_name((int)$datasetid, $field, $firstrow);
    }

    /**
     * The report's name, formatted for output.
     *
     * @param int|string $datasetid Report id.
     * @return string
     */
    #[\Override]
    public function get_dataset_label(int|string $datasetid): string {
        $report = manager::get_report_from_id((int)$datasetid);
        return format_string($report->get_report_persistent()->get('name'));
    }

    /**
     * The report ids referenced by the given params.
     *
     * @param array $params
     * @return int[]
     */
    private function report_ids(array $params): array {
        $ids = [];
        foreach (['idbase', 'idtotal', 'report'] as $key) {
            if (!empty($params[$key])) {
                $ids[(int)$params[$key]] = (int)$params[$key];
            }
        }
        foreach (shaper::parse_id_list((string)($params['reports'] ?? '')) as $id) {
            $ids[$id] = $id;
        }
        return array_values($ids);
    }

    /**
     * Translate constraints into the report's filter-form value array.
     *
     * @param \core_reportbuilder\datasource $report
     * @param filter_constraint[] $constraints
     * @return array
     */
    private function build_filter_values($report, array $constraints): array {
        if (empty($constraints)) {
            return [];
        }

        // Index the report's active filters by every alias they answer to
        // (full identifier, short name, custom/profile field shortname).
        $byidentifier = [];
        foreach ($report->get_active_filters() as $filter) {
            foreach (self::filter_aliases($filter->get_unique_identifier()) as $alias) {
                $byidentifier[strtolower($alias)] = $filter;
            }
        }

        $values = [];
        foreach ($constraints as $constraint) {
            $filter = $byidentifier[strtolower($constraint->key)] ?? null;
            if ($filter === null) {
                continue; // This report does not map that key: ignore it.
            }
            $values += $this->constraint_to_filter_values($filter, $constraint);
        }
        return $values;
    }

    /**
     * Build the filter-form values for one constraint, branching on filter type.
     *
     * @param \core_reportbuilder\local\report\filter $filter
     * @param filter_constraint $constraint
     * @return array
     */
    private function constraint_to_filter_values($filter, filter_constraint $constraint): array {
        $name = $filter->get_unique_identifier();
        $class = $filter->get_filter_class();
        $value = $constraint->value;

        if (is_a($class, select::class, true)) {
            // Core's select filter silently applies NO filter when the value is
            // not a valid option. For a locked (server-forced) constraint that
            // would fail open, so a mismatch must abort instead.
            if ($constraint->locked && !$this->is_valid_select_option($filter, (string)$value)) {
                throw new \moodle_exception('error:lockedfilterinvalidvalue', 'local_wb_dashboard', '', s($constraint->key));
            }
            return [
                "{$name}_operator" => select::EQUAL_TO,
                "{$name}_value" => $value,
            ];
        }

        if (is_a($class, text::class, true)) {
            // Text comparisons are applied verbatim, no option check needed.
            $operator = $constraint->operator === filter_constraint::OP_EQUAL ? text::IS_EQUAL_TO : text::CONTAINS;
            return [
                "{$name}_operator" => $operator,
                "{$name}_value" => (string)$value,
            ];
        }

        if (is_a($class, number::class, true)) {
            switch ($constraint->operator) {
                case filter_constraint::OP_GREATER_EQUAL:
                    return ["{$name}_operator" => number::EQUAL_OR_GREATER_THAN, "{$name}_value1" => (float)$value];
                case filter_constraint::OP_LESS_EQUAL:
                    return ["{$name}_operator" => number::EQUAL_OR_LESS_THAN, "{$name}_value1" => (float)$value];
                case filter_constraint::OP_BETWEEN:
                    [$min, $max] = is_array($value) ? array_values($value) : [$value, $value];
                    return [
                        "{$name}_operator" => number::RANGE,
                        "{$name}_value1" => (float)$min,
                        "{$name}_value2" => (float)$max,
                    ];
                default:
                    return ["{$name}_operator" => number::EQUAL_TO, "{$name}_value1" => (float)$value];
            }
        }

        if (is_a($class, date::class, true)) {
            [$from, $to] = is_array($value) ? array_values($value) : [$value, 0];
            return [
                "{$name}_operator" => date::DATE_RANGE,
                "{$name}_from" => (int)$from,
                "{$name}_to" => (int)$to,
            ];
        }

        return [];
    }

    /**
     * Whether the value is one of the select filter's declared options.
     *
     * @param \core_reportbuilder\local\report\filter $filter
     * @param string $value
     * @return bool
     */
    private function is_valid_select_option($filter, string $value): bool {
        $options = (array)$filter->get_options();
        if (count($options) !== count($options, COUNT_RECURSIVE)) {
            $options = array_merge(...array_values($options));
        }
        return array_key_exists($value, $options);
    }
}
