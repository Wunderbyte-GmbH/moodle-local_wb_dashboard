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
 * Thin digits-field runtime. The PHP web service already reduced the data to a
 * single formatted value; this module only fetches it, writes it into the DOM
 * (via textContent), and re-queries when a subscribed filter changes.
 *
 * @module     local_wb_dashboard/digits
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Filterbus from 'local_wb_dashboard/filterbus';

/**
 * Turn one field wrapper into a live, filter-aware digits field.
 *
 * @param {HTMLElement} root
 * @return {Object}
 */
const createController = (root) => {
    const valueEl = root.querySelector('.local-dashboard-digits-value');
    const labelEl = root.querySelector('.local-dashboard-digits-label');
    const skeleton = root.querySelector('.local-dashboard-digits-skeleton');
    const wsargs = JSON.parse(root.dataset.wsargs || '{}');
    const consumes = JSON.parse(root.dataset.consumes || '[]');
    // Fixed filter values pinned on this instance; they always win over the
    // page filter of the same key (locked filters still win server-side).
    const fixedFilters = wsargs.fixedfilters || [];
    const fixedKeys = fixedFilters.map((f) => f.key);
    let requestToken = 0;

    const setBusy = (busy) => {
        root.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (skeleton) {
            skeleton.style.display = busy ? '' : 'none';
        }
    };

    const render = (data) => {
        if (valueEl) {
            valueEl.textContent = data.formatted;
            valueEl.classList.toggle('local-dashboard-digits-value--percent', Boolean(data.ispercent));
        }
        if (labelEl) {
            labelEl.textContent = data.label || '';
        }
        setBusy(false);
    };

    const reload = () => {
        setBusy(true);
        const token = ++requestToken;
        const args = {
            source: wsargs.source,
            display: wsargs.display || 'number',
            sourceparams: wsargs.sourceparams || [],
            label: wsargs.label || '',
            decimals: wsargs.decimals || 0,
            unit: wsargs.unit || '',
            filtervalues: fixedFilters.concat(
                Filterbus.valuesFor(consumes).filter((fv) => fixedKeys.indexOf(fv.key) === -1))
        };
        Ajax.call([{methodname: 'local_wb_dashboard_get_digits_data', args: args}])[0]
            .then((result) => {
                if (token !== requestToken) {
                    return null; // A newer request superseded this one.
                }
                render(result);
                return null;
            })
            .catch((error) => {
                setBusy(false);
                Notification.exception(error);
            });
    };

    return {reload: reload, consumes: consumes};
};

export default {
    /**
     * Initialise a digits field.
     *
     * @param {String} domId
     */
    init: (domId) => {
        const root = document.getElementById(domId);
        if (!root || root.dataset.ldInitialised === '1') {
            return;
        }
        root.dataset.ldInitialised = '1';
        const controller = createController(root);
        Filterbus.subscribe(controller, controller.consumes);
        controller.reload();
    }
};
