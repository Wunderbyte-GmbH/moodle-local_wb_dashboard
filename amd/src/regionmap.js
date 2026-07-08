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
 * Clickable regions-of-Italy map filter control. The visible SVG is only a
 * proxy for a hidden filter input: activating a region writes its value into
 * that input and dispatches a native "change" event, so the existing filter bus
 * (registered separately on the same input) handles URL state, persistence and
 * chart reloads. This module owns nothing but the map <-> input wiring.
 *
 * The map may share its filter key with any other control on the page (e.g. a
 * region select): the bus keeps all controls of a key in sync and announces
 * externally-written values via its reflect event, on which the map repaints.
 *
 * @module     local_wb_dashboard/regionmap
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Filterbus from 'local_wb_dashboard/filterbus';

/**
 * Reflect the current filter value onto the map: mark the active region and
 * update the readout with its display name (or the empty-state text).
 *
 * @param {HTMLInputElement} input The hidden filter control.
 * @param {NodeListOf<Element>} paths The region <path> elements.
 * @param {HTMLElement|null} readout The live readout element.
 */
const reflect = (input, paths, readout) => {
    const value = input.value;
    let activename = '';
    paths.forEach((path) => {
        const active = path.dataset.value === value && value !== '';
        path.classList.toggle('selected', active);
        path.setAttribute('aria-pressed', active ? 'true' : 'false');
        if (active) {
            activename = path.dataset.name;
        }
    });
    if (readout) {
        readout.textContent = activename !== '' ? activename : readout.dataset.emptytext;
        readout.classList.toggle('active', activename !== '');
    }
};

/**
 * Toggle a region: activate it, or clear the filter if it is already active,
 * then notify the filter bus via a change event.
 *
 * @param {HTMLInputElement} input The hidden filter control.
 * @param {NodeListOf<Element>} paths The region <path> elements.
 * @param {HTMLElement|null} readout The live readout element.
 * @param {Element} path The activated region.
 */
const activate = (input, paths, readout, path) => {
    const value = path.dataset.value;
    input.value = (input.value === value) ? '' : value;
    reflect(input, paths, readout);
    input.dispatchEvent(new Event('change', {bubbles: true}));
};

export default {
    /**
     * Wire a map control (identified by its hidden input id) to the filter bus.
     *
     * @param {String} controlId The hidden input's DOM id.
     */
    init: (controlId) => {
        const input = document.getElementById(controlId);
        if (!input) {
            return;
        }
        const wrapper = input.closest('[data-region="chart-filter"]');
        if (!wrapper || wrapper.dataset.mapInitialised) {
            return;
        }
        wrapper.dataset.mapInitialised = '1';

        const paths = wrapper.querySelectorAll('.wb-region');
        const readout = wrapper.querySelector('[data-region="readout"]');

        paths.forEach((path) => {
            path.addEventListener('click', () => activate(input, paths, readout, path));
            path.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    activate(input, paths, readout, path);
                }
            });
            // Preview the region name on hover/focus; restore the active one on leave.
            path.addEventListener('mouseenter', () => {
                if (readout) {
                    readout.textContent = path.dataset.name;
                    readout.classList.add('active');
                }
            });
            path.addEventListener('focus', () => {
                if (readout) {
                    readout.textContent = path.dataset.name;
                    readout.classList.add('active');
                }
            });
            path.addEventListener('mouseleave', () => reflect(input, paths, readout));
            path.addEventListener('blur', () => reflect(input, paths, readout));
        });

        // Repaint whenever the bus writes a value into the input (URL/cache
        // seeding, or a sibling control bound to the same filter key).
        input.addEventListener(Filterbus.eventTypes.reflect, () => reflect(input, paths, readout));

        // The filter bus may have seeded the input from URL/cache state before us.
        reflect(input, paths, readout);
    }
};
