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

namespace local_wb_dashboard\local\source;

use local_wb_dashboard\local\dto\chart_data;
use local_wb_dashboard\local\dto\filter_constraint;
use local_wb_dashboard\local\filter\filter_factory;
use local_wb_dashboard\local\filter\locked_filters;
use moodle_exception;

/**
 * The shared server-side data-acquisition pipeline.
 *
 * Resolves the source, allowlists its params, enforces object-level access,
 * translates the page filter values into neutral constraints and fetches the
 * normalized chart_data DTO. Every display web service (charts, digits, ...)
 * runs this identical pipeline; only what it does with the DTO differs.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pipeline {
    /**
     * Resolve a source and fetch its normalized data for the given params/filters.
     *
     * @param string $sourcename Registered source name.
     * @param array $sourceparams WS name/value pair list of source parameters.
     * @param array $filtervalues WS list of {key,type,value} page filter values.
     * @return chart_data
     */
    public static function fetch(string $sourcename, array $sourceparams, array $filtervalues): chart_data {
        // Resolve the source (throws on unknown).
        $source = source_registry::get($sourcename);
        $cleanparams = self::allowlist_params($source, $sourceparams);

        // Real object-level authorization lives in the source.
        $source->require_access($cleanparams);

        $constraints = self::build_constraints($source, $cleanparams, $filtervalues);

        // Serve the shaped result from cache when an identical request was
        // fetched recently. The key covers the params and every constraint —
        // including locked per-user values — so entries are safely shared;
        // require_access() above has already vetted this user.
        $cachekey = sha1(json_encode([
            $sourcename,
            $cleanparams,
            array_map(static fn(filter_constraint $c): array =>
                [$c->key, $c->operator, $c->value, $c->locked], $constraints),
        ]));
        $cache = \cache::make('local_wb_dashboard', 'chartdata');
        $cached = $cache->get($cachekey);
        if ($cached instanceof chart_data) {
            return $cached;
        }

        $data = $source->fetch($cleanparams, $constraints);
        $cache->set($cachekey, $data);
        return $data;
    }

    /**
     * Allowlist WS name/value source params against what the source declares
     * it needs; anything else is dropped.
     *
     * @param source_interface $source The resolved source.
     * @param array $sourceparams WS list of {name, value} pairs.
     * @return array name => value
     */
    public static function allowlist_params(source_interface $source, array $sourceparams): array {
        $allowed = array_flip($source->required_params());
        $cleanparams = [];
        foreach ($sourceparams as $pair) {
            if (isset($allowed[$pair['name']])) {
                $cleanparams[$pair['name']] = $pair['value'];
            }
        }
        return $cleanparams;
    }

    /**
     * Translate submitted page filter values into neutral constraints.
     *
     * Locked filter keys are forced server-side: whatever the client submitted
     * for them is discarded and the current user's own profile field value is
     * applied instead. They go first so that, if a same-key client constraint
     * ever slipped through, the source's first-wins merge keeps the locked
     * value. Submitted keys the source cannot map are ignored.
     *
     * Shared by every consumer that queries a source on the user's behalf
     * (chart/digits web services via {@see fetch}, the filter-aware report
     * download endpoint) so locked-filter enforcement cannot diverge.
     *
     * @param source_interface $source The resolved source.
     * @param array $cleanparams Allowlisted source params.
     * @param array $filtervalues List of {key, type, value} page filter values.
     * @return filter_constraint[]
     * @throws moodle_exception When a locked key has no profile field value (fail closed).
     */
    public static function build_constraints(source_interface $source, array $cleanparams, array $filtervalues): array {
        $locked = locked_filters::for_current_user();
        $constraints = [];
        foreach ($locked as $key => $value) {
            if ($value === '') {
                // Locked, but no profile field value: fail closed, never unfiltered.
                throw new moodle_exception('error:lockedfilternovalue', 'local_wb_dashboard', '', $key);
            }
            $constraints[] = new filter_constraint($key, filter_constraint::OP_EQUAL, $value, true);
        }
        // Sources map keys case-insensitively, so the client skip must be too.
        $lockedlower = array_change_key_case($locked, CASE_LOWER);

        $supported = array_flip($source->get_supported_filter_keys($cleanparams));
        foreach ($filtervalues as $fv) {
            if ($fv['value'] === '' || !isset($supported[$fv['key']])) {
                continue;
            }
            if (isset($lockedlower[\core_text::strtolower($fv['key'])])) {
                continue;
            }
            if (!filter_factory::exists($fv['type'])) {
                continue;
            }
            $filter = filter_factory::create($fv['type'], $fv['key']);
            $constraints[] = $filter->to_constraint($filter->normalize_value($fv['value']));
        }
        return $constraints;
    }
}
