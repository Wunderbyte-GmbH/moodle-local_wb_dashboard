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

use local_wb_dashboard\local\digits\digits_reducer;

/**
 * A serializable, complete description of one digits (single-value) field.
 *
 * Mirrors chart_definition: the shortcode is one producer today; a future
 * DB-backed dashboard builder is another. Everything downstream (renderer, web
 * service, source) consumes the definition, never the raw shortcode args.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class digits_definition {
    /** Reserved shortcode keys handled by the plugin (everything else = source param). */
    private const RESERVED = ['source', 'display', 'label', 'decimals', 'unit', 'consumes', 'pageid', 'fixedfilters'];

    /** @var string Source name (e.g. "reportbuilder"). */
    public string $source;

    /** @var string Display mode: number | count | percent. */
    public string $display;

    /** @var array Source-specific parameters (allowlisted by the source later). */
    public array $sourceparams;

    /** @var array Display options: label, decimals, unit. */
    public array $displayopts;

    /** @var string[] Logical filter keys this field reacts to. Empty = every key its source maps. */
    public array $consumesfilters;

    /** @var string Page identifier the field's filter state belongs to. */
    public string $pageid;

    /** @var array Fixed filter values pinned on this instance (filtervalues triples). */
    public array $fixedfilters = [];

    /**
     * Constructor.
     *
     * @param string $source
     * @param string $display
     * @param array $sourceparams
     * @param array $displayopts
     * @param string[] $consumesfilters
     * @param string $pageid
     */
    public function __construct(
        string $source,
        string $display,
        array $sourceparams,
        array $displayopts,
        array $consumesfilters,
        string $pageid
    ) {
        $this->source = $source;
        $this->display = $display;
        $this->sourceparams = $sourceparams;
        $this->displayopts = $displayopts;
        $this->consumesfilters = $consumesfilters;
        $this->pageid = $pageid;
    }

    /**
     * Build a definition from raw [digits] shortcode arguments.
     *
     * Reserved keys are extracted; every remaining key becomes a source param.
     *
     * @param array $args
     * @return self
     */
    public static function create_definition_from_shortcode_args(array $args): self {
        $source = isset($args['source']) ? clean_param($args['source'], PARAM_ALPHANUMEXT) : '';
        $display = isset($args['display']) ? clean_param($args['display'], PARAM_ALPHA) : digits_reducer::MODE_NUMBER;
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

        $displayopts = [
            'label'    => isset($args['label']) ? clean_param($args['label'], PARAM_TEXT) : '',
            'decimals' => isset($args['decimals']) ? max(0, min(6, (int)$args['decimals'])) : 0,
            'unit'     => isset($args['unit']) ? clean_param($args['unit'], PARAM_TEXT) : '',
        ];

        // Everything not reserved is a source parameter.
        $sourceparams = [];
        foreach ($args as $k => $v) {
            if (in_array($k, self::RESERVED, true)) {
                continue;
            }
            $sourceparams[(string)$k] = (string)$v;
        }

        $definition = new self($source, $display, $sourceparams, $displayopts, $consumes, $pageid);
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
            'display'      => $this->display,
            'pageid'       => $this->pageid,
            'sourceparams' => $pairs,
            'label'        => $this->displayopts['label'] ?? '',
            'decimals'     => $this->displayopts['decimals'] ?? 0,
            'unit'         => $this->displayopts['unit'] ?? '',
            'fixedfilters' => $this->fixedfilters,
        ];
    }

    /**
     * A deterministic, constant DOM id derived from the identity-defining parts
     * (source, source params, display mode).
     *
     * The id is stable across reloads so it can be targeted in CSS. Two fields
     * with an identical configuration on the same page share an id by design.
     *
     * @return string
     */
    public function to_domid(): string {
        $params = $this->sourceparams;
        ksort($params);
        $identity = [
            'source'       => $this->source,
            'display'      => $this->display,
            'sourceparams' => $params,
        ];
        // Only fold fixed filters in when set, so pre-existing ids stay stable.
        if (!empty($this->fixedfilters)) {
            $identity['fixedfilters'] = $this->fixedfilters;
        }
        $canonical = json_encode($identity);
        return 'local-dashboard-digits-' . substr(sha1((string)$canonical), 0, 12);
    }
}
