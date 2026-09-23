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
 * Cascading select: a select filter B that follows one or more other filters live.
 *
 * Whenever any of its parents changes on the bus, B's dynamic options are
 * re-fetched scoped by every parent value at once (get_filter_options web
 * service) and B's option list is rebuilt. What happens to B's own value then
 * depends on how narrow the scope is:
 *
 * - exactly one option left: it is selected and the control is locked
 *   (disabled), because the parents already determine it — picking an ASL
 *   settles its region, picking a user settles both. The control the user
 *   just picked from is never locked, so mutually linked controls cannot
 *   freeze each other;
 * - several options left: the list is merely narrowed. B keeps its value when
 *   it is still offered and is cleared when it is not, so the user still
 *   chooses freely inside the scope;
 * - no parent set at all: the server-rendered (unscoped) options are restored,
 *   again keeping B's value when it survives.
 *
 * Any value the module picks is published through the bus like a user change,
 * so charts consuming B reload. Parents may cascade from B in turn (mutual
 * linking): publishing only on a real change makes such a cycle settle.
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
 * The real (non-placeholder) option values currently offered.
 *
 * @param {HTMLSelectElement} select
 * @return {String[]}
 */
const offered = (select) => Array.from(select.options)
    .map((node) => node.value)
    .filter((value) => value !== '');

export default {
    /**
     * Wire a select control (by id) to the filters it cascades from. The
     * wrapper carries data-cascadefrom (the parent keys, comma separated) and
     * data-optionsargs (JSON web-service args to re-fetch the options).
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

        const keys = (wrapper.dataset.filterKeys || wrapper.dataset.filterKey || '')
            .split(',').filter(Boolean);
        if (!keys.length) {
            return;
        }
        // A control never scopes itself, whichever key of its own is named.
        const parentKeys = (wrapper.dataset.cascadefrom || '')
            .split(',').filter((key) => key !== '' && keys.indexOf(key) === -1);
        let wsargs = {};
        try {
            wsargs = JSON.parse(wrapper.dataset.optionsargs || '{}');
        } catch (e) {
            return;
        }
        if (!parentKeys.length || !wsargs.source) {
            return;
        }

        // The server-rendered options are the unscoped list: keep a copy to
        // restore when no parent is set, without another request.
        const unscoped = Array.from(select.children).slice(1).map((node) => node.cloneNode(true));
        let requestToken = 0;
        let locked = false;

        /**
         * Lock (disable) the control while its parents fully determine it, or
         * release it again.
         *
         * @param {Boolean} value
         */
        const setLocked = (value) => {
            locked = value;
            select.disabled = value;
            // Every control of these keys carries the mark, not just this one:
            // a map bound to the same key must refuse clicks while it holds.
            Filterbus.markLocked(keys, value);
        };

        /**
         * Toggle the loading state on the control, leaving a lock in place.
         *
         * @param {Boolean} busy
         */
        const setBusy = (busy) => {
            select.disabled = busy || locked;
            if (busy) {
                select.setAttribute('aria-busy', 'true');
            } else {
                select.removeAttribute('aria-busy');
            }
        };

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
         * Whether the user's own last choice was this very control. Two
         * controls that scope each other can both end up with a single option;
         * leaving the one the user just picked unlocked keeps a way out of
         * that state without resetting every filter.
         *
         * @return {Boolean}
         */
        const ownsLastUserChange = () =>
            Filterbus.lastUserKeys().some((key) => keys.indexOf(key) !== -1);

        /**
         * Keep the control's current value when the new option list still
         * offers it, lock it to the only option left, or clear it.
         *
         * @param {Boolean} scoped Whether at least one parent is set.
         */
        const settleWithin = (scoped) => {
            const values = offered(select);
            if (scoped && values.length === 1 && !ownsLastUserChange()) {
                setLocked(true);
                settle(values[0]);
                return;
            }
            setLocked(false);
            const current = busValue(keys);
            settle(values.indexOf(current) === -1 ? '' : current);
        };

        /**
         * React to the parents' current values.
         */
        const apply = () => {
            const token = ++requestToken; // Also supersedes any in-flight fetch.
            const filtervalues = Filterbus.valuesFor(parentKeys);
            if (!filtervalues.length) {
                clearOptions(select);
                unscoped.forEach((node) => select.appendChild(node.cloneNode(true)));
                setBusy(false);
                settleWithin(false);
                return;
            }

            setBusy(true);
            const args = {
                source: wsargs.source,
                sourceparams: wsargs.sourceparams || [],
                field: wsargs.field || '',
                groupfield: wsargs.groupfield || '',
                filtervalues: filtervalues
            };
            Ajax.call([{methodname: 'local_wb_dashboard_get_filter_options', args: args}])[0]
                .then((result) => {
                    if (token !== requestToken) {
                        return null; // A newer request superseded this one.
                    }
                    rebuild(select, result);
                    setBusy(false);
                    settleWithin(true);
                    return null;
                })
                .catch((error) => {
                    if (token === requestToken) {
                        setBusy(false);
                    }
                    Notification.exception(error);
                });
        };

        Filterbus.subscribe({reload: () => apply()}, parentKeys);

        // Initial pass once every control on the page has registered with the
        // bus (a parent may come later in the content): a parent already set
        // from the URL or cached state scopes us right away.
        window.setTimeout(() => {
            if (Filterbus.valuesFor(parentKeys).length) {
                apply();
            }
        }, 0);
    }
};
