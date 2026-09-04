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
use local_wb_dashboard\local\source\option_provider_interface;
use renderer_base;

/**
 * A dropdown filter.
 *
 * Options come either from a static "value:Label,value:Label" config string or,
 * when optionsfield="..." is configured, dynamically from a data source that
 * implements {@see option_provider_interface} (source="..." selects it,
 * defaulting to reportbuilder). Static options act as the fallback whenever the
 * dynamic lookup yields nothing (unknown source, no permission, empty data).
 *
 * With cascadefrom="<key>" the control follows another filter live: the client
 * re-fetches the dynamic options scoped by that filter's current value (see
 * the cascadeselect AMD module and the get_filter_options web service) and
 * auto-selects the first one. Server-side, a dependent control's options are
 * scoped by the viewer's locked filters only.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class select_filter extends base_filter {
    /** @var array|null|false Resolved dynamic source; false until first resolved. */
    private $resolved = false;

    #[\Override]
    public function get_type(): string {
        return 'select';
    }

    #[\Override]
    public function export_for_template(renderer_base $output): array {
        $context = parent::export_for_template($output);
        $default = $this->get_default();
        $context['options'] = array_map(static function (array $opt) use ($default): array {
            return [
                'value' => $opt['value'],
                'label' => $opt['label'],
                'selected' => ((string)$opt['value'] === $default),
            ];
        }, $this->resolve_options());

        // What the client needs to re-fetch the options ('' = static only).
        $resolved = $this->dynamic_source();
        $context['optionsargs'] = $resolved === null
            ? ''
            : json_encode(dynamic_options::wsargs($resolved, $this->options_field()));
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
     * The configured dynamic options field ('' = static options only).
     *
     * @return string
     */
    private function options_field(): string {
        return trim((string)($this->config['optionsfield'] ?? ''));
    }

    /**
     * Resolve (once) the dynamic source behind optionsfield, null when there is
     * none usable.
     *
     * @return array|null
     */
    private function dynamic_source(): ?array {
        if ($this->resolved === false) {
            $this->resolved = $this->options_field() === ''
                ? null
                : dynamic_options::resolve_source($this->config, option_provider_interface::class);
        }
        return $this->resolved;
    }

    /**
     * The option list: dynamic (optionsfield config) with static fallback.
     *
     * A dependent control (cascadefrom / dependson) has its dynamic options
     * scoped by the viewer's locked filters; when that scope yields nothing the
     * static list is NOT shown, since it would be unscoped.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function resolve_options(): array {
        $resolved = $this->dynamic_source();
        if ($resolved !== null) {
            $constraints = [];
            if ($this->is_dependent()) {
                $constraints = dynamic_options::render_constraints($resolved['source'], $resolved['params']);
                if ($constraints === null) {
                    return [];
                }
            }
            $options = dynamic_options::options(
                $resolved['name'],
                $resolved['source'],
                $resolved['params'],
                $this->options_field(),
                $constraints
            );
            if (!empty($options) || !empty($constraints)) {
                return $options;
            }
        }
        return $this->parse_options();
    }

    /**
     * Parse the "value:Label,value:Label" options string into a list.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function parse_options(): array {
        $raw = (string)($this->config['options'] ?? '');
        $options = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $pair) {
            if (strpos($pair, ':') !== false) {
                [$value, $label] = explode(':', $pair, 2);
            } else {
                $value = $label = $pair;
            }
            $options[] = [
                'value' => clean_param(trim($value), PARAM_RAW_TRIMMED),
                'label' => format_string(trim($label)),
            ];
        }
        return $options;
    }
}
