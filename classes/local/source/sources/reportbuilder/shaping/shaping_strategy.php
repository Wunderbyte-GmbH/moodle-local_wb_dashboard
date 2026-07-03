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
use local_wb_dashboard\local\dto\filter_constraint;
use local_wb_dashboard\local\source\sources\reportbuilder\reportbuilder_source;

/**
 * One way of shaping a Report Builder result into a chart_data DTO.
 *
 * Each strategy decides, from the source params alone, whether it applies, and
 * delegates the actual shaping to the matching method on reportbuilder_source.
 * reportbuilder_source::fetch() walks the strategies in priority order and hands
 * off to the first whose supports() returns true.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface shaping_strategy {
    /**
     * Whether this strategy handles the given source params.
     *
     * @param array $params Source params.
     * @return bool
     */
    public function supports(array $params): bool;

    /**
     * Shape the source's data, delegating to the matching reportbuilder_source method.
     *
     * @param reportbuilder_source $source
     * @param array $params Source params.
     * @param filter_constraint[] $constraints
     * @return chart_data
     */
    public function shape(reportbuilder_source $source, array $params, array $constraints): chart_data;
}
