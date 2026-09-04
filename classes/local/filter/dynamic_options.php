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
use local_wb_dashboard\local\source\option_provider_interface;
use local_wb_dashboard\local\source\pipeline;
use local_wb_dashboard\local\source\source_interface;
use local_wb_dashboard\local\source\source_registry;

/**
 * Dynamic select-filter options: source resolution, scoping and caching.
 *
 * Shared by the select / grouped select filters (server-side render) and the
 * get_filter_options web service (client-side cascade), so both compute the
 * same option lists from the same cache. Options are scoped by neutral
 * constraints; callers derive those from page filter values through
 * {@see pipeline::build_constraints()} so locked filters are always honoured.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dynamic_options {
    /**
     * Resolve the source a filter config points at, allowlist its params and
     * check access — degrading to null (unknown source, source without the
     * wanted capability, access denied) so callers fall back to static options.
     *
     * @param array $config Filter config (source, plus the source's own params).
     * @param string $interface Capability the source must implement.
     * @return array{name: string, source: source_interface, params: array}|null
     */
    public static function resolve_source(array $config, string $interface = option_provider_interface::class): ?array {
        $name = trim((string)($config['source'] ?? 'reportbuilder'));
        if (!source_registry::exists($name)) {
            return null;
        }
        $source = source_registry::get($name);
        if (!$source instanceof $interface) {
            return null;
        }

        $params = array_intersect_key($config, array_flip($source->required_params()));
        try {
            // Per-user authorization always runs before the shared cache is read.
            $source->require_access($params);
        } catch (\Throwable $e) {
            return null;
        }
        return ['name' => $name, 'source' => $source, 'params' => $params];
    }

    /**
     * The web-service arguments a client needs to re-fetch this filter's
     * options (mirrors the chart wsargs pattern).
     *
     * @param array $resolved A {@see resolve_source()} result.
     * @param string $field Option value field.
     * @param string $groupfield Group field ('' for a flat select).
     * @return array{source: string, sourceparams: array, field: string, groupfield: string}
     */
    public static function wsargs(array $resolved, string $field, string $groupfield = ''): array {
        $pairs = [];
        foreach ($resolved['params'] as $name => $value) {
            $pairs[] = ['name' => (string)$name, 'value' => (string)$value];
        }
        return [
            'source' => $resolved['name'],
            'sourceparams' => $pairs,
            'field' => $field,
            'groupfield' => $groupfield,
        ];
    }

    /**
     * The constraints a server-side render applies to a dependent control's
     * options: no client values yet, so only the viewer's locked filters. Null
     * when a locked key has no value (fail closed — render no options rather
     * than unscoped ones).
     *
     * @param source_interface $source
     * @param array $params Allowlisted source params.
     * @return filter_constraint[]|null
     */
    public static function render_constraints(source_interface $source, array $params): ?array {
        try {
            return pipeline::build_constraints($source, $params, []);
        } catch (\moodle_exception $e) {
            return null;
        }
    }

    /**
     * Flat options (cached), scoped by the given constraints.
     *
     * @param string $name Source name.
     * @param source_interface $source The resolved source.
     * @param array $params Allowlisted source params.
     * @param string $field Option value field.
     * @param filter_constraint[] $constraints
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(
        string $name,
        source_interface $source,
        array $params,
        string $field,
        array $constraints = []
    ): array {
        if (!$source instanceof option_provider_interface) {
            return [];
        }
        $cache = \cache::make('local_wb_dashboard', 'filteroptions');
        $cachekey = self::cache_key(['flat', $name, $field, $params], $constraints);
        $options = $cache->get($cachekey);
        if ($options === false) {
            $options = $source->get_filter_options($params, $field, $constraints);
            $cache->set($cachekey, $options);
        }
        return $options;
    }

    /**
     * Grouped options (cached), scoped by the given constraints and/or group.
     *
     * @param string $name Source name.
     * @param source_interface $source The resolved source.
     * @param array $params Allowlisted source params.
     * @param string $groupfield Group field.
     * @param string $valuefield Option value field.
     * @param string $scopevalue When non-empty, only the group with this label.
     * @param filter_constraint[] $constraints
     * @return array<int, array{group: string, options: array<int, array{value: string, label: string}>}>
     */
    public static function groups(
        string $name,
        source_interface $source,
        array $params,
        string $groupfield,
        string $valuefield,
        string $scopevalue = '',
        array $constraints = []
    ): array {
        if (!$source instanceof grouped_option_provider_interface) {
            return [];
        }
        $cache = \cache::make('local_wb_dashboard', 'filteroptions');
        $cachekey = self::cache_key(['grouped', $name, $groupfield, $valuefield, $scopevalue, $params], $constraints);
        $groups = $cache->get($cachekey);
        if ($groups === false) {
            $groups = $source->get_grouped_filter_options($params, $groupfield, $valuefield, $scopevalue, $constraints);
            $cache->set($cachekey, $groups);
        }
        return $groups;
    }

    /**
     * Cache key covering every input of an option lookup. Constraints —
     * including locked per-user values — are part of it, so entries are safely
     * shared between viewers (access was checked before the lookup).
     *
     * @param array $parts
     * @param filter_constraint[] $constraints
     * @return string
     */
    private static function cache_key(array $parts, array $constraints): string {
        $parts[] = array_map(static fn(filter_constraint $c): array =>
            [$c->key, $c->operator, $c->value, $c->locked], $constraints);
        return sha1(json_encode($parts));
    }
}
