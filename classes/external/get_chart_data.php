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

namespace local_wb_dashboard\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_wb_dashboard\local\chart\chart_director;
use local_wb_dashboard\local\settings\chart_settings;
use local_wb_dashboard\local\source\pipeline;

/**
 * Return the fully-built chart configuration for a chart definition.
 *
 * The return is a JSON string ("payload"): the DTO/config varies by source and
 * type and carries free-form metadata, which does not fit Report Builder's
 * strict external typing. The payload is fully sanitized in PHP before encoding
 * (numbers cast to float, all strings passed through format_string upstream in
 * the source/builder), so PARAM_RAW is safe here — the client must still set any
 * text via textContent, never innerHTML.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_chart_data extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'source' => new external_value(PARAM_ALPHANUMEXT, 'Source name'),
            'type' => new external_value(PARAM_ALPHA, 'Semantic chart type'),
            'sourceparams' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_ALPHANUMEXT, 'Param name'),
                    'value' => new external_value(PARAM_RAW, 'Param value'),
                ]),
                'Source-specific parameters',
                VALUE_DEFAULT,
                []
            ),
            'filtervalues' => new external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_ALPHANUMEXT, 'Logical filter key'),
                    'type' => new external_value(PARAM_ALPHA, 'Filter type'),
                    'value' => new external_value(PARAM_RAW, 'Submitted value'),
                ]),
                'Current page filter values',
                VALUE_DEFAULT,
                []
            ),
            'chartid' => new external_value(PARAM_ALPHANUMEXT, 'Stable chart id for per-chart settings', VALUE_DEFAULT, ''),
            'title' => new external_value(PARAM_TEXT, 'Chart title', VALUE_DEFAULT, ''),
            'centertext' => new external_value(PARAM_BOOL, 'Show doughnut centre text', VALUE_DEFAULT, true),
            'target' => new external_value(PARAM_FLOAT, 'Manual value-axis maximum (0 = automatic)', VALUE_DEFAULT, 0.0),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $source
     * @param string $type
     * @param array $sourceparams
     * @param array $filtervalues
     * @param string $chartid
     * @param string $title
     * @param bool $centertext
     * @param float $target
     * @return array
     */
    public static function execute(
        string $source,
        string $type,
        array $sourceparams = [],
        array $filtervalues = [],
        string $chartid = '',
        string $title = '',
        bool $centertext = true,
        float $target = 0.0
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'source' => $source,
            'type' => $type,
            'sourceparams' => $sourceparams,
            'filtervalues' => $filtervalues,
            'chartid' => $chartid,
            'title' => $title,
            'centertext' => $centertext,
            'target' => $target,
        ]);

        require_login();
        self::validate_context(context_system::instance());

        // Fetch normalized data (source resolve + authz + filters), then build
        // the FULL chart config.
        $dto = pipeline::fetch($params['source'], $params['sourceparams'], $params['filtervalues']);

        // Per-chart stored overrides (set via the settings gear) merged over the
        // active palette; unset slots follow the palette.
        $colors = chart_settings::resolve($params['chartid']);

        $config = (new chart_director())->build($params['type'], $dto, [
            'colors' => $colors,
            'title' => $params['title'],
            'centertext' => $params['centertext'],
            'target' => $params['target'],
        ]);

        return ['payload' => json_encode($config->jsonSerialize())];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'payload' => new external_value(PARAM_RAW, 'JSON-encoded, sanitized Chart.js config'),
        ]);
    }
}
