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
use local_wb_dashboard\local\source\grouped_option_provider_interface;
use renderer_base;

/**
 * A dropdown whose options are grouped by a second field (rendered as optgroups).
 *
 * Behaves exactly like {@see select_filter} downstream — it emits a single value
 * for its own key, so the whole filter bus / constraint pipeline is unchanged.
 * The grouping is purely presentational: e.g. ASL options shown under their
 * REGION. Options come dynamically from a {@see grouped_option_provider_interface}
 * source (optionsfield="asl" groupfield="region" source=...), with a static
 * "groups=..." string as the fallback.
 *
 * When dependson="region" is set and that key is locked for the viewer (see
 * {@see locked_filters}), the options are scoped to the locked group only — so a
 * regional manager frozen to their region sees just that region's ASLs, while an
 * unscoped admin sees every region's ASLs grouped. A single resulting group is
 * rendered flat (no optgroup wrapper).
 *
 * With cascadefrom="region" the control additionally follows the region filter
 * live on the client (see {@see select_filter}).
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grouped_select_filter extends base_filter {
    /** @var array|null|false Resolved dynamic source; false until first resolved. */
    private $resolved = false;

    #[\Override]
    public function get_type(): string {
        return 'groupedselect';
    }

    #[\Override]
    public function export_for_template(renderer_base $output): array {
        $context = parent::export_for_template($output);
        $default = $this->get_default();

        $groups = array_map(static function (array $group) use ($default): array {
            return [
                'label' => (string)$group['group'],
                'options' => array_map(static function (array $opt) use ($default): array {
                    return [
                        'value' => (string)$opt['value'],
                        'label' => (string)$opt['label'],
                        'selected' => ((string)$opt['value'] === $default),
                    ];
                }, $group['options']),
            ];
        }, $this->resolve_groups());

        // A single group (e.g. a viewer scoped to one region) renders flat: drop
        // the label so the template omits the optgroup wrapper.
        if (count($groups) === 1) {
            $groups[0]['label'] = '';
        }

        $context['groups'] = $groups;

        // What the client needs to re-fetch the options ('' = static only).
        $resolved = $this->dynamic_source();
        $context['optionsargs'] = $resolved === null
            ? ''
            : json_encode(dynamic_options::wsargs($resolved, $this->value_field(), $this->group_field()));
        $context['cascadefrom'] = $this->get_cascade_key();
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
     * The configured option value field ('' = static groups only).
     *
     * @return string
     */
    private function value_field(): string {
        return trim((string)($this->config['optionsfield'] ?? ''));
    }

    /**
     * The configured group field ('' = static groups only).
     *
     * @return string
     */
    private function group_field(): string {
        return trim((string)($this->config['groupfield'] ?? ''));
    }

    /**
     * Resolve (once) the grouped dynamic source, null when there is none usable.
     *
     * @return array|null
     */
    private function dynamic_source(): ?array {
        if ($this->resolved === false) {
            $this->resolved = ($this->value_field() === '' || $this->group_field() === '')
                ? null
                : dynamic_options::resolve_source($this->config, grouped_option_provider_interface::class);
        }
        return $this->resolved;
    }

    /**
     * The grouped option list: dynamic (optionsfield + groupfield) with static
     * fallback, scoped to a single group when the dependson key is locked.
     *
     * @return array<int, array{group: string, options: array<int, array{value: string, label: string}>}>
     */
    private function resolve_groups(): array {
        $groups = [];
        $resolved = $this->dynamic_source();
        if ($resolved !== null) {
            $constraints = [];
            if ($this->is_dependent()) {
                $constraints = dynamic_options::render_constraints($resolved['source'], $resolved['params']);
                if ($constraints === null) {
                    return [];
                }
            }
            $groups = dynamic_options::groups(
                $resolved['name'],
                $resolved['source'],
                $resolved['params'],
                $this->group_field(),
                $this->value_field(),
                $this->scope_value(),
                $constraints
            );
            if (empty($groups) && !empty($constraints)) {
                // Scoped and empty: an unscoped static list would be wrong.
                return [];
            }
        }
        if (empty($groups)) {
            $groups = $this->parse_static_groups();
        }

        // Scope to the locked group (dynamic already filters via scopevalue; this
        // also applies the scope to the static fallback).
        $scope = $this->scope_value();
        if ($scope !== '') {
            $needle = \core_text::strtolower($scope);
            $groups = array_values(array_filter($groups, static function (array $group) use ($needle): bool {
                return \core_text::strtolower((string)$group['group']) === $needle;
            }));
        }
        return $groups;
    }

    /**
     * The value of the dependson key when it is locked for the current user,
     * else '' (unscoped).
     *
     * @return string
     */
    private function scope_value(): string {
        $dependson = trim((string)($this->config['dependson'] ?? ''));
        if ($dependson === '') {
            return '';
        }
        return (string)(locked_filters::for_current_user()[$dependson] ?? '');
    }

    /**
     * Parse the static "GroupA=value:Label;value:Label||GroupB=value" string.
     *
     * @return array<int, array{group: string, options: array<int, array{value: string, label: string}>}>
     */
    private function parse_static_groups(): array {
        $raw = (string)($this->config['groups'] ?? '');
        $result = [];
        foreach (array_filter(array_map('trim', explode('||', $raw))) as $groupstr) {
            if (strpos($groupstr, '=') === false) {
                continue;
            }
            [$label, $opts] = explode('=', $groupstr, 2);
            $options = [];
            foreach (array_filter(array_map('trim', explode(';', $opts))) as $pair) {
                if (strpos($pair, ':') !== false) {
                    [$value, $optlabel] = explode(':', $pair, 2);
                } else {
                    $value = $optlabel = $pair;
                }
                $options[] = [
                    'value' => clean_param(trim($value), PARAM_RAW_TRIMMED),
                    'label' => format_string(trim($optlabel)),
                ];
            }
            if (!empty($options)) {
                $result[] = ['group' => format_string(trim($label)), 'options' => $options];
            }
        }
        return $result;
    }
}
