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

$string['activemonth:firstlogin'] = 'First login in month';
$string['activemonth:lastlogin'] = 'Last login in month';
$string['activemonth:logins'] = 'Logins in month';
$string['activemonth:month'] = 'Month';
$string['cachedef_chartdata'] = 'Shaped chart data';
$string['cachedef_filteroptions'] = 'Dynamic filter dropdown options';
$string['cachedef_pagefilterstate'] = 'Per-user page filter state';
$string['chart'] = 'Chart';
$string['chartsettings:colourslot'] = 'Colour {$a}';
$string['chartsettings:gear'] = 'Colour settings';
$string['chartsettings:intro'] = 'Choose which palette colour each slot uses. Slots left on their default keep following the active palette.';
$string['chartsettings:invalidcolour'] = 'Choose a colour from the palette.';
$string['chartsettings:paletteoption'] = 'Colour {$a->index} ({$a->hex})';
$string['chartsettings:title'] = 'Chart colours';
$string['datasource:activeusers'] = 'Active users (unique per month)';
$string['daterangefilter:from'] = 'from';
$string['daterangefilter:to'] = 'to';
$string['entity:activemonth'] = 'Active month';
$string['error:invalidfieldcombination'] = 'The "valuefields"/"remainderof" parameters need at least one value field and cannot be combined with "stackfield" or aggregation=count.';
$string['error:invalidreportid'] = 'Invalid or missing report id.';
$string['error:lockedfilterinvalidvalue'] = 'Your assigned value for the filter "{$a}" is not a valid option of the report filter. Please contact your administrator.';
$string['error:lockedfilternovalue'] = 'No value is assigned to you for the filter "{$a}". Please contact your administrator.';
$string['error:missingfilterkey'] = 'The chartfilter shortcode requires a "key" argument.';
$string['error:missingparam'] = 'Missing required parameter "{$a}".';
$string['error:missingsource'] = 'The chart shortcode requires a "source" argument.';
$string['error:noreportdata'] = 'The selected report returned no data.';
$string['error:unknowncharttype'] = 'Unknown chart type "{$a}".';
$string['error:unknowndisplaymode'] = 'Unknown digits display mode "{$a}".';
$string['error:unknownfiltertype'] = 'Unknown filter type "{$a}".';
$string['error:unknownsource'] = 'Unknown chart source "{$a}".';
$string['label:count'] = 'Count';
$string['label:logged'] = 'Logged';
$string['label:remaining'] = 'Remaining';
$string['mapfilter:maplabel'] = 'Clickable map of the regions of Italy';
$string['mapfilter:noregion'] = 'No region selected';
$string['pluginname'] = 'Wunderbyte Dashboard Charts';
$string['privacy:metadata'] = 'The dashboard charts plugin does not store any personal data in the database. Page filter selections are cached transiently only.';
$string['settings:activepalette'] = 'Active palette';
$string['settings:activepalette_desc'] = 'The palette subplugin used site-wide. It supplies the chart colour scheme and (optionally) its own CSS. Install a palette to add a client\'s branding.';
$string['settings:lockedfilters'] = 'Locked filters';
$string['settings:lockedfilters_desc'] = 'Lock filter keys to user profile fields, one mapping per line as "filterkey=profilefieldshortname" (e.g. "region=region"). A mapping may be limited to one or more roles by appending "|role1,role2" (e.g. "region=region|regionalmanager"): only users assigned one of those roles in the system context get that key locked, so different roles can have different filters frozen on the same page. Without a role suffix, the mapping applies to every user. For an affected user, a mapped filter key is forced server-side to that user\'s own profile field value on all charts and digits; the [chartfilter] control for the key is replaced by a static value. The "local/wb_dashboard:ignorelockedfilters" capability exempts a user from all locks. The profile field value must exactly match the report filter\'s option values (case and spelling). A locked user with an empty profile field sees no data. Role names must match existing role shortnames; an unmatched role name means the lock applies to nobody.';
$string['shortcode:chart'] = 'Render a chart from a data source (type, source and filters configurable).';
$string['shortcode:chartfilter'] = 'Render a page-level filter control that all charts on the page react to.';
$string['shortcode:digits'] = 'Render a single numeric value (number, count or percentage) as a styleable field.';
$string['subplugintype_wbdashboardpalette'] = 'Dashboard palette';
$string['subplugintype_wbdashboardpalette_plural'] = 'Dashboard palettes';
$string['wb_dashboard:configurecharts'] = 'Configure per-chart colours';
$string['wb_dashboard:ignorelockedfilters'] = 'Ignore locked page filters';
