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

namespace local_wb_dashboard\local\filter;

use local_wb_dashboard\local\dto\filter_constraint;
use renderer_base;

/**
 * A clickable map filter. Renders the regions of Italy as an interactive SVG;
 * clicking a region sets the filter value to that region and re-queries the
 * page's charts. A single region is active at a time; clicking it again clears
 * the filter.
 *
 * The emitted value is the region's identifier as stored in the data source
 * (uppercase Italian region name, e.g. "LAZIO"). The static region geometry and
 * the id remap live in data/italy_regions.json — edit the "value" there if your
 * dataset stores the region under a different string.
 *
 * The map can stand alone or share its key with any other filter control —
 * e.g. [chartfilter key=region type=select ...] plus [chartfilter key=region
 * type=map] anywhere else on the page act as one "region" filter, and the
 * filter bus keeps all controls of a key in sync in both directions.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class map_filter extends base_filter {
    /** @var array|null Lazily loaded region geometry ({viewbox, regions}). */
    private static ?array $mapdata = null;

    #[\Override]
    public function get_type(): string {
        return 'map';
    }

    #[\Override]
    public function export_for_template(renderer_base $output): array {
        $context = parent::export_for_template($output);
        $mapdata = self::mapdata();
        $default = $this->get_default();

        $context['viewbox'] = $mapdata['viewbox'];
        $context['regions'] = array_map(static function (array $region) use ($default): array {
            return [
                'id' => $region['id'],
                'value' => $region['value'],
                'name' => $region['name'],
                'd' => $region['d'],
                'selected' => ((string)$region['value'] === $default),
            ];
        }, $mapdata['regions']);

        return $context;
    }

    #[\Override]
    public function normalize_value($raw) {
        return clean_param((string)$raw, PARAM_RAW_TRIMMED);
    }

    #[\Override]
    public function to_constraint($value): filter_constraint {
        return new filter_constraint($this->key, filter_constraint::OP_EQUAL, $value);
    }

    /**
     * Load (and cache) the bundled region geometry.
     *
     * @return array{viewbox: string, regions: array<int, array{id: string, value: string, name: string, d: string}>}
     */
    private static function mapdata(): array {
        if (self::$mapdata === null) {
            $json = file_get_contents(__DIR__ . '/data/italy_regions.json');
            $decoded = $json !== false ? json_decode($json, true) : null;
            self::$mapdata = is_array($decoded) ? $decoded : ['viewbox' => '0 0 1000 1150', 'regions' => []];
        }
        return self::$mapdata;
    }
}
