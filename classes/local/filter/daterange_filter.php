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

namespace local_wb_dashboard\local\filter;

use local_wb_dashboard\local\dto\filter_constraint;
use renderer_base;

/**
 * A date range filter. The control submits a single "from|to" string where each
 * side is a "YYYY-MM-DD" date and either side may be empty for an open-ended
 * range (e.g. "2026-01-01|2026-06-30", "2026-01-01|", "|2026-06-30"). The
 * normalized value is [fromtimestamp, totimestamp] with 0 meaning "unbounded";
 * the from side maps to 00:00:00, the to side to 23:59:59 so the chosen to-day
 * is included. Sources decide how to apply the resulting "between" constraint
 * (Report Builder maps it to a DATE_RANGE from/to pair).
 *
 * The shortcode default uses the same format: default="2026-01-01|2026-06-30".
 * Alternatively a relative keyword default="last12months" (any month count)
 * resolves at render time to the range from N months ago until today, in the
 * viewing user's timezone.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class daterange_filter extends base_filter {
    #[\Override]
    public function get_type(): string {
        return 'daterange';
    }

    /**
     * The configured default, with the relative "last<N>months" keyword
     * resolved to a concrete "from|to" ISO range: from N months before today
     * until today, in the current user's timezone. Anything else is returned
     * verbatim.
     *
     * @return string
     */
    #[\Override]
    protected function get_default(): string {
        $default = parent::get_default();
        if (!preg_match('/^last(\d{1,3})months?$/i', trim($default), $matches)) {
            return $default;
        }
        $today = new \DateTimeImmutable('now', \core_date::get_user_timezone_object());
        $from = $today->modify('-' . (int)$matches[1] . ' months');
        return $from->format('Y-m-d') . '|' . $today->format('Y-m-d');
    }

    /**
     * Split a raw "from|to" wire value into its two sides. A value without a
     * pipe is treated as the from side. Two ISO dates in the wrong order are
     * swapped so the range is always ascending.
     *
     * @param string $raw
     * @return string[] [$fromraw, $toraw]
     */
    public static function split_raw(string $raw): array {
        $raw = trim($raw);
        if (!str_contains($raw, '|')) {
            return [$raw, ''];
        }
        [$fromraw, $toraw] = explode('|', $raw, 2);
        $fromraw = trim($fromraw);
        $toraw = trim($toraw);
        $isopattern = '/^\d{4}-\d{2}-\d{2}$/';
        if (preg_match($isopattern, $fromraw) && preg_match($isopattern, $toraw) && $fromraw > $toraw) {
            [$fromraw, $toraw] = [$toraw, $fromraw];
        }
        return [$fromraw, $toraw];
    }

    #[\Override]
    public function normalize_value($raw) {
        [$fromraw, $toraw] = self::split_raw((string)$raw);
        return [$this->parse_side($fromraw, false), $this->parse_side($toraw, true)];
    }

    /**
     * Parse one side of the range into a unix timestamp (0 = unbounded).
     *
     * @param string $side
     * @param bool $isend Whether this is the to side (maps to 23:59:59 instead of 00:00:00).
     * @return int
     */
    private function parse_side(string $side, bool $isend): int {
        if ($side === '') {
            return 0;
        }
        // Accept ISO date "YYYY-MM-DD".
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $side, $m)) {
            return $isend
                ? make_timestamp((int)$m[1], (int)$m[2], (int)$m[3], 23, 59, 59)
                : make_timestamp((int)$m[1], (int)$m[2], (int)$m[3], 0, 0, 0);
        }
        // Accept a raw unix timestamp.
        if (ctype_digit($side)) {
            return (int)$side;
        }
        return 0;
    }

    #[\Override]
    public function to_constraint($value): filter_constraint {
        $value = (array)$value;
        return new filter_constraint(
            $this->key,
            filter_constraint::OP_BETWEEN,
            [(int)($value[0] ?? 0), (int)($value[1] ?? 0)]
        );
    }

    #[\Override]
    public function export_for_template(renderer_base $output): array {
        $context = parent::export_for_template($output);
        [$context['valuefrom'], $context['valueto']] = self::split_raw((string)$this->get_default());
        return $context;
    }
}
