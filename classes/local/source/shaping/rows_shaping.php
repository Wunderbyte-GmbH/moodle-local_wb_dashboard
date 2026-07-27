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

namespace local_wb_dashboard\local\source\shaping;

use local_wb_dashboard\local\dto\chart_data;
use local_wb_dashboard\local\dto\chart_series;
use local_wb_dashboard\local\dto\filter_constraint;
use local_wb_dashboard\local\source\shapable_source;
use moodle_exception;

/**
 * Rows: one data point per category, optionally grouped into stacks.
 *
 * Instead of a single "valuefield", a comma-separated "valuefields" list plots
 * one series per field (grouped bars). An additional "remainderof" field turns
 * that into a stacked part-of-whole bar: the listed fields are stacked and
 * topped up with a computed remainder segment (remainderof minus the listed
 * fields), so the total bar height equals the remainderof field — e.g.
 * valuefields=delivered remainderof=sent renders delivered as a subset of all
 * sent. Neither combines with "stackfield" or aggregation=count.
 *
 * Suited to bar/stacked/horizontal charts.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rows_shaping implements shaping_strategy {
    /**
     * Applies when a single dataset id and a category field are given.
     *
     * @param array $params Source params.
     * @return bool
     */
    #[\Override]
    public function supports(array $params): bool {
        // The "count" aggregation needs only a category; "sum" also needs a value field.
        $iscount = strtolower((string)($params['aggregation'] ?? '')) === 'count';
        return !empty($params['report']) && !empty($params['categoryfield'])
            && (!empty($params['valuefield']) || !empty($params['valuefields']) || $iscount);
    }

    /**
     * One point per category, optionally one series per distinct stack value.
     *
     * @param shapable_source $source Data access into the source.
     * @param array $params Source params.
     * @param filter_constraint[] $constraints
     * @return chart_data
     */
    #[\Override]
    public function shape(shapable_source $source, array $params, array $constraints): chart_data {
        $datasetid = (int)$params['report'];
        $categoryfield = (string)$params['categoryfield'];
        $valuefield = isset($params['valuefield']) ? (string)$params['valuefield'] : '';
        $stackfield = isset($params['stackfield']) ? (string)$params['stackfield'] : '';
        // The "count" option tallies one per row; "sum" (default) adds the value field.
        $iscount = strtolower((string)($params['aggregation'] ?? 'sum')) === 'count';
        // Optional top-N: keep only the N highest (or lowest) categories.
        $top = (int)($params['top'] ?? 0);
        $order = (string)($params['order'] ?? 'desc');

        // The "valuefields" list plots one series per field; "remainderof"
        // additionally stacks them against a computed remainder of that field.
        $valuefields = [];
        if (!empty($params['valuefields'])) {
            $valuefields = array_values(array_filter(array_map('trim', explode(',', (string)$params['valuefields']))));
        } else if ($valuefield !== '') {
            $valuefields = [$valuefield];
        }
        $remainderof = trim((string)($params['remainderof'] ?? ''));
        $multifield = count($valuefields) > 1 || $remainderof !== '';
        if ($multifield && ($iscount || $stackfield !== '' || empty($valuefields))) {
            throw new moodle_exception('error:invalidfieldcombination', 'local_wb_dashboard');
        }
        // A one-entry "valuefields" without remainder is just a value field.
        $valuefield = $valuefields[0] ?? $valuefield;

        $rows = $source->load_rows($datasetid, $constraints);
        if (empty($rows)) {
            throw new moodle_exception('error:noreportdata', 'local_wb_dashboard');
        }

        $catkey = $source->resolve_field($datasetid, $categoryfield, $rows[0]);
        $valkey = (!$iscount && $valuefield !== '')
            ? $source->resolve_field($datasetid, $valuefield, $rows[0]) : '';
        $stackkey = $stackfield !== '' ? $source->resolve_field($datasetid, $stackfield, $rows[0]) : '';

        // Each row contributes 1 (count) or its numeric value field (sum).
        $contribution = function (array $row) use ($iscount, $valkey): float {
            return $iscount ? 1.0 : shaper::to_float($row[$valkey] ?? 0);
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

        if ($multifield) {
            $this->add_field_series($data, $source, $datasetid, $rows, $catkey, $catindex, $valuefields, $remainderof,
                isset($params['remainderlabel']) ? (string)$params['remainderlabel'] : '');
            return $this->apply_topn($data, $top, $order);
        }

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

        return $this->apply_topn($data, $top, $order);
    }

    /**
     * Add one series per value field, optionally stacked under a computed
     * remainder segment of another field.
     *
     * With a remainder field, the listed series and the remainder are stacked,
     * so each category's total bar height equals the remainder field's sum —
     * the listed fields read as subsets of that whole. The remainder is
     * clamped at zero where the listed fields exceed it.
     *
     * @param chart_data $data Chart data with the category labels already set.
     * @param shapable_source $source Data access into the source.
     * @param int $datasetid
     * @param array $rows Loaded report rows.
     * @param string $catkey Resolved category row key.
     * @param array $catindex Category value => data index map.
     * @param string[] $valuefields The fields to plot, one series each.
     * @param string $remainderof Field supplying the stacked total ('' = none).
     * @param string $remainderlabel Label for the remainder segment ('' = default).
     */
    private function add_field_series(
        chart_data $data,
        shapable_source $source,
        int $datasetid,
        array $rows,
        string $catkey,
        array $catindex,
        array $valuefields,
        string $remainderof,
        string $remainderlabel
    ): void {
        $fieldkeys = [];
        foreach ($valuefields as $field) {
            $fieldkeys[$field] = $source->resolve_field($datasetid, $field, $rows[0]);
        }
        $remkey = $remainderof !== '' ? $source->resolve_field($datasetid, $remainderof, $rows[0]) : '';

        $categorycount = count($catindex);
        $totals = array_fill_keys($valuefields, array_fill(0, $categorycount, 0.0));
        $remtotals = array_fill(0, $categorycount, 0.0);
        foreach ($rows as $row) {
            $i = $catindex[(string)($row[$catkey] ?? '')];
            foreach ($fieldkeys as $field => $key) {
                $totals[$field][$i] += shaper::to_float($row[$key] ?? 0);
            }
            if ($remkey !== '') {
                $remtotals[$i] += shaper::to_float($row[$remkey] ?? 0);
            }
        }

        $stack = $remainderof !== '' ? 'group' : null;
        foreach ($totals as $field => $values) {
            $data->add_series(new chart_series(format_string((string)$field), $values, [], null, $stack));
        }
        if ($remainderof === '') {
            return;
        }

        $remainder = [];
        foreach ($remtotals as $i => $total) {
            $listed = 0.0;
            foreach ($totals as $values) {
                $listed += $values[$i];
            }
            $remainder[$i] = max(0.0, $total - $listed);
        }
        $label = trim($remainderlabel) !== ''
            ? format_string(trim($remainderlabel))
            : get_string('label:remaining', 'local_wb_dashboard');
        $data->add_series(new chart_series($label, $remainder, [], null, 'group'));
        $data->set_meta('stacked', true);
    }

    /**
     * Keep only the top (or bottom) N categories by total value.
     *
     * Ranks each category by the sum of its value across all series (its
     * stacked bar height), so it behaves identically for single and stacked
     * output, then reorders the labels and every series to that ranking and
     * slices to $top. Ties keep first-seen order.
     *
     * @param chart_data $data
     * @param int $top Positive category cap; 0 or less leaves the data untouched.
     * @param string $order 'asc' keeps the bottom N; anything else (default) the top N.
     * @return chart_data
     */
    private function apply_topn(chart_data $data, int $top, string $order): chart_data {
        if ($top <= 0 || count($data->labels) <= $top) {
            return $data;
        }

        // Rank each category by its total across all series (the stacked height).
        $totals = [];
        foreach (array_keys($data->labels) as $i) {
            $sum = 0.0;
            foreach ($data->series as $series) {
                $sum += $series->data[$i] ?? 0.0;
            }
            $totals[$i] = $sum;
        }

        // Order the category indexes, keeping first-seen order for ties.
        $asc = strtolower($order) === 'asc';
        $indexes = array_keys($totals);
        usort($indexes, function (int $a, int $b) use ($totals, $asc): int {
            $cmp = $totals[$a] <=> $totals[$b];
            $cmp = $asc ? $cmp : -$cmp;
            return $cmp !== 0 ? $cmp : $a <=> $b;
        });
        $keep = array_slice($indexes, 0, $top);

        // Remap the labels and every series onto the surviving order.
        $data->labels = array_values(array_map(fn(int $i): string => $data->labels[$i], $keep));
        foreach ($data->series as $series) {
            $series->data = array_values(array_map(fn(int $i): float => $series->data[$i] ?? 0.0, $keep));
        }
        return $data;
    }
}
