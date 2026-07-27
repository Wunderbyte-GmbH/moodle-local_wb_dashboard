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

namespace local_wb_dashboard\local\toplist;

use local_wb_dashboard\local\dto\chart_data;

/**
 * Reduce chart data to a ranked top-N list with a bar percentage per row.
 *
 * Ranking happens here — not via the shaping's "top" parameter — because
 * apply_topn ranks categories by the sum of ALL series, which would be wrong
 * when a second (bar) series rides along. Sorting semantics mirror apply_topn:
 * "desc" keeps the top N, "asc" the bottom N, ties keep first-seen order.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class toplist_reducer {
    /** @var string The second series already holds the bar percent (0-100). */
    public const BAR_PERCENTFIELD = 'percentfield';
    /** @var string The second series holds a total; bar = value / total. */
    public const BAR_TOTALFIELD = 'totalfield';
    /** @var string Bar = value / fixed maximum. */
    public const BAR_MAX = 'max';
    /** @var string Bar relative to the highest kept value (that row = 100%). */
    public const BAR_RELATIVE = 'relative';

    /**
     * Whether the given bar mode is supported.
     *
     * @param string $mode
     * @return bool
     */
    public static function is_valid_barmode(string $mode): bool {
        return in_array($mode, [
            self::BAR_PERCENTFIELD, self::BAR_TOTALFIELD, self::BAR_MAX, self::BAR_RELATIVE,
        ], true);
    }

    /**
     * Rank, slice and compute the bar percentage for each surviving row.
     *
     * The DTO's series[0] is the ranking value; series[1] (when present) is
     * the bar metric for the percentfield/totalfield modes. A zero divisor
     * yields a 0% bar, never a division error; percentages are clamped 0-100.
     *
     * @param chart_data $dto Labels plus one or two aligned series.
     * @param int $top Number of rows to keep (>= 1).
     * @param string $order "desc" keeps the highest values, "asc" the lowest.
     * @param string $barmode One of the BAR_* constants.
     * @param float $max Fixed maximum for BAR_MAX.
     * @return array<int, array{label: string, value: float, percent: float, id: string}>
     */
    public static function reduce(chart_data $dto, int $top, string $order, string $barmode, float $max = 0.0): array {
        $values = $dto->series[0]->data ?? [];
        $bars = $dto->series[1]->data ?? [];

        $indices = array_keys($dto->labels);
        $asc = strtolower($order) === 'asc';
        usort($indices, static function (int $a, int $b) use ($values, $asc): int {
            $va = $values[$a] ?? 0.0;
            $vb = $values[$b] ?? 0.0;
            $cmp = $asc ? ($va <=> $vb) : ($vb <=> $va);
            return $cmp !== 0 ? $cmp : ($a <=> $b);
        });
        $indices = array_slice($indices, 0, max(1, $top));

        // The reference for relative bars: the largest kept value (in "asc"
        // order that is not necessarily the first row).
        $peak = 0.0;
        foreach ($indices as $i) {
            $peak = max($peak, (float)($values[$i] ?? 0.0));
        }

        $rows = [];
        foreach ($indices as $i) {
            $value = (float)($values[$i] ?? 0.0);
            switch ($barmode) {
                case self::BAR_PERCENTFIELD:
                    $percent = (float)($bars[$i] ?? 0.0);
                    break;
                case self::BAR_TOTALFIELD:
                    $total = (float)($bars[$i] ?? 0.0);
                    $percent = $total > 0 ? $value / $total * 100 : 0.0;
                    break;
                case self::BAR_MAX:
                    $percent = $max > 0 ? $value / $max * 100 : 0.0;
                    break;
                default:
                    $percent = $peak > 0 ? $value / $peak * 100 : 0.0;
            }
            $rows[] = [
                'label' => (string)($dto->labels[$i] ?? ''),
                'value' => $value,
                'percent' => round(min(100.0, max(0.0, $percent)), 1),
                // Raw category id when the shaping carried one (idfield).
                'id' => (string)($dto->meta['rowids'][$i] ?? ''),
            ];
        }
        return $rows;
    }
}
