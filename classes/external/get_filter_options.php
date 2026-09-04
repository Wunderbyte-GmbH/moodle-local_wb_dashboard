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
use local_wb_dashboard\local\filter\dynamic_options;
use local_wb_dashboard\local\source\grouped_option_provider_interface;
use local_wb_dashboard\local\source\pipeline;
use local_wb_dashboard\local\source\source_registry;

/**
 * Return the dynamic options of a select filter, scoped by the current page
 * filter values — the server half of a cascading select.
 *
 * Runs the same resolution, allowlisting, access check and locked-filter
 * translation as the chart pipeline, then the same cached option lookup the
 * server-side render uses. Strings are formatted server-side; the client must
 * write them via textContent, never innerHTML.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_filter_options extends external_api {
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
            'field' => new external_value(PARAM_RAW_TRIMMED, 'Logical field the option values come from'),
            'groupfield' => new external_value(
                PARAM_RAW_TRIMMED,
                'Logical field grouping the options (grouped select)',
                VALUE_DEFAULT,
                ''
            ),
            'filtervalues' => new external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_ALPHANUMEXT, 'Logical filter key'),
                    'type' => new external_value(PARAM_ALPHA, 'Filter type'),
                    'value' => new external_value(PARAM_RAW, 'Submitted value'),
                ]),
                'Page filter values scoping the options',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $source
     * @param array $sourceparams
     * @param string $field
     * @param string $groupfield
     * @param array $filtervalues
     * @return array
     */
    public static function execute(
        string $source,
        array $sourceparams = [],
        string $field = '',
        string $groupfield = '',
        array $filtervalues = []
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'source' => $source,
            'sourceparams' => $sourceparams,
            'field' => $field,
            'groupfield' => $groupfield,
            'filtervalues' => $filtervalues,
        ]);

        require_login();
        self::validate_context(context_system::instance());

        // Same pipeline as charts: resolve, allowlist, authorize, constrain.
        $source = source_registry::get($params['source']);
        $cleanparams = pipeline::allowlist_params($source, $params['sourceparams']);
        $source->require_access($cleanparams);
        $constraints = pipeline::build_constraints($source, $cleanparams, $params['filtervalues']);

        $options = [];
        $groups = [];
        if ($params['groupfield'] !== '' && $source instanceof grouped_option_provider_interface) {
            $grouped = dynamic_options::groups(
                $params['source'],
                $source,
                $cleanparams,
                $params['groupfield'],
                $params['field'],
                '',
                $constraints
            );
            foreach ($grouped as $group) {
                $groups[] = [
                    'label' => (string)$group['group'],
                    'options' => self::export_options($group['options']),
                ];
            }
        } else {
            $options = self::export_options(
                dynamic_options::options($params['source'], $source, $cleanparams, $params['field'], $constraints)
            );
        }

        return ['options' => $options, 'groups' => $groups];
    }

    /**
     * Cast an option list to the strict WS shape.
     *
     * @param array $options
     * @return array<int, array{value: string, label: string}>
     */
    private static function export_options(array $options): array {
        return array_map(static fn(array $opt): array => [
            'value' => (string)$opt['value'],
            'label' => (string)$opt['label'],
        ], $options);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $option = new external_single_structure([
            'value' => new external_value(PARAM_RAW, 'Option value'),
            'label' => new external_value(PARAM_TEXT, 'Option label'),
        ]);
        return new external_single_structure([
            'options' => new external_multiple_structure($option, 'Flat options (empty for a grouped lookup)'),
            'groups' => new external_multiple_structure(
                new external_single_structure([
                    'label' => new external_value(PARAM_TEXT, 'Group label'),
                    'options' => new external_multiple_structure($option, 'Options in the group'),
                ]),
                'Grouped options (empty for a flat lookup)'
            ),
        ]);
    }
}
