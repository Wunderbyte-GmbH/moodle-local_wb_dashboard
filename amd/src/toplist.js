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
 * Thin top-list runtime. The PHP web service already ranked and formatted the
 * rows; this module only fetches them, fills the server-rendered row slots
 * (via textContent and bar widths), and re-queries when a subscribed filter
 * changes.
 *
 * @module     local_wb_dashboard/toplist
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Filterbus from 'local_wb_dashboard/filterbus';

/**
 * Turn one list wrapper into a live, filter-aware top list.
 *
 * @param {HTMLElement} root
 * @return {Object}
 */
const createController = (root) => {
    const rowEls = root.querySelectorAll('[data-region="toplist-row"]');
    const emptyEl = root.querySelector('[data-region="toplist-empty"]');
    const skeleton = root.querySelector('.local-dashboard-toplist-skeleton');
    const wsargs = JSON.parse(root.dataset.wsargs || '{}');
    const consumes = JSON.parse(root.dataset.consumes || '[]');
    let requestToken = 0;

    const setBusy = (busy) => {
        root.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (skeleton) {
            skeleton.style.display = busy ? '' : 'none';
        }
    };

    const render = (data) => {
        rowEls.forEach((rowEl, i) => {
            const row = data.rows[i];
            if (!row) {
                rowEl.style.display = 'none';
                return;
            }
            rowEl.style.display = '';
            rowEl.querySelector('[data-region="toplist-label"]').textContent = row.label;
            rowEl.querySelector('[data-region="toplist-value"]').textContent = row.formatted;
            const bar = rowEl.querySelector('[data-region="toplist-bar"]');
            bar.style.width = row.percent + '%';
            bar.setAttribute('title', row.percent + '%');
        });
        if (emptyEl) {
            emptyEl.style.display = data.rows.length ? 'none' : '';
        }
        setBusy(false);
    };

    const reload = () => {
        setBusy(true);
        const token = ++requestToken;
        const args = {
            source: wsargs.source,
            sourceparams: wsargs.sourceparams || [],
            top: wsargs.top || 5,
            order: wsargs.order || 'desc',
            barmode: wsargs.barmode || 'relative',
            max: wsargs.max || 0,
            decimals: wsargs.decimals || 0,
            suffix: wsargs.suffix || '',
            filtervalues: Filterbus.valuesFor(consumes)
        };
        Ajax.call([{methodname: 'local_wb_dashboard_get_toplist_data', args: args}])[0]
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
     * Initialise a top list.
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
