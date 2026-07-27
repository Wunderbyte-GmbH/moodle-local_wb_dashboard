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
 * Per-row "see details" modal for the [toplist] shortcode.
 *
 * A click on a row's detail button opens a modal whose body is the named
 * detail template (an admin setting), rendered server-side via the fragment
 * API with the row's raw id and label substituted in — so the shortcodes
 * inside the template load their data pinned to the clicked entity.
 *
 * @module     local_wb_dashboard/detail_modal
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import Fragment from 'core/fragment';
import Notification from 'core/notification';

/**
 * Escape a plain string for use in an HTML context (the modal title).
 *
 * @param {String} text
 * @return {String}
 */
const escapeHtml = (text) => {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
};

/**
 * Open the detail modal for one row.
 *
 * @param {HTMLElement} root The toplist wrapper (carries details/contextid).
 * @param {HTMLElement} rowEl The clicked row (carries rowid/rowlabel).
 * @return {Promise}
 */
const openModal = (root, rowEl) => {
    const params = {
        name: root.dataset.details || '',
        value: rowEl.dataset.rowid || '',
        label: rowEl.dataset.rowlabel || '',
    };
    // The fragment body promise resolves with (html, js); the modal inserts
    // both, so the inner shortcode modules boot exactly as on a normal page.
    // removeOnClose matters: the inner widgets' DOM ids are deterministic and
    // carry init guards, so a closed modal's DOM must not linger.
    return Modal.create({
        title: escapeHtml(rowEl.dataset.rowlabel || ''),
        body: Fragment.loadFragment('local_wb_dashboard', 'detail',
            parseInt(root.dataset.contextid, 10) || 1, params),
        large: true,
        show: true,
        removeOnClose: true,
    }).catch(Notification.exception);
};

export default {
    /**
     * Initialise the detail buttons of one toplist wrapper.
     *
     * @param {String} domId
     */
    init: (domId) => {
        const root = document.getElementById(domId);
        if (!root || root.dataset.ldDetailInitialised === '1') {
            return;
        }
        root.dataset.ldDetailInitialised = '1';
        root.addEventListener('click', (e) => {
            const button = e.target.closest('[data-action="wb-dashboard-detail"]');
            if (!button || !root.contains(button)) {
                return;
            }
            const rowEl = button.closest('[data-region="toplist-row"]');
            if (!rowEl || !rowEl.dataset.rowid) {
                return;
            }
            e.preventDefault();
            openModal(root, rowEl);
        });
    }
};
