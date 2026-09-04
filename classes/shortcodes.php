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
use core_reportbuilder\local\report\base as report_base;
use core_reportbuilder\manager;
use core_reportbuilder\permission;
use local_wb_dashboard\local\chart\chart_type;
use local_wb_dashboard\local\definition\chart_definition;
use local_wb_dashboard\local\definition\digits_definition;
use local_wb_dashboard\local\definition\filter_definition;
use local_wb_dashboard\local\definition\toplist_definition;
use local_wb_dashboard\local\digits\digits_reducer;
use local_wb_dashboard\local\filter\daterange_filter;
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

        // $env->context can be null when the shortcode is rendered outside a
        // context-bound page (e.g. a system-level content block); fall back to
        // the system context so the capability check still has somewhere to run.
        $envcontext = $env->context ?? \context_system::instance();
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
        // control — or nothing at all with hidewhenlocked="1". For a multi-key
        // control, ANY locked key locks the whole control: a free control whose
        // sibling keys are force-overridden server-side would be incoherent.
        $lockedvalues = locked_filters::for_current_user();
        $lockedkeys = array_values(array_intersect($definition->keys, array_keys($lockedvalues)));
        $islocked = !empty($lockedkeys);
        if ($islocked && !empty($definition->config['hidewhenlocked'])) {
            return '';
        }

        $filter = filter_factory::create($definition->type, $definition->key, $definition->config);
        $context = $filter->export_for_template($PAGE->get_renderer('core'));

        if ($islocked) {
            // Show the option/region label where one matches the forced value.
            $lockedvalue = $lockedvalues[$lockedkeys[0]];
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
            // Same, for a grouped select's optgroup options.
            if (!empty($context['groups'])) {
                foreach ($context['groups'] as &$group) {
                    foreach ($group['options'] as &$option) {
                        $option['selected'] = ((string)$option['value'] === (string)$context['value']);
                    }
                    unset($option);
                }
                unset($group);
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

        // Split a (possibly cache-prefilled) "from|to" range value for the two
        // date inputs of the daterange control.
        if ($definition->type === 'daterange' && !$islocked) {
            [$context['valuefrom'], $context['valueto']] =
                daterange_filter::split_raw((string)$context['value']);
        }

        $context['pageid'] = $definition->pageid;
        $context['keys'] = implode(',', $definition->keys);
        $context['palettename'] = palette_manager::name();
        $context['islocked'] = $islocked;
        $context['isselect'] = !$islocked && ($definition->type === 'select');
        $context['isgroupedselect'] = !$islocked && ($definition->type === 'groupedselect');
        $context['isdate'] = !$islocked && ($definition->type === 'date');
        $context['isdaterange'] = !$islocked && ($definition->type === 'daterange');
        $context['istext'] = !$islocked && ($definition->type === 'text');
        $context['isnumber'] = !$islocked && ($definition->type === 'number');
        $context['ismap'] = !$islocked && ($definition->type === 'map');

        // Live cascade (cascadefrom=<key>): only a free select with dynamic
        // options can follow another filter, and only when that filter is not
        // locked for the viewer — a locked key is already applied server-side
        // and never changes on the client.
        $cascadefrom = (string)($context['cascadefrom'] ?? '');
        $cancascade = $cascadefrom !== ''
            && ($context['isselect'] || $context['isgroupedselect'])
            && (string)($context['optionsargs'] ?? '') !== ''
            && !isset($lockedvalues[$cascadefrom]);
        $context['cascadefrom'] = $cancascade ? $cascadefrom : '';
        $context['optionsargs'] = $cancascade ? (string)$context['optionsargs'] : '';

        return $OUTPUT->render_from_template('local_wb_dashboard/chartfilter', $context);
    }

    /**
     * [filterreset ...] — render a button that clears every page filter.
     *
     * Arguments: label (button text, default from lang).
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param \Closure $next
     * @return string
     */
    public static function filterreset($shortcode, $args, $content, $env, $next): string {
        global $OUTPUT;

        $args = (array)$args;
        $label = isset($args['label'])
            ? clean_param($args['label'], PARAM_TEXT)
            : get_string('filterreset:label', 'local_wb_dashboard');

        $context = [
            'elementid' => html_writer::random_id('local-dashboard-filterreset-'),
            'label' => $label,
        ];
        return $OUTPUT->render_from_template('local_wb_dashboard/filterreset', $context);
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

    /**
     * [downloadreport ...] — render a download button for a custom report that
     * exports with the current page filters applied.
     *
     * The link points at the plugin's own download endpoint; on click, the
     * filterbus appends the live filter values, which the endpoint translates
     * into the report's native filters (locked filters are enforced
     * server-side either way).
     *
     * Arguments: report (id), format (enabled dataformat, default "excel"),
     * label, pageid, consumes (comma-separated filter keys, empty = all).
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param \Closure $next
     * @return string
     */
    public static function downloadreport($shortcode, $args, $content, $env, $next): string {
        global $OUTPUT;

        $args = (array)$args;
        $reportid = (int)($args['report'] ?? $args['reportid'] ?? 0);
        $format = isset($args['format']) ? clean_param($args['format'], PARAM_ALPHA) : 'excel';
        $pageid = isset($args['pageid']) ? clean_param($args['pageid'], PARAM_ALPHANUMEXT) : 'default';
        $label = isset($args['label'])
            ? clean_param($args['label'], PARAM_TEXT)
            : get_string('downloadreport:label', 'local_wb_dashboard');

        $consumes = [];
        if (!empty($args['consumes'])) {
            foreach (explode(',', (string)$args['consumes']) as $key) {
                $key = clean_param(trim($key), PARAM_ALPHANUMEXT);
                if ($key !== '') {
                    $consumes[] = $key;
                }
            }
        }

        if ($reportid <= 0) {
            return get_string('error:invalidreportid', 'local_wb_dashboard');
        }
        $enabledformats = \core_plugin_manager::instance()->get_enabled_plugins('dataformat');
        if (!isset($enabledformats[$format])) {
            return get_string('error:unknowndownloadformat', 'local_wb_dashboard', s($format));
        }

        try {
            $report = manager::get_report_from_id($reportid);
        } catch (\Throwable $e) {
            return get_string('error:invalidreportid', 'local_wb_dashboard');
        }
        $persistent = $report->get_report_persistent();
        if ($persistent->get('type') !== report_base::TYPE_CUSTOM_REPORT) {
            // Only custom reports have user-scoped filters to apply.
            return get_string('error:invalidreportid', 'local_wb_dashboard');
        }
        try {
            permission::require_can_view_report($persistent);
        } catch (\Throwable $e) {
            // No access: render nothing instead of a broken button.
            return '';
        }

        $url = new \moodle_url('/local/wb_dashboard/download.php', [
            'id' => $reportid,
            'download' => $format,
            'sesskey' => sesskey(),
        ]);

        $context = [
            'elementid' => html_writer::random_id('local-dashboard-download-'),
            'url' => $url->out(false),
            'label' => $label,
            'pageid' => $pageid,
            'consumes' => json_encode($consumes),
        ];
        return $OUTPUT->render_from_template('local_wb_dashboard/downloadreport', $context);
    }

    /**
     * [toplist ...] — render a ranked top-N list (rank, label, progress bar,
     * value). Data is loaded client-side via the web service.
     *
     * The row slots are rendered server-side (rank numbers prefilled, hidden);
     * the JS only fills text and bar widths, never builds markup.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param \Closure $next
     * @return string
     */
    public static function toplist($shortcode, $args, $content, $env, $next): string {
        global $OUTPUT;

        $args = (array)$args;
        $definition = toplist_definition::create_definition_from_shortcode_args($args);

        if ($definition->source === '') {
            return get_string('error:missingsource', 'local_wb_dashboard');
        }
        if (!source_registry::exists($definition->source)) {
            return get_string('error:unknownsource', 'local_wb_dashboard', s($definition->source));
        }

        // Optional per-row drill-down: "details" names an admin-configured
        // detail template rendered into a modal (see detail_templates). A
        // misconfigured name surfaces as an error string, like other args.
        $detailsname = $definition->displayopts['details'] ?? '';
        if ($detailsname !== '' && \local_wb_dashboard\local\detail\detail_templates::get($detailsname) === null) {
            return get_string('error:unknowndetailtemplate', 'local_wb_dashboard', s($detailsname));
        }
        // Same context fallback as chart(): a shortcode rendered outside a
        // context-bound page falls back to the system context.
        $envcontext = $env->context ?? \context_system::instance();

        $domid = $definition->to_domid();
        $rows = [];
        for ($rank = 1; $rank <= $definition->displayopts['top']; $rank++) {
            $rows[] = ['rank' => $rank];
        }

        $context = [
            'domid' => $domid,
            'title' => $definition->displayopts['title'],
            'rows' => $rows,
            'pageid' => $definition->pageid,
            'consumes' => json_encode($definition->consumesfilters),
            'wsargs' => json_encode($definition->to_wsargs()),
            'palettename' => palette_manager::name(),
            'hasdetails' => $detailsname !== '',
            'detailsname' => $detailsname,
            'contextid' => (int)$envcontext->id,
            'hasbars' => !empty($definition->displayopts['bars']),
        ];

        return $OUTPUT->render_from_template('local_wb_dashboard/toplist', $context);
    }
}
