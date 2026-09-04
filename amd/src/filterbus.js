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
 * Page-level shared filter state. A single instance per page holds the current
 * filter values, keeps them in the URL (canonical) and the server cache
 * (persistence), and notifies subscribed charts to re-query on change.
 *
 * @module     local_wb_dashboard/filterbus
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

const URL_PREFIX = 'ldf_';
const DEBOUNCE_MS = 300;
const ACTIVE_CLASS = 'wb-filter-active';

/**
 * Events dispatched on registered control elements. Exposed on the default
 * export (not as a named export — the AMD build returns only the default).
 *
 * @type {{reflect: String}} reflect - the bus wrote a new value into the
 *     control (from a sibling control, URL or cache); widgets wrapping the
 *     control (e.g. the region map) should repaint from control.value.
 */
const eventTypes = {
    reflect: 'local_wb_dashboard_filterbus/reflect',
};

// Page-singleton state.
const state = {};
const charts = [];
const controls = {};
let pageid = 'default';
let urlLoaded = false;
let persistTimer = null;

/**
 * Read the ldf_* params from the URL once into state (values only; types are
 * filled as controls register).
 */
const ensureUrlLoaded = () => {
    if (urlLoaded) {
        return;
    }
    urlLoaded = true;
    const params = new URLSearchParams(window.location.search);
    params.forEach((value, name) => {
        if (name.indexOf(URL_PREFIX) === 0) {
            const key = name.substring(URL_PREFIX.length);
            state[key] = {value: value, type: (state[key] && state[key].type) || 'text'};
        }
    });
};

/**
 * Mirror whether a control holds a value onto its wrapper, so CSS can
 * highlight ("glow") filters that are currently set. Called from every path
 * that writes a control's value: registration, user change, sibling sync
 * and reset.
 *
 * @param {HTMLElement} control
 */
const reflectActive = (control) => {
    const wrapper = control.closest('[data-region="chart-filter"]');
    if (!wrapper) {
        return;
    }
    const value = control.value === null ? '' : String(control.value);
    wrapper.classList.toggle(ACTIVE_CLASS, value !== '');
};

/**
 * Write a single filter value into the URL without reloading.
 *
 * @param {String} key
 * @param {String} value
 */
const updateUrl = (key, value) => {
    const url = new URL(window.location.href);
    if (value === '' || value === null) {
        url.searchParams.delete(URL_PREFIX + key);
    } else {
        url.searchParams.set(URL_PREFIX + key, value);
    }
    window.history.replaceState({}, '', url.toString());
};

/**
 * Persist the whole state to the server cache (debounced).
 */
const persist = () => {
    if (persistTimer) {
        window.clearTimeout(persistTimer);
    }
    persistTimer = window.setTimeout(() => {
        const filtervalues = Object.keys(state).map((key) => ({key: key, value: String(state[key].value)}));
        Ajax.call([{
            methodname: 'local_wb_dashboard_set_filter_state',
            args: {pageid: pageid, filtervalues: filtervalues}
        }])[0].catch(() => {
            // A failed persist must not break the page; URL state remains canonical.
            return null;
        });
    }, DEBOUNCE_MS);
};

/**
 * Notify every chart that consumes any of the changed keys, at most once per
 * chart (a chart consuming several of them must not fetch repeatedly).
 *
 * @param {String[]} keys
 */
const notify = (keys) => {
    charts.forEach((entry) => {
        if (entry.keys.length === 0 || entry.keys.some((k) => keys.indexOf(k) !== -1)) {
            entry.api.reload();
        }
    });
};

/**
 * Reflect a value into every other control registered for the key, so
 * multiple controls bound to the same key (e.g. a region select and a region
 * map) stay in sync. Dispatches the reflect event — never a change event,
 * which would loop back into the bus.
 *
 * @param {String} key
 * @param {String} value
 * @param {HTMLElement|null} origin The control the value came from.
 */
const syncControls = (key, value, origin) => {
    (controls[key] || []).forEach((control) => {
        if (control === origin || control.value === value) {
            return;
        }
        control.value = value;
        reflectActive(control);
        control.dispatchEvent(new CustomEvent(eventTypes.reflect));
    });
};

/**
 * Handle a control value change: publish the value under every key the
 * control is registered for (a multi-key control fans one value out to all
 * of its keys), then persist and notify once.
 *
 * @param {String[]} keys
 * @param {String} type
 * @param {String} value
 * @param {HTMLElement} origin The control that changed.
 */
const handleChange = (keys, type, value, origin) => {
    reflectActive(origin);
    keys.forEach((key) => {
        state[key] = {value: value, type: type};
        syncControls(key, value, origin);
        updateUrl(key, value);
    });
    persist();
    notify(keys);
};

