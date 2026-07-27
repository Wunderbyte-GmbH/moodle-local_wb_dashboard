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

namespace local_wb_dashboard\local\dto;

/**
 * The normalized chart-data DTO.
 *
 * One shape that expresses both a single-value doughnut and a multi-series
 * bar/line chart, decoupled from any source's native row shape. Different
 * sources shape their data into this; the chart builder consumes only this.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chart_data implements \JsonSerializable {
    /** Allowed keys in $meta. Anything else is dropped by set_meta(). */
    private const ALLOWED_META = ['title', 'centertext', 'unit', 'axistitles', 'stacked', 'indexaxis', 'axismax', 'rowids'];

    /** @var string[] Category / axis labels. */
    public array $labels = [];

    /** @var chart_series[] */
    public array $series = [];

    /** @var array Constrained free-form metadata (see ALLOWED_META). */
    public array $meta = [];

    /**
     * Add a series.
     *
     * @param chart_series $series
     * @return self
     */
    public function add_series(chart_series $series): self {
        $this->series[] = $series;
        return $this;
    }

    /**
     * Set the category/axis labels.
     *
     * @param string[] $labels
     * @return self
     */
    public function set_labels(array $labels): self {
        $this->labels = array_values($labels);
        return $this;
    }

    /**
     * Set a metadata value, ignoring keys outside the allowlist.
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function set_meta(string $key, $value): self {
        if (in_array($key, self::ALLOWED_META, true)) {
            $this->meta[$key] = $value;
        }
        return $this;
    }

    #[\Override]
    public function jsonSerialize(): array {
        return [
            'labels' => $this->labels,
            'series' => array_map(static function (chart_series $s): array {
                return [
                    'label' => $s->label,
                    'data' => $s->data,
                    'colors' => $s->colors,
                    'type' => $s->type,
                    'stack' => $s->stack,
                ];
            }, $this->series),
            'meta' => $this->meta,
        ];
    }
}
