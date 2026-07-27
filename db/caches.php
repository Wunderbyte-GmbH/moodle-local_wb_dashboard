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
 * Cache definitions for local_wb_dashboard.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Shaped chart data, keyed by a hash of source + params + constraints.
    // Shared across users: object-level access is enforced before every read,
    // and per-user forced (locked) filter values are part of the key. The TTL
    // bounds staleness — dashboards tolerate a few minutes of it in exchange
    // for not re-running the report query per chart, viewer and filter change.
    'chartdata' => [
        'mode'         => cache_store::MODE_APPLICATION,
        'simplekeys'   => true,
        'simpledata'   => false,
        'ttl'          => 300,
    ],
    // Dynamic select-filter options, keyed by a hash of source + params + field.
    // Options are user-independent (access is checked before every read), but the
    // fallback path runs a full report query, so results are shared and TTL-bound.
    'filteroptions' => [
        'mode'         => cache_store::MODE_APPLICATION,
        'simplekeys'   => true,
        'simpledata'   => false,
        'ttl'          => 600,
    ],
    // Per-user page filter state, keyed by "<userid>_<pageid>". Persists the last-used
    // filter selection across visits when the URL carries no state.
    'pagefilterstate' => [
        'mode'         => cache_store::MODE_APPLICATION,
        'simplekeys'   => true,
        'simpledata'   => false,
        'ttl'          => 0,
    ],
];
