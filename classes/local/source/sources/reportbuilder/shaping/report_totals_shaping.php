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

namespace local_wb_dashboard\local\source\sources\reportbuilder\shaping;

use local_wb_dashboard\local\dto\chart_data;
use local_wb_dashboard\local\source\sources\reportbuilder\reportbuilder_source;

/**
 * Multi-report totals: one data point per report (row count or a summed field).
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_totals_shaping implements shaping_strategy {
    #[\Override]
    public function supports(array $params): bool {
        return !empty($params['reports']);
    }

    #[\Override]
    public function shape(reportbuilder_source $source, array $params, array $constraints): chart_data {
        return $source->fetch_report_totals($params, $constraints);
    }
}
