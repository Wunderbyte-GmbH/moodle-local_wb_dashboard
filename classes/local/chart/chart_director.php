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

namespace local_wb_dashboard\local\chart;

use local_wb_dashboard\local\dto\chart_data;
use moodle_exception;

/**
 * Selects and drives the right concrete builder for a semantic chart type,
 * returning the complete chart_config. This is the single entry point the
 * web service calls to turn a DTO into a client-ready chart.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chart_director {
    /**
     * Build the full chart config for a semantic type.
     *
     * @param string $type One of chart_type::* .
     * @param chart_data $dto The normalized data.
     * @param array $displayopts Display options (colors, title, ...).
     * @return chart_config
     * @throws moodle_exception If the type is not supported.
     */
    public function build(string $type, chart_data $dto, array $displayopts = []): chart_config {
        if (!chart_type::is_valid($type)) {
            throw new moodle_exception('error:unknowncharttype', 'local_wb_dashboard', '', $type);
        }

        // Honour an explicit request to hide the doughnut centre text.
        if (array_key_exists('centertext', $displayopts) && !$displayopts['centertext']) {
            unset($dto->meta['centertext']);
        }

        // A manual target overrides any axis maximum derived by the shaping, so
        // e.g. a progress bar fills against a configured goal instead of its own
        // total. Builders without a value axis (doughnut) ignore it.
        if (!empty($displayopts['target']) && (float)$displayopts['target'] > 0) {
            $dto->set_meta('axismax', (float)$displayopts['target']);
        }

        $builder = $this->make_builder($type);
        $builder->set_data($dto)
            ->set_colors($displayopts['colors'] ?? [])
            ->set_title($displayopts['title'] ?? null);

        return $builder->build();
    }

    /**
     * Instantiate and preconfigure the concrete builder for a semantic type.
     *
     * @param string $type
     * @return chart_builder
     */
    private function make_builder(string $type): chart_builder {
        switch ($type) {
            case chart_type::DOUGHNUT:
                return new doughnut_chart_builder();

            case chart_type::HORIZONTALBAR:
                return (new bar_chart_builder())->set_horizontal(true);

            case chart_type::STACKEDBAR:
                return (new bar_chart_builder())->set_stacked(true);

            case chart_type::PROGRESS:
                return (new bar_chart_builder())->set_horizontal(true)->set_stacked(true)->set_progress(true);

            case chart_type::BAR:
            default:
                return new bar_chart_builder();
        }
    }
}
