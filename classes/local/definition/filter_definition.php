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
 * A serializable description of one page-level filter control.
 *
 * Produced by the [chartfilter] shortcode today; a future DnD builder produces
 * the same object.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filter_definition {
    /** Reserved keys handled by the plugin (rest become filter config). */
    private const RESERVED = ['key', 'type', 'pageid'];

    /** @var string Logical filter key (shared page vocabulary). */
    public string $key;

    /** @var string Filter UI type (select|date|text|number|map). */
    public string $type;

    /** @var array Filter config: label, options, default, ... */
    public array $config;

    /** @var string Page identifier this filter belongs to. */
    public string $pageid;

    /**
     * Constructor.
     *
     * @param string $key
     * @param string $type
     * @param array $config
     * @param string $pageid
     */
    public function __construct(string $key, string $type, array $config, string $pageid) {
        $this->key = $key;
        $this->type = $type;
        $this->config = $config;
        $this->pageid = $pageid;
    }

    /**
     * Build a definition from raw [chartfilter] shortcode arguments.
     *
     * @param array $args
     * @return self
     */
    public static function create_definition_from_shortcode_args(array $args): self {
        $key = isset($args['key']) ? clean_param($args['key'], PARAM_ALPHANUMEXT) : '';
        $type = isset($args['type']) ? clean_param($args['type'], PARAM_ALPHA) : 'text';
        $pageid = isset($args['pageid']) ? clean_param($args['pageid'], PARAM_ALPHANUMEXT) : 'default';

        $config = [];
        foreach ($args as $k => $v) {
            if (in_array($k, self::RESERVED, true)) {
                continue;
            }
            $config[(string)$k] = (string)$v;
        }

        return new self($key, $type, $config, $pageid);
    }
}
