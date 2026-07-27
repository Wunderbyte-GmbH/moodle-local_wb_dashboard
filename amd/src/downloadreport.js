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
 * Filter-aware report download button. On click, the current filterbus values
 * for the consumed keys are appended to the link, so the endpoint exports the
 * report with the same filters the charts on the page are showing.
 *
 * @module     local_wb_dashboard/downloadreport
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Filterbus from 'local_wb_dashboard/filterbus';

export default {
    /**
     * Initialise a download link.
     *
     * @param {String} elementId
     */
    init: (elementId) => {
        const link = document.getElementById(elementId);
        if (!link || link.dataset.ldInitialised === '1') {
            return;
        }
        link.dataset.ldInitialised = '1';
        const consumes = JSON.parse(link.dataset.consumes || '[]');

        // The state is read at click time (not at init), so the link always
        // carries whatever the filters say right now.
        link.addEventListener('click', () => {
            const url = new URL(link.href, window.location.href);
            url.searchParams.set('filters', JSON.stringify(Filterbus.valuesFor(consumes)));
            link.href = url.toString();
        });
    }
};
