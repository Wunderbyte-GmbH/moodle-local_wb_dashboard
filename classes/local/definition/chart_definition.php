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

/**
 * A serializable, complete description of one chart.
 *
 * This is the drag-and-drop seam: the shortcode is one producer of a
 * chart_definition today; a future DB-backed dashboard builder is another.
 * Everything downstream (renderer, web service, source) consumes the
 * definition, never the raw shortcode args.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chart_definition {
    /** Reserved shortcode keys handled by the plugin (everything else = source param). */
    private const RESERVED = ['type', 'source', 'width', 'height', 'title', 'consumes', 'pageid', 'target'];

    /** @var string Source name (e.g. "reportbuilder"). */
    public string $source;

    /** @var string Semantic chart type (e.g. "doughnut", "stackedbar"). */
    public string $type;

    /** @var array Source-specific parameters (allowlisted by the source later). */
    public array $sourceparams;

    /** @var array Display options: title, width, height, centertext. */
    public array $displayopts;

    /** @var string[] Logical filter keys this chart reacts to. Empty = every key its source maps. */
    public array $consumesfilters;

    /** @var string Page identifier the chart's filter state belongs to. */
    public string $pageid;

    /**
     * Constructor.
     *
     * @param string $source
     * @param string $type
     * @param array $sourceparams
     * @param array $displayopts
     * @param string[] $consumesfilters
     * @param string $pageid
     */
    public function __construct(
        string $source,
        string $type,
        array $sourceparams,
        array $displayopts,
        array $consumesfilters,
        string $pageid
    ) {
        $this->source = $source;
        $this->type = $type;
        $this->sourceparams = $sourceparams;
        $this->displayopts = $displayopts;
        $this->consumesfilters = $consumesfilters;
        $this->pageid = $pageid;
    }

    /**
     * Build a definition from raw [chart] shortcode arguments.
     *
     * Reserved keys are extracted; every remaining key becomes a source param.
     * Per-chart colours are no longer taken from the shortcode: they are managed
     * through the per-chart settings gear and resolved server-side by chart id.
     *
     * @param array $args
     * @return self
     */
    public static function create_definition_from_shortcode_args(array $args): self {
        $source = isset($args['source']) ? clean_param($args['source'], PARAM_ALPHANUMEXT) : '';
        $type = isset($args['type']) ? clean_param($args['type'], PARAM_ALPHA) : 'bar';
        $pageid = isset($args['pageid']) ? clean_param($args['pageid'], PARAM_ALPHANUMEXT) : 'default';

        $consumes = [];
        if (!empty($args['consumes'])) {
            foreach (explode(',', $args['consumes']) as $key) {
                $key = clean_param(trim($key), PARAM_ALPHANUMEXT);
                if ($key !== '') {
                    $consumes[] = $key;
                }
            }
        }

        $displayopts = [
            'title'  => isset($args['title']) ? clean_param($args['title'], PARAM_TEXT) : '',
            'width'  => isset($args['width']) ? (float)$args['width'] : 32.0,
            'height' => isset($args['height']) ? (float)$args['height'] : 20.0,
            // Doughnut centre text is on by default; centertext=0 hides it.
            'centertext' => array_key_exists('centertext', $args)
                ? (bool)clean_param((string)$args['centertext'], PARAM_BOOL) : true,
            // Manual value-axis maximum (e.g. a progress bar's goal); 0 = automatic.
            'target' => isset($args['target']) ? max(0.0, (float)$args['target']) : 0.0,
        ];

        // Everything not reserved is a source parameter. Legacy colour args
        // (color1, color2, ...) are ignored — colours are managed via the
        // per-chart settings gear — and must not leak into the source params or
        // the chart id.
        $sourceparams = [];
        foreach ($args as $k => $v) {
            if (in_array($k, self::RESERVED, true) || preg_match('/^color\d+$/', (string)$k)) {
                continue;
            }
            $sourceparams[(string)$k] = (string)$v;
        }

        return new self($source, $type, $sourceparams, $displayopts, $consumes, $pageid);
    }

    /**
     * The stable, deterministic base id for this chart within a given context.
     *
     * Derived from the identity-defining parts only (context, source, type and
     * source params) — cosmetic options (title, size) are excluded so renaming or
     * resizing a chart keeps its stored settings. Folding in the context id means
     * the same shortcode placed on two different pages/blocks gets distinct ids.
     *
     * Two identical charts in the same content share this base; the shortcode
     * layer disambiguates them with an occurrence suffix.
     *
     * @param int $contextid The context the chart is rendered in.
     * @return string
     */
    public function chartid_base(int $contextid): string {
        $params = $this->sourceparams;
        ksort($params);
        $canonical = json_encode([
            'context'      => $contextid,
            'source'       => $this->source,
            'type'         => $this->type,
            'sourceparams' => $params,
        ]);
        return 'c' . substr(sha1((string)$canonical), 0, 12);
    }

    /**
     * Resolve the stable chart id for this definition in a context, disambiguating
     * identical charts on the same rendered page with an occurrence suffix.
     *
     * The occurrence counter is kept across all calls within a single request, so
     * two identical charts in the same content receive distinct ids ("base",
     * "base-1", ...).
     *
     * @param int $contextid The context the chart is rendered in.
     * @return string
     */
    public function create_chartid(int $contextid): string {
        static $seen = [];

        $base = $this->chartid_base($contextid);
        $occurrence = $seen[$base] ?? 0;
        $seen[$base] = $occurrence + 1;

        return $occurrence === 0 ? $base : $base . '-' . $occurrence;
    }

    /**
     * Serialize the parts the JS needs to ship to the web service.
     *
     * sourceparams are sent as a name/value list because keys vary per source.
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
            'type'         => $this->type,
            'pageid'       => $this->pageid,
            'sourceparams' => $pairs,
            'title'        => $this->displayopts['title'] ?? get_string('chart', 'local_wb_dashboard'),
            'centertext'   => $this->displayopts['centertext'] ?? true,
            'target'       => (float)($this->displayopts['target'] ?? 0),
        ];
    }
}
