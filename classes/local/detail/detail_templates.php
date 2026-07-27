<?php
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

namespace local_wb_dashboard\local\detail;

/**
 * Named detail templates: the admin-authored modal bodies for the per-row
 * "see details" drill-down of the [toplist] shortcode.
 *
 * All templates live in one admin setting ("detailtemplates"), delimited by
 * "=== name ===" marker lines. A template body is arbitrary HTML containing
 * display shortcodes; the placeholders {{id}} and {{label}} are substituted
 * with the clicked row's raw id and label before the shortcodes filter runs,
 * typically inside a fixedfilters argument:
 *
 *     === coursedetail ===
 *     <h3>{{label}}</h3>
 *     [digits ... consumes=none fixedfilters="courseid:{{id}}"]
 *
 * The setting is site-admin-only, so template bodies are trusted markup; the
 * substituted values, however, come from report data and are sanitized so
 * they can never alter the surrounding shortcode syntax.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class detail_templates {
    /** @var string Placeholder replaced with the clicked row's raw id. */
    public const PLACEHOLDER_ID = '{{id}}';
    /** @var string Placeholder replaced with the clicked row's label. */
    public const PLACEHOLDER_LABEL = '{{label}}';

    /**
     * All configured templates.
     *
     * @return array<string, string> name => raw template body.
     */
    public static function all(): array {
        return self::parse_config((string)get_config('local_wb_dashboard', 'detailtemplates'));
    }

    /**
     * One template's raw body, or null when the name is not configured.
     *
     * @param string $name
     * @return string|null
     */
    public static function get(string $name): ?string {
        return self::all()[$name] ?? null;
    }

    /**
     * Parse the raw setting into named template bodies.
     *
     * Sections start at a "=== name ===" line (surrounding whitespace is
     * tolerated, names are cleaned to PARAM_ALPHANUMEXT); everything up to the
     * next marker is that template's body. Content before the first marker is
     * ignored; a repeated name keeps the last section; sections whose body is
     * empty after trimming are dropped.
     *
     * @param string $raw The raw setting value.
     * @return array<string, string> name => raw template body.
     */
    public static function parse_config(string $raw): array {
        $templates = [];
        $name = null;
        $body = [];
        $flush = function () use (&$templates, &$name, &$body): void {
            if ($name !== null && trim(implode("\n", $body)) !== '') {
                $templates[$name] = trim(implode("\n", $body));
            }
            $body = [];
        };
        foreach (preg_split('/\R/', $raw) as $line) {
            if (preg_match('/^\s*===\s*(.+?)\s*===\s*$/', $line, $matches)) {
                $flush();
                $name = clean_param($matches[1], PARAM_ALPHANUMEXT);
                $name = $name === '' ? null : $name;
                continue;
            }
            $body[] = $line;
        }
        $flush();
        return $templates;
    }

    /**
     * Substitute the placeholders with sanitized values.
     *
     * Substitution happens before the shortcodes filter parses the result, so
     * the values must not be able to alter the surrounding syntax: quotes and
     * square brackets (shortcode arg/tag delimiters), semicolons (the
     * fixedfilters pair separator) and control characters are stripped.
     * Everything else — letters including umlauts, digits, spaces, commas,
     * apostrophes, colons — survives, so real-world names stay usable.
     *
     * @param string $template Raw template body.
     * @param string $id The clicked row's raw id.
     * @param string $label The clicked row's label.
     * @return string
     */
    public static function substitute(string $template, string $id, string $label): string {
        $sanitize = fn(string $value): string =>
            trim((string)preg_replace('/["\[\];\x00-\x1f\x7f]/u', '', $value));
        return str_replace(
            [self::PLACEHOLDER_ID, self::PLACEHOLDER_LABEL],
            [$sanitize($id), $sanitize($label)],
            $template
        );
    }
}
