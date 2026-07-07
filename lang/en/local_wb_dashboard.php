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

/**
 * Language strings for local_wb_dashboard.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['cachedef_pagefilterstate'] = 'Per-user page filter state';
$string['chart'] = 'Chart';
$string['chartsettings:colourslot'] = 'Colour {$a}';
$string['chartsettings:gear'] = 'Colour settings';
$string['chartsettings:intro'] = 'Choose which palette colour each slot uses. Slots left on their default keep following the active palette.';
$string['chartsettings:invalidcolour'] = 'Choose a colour from the palette.';
$string['chartsettings:paletteoption'] = 'Colour {$a->index} ({$a->hex})';
$string['chartsettings:saved'] = 'Chart colours saved.';
$string['chartsettings:title'] = 'Chart colours';
$string['error:invalidfield'] = 'Invalid or missing report field.';
$string['error:invalidreportid'] = 'Invalid or missing report id.';
$string['error:missingfilterkey'] = 'The chartfilter shortcode requires a "key" argument.';
$string['error:missingparam'] = 'Missing required parameter "{$a}".';
$string['error:missingsource'] = 'The chart shortcode requires a "source" argument.';
$string['error:noreportdata'] = 'The selected report returned no data.';
$string['error:notallowed'] = 'You are not allowed to view this data.';
$string['error:unknowncharttype'] = 'Unknown chart type "{$a}".';
$string['error:unknowndisplaymode'] = 'Unknown digits display mode "{$a}".';
$string['error:unknownfiltertype'] = 'Unknown filter type "{$a}".';
$string['error:unknownsource'] = 'Unknown chart source "{$a}".';
$string['label:count'] = 'Count';
$string['label:logged'] = 'Logged';
$string['label:remaining'] = 'Remaining';
$string['pluginname'] = 'Wunderbyte Dashboard Charts';
$string['privacy:metadata'] = 'The dashboard charts plugin does not store any personal data in the database. Page filter selections are cached transiently only.';
$string['settings:activepalette'] = 'Active palette';
$string['settings:activepalette_desc'] = 'The palette subplugin used site-wide. It supplies the chart colour scheme and (optionally) its own CSS. Install a palette to add a client\'s branding.';
$string['shortcode:chart'] = 'Render a chart from a data source (type, source and filters configurable).';
$string['shortcode:chartfilter'] = 'Render a page-level filter control that all charts on the page react to.';
$string['shortcode:digits'] = 'Render a single numeric value (number, count or percentage) as a styleable field.';
$string['subplugintype_wbdashboardpalette'] = 'Dashboard palette';
$string['subplugintype_wbdashboardpalette_plural'] = 'Dashboard palettes';
$string['wb_dashboard:configurecharts'] = 'Configure per-chart colours';
$string['wb_dashboard:view'] = 'View dashboard charts';
