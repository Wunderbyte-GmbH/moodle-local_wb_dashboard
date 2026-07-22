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
 * Two-dataset delta: a single value and its remainder (logged vs remaining).
 *
 * Suited to doughnut/progress charts; sets the centertext/axismax meta so the
 * same DTO renders as a doughnut (centre text) or a progress bar (fixed axis max).
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class two_report_delta_shaping implements shaping_strategy {
    /**
     * Applies when a base and a total dataset id are given.
     *
     * @param array $params Source params.
     * @return bool
     */
    #[\Override]
    public function supports(array $params): bool {
        return !empty($params['idbase']) && !empty($params['idtotal']);
    }

    /**
     * A single value (base) and its remainder against a total.
     *
     * @param shapable_source $source Data access into the source.
     * @param array $params Source params.
     * @param filter_constraint[] $constraints
     * @return chart_data
     */
    #[\Override]
    public function shape(shapable_source $source, array $params, array $constraints): chart_data {
        foreach (['fieldbase', 'fieldtotal'] as $required) {
            if (empty($params[$required])) {
                throw new moodle_exception('error:missingparam', 'local_wb_dashboard', '', $required);
            }
        }

        $base = $this->extract_value($source, (int)$params['idbase'], (string)$params['fieldbase'], $constraints);
        $total = $this->extract_value($source, (int)$params['idtotal'], (string)$params['fieldtotal'], $constraints);
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
     * Extract a single numeric value from the first row of a dataset.
     *
     * @param shapable_source $source Data access into the source.
     * @param int $datasetid
     * @param string $field
     * @param filter_constraint[] $constraints
     * @return float
     */
    private function extract_value(shapable_source $source, int $datasetid, string $field, array $constraints): float {
        $rows = $source->load_rows($datasetid, $constraints);
        if (empty($rows)) {
            // A report that ran but matched no rows (e.g. filtered to empty) is
            // a legitimate zero for a single-value extraction, not an error.
            return 0.0;
        }
        $row = $rows[0];
        $resolved = $source->resolve_field($datasetid, $field, $row);
        return shaper::to_float($row[$resolved] ?? 0);
    }
}