/**
 * Simple trailing debounce.
 *
 * @param {Function} fn
 * @param {Number} wait
 * @return {Function}
 */
const debounce = (fn, wait) => {
    let timer = null;
    return (...args) => {
        if (timer) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(() => fn(...args), wait);
    };
};

export default {
    eventTypes: eventTypes,

    /**
     * Register a filter control element (by id) with the bus. Several controls
     * may register the same key; they act as one filter and are kept in sync.
     * A control may declare several keys (data-filter-keys, comma-separated):
     * it then publishes one value under every key.
     *
     * @param {String} controlId
     */
    registerControl: (controlId) => {
        ensureUrlLoaded();
        const control = document.getElementById(controlId);
        if (!control) {
            return;
        }
        const wrapper = control.closest('[data-region="chart-filter"]');
        if (!wrapper) {
            return;
        }
        const keys = (wrapper.dataset.filterKeys || wrapper.dataset.filterKey || '')
            .split(',').filter(Boolean);
        if (!keys.length) {
            return;
        }
        const type = wrapper.dataset.filterType;
        pageid = wrapper.dataset.pageid || pageid;

        // URL state and an already-registered control for the same key win over
        // this control's server-rendered (cache) value. First winning key
        // supplies the seed; controls with overlapping-but-different key sets
        // are not reconciled beyond that (same limitation as before, per key).
        const params = new URLSearchParams(window.location.search);
        const winner = keys.find((key) =>
            state[key] && typeof state[key].value !== 'undefined'
            && (params.has(URL_PREFIX + key) || (controls[key] || []).length > 0));
        const seed = winner ? state[winner].value : control.value;
        if (winner && control.value !== seed) {
            control.value = seed;
            control.dispatchEvent(new CustomEvent(eventTypes.reflect));
        }
        reflectActive(control);

        // Claim every key: the state entry fixes the placeholder type stamped
        // while reading the URL, so fan-out keys keep the control's real type.
        keys.forEach((key) => {
            state[key] = {value: seed, type: type};
            controls[key] = controls[key] || [];
            controls[key].push(control);
        });

        const onChange = debounce(() => handleChange(keys, type, control.value, control), DEBOUNCE_MS);
        control.addEventListener('change', onChange);
        control.addEventListener('input', onChange);
    },

    /**
     * Subscribe a chart to filter changes.
     *
     * @param {Object} api An object exposing reload().
     * @param {String[]} keys Filter keys the chart consumes ([] = all).
     */
    subscribe: (api, keys) => {
        charts.push({api: api, keys: Array.isArray(keys) ? keys : []});
    },

    /**
     * Clear every filter: blank all state, controls and ldf_* URL params,
     * persist the empty state and reload all charts once.
     */
    reset: () => {
        ensureUrlLoaded();
        const keys = Object.keys(state);
        keys.forEach((key) => {
            state[key] = {value: '', type: state[key].type};
            (controls[key] || []).forEach((control) => {
                if (control.value !== '') {
                    control.value = '';
                    reflectActive(control);
                    control.dispatchEvent(new CustomEvent(eventTypes.reflect));
                }
            });
            updateUrl(key, '');
        });
        if (keys.length) {
            persist();
            notify(keys);
        }
    },

    /**
     * Programmatically set a registered control's value and publish it exactly
     * like a user change (sibling sync, URL, persistence, chart reloads) —
     * immediately, not debounced. Used by dependent controls (cascadeselect)
     * that pick a value on the user's behalf. The control's own change
     * listener does not fire, so this cannot loop back into the bus.
     *
     * @param {String} controlId
     * @param {String} value
     */
    setValue: (controlId, value) => {
        const control = document.getElementById(controlId);
        if (!control) {
            return;
        }
        const wrapper = control.closest('[data-region="chart-filter"]');
        if (!wrapper) {
            return;
        }
        const keys = (wrapper.dataset.filterKeys || wrapper.dataset.filterKey || '')
            .split(',').filter(Boolean);
        if (!keys.length) {
            return;
        }
        control.value = value;
        handleChange(keys, wrapper.dataset.filterType, value, control);
    },

    /**
     * Return the current {key, type, value} triples for the given keys
     * ([] = all), skipping empty values.
     *
     * @param {String[]} keys
     * @return {Array}
     */
    valuesFor: (keys) => {
        const wanted = Array.isArray(keys) ? keys : [];
        return Object.keys(state)
            .filter((key) => (wanted.length === 0 || wanted.indexOf(key) !== -1) && state[key].value !== '')
            .map((key) => ({key: key, type: state[key].type || 'text', value: String(state[key].value)}));
    }
};
