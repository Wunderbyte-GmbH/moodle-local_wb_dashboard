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

namespace local_wb_dashboard\local\definition;

use local_wb_dashboard\local\toplist\toplist_reducer;

/**
 * A serializable, complete description of one ranked top-N list.
 *
 * Mirrors digits_definition: the shortcode is one producer today; a future
 * DB-backed dashboard builder is another. Everything downstream (renderer, web
 * service, source) consumes the definition, never the raw shortcode args.
 *
 * The ranking metric and the progress-bar metric may differ: "barfield" points
 * at a field that already holds the bar percentage, "bartotalfield" at a field
 * the value is divided by. Either is fetched as a second aligned series by
 * folding it into the source's "valuefields" parameter.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class toplist_definition {
    /** Reserved shortcode keys handled by the plugin (everything else = source param). */
    private const RESERVED = [
        'source', 'top', 'order', 'barfield', 'bartotalfield', 'max',
        'decimals', 'suffix', 'title', 'consumes', 'pageid', 'fixedfilters', 'details', 'bars',
    ];

    /** Hard cap for the number of rows a list may request. */
    public const MAX_TOP = 20;

    /** @var string Source name (e.g. "reportbuilder"). */
    public string $source;

    /** @var array Source-specific parameters (allowlisted by the source later). */
    public array $sourceparams;

    /** @var array Display options: title, top, order, barmode, max, decimals, suffix. */
    public array $displayopts;

    /** @var string[] Logical filter keys this list reacts to. Empty = every key its source maps. */
    public array $consumesfilters;

    /** @var string Page identifier the list's filter state belongs to. */
    public string $pageid;

    /** @var array Fixed filter values pinned on this instance (filtervalues triples). */
    public array $fixedfilters = [];

    /**
     * Constructor.
     *
     * @param string $source
     * @param array $sourceparams
     * @param array $displayopts
     * @param string[] $consumesfilters
     * @param string $pageid
     */
    public function __construct(
        string $source,
        array $sourceparams,
        array $displayopts,
        array $consumesfilters,
        string $pageid
    ) {
        $this->source = $source;
        $this->sourceparams = $sourceparams;
        $this->displayopts = $displayopts;
        $this->consumesfilters = $consumesfilters;
        $this->pageid = $pageid;
    }

    /**
     * Build a definition from raw [toplist] shortcode arguments.
     *
     * Reserved keys are extracted; every remaining key becomes a source param.
     * A bar field is folded into the source's "valuefields" so the pipeline
     * returns it as a second series aligned with the ranking values.
     *
     * @param array $args
     * @return self
     */
    public static function create_definition_from_shortcode_args(array $args): self {
        $source = isset($args['source']) ? clean_param($args['source'], PARAM_ALPHANUMEXT) : '';
        $pageid = isset($args['pageid']) ? clean_param($args['pageid'], PARAM_ALPHANUMEXT) : 'default';

        $consumes = [];
        if (!empty($args['consumes'])) {
            // A value of "none" isolates the instance from every page filter
            // (empty consumes means "react to all keys" — the opposite).
            if (strtolower(trim((string)$args['consumes'])) === 'none') {
                $consumes = ['__none__'];
            } else {
                foreach (explode(',', $args['consumes']) as $key) {
                    $key = clean_param(trim($key), PARAM_ALPHANUMEXT);
                    if ($key !== '') {
                        $consumes[] = $key;
                    }
                }
            }
        }

        $barfield = trim((string)($args['barfield'] ?? ''));
        $bartotalfield = trim((string)($args['bartotalfield'] ?? ''));
        $max = isset($args['max']) ? (float)$args['max'] : 0.0;

        // The bar metric, in priority order: a field already holding the
        // percent, a field to divide the value by, a fixed maximum, or —
        // without any of those — relative to the top-ranked value.
        if ($barfield !== '') {
            $barmode = toplist_reducer::BAR_PERCENTFIELD;
        } else if ($bartotalfield !== '') {
            $barmode = toplist_reducer::BAR_TOTALFIELD;
        } else if ($max > 0) {
            $barmode = toplist_reducer::BAR_MAX;
        } else {
            $barmode = toplist_reducer::BAR_RELATIVE;
        }

        $displayopts = [
            'title'    => isset($args['title']) ? clean_param($args['title'], PARAM_TEXT) : '',
            'top'      => isset($args['top']) ? max(1, min(self::MAX_TOP, (int)$args['top'])) : 5,
            'order'    => strtolower((string)($args['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
            'barmode'  => $barmode,
            'max'      => $max,
            'decimals' => isset($args['decimals']) ? max(0, min(6, (int)$args['decimals'])) : 0,
            'suffix'   => isset($args['suffix']) ? clean_param($args['suffix'], PARAM_TEXT) : '',
            // Named detail template opening a per-row drill-down modal.
            'details'  => isset($args['details']) ? clean_param($args['details'], PARAM_ALPHANUMEXT) : '',
            // Render without the per-row progress bar when bars=0.
            'bars'     => !array_key_exists('bars', $args)
                || (bool)clean_param((string)$args['bars'], PARAM_BOOL),
        ];

        // Everything not reserved is a source parameter.
        $sourceparams = [];
        foreach ($args as $k => $v) {
            if (in_array($k, self::RESERVED, true)) {
                continue;
            }
            $sourceparams[(string)$k] = (string)$v;
        }

        // A second field rides along as an extra series: the shaping's
        // "valuefields" mode returns one aligned series per listed field.
        $secondfield = $barfield !== '' ? $barfield : $bartotalfield;
        if ($secondfield !== '' && !empty($sourceparams['valuefield'])) {
            $sourceparams['valuefields'] = $sourceparams['valuefield'] . ',' . $secondfield;
            unset($sourceparams['valuefield']);
        }

        $definition = new self($source, $sourceparams, $displayopts, $consumes, $pageid);
        $definition->fixedfilters = fixed_filters::parse((string)($args['fixedfilters'] ?? ''));
        return $definition;
    }

    /**
     * Serialize the parts the JS needs to ship to the web service.
     *
     * @return array
     */
    public function to_wsargs(): array {
        $pairs = [];
        foreach ($this->sourceparams as $name => $value) {
            $pairs[] = ['name' => $name, 'value' => (string)$value];
        }
        return [
            'source'       => $this->source,
            'pageid'       => $this->pageid,
            'sourceparams' => $pairs,
            'top'          => $this->displayopts['top'],
            'order'        => $this->displayopts['order'],
            'barmode'      => $this->displayopts['barmode'],
            'max'          => $this->displayopts['max'],
            'decimals'     => $this->displayopts['decimals'],
            'suffix'       => $this->displayopts['suffix'],
            'fixedfilters' => $this->fixedfilters,
        ];
    }

    /**
     * A deterministic, constant DOM id derived from the identity-defining parts
     * (source, source params, ranking and bar configuration).
     *
     * The id is stable across reloads so it can be targeted in CSS. Two lists
     * with an identical configuration on the same page share an id by design.
     *
     * @return string
     */
    public function to_domid(): string {
        $params = $this->sourceparams;
        ksort($params);
        $identity = [
            'source'       => $this->source,
            'sourceparams' => $params,
            'top'          => $this->displayopts['top'],
            'order'        => $this->displayopts['order'],
            'barmode'      => $this->displayopts['barmode'],
            'max'          => $this->displayopts['max'],
        ];
        // Only fold fixed filters in when set, so pre-existing ids stay stable.
        if (!empty($this->fixedfilters)) {
            $identity['fixedfilters'] = $this->fixedfilters;
        }
        $canonical = json_encode($identity);
        return 'local-dashboard-toplist-' . substr(sha1((string)$canonical), 0, 12);
    }
}
