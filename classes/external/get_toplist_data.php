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
use local_wb_dashboard\local\source\pipeline;
use local_wb_dashboard\local\toplist\toplist_reducer;
use moodle_exception;

/**
 * Return the ranked rows for a top-N list, ready to write straight into the
 * server-rendered row slots.
 *
 * Like the digits web service the shape is fixed, so it uses strict external
 * typing. Strings are formatted server-side; the client must still set text
 * via textContent, never innerHTML. A report that ran but matched no rows is
 * a legitimate empty list, not an error.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_toplist_data extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'source' => new external_value(PARAM_ALPHANUMEXT, 'Source name'),
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
            'top' => new external_value(PARAM_INT, 'Number of rows to keep', VALUE_DEFAULT, 5),
            'order' => new external_value(PARAM_ALPHA, 'desc = top N, asc = bottom N', VALUE_DEFAULT, 'desc'),
            'barmode' => new external_value(PARAM_ALPHA, 'Bar mode: percentfield|totalfield|max|relative',
                VALUE_DEFAULT, 'relative'),
            'max' => new external_value(PARAM_FLOAT, 'Fixed bar maximum (barmode "max")', VALUE_DEFAULT, 0),
            'decimals' => new external_value(PARAM_INT, 'Decimal places (0-6) for the value', VALUE_DEFAULT, 0),
            'suffix' => new external_value(PARAM_TEXT, 'Suffix appended to the value (e.g. "/5", "%")', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $source
     * @param array $sourceparams
     * @param array $filtervalues
     * @param int $top
     * @param string $order
     * @param string $barmode
     * @param float $max
     * @param int $decimals
     * @param string $suffix
     * @return array
     */
    public static function execute(
        string $source,
        array $sourceparams = [],
        array $filtervalues = [],
        int $top = 5,
        string $order = 'desc',
        string $barmode = 'relative',
        float $max = 0.0,
        int $decimals = 0,
        string $suffix = ''
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'source' => $source,
            'sourceparams' => $sourceparams,
            'filtervalues' => $filtervalues,
            'top' => $top,
            'order' => $order,
            'barmode' => $barmode,
            'max' => $max,
            'decimals' => $decimals,
            'suffix' => $suffix,
        ]);

        require_login();
        self::validate_context(context_system::instance());

        if (!toplist_reducer::is_valid_barmode($params['barmode'])) {
            throw new moodle_exception('error:unknownbarmode', 'local_wb_dashboard', '', $params['barmode']);
        }
        $top = max(1, min(\local_wb_dashboard\local\definition\toplist_definition::MAX_TOP, $params['top']));

        // Same server pipeline as charts, then rank and slice. A report that
        // matched no rows is a legitimate empty list, not an error.
        try {
            $dto = pipeline::fetch($params['source'], $params['sourceparams'], $params['filtervalues']);
            $ranked = toplist_reducer::reduce($dto, $top, $params['order'], $params['barmode'], (float)$params['max']);
        } catch (moodle_exception $e) {
            if ($e->errorcode !== 'error:noreportdata') {
                throw $e;
            }
            $ranked = [];
        }

        $decimals = max(0, min(6, $params['decimals']));
        $rows = [];
        foreach ($ranked as $i => $row) {
            $rows[] = [
                'rank' => $i + 1,
                'label' => $row['label'],
                'formatted' => format_float($row['value'], $decimals) . $params['suffix'],
                'percent' => $row['percent'],
            ];
        }
        return ['rows' => $rows];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'rows' => new external_multiple_structure(
                new external_single_structure([
                    'rank' => new external_value(PARAM_INT, 'Rank, 1-based'),
                    'label' => new external_value(PARAM_TEXT, 'Row label'),
                    'formatted' => new external_value(PARAM_TEXT, 'Locale-formatted value with suffix'),
                    'percent' => new external_value(PARAM_FLOAT, 'Bar fill percentage (0-100)'),
                ]),
                'Ranked rows, best first'
            ),
        ]);
    }
}
