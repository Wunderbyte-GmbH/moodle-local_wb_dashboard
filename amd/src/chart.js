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
 * Thin chart runtime. The PHP builder already produced the complete Chart.js
 * config; this module only fetches it, instantiates it, wires JS-only plugins
 * by name, and re-queries when a subscribed filter changes.
 *
 * @module     local_wb_dashboard/chart
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Chart from 'core/chartjs';
import Notification from 'core/notification';
import Filterbus from 'local_wb_dashboard/filterbus';

/**
 * Wrap text into lines fitting maxWidth using the font already set on ctx.
 *
 * @param {Object} ctx
 * @param {String} text
 * @param {Number} maxWidth
 * @return {String[]}
 */
const wrapTextLines = (ctx, text, maxWidth) => {
    const words = String(text).split(' ');
    const lines = [];
    let line = '';
    words.forEach((word) => {
        const test = line + word + ' ';
        if (ctx.measureText(test).width > maxWidth && line !== '') {
            lines.push(line.trim());
            line = word + ' ';
        } else {
            line = test;
        }
    });
    lines.push(line.trim());
    return lines;
};

/**
 * Build the doughnut centre-text plugin from the config's plugindata.
 *
 * @param {Object} data {value, label}
 * @return {Object} A Chart.js plugin.
 */
const centerTextPlugin = (data) => ({
    id: 'localDashboardCenterText',
    afterDraw: (chart) => {
        const meta = chart.getDatasetMeta(0);
        const arc = meta.data && meta.data[0];
        if (!arc) {
            return;
        }
        const ctx = chart.ctx;
        const innerRadius = arc.innerRadius;
        const maxWidth = innerRadius * 1.6;
        const labelSize = Math.max(11, Math.round(innerRadius / 4.5));
        const valueSize = Math.max(16, Math.round(innerRadius / 2.5));
        const lineHeight = labelSize * 1.4;

        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = '500 ' + labelSize + 'px sans-serif';
        const lines = wrapTextLines(ctx, data.label || '', maxWidth);
        const valueBottomOffset = valueSize / 2 + lineHeight * 0.6;
        const blockHeight = valueBottomOffset + lines.length * lineHeight;
        const blockTop = arc.y - blockHeight / 2;
        const valueY = blockTop + valueSize / 2;
        const firstLabelY = valueY + valueBottomOffset;

        ctx.fillStyle = '#111';
        ctx.font = '700 ' + valueSize + 'px sans-serif';
        ctx.fillText(String(data.value || ''), arc.x, valueY);

        ctx.fillStyle = '#666';
        ctx.font = '500 ' + labelSize + 'px sans-serif';
        lines.forEach((text, i) => ctx.fillText(text, arc.x, firstLabelY + i * lineHeight));
        ctx.restore();
    }
});

/** Registry of JS-only plugins referenced by name from the config. */
const JS_PLUGINS = {
    centertext: (config) => centerTextPlugin((config.plugindata && config.plugindata.centertext) || {})
};

/**
 * Turn one canvas into a live, filter-aware chart.
 *
 * @param {HTMLCanvasElement} canvas
 */
const createController = (canvas) => {
    const skeleton = canvas.previousElementSibling;
    const wsargs = JSON.parse(canvas.dataset.wsargs || '{}');
    const consumes = JSON.parse(canvas.dataset.consumes || '[]');
    let requestToken = 0;

    const setBusy = (busy) => {
        canvas.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (busy) {
            if (skeleton) {
                skeleton.style.display = '';
            }
            canvas.style.display = 'none';
        } else {
            if (skeleton) {
                skeleton.style.display = 'none';
            }
            canvas.style.display = 'block';
        }
    };

    const draw = (config) => {
        const existing = (typeof Chart.getChart === 'function') ? Chart.getChart(canvas) : null;
        if (existing) {
            existing.destroy();
        }
        const plugins = (config.plugins || [])
            .filter((name) => Object.prototype.hasOwnProperty.call(JS_PLUGINS, name))
            .map((name) => JS_PLUGINS[name](config));
        // eslint-disable-next-line no-new
        new Chart(canvas, {
            type: config.type,
            data: config.data,
            options: config.options,
            plugins: plugins
        });
        setBusy(false);
    };

    const reload = () => {
        setBusy(true);
        const token = ++requestToken;
        const args = {
            source: wsargs.source,
            type: wsargs.type,
            sourceparams: wsargs.sourceparams || [],
            chartid: wsargs.chartid || '',
            title: wsargs.title || '',
            centertext: wsargs.centertext !== false,
            target: wsargs.target || 0,
            filtervalues: Filterbus.valuesFor(consumes)
        };
        Ajax.call([{methodname: 'local_wb_dashboard_get_chart_data', args: args}])[0]
            .then((result) => {
                if (token !== requestToken) {
                    return null; // A newer request superseded this one.
                }
                draw(JSON.parse(result.payload));
                return null;
            })
            .catch((error) => {
                setBusy(false);
                Notification.exception(error);
            });
    };

    return {reload: reload, consumes: consumes, chartid: wsargs.chartid || ''};
};

export default {
    /**
     * Initialise a chart canvas.
     *
     * @param {String} canvasId
     */
    init: (canvasId) => {
        const canvas = document.getElementById(canvasId);
        if (!canvas || canvas.dataset.ldInitialised === '1') {
            return;
        }
        canvas.dataset.ldInitialised = '1';
        const controller = createController(canvas);
        Filterbus.subscribe(controller, controller.consumes);
        // Reload when this chart's stored colours change (settings gear saved).
        if (controller.chartid) {
            document.addEventListener('local_wb_dashboard:chart-reload', (e) => {
                if (e.detail && e.detail.chartid === controller.chartid) {
                    controller.reload();
                }
            });
        }
        controller.reload();
    }
};
