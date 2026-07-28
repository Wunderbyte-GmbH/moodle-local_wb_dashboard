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
 * Reset-all-filters button. On click, clears every page filter via the
 * filterbus, which blanks the controls, the URL and the server cache and
 * reloads all charts.
 *
 * @module     local_wb_dashboard/filterreset
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Filterbus from 'local_wb_dashboard/filterbus';

export default {
    /**
     * Initialise a reset button.
     *
     * @param {String} elementId
     */
    init: (elementId) => {
        const button = document.getElementById(elementId);
        if (!button || button.dataset.ldInitialised === '1') {
            return;
        }
        button.dataset.ldInitialised = '1';
        button.addEventListener('click', (e) => {
            e.preventDefault();
            Filterbus.reset();
        });
    }
};
