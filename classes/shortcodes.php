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

namespace local_wb_dashboard;

use core\output\html_writer;
use local_wb_dashboard\local\chart\chart_type;
use local_wb_dashboard\local\definition\chart_definition;
use local_wb_dashboard\local\definition\digits_definition;
use local_wb_dashboard\local\definition\filter_definition;
use local_wb_dashboard\local\digits\digits_reducer;
use local_wb_dashboard\local\filter\filter_factory;
use local_wb_dashboard\local\filter\locked_filters;
use local_wb_dashboard\local\filter\page_filter_state;
use local_wb_dashboard\local\palette\palette_manager;
use local_wb_dashboard\local\source\source_registry;

/**
 * Shortcode handlers for local_wb_dashboard.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shortcodes {
    /**
     * [chart ...] — render a chart. Data is loaded client-side via the web service.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param \Closure $next
     * @return string
     */
    public static function chart($shortcode, $args, $content, $env, $next): string {
        global $OUTPUT;

        $definition = chart_definition::create_definition_from_shortcode_args($args);
        if (!source_registry::exists($definition->source)) {
            return get_string('error:unknownsource', 'local_wb_dashboard', s($definition->source));
        }
        if (!chart_type::is_valid($definition->type)) {
            return get_string('error:unknowncharttype', 'local_wb_dashboard', s($definition->type));
        }

        $envcontext = $env->context;
        $chartid = $definition->create_chartid((int)$envcontext->id);

        $wsargs = $definition->to_wsargs();
        $wsargs['chartid'] = $chartid;

        $context = [
            'canvasid' => html_writer::random_id('local-dashboard-chart-'),
            'chartid' => $chartid,
            'title' => $wsargs['title'],
            'width' => $definition->displayopts['width'],
            'height' => $definition->displayopts['height'],
            'pageid' => $definition->pageid,
            'consumes' => json_encode($definition->consumesfilters),
            'wsargs' => json_encode($wsargs),
            'palettename' => palette_manager::name(),
            'cansettings' => has_capability('local/wb_dashboard:configurecharts', $envcontext),
        ];
        return $OUTPUT->render_from_template('local_wb_dashboard/chart', $context);
    }

    /**
     * [chartfilter ...] — render a page-level filter control.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param \Closure $next
     * @return string
     */
    public static function chartfilter($shortcode, $args, $content, $env, $next): string {
        global $OUTPUT, $PAGE;

        $args = (array)$args;
        $definition = filter_definition::create_definition_from_shortcode_args($args);

        if ($definition->key === '') {
            return get_string('error:missingfilterkey', 'local_wb_dashboard');
        }
        if (!filter_factory::exists($definition->type)) {
            return get_string('error:unknownfiltertype', 'local_wb_dashboard', s($definition->type));
        }

        // A locked key is forced server-side to the user's profile field value
        // (see locked_filters), so the user gets a static value instead of a
        // control — or nothing at all with hidewhenlocked="1".
        $lockedvalues = locked_filters::for_current_user();
        $islocked = array_key_exists($definition->key, $lockedvalues);
        if ($islocked && !empty($definition->config['hidewhenlocked'])) {
            return '';
        }

        $filter = filter_factory::create($definition->type, $definition->key, $definition->config);
        $context = $filter->export_for_template($PAGE->get_renderer('core'));

        if ($islocked) {
            // Show the option/region label where one matches the forced value.
            $lockedvalue = $lockedvalues[$definition->key];
            $display = $lockedvalue;
            foreach ($context['options'] ?? [] as $option) {
                if ((string)$option['value'] === $lockedvalue) {
                    $display = (string)$option['label'];
                    break;
                }
            }
            foreach ($context['regions'] ?? [] as $region) {
                if ((string)$region['value'] === $lockedvalue) {
                    $display = (string)$region['name'];
                    break;
                }
            }
            $context['value'] = $display;
            $context['options'] = [];
            $context['regions'] = [];
        } else {
            // Prefill from persisted state (URL state overrides client-side).
            $context['value'] = page_filter_state::get_value(
                $definition->pageid,
                $definition->key,
                (string)($context['value'] ?? '')
            );
            // Reflect the prefilled value into select option selection.
            if (!empty($context['options'])) {
                foreach ($context['options'] as &$option) {
                    $option['selected'] = ((string)$option['value'] === (string)$context['value']);
                }
                unset($option);
            }
        }
        // Reflect the prefilled value into the active map region and expose its
        // display name for the readout.
        if (!empty($context['regions'])) {
            foreach ($context['regions'] as &$region) {
                $region['selected'] = ((string)$region['value'] === (string)$context['value']);
                if ($region['selected']) {
                    $context['valuename'] = (string)$region['name'];
                }
            }
            unset($region);
        }

        $context['pageid'] = $definition->pageid;
        $context['palettename'] = palette_manager::name();
        $context['islocked'] = $islocked;
        $context['isselect'] = !$islocked && ($definition->type === 'select');
        $context['isdate'] = !$islocked && ($definition->type === 'date');
        $context['istext'] = !$islocked && ($definition->type === 'text');
        $context['isnumber'] = !$islocked && ($definition->type === 'number');
        $context['ismap'] = !$islocked && ($definition->type === 'map');

        return $OUTPUT->render_from_template('local_wb_dashboard/chartfilter', $context);
    }

    /**
     * [digits ...] — render a single numeric value (number, count or percentage)
     * as a styleable DOM field. Data is loaded client-side via the web service.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param \Closure $next
     * @return string
     */
    public static function digits($shortcode, $args, $content, $env, $next): string {
        global $OUTPUT;

        $args = (array)$args;
        $definition = digits_definition::create_definition_from_shortcode_args($args);

        if ($definition->source === '') {
            return get_string('error:missingsource', 'local_wb_dashboard');
        }
        if (!source_registry::exists($definition->source)) {
            return get_string('error:unknownsource', 'local_wb_dashboard', s($definition->source));
        }
        if (!digits_reducer::is_valid_mode($definition->display)) {
            return get_string('error:unknowndisplaymode', 'local_wb_dashboard', s($definition->display));
        }

        $domid = $definition->to_domid();
        $context = [
            'domid' => $domid,
            'valueid' => $domid . '-value',
            'labelid' => $domid . '-label',
            'label' => $definition->displayopts['label'] ?? '',
            'pageid' => $definition->pageid,
            'consumes' => json_encode($definition->consumesfilters),
            'wsargs' => json_encode($definition->to_wsargs()),
            'palettename' => palette_manager::name(),
        ];

        return $OUTPUT->render_from_template('local_wb_dashboard/digits', $context);
    }
}
