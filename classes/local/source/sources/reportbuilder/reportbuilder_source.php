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
use local_wb_dashboard\local\dto\chart_series;
use local_wb_dashboard\local\dto\filter_constraint;
use local_wb_dashboard\local\source\source_interface;
use local_wb_dashboard\local\source\sources\reportbuilder\shaping\report_totals_shaping;
use local_wb_dashboard\local\source\sources\reportbuilder\shaping\rows_shaping;
use local_wb_dashboard\local\source\sources\reportbuilder\shaping\shaping_strategy;
use local_wb_dashboard\local\source\sources\reportbuilder\shaping\two_report_delta_shaping;
use moodle_exception;

/**
 * Report Builder data source.
 *
 * Three shaping modes, each a {@see shaping_strategy} that declares which params
 * it handles and delegates to the matching shaping method here:
 *  - Multi-report totals (reports): one data point per report (its row count or a
 *    summed value field), suited to comparing reports side by side.
 *  - Two-report delta (idbase/fieldbase + idtotal/fieldtotal): a single value and
 *    its remainder, suited to doughnut/progress ("logged vs remaining").
 *  - Rows (report/categoryfield/valuefield [+ stackfield]): one data point per row,
 *    optionally grouped into stacks, suited to bar/stacked/horizontal charts.
 *
 * fetch() picks the first strategy whose supports() matches, replacing an
 * if/if/if chain so new shaping modes are added as a strategy, not a branch.
 *
 * Filters are applied the report-native way: constraints are translated into the
 * report's own filter values (via Report Builder's user_filter_manager), applied
 * around the query and then restored so no state leaks into the report UI.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reportbuilder_source implements source_interface {
    /**
     * Shaping strategies in priority order: the first whose supports() matches wins.
     *
     * @var class-string<shaping_strategy>[]
     */
    private const SHAPING_STRATEGIES = [
        report_totals_shaping::class,
        two_report_delta_shaping::class,
        rows_shaping::class,
    ];

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
                $uid = $filter->get_unique_identifier();
                $keys[$uid] = $uid;
                // Also expose the short name after the entity prefix as a friendly key.
                if (($pos = strpos($uid, ':')) !== false) {
                    $short = substr($uid, $pos + 1);
                    $keys[$short] = $short;
                }
            }
        }
        return array_values($keys);
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
        foreach (self::SHAPING_STRATEGIES as $strategyclass) {
            /** @var shaping_strategy $strategy */
            $strategy = new $strategyclass();
            if ($strategy->supports($sourceparams)) {
                return $strategy->shape($this, $sourceparams, $constraints);
            }
        }
        throw new moodle_exception('error:invalidreportid', 'local_wb_dashboard');
    }

    /**
     * Multi-report totals: one data point per report (its row count, or the sum
     * of a value field across its rows). Labels are the report names.
     *
     * @param array $params
     * @param filter_constraint[] $constraints
     * @return chart_data
     */
    public function fetch_report_totals(array $params, array $constraints): chart_data {
        $reportids = $this->parse_report_list((string)$params['reports']);
        if (empty($reportids)) {
            throw new moodle_exception('error:invalidreportid', 'local_wb_dashboard');
        }
        $valuefield = isset($params['valuefield']) ? (string)$params['valuefield'] : '';
        $iscount = $valuefield === '' || strtolower((string)($params['aggregation'] ?? 'count')) === 'count';

        $labels = [];
        $values = [];
        foreach ($reportids as $reportid) {
            $rows = $this->query_with_constraints($reportid, $constraints);
            if ($iscount) {
                $values[] = (float)count($rows);
            } else {
                $sum = 0.0;
                $valkey = !empty($rows) ? reporthandler::resolve_field_name($reportid, $valuefield, $rows[0]) : '';
                foreach ($rows as $row) {
                    $sum += $this->to_float($row[$valkey] ?? 0);
                }
                $values[] = $sum;
            }
            $report = manager::get_report_from_id($reportid);
            $labels[] = format_string($report->get_report_persistent()->get('name'));
        }

        $data = new chart_data();
        $data->set_labels($labels);
        $data->add_series(new chart_series(get_string('label:count', 'local_wb_dashboard'), $values));
        return $data;
    }

    /**
     * Two-report delta shaping (logged vs remaining).
     *
     * @param array $params
     * @param filter_constraint[] $constraints
     * @return chart_data
     */
    public function fetch_two_report_delta(array $params, array $constraints): chart_data {
        $base = $this->extract_value((int)$params['idbase'], (string)$params['fieldbase'], $constraints);
        $total = $this->extract_value((int)$params['idtotal'], (string)$params['fieldtotal'], $constraints);
        $remaining = max(0.0, $total - $base);

        $data = new chart_data();
        $data->set_labels([
            get_string('label:logged', 'local_wb_dashboard'),
            get_string('label:remaining', 'local_wb_dashboard'),
        ]);
        $data->add_series(new chart_series('', [$base, $remaining]));
        // Metadata that lets the same DTO render as a doughnut (centre text) or a
        // progress bar (fixed axis maximum = total).
        $data->set_meta('centertext', $base);
        $data->set_meta('axismax', $total);
        return $data;
    }

    /**
     * Rows shaping: one point per category, optionally grouped into stacks.
     *
     * @param array $params
     * @param filter_constraint[] $constraints
     * @return chart_data
     */
    public function fetch_rows(array $params, array $constraints): chart_data {
        $reportid = (int)$params['report'];
        $categoryfield = (string)$params['categoryfield'];
        $valuefield = isset($params['valuefield']) ? (string)$params['valuefield'] : '';
        $stackfield = isset($params['stackfield']) ? (string)$params['stackfield'] : '';
        // The "count" option tallies one per row; "sum" (default) adds the value field.
        $iscount = strtolower((string)($params['aggregation'] ?? 'sum')) === 'count';

        $rows = $this->query_with_constraints($reportid, $constraints);
        if (empty($rows)) {
            throw new moodle_exception('error:noreportdata', 'local_wb_dashboard');
        }

        $catkey = reporthandler::resolve_field_name($reportid, $categoryfield, $rows[0]);
        $valkey = (!$iscount && $valuefield !== '')
            ? reporthandler::resolve_field_name($reportid, $valuefield, $rows[0]) : '';
        $stackkey = $stackfield !== '' ? reporthandler::resolve_field_name($reportid, $stackfield, $rows[0]) : '';

        // Each row contributes 1 (count) or its numeric value field (sum).
        $contribution = function (array $row) use ($iscount, $valkey): float {
            return $iscount ? 1.0 : $this->to_float($row[$valkey] ?? 0);
        };
        $serieslabel = $iscount
            ? get_string('label:count', 'local_wb_dashboard')
            : format_string($valuefield);

        // Preserve first-seen category order.
        $categories = [];
        foreach ($rows as $row) {
            $cat = (string)($row[$catkey] ?? '');
            $categories[$cat] = true;
        }
        $categories = array_keys($categories);
        $catindex = array_flip($categories);

        $data = new chart_data();
        $data->set_labels(array_map('format_string', $categories));

        if ($stackkey === '') {
            // Single series.
            $values = array_fill(0, count($categories), 0.0);
            foreach ($rows as $row) {
                $cat = (string)($row[$catkey] ?? '');
                $values[$catindex[$cat]] += $contribution($row);
            }
            $data->add_series(new chart_series($serieslabel, $values));
        } else {
            // One series per distinct stack value.
            $stacks = [];
            foreach ($rows as $row) {
                $stack = (string)($row[$stackkey] ?? '');
                if (!isset($stacks[$stack])) {
                    $stacks[$stack] = array_fill(0, count($categories), 0.0);
                }
                $cat = (string)($row[$catkey] ?? '');
                $stacks[$stack][$catindex[$cat]] += $contribution($row);
            }
            foreach ($stacks as $stacklabel => $values) {
                $data->add_series(new chart_series(format_string($stacklabel), $values, [], null, 'group'));
            }
            $data->set_meta('stacked', true);
        }

        return $data;
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
        foreach ($this->parse_report_list($params['reports'] ?? '') as $id) {
            $ids[$id] = $id;
        }
        return array_values($ids);
    }

    /**
     * Parse a comma-separated list of report ids.
     *
     * @param string $raw
     * @return int[]
     */
    private function parse_report_list(string $raw): array {
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = (int)trim($part);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /**
     * Extract a single numeric value from the first row of a report.
     *
     * @param int $reportid
     * @param string $field
     * @param filter_constraint[] $constraints
     * @return float
     */
    private function extract_value(int $reportid, string $field, array $constraints): float {
        $rows = $this->query_with_constraints($reportid, $constraints);
        if (empty($rows)) {
            throw new moodle_exception('error:noreportdata', 'local_wb_dashboard');
        }
        $row = $rows[0];
        $resolved = reporthandler::resolve_field_name($reportid, $field, $row);
        return $this->to_float($row[$resolved] ?? 0);
    }

    /**
     * Run a report with the recognized constraints applied as report-native
     * filter values, restoring the user's prior filter state afterwards.
     *
     * @param int $reportid
     * @param filter_constraint[] $constraints
     * @return array Formatted report rows.
     */
    private function query_with_constraints(int $reportid, array $constraints): array {
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

        // Index the report's active filters by both full identifier and short name.
        $byidentifier = [];
        foreach ($report->get_active_filters() as $filter) {
            $uid = $filter->get_unique_identifier();
            $byidentifier[strtolower($uid)] = $filter;
            if (($pos = strpos($uid, ':')) !== false) {
                $byidentifier[strtolower(substr($uid, $pos + 1))] = $filter;
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
            return [
                "{$name}_operator" => select::EQUAL_TO,
                "{$name}_value" => $value,
            ];
        }

        if (is_a($class, text::class, true)) {
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
     * Coerce a report cell to float.
     *
     * @param mixed $value
     * @return float
     */
    private function to_float($value): float {
        if (is_numeric($value)) {
            return (float)$value;
        }
        // Strip common formatting (thousands separators, stray markup) then retry.
        $clean = preg_replace('/[^0-9,.\-]/', '', (string)$value);
        $clean = str_replace(',', '', $clean);
        return is_numeric($clean) ? (float)$clean : 0.0;
    }
}
