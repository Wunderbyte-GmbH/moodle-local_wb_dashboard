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
 * Cascading select: a select filter B that follows another filter A live.
 *
 * Whenever A's value changes on the bus, B's dynamic options are re-fetched
 * scoped by that value (get_filter_options web service), B's option list is
 * rebuilt and B is set to its first option (keeping its current value when
 * that is still available). The new value is published through the bus like
 * a user change, so charts consuming B reload. When A is cleared, the
 * server-rendered (unscoped) options are restored and B is cleared.
 *
 * The module owns nothing but the A -> B wiring; the bus still handles B's
 * own change events, URL state and persistence.
 *
 * @module     local_wb_dashboard/cascadeselect
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Filterbus from 'local_wb_dashboard/filterbus';

/**
 * The bus value for the first of the given keys ('' when unset).
 *
 * @param {String[]} keys
 * @return {String}
 */
const busValue = (keys) => {
    const values = Filterbus.valuesFor(keys);
    return values.length ? values[0].value : '';
};

/**
 * Drop every option after the placeholder (index 0).
 *
 * @param {HTMLSelectElement} select
 */
const clearOptions = (select) => {
    while (select.children.length > 1) {
        select.removeChild(select.lastChild);
    }
};

/**
 * Append an option built from a {value, label} pair. Labels are written as
 * text: they are server-formatted strings, never markup.
 *
 * @param {HTMLElement} parent The select or an optgroup.
 * @param {{value: String, label: String}} option
 */
const appendOption = (parent, option) => {
    const node = document.createElement('option');
    node.value = option.value;
    node.textContent = option.label;
    parent.appendChild(node);
};

/**
 * Rebuild the option list from a web-service result. Like the server-side
 * render, a single group is shown flat (no optgroup wrapper).
 *
 * @param {HTMLSelectElement} select
 * @param {{options: Array, groups: Array}} result
 */
const rebuild = (select, result) => {
    clearOptions(select);
    const groups = result.groups || [];
    if (groups.length > 1) {
        groups.forEach((group) => {
            const optgroup = document.createElement('optgroup');
            optgroup.label = group.label;
            group.options.forEach((option) => appendOption(optgroup, option));
            select.appendChild(optgroup);
        });
    } else if (groups.length === 1) {
        groups[0].options.forEach((option) => appendOption(select, option));
    } else {
        (result.options || []).forEach((option) => appendOption(select, option));
    }
};

/**
 * The value of the first real (non-placeholder) option, '' when there is none.
 *
 * @param {HTMLSelectElement} select
 * @return {String}
 */
const firstValue = (select) => {
    const option = Array.from(select.options).find((node) => node.value !== '');
    return option ? option.value : '';
};

/**
 * Whether the select currently offers the given non-empty value.
 *
 * @param {HTMLSelectElement} select
 * @param {String} value
 * @return {Boolean}
 */
const offers = (select, value) =>
    value !== '' && Array.from(select.options).some((node) => node.value === value);

/**
 * Toggle the loading state on the control.
 *
 * @param {HTMLSelectElement} select
 * @param {Boolean} busy
 */
const setBusy = (select, busy) => {
    select.disabled = busy;
    if (busy) {
        select.setAttribute('aria-busy', 'true');
    } else {
        select.removeAttribute('aria-busy');
    }
};

export default {
    /**
     * Wire a select control (by id) to the filter it cascades from. The
     * wrapper carries data-cascadefrom (the parent key) and data-optionsargs
     * (JSON web-service args to re-fetch the options).
     *
     * @param {String} controlId
     */
    init: (controlId) => {
        const select = document.getElementById(controlId);
        if (!select) {
            return;
        }
        const wrapper = select.closest('[data-region="chart-filter"]');
        if (!wrapper || wrapper.dataset.cascadeInitialised) {
            return;
        }
        wrapper.dataset.cascadeInitialised = '1';

        const parentKey = wrapper.dataset.cascadefrom || '';
        let wsargs = {};
        try {
            wsargs = JSON.parse(wrapper.dataset.optionsargs || '{}');
        } catch (e) {
            return;
        }
        if (parentKey === '' || !wsargs.source) {
            return;
        }
        const keys = (wrapper.dataset.filterKeys || wrapper.dataset.filterKey || '')
            .split(',').filter(Boolean);
        if (!keys.length) {
            return;
        }

        // The server-rendered options are the unscoped list: keep a copy to
        // restore when the parent is cleared, without another request.
        const unscoped = Array.from(select.children).slice(1).map((node) => node.cloneNode(true));
        let requestToken = 0;

        /**
         * Select a value and publish it on the bus if it is not already there.
         *
         * @param {String} value
         */
        const settle = (value) => {
            select.value = value;
            if (busValue(keys) !== value) {
                Filterbus.setValue(controlId, value);
            }
        };

        /**
         * React to the parent's current value.
         *
         * @param {String} parentValue
         */
        const apply = (parentValue) => {
            const token = ++requestToken; // Also supersedes any in-flight fetch.
            if (parentValue === '') {
                clearOptions(select);
                unscoped.forEach((node) => select.appendChild(node.cloneNode(true)));
                setBusy(select, false);
                settle('');
                return;
            }

            setBusy(select, true);
            const args = {
                source: wsargs.source,
                sourceparams: wsargs.sourceparams || [],
                field: wsargs.field || '',
                groupfield: wsargs.groupfield || '',
                filtervalues: Filterbus.valuesFor([parentKey])
            };
            Ajax.call([{methodname: 'local_wb_dashboard_get_filter_options', args: args}])[0]
                .then((result) => {
                    if (token !== requestToken) {
                        return null; // A newer request superseded this one.
                    }
                    rebuild(select, result);
                    setBusy(select, false);
                    const current = busValue(keys);
                    settle(offers(select, current) ? current : firstValue(select));
                    return null;
                })
                .catch((error) => {
                    if (token === requestToken) {
                        setBusy(select, false);
                    }
                    Notification.exception(error);
                });
        };

        const parentValue = () => busValue([parentKey]);

        Filterbus.subscribe({reload: () => apply(parentValue())}, [parentKey]);

        // Initial pass once every control on the page has registered with the
        // bus (the parent may come later in the content): a parent already set
        // from the URL or cached state scopes us right away.
        window.setTimeout(() => {
            if (parentValue() !== '') {
                apply(parentValue());
            }
        }, 0);
    }
};
