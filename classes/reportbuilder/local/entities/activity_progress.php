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

declare(strict_types=1);

namespace local_wb_dashboard\reportbuilder\local\entities;

use lang_string;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use local_wb_dashboard\reportbuilder\local\filters\completed_all_except;

/**
 * Activity completion progress entity: per user per course counts of trackable,
 * completed and remaining activities, based on correlated subqueries over
 * {course_modules} and {course_modules_completion}.
 *
 * The entity does not join any tables itself; both tables are only referenced
 * inside correlated subqueries under aliases generated per column/filter. It
 * requires the 'course' and 'user' table aliases to be those of the report's
 * course and user tables, so the datasource must pass them in via
 * {@see base::set_table_aliases} before adding the entity.
 *
 * Semantics (mirroring \completion_info::get_activities()):
 * - an activity is trackable when cm.completion <> COMPLETION_TRACKING_NONE
 *   and cm.deletioninprogress = 0;
 * - an activity is complete when a completion row in state COMPLETION_COMPLETE
 *   or COMPLETION_COMPLETE_PASS exists; COMPLETION_COMPLETE_FAIL is not
 *   progress, and a missing row means incomplete.
 *
 * Known deliberate divergence from core: unlike get_activities(), core's
 * \completion_info::count_modules_completed() does not filter on
 * cm.completion <> 0, so stale completion rows of activities whose completion
 * tracking was later switched off keep counting and core can report over 100%
 * progress. Our completed count applies the same trackable filter as the
 * denominator, so in that edge case our numbers legitimately differ from the
 * core progress block (ours never exceed 100%).
 *
 * Documented limitations (also see docs/activity_progress.md):
 * - Visibility and availability restrictions are not applied (matching
 *   get_activities()): hidden or access-restricted activities still count in
 *   the denominator, so affected users may never reach 0 remaining.
 *   Replicating $cm->uservisible per user is not expressible in SQL.
 * - Every enrolled user is counted, not only users passing
 *   \completion_info::is_tracked_user() (enrolled with the
 *   moodle/course:isincompletionreports capability, which normally excludes
 *   teachers). Filter by role in the report to narrow this down.
 * - Deleted activities cascade away their completion rows; there is no history.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_progress extends base {
    /**
     * Database tables that this entity uses
     *
     * The {course_modules} and {course_modules_completion} tables are only ever
     * referenced inside correlated subqueries, under generated aliases.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            'course',
            'user',
        ];
    }

    /**
     * The default title for this entity
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity:activityprogress', 'local_wb_dashboard');
    }

    /**
     * Initialise the entity
     *
     * @return base
     */
    public function initialise(): base {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }

        foreach ($this->get_all_filters() as $filter) {
            $this
                ->add_filter($filter)
                ->add_condition($filter);
        }

        return $this;
    }

    /**
     * Correlated subquery counting the trackable activities of the course,
     * applying the same rules as \completion_info::get_activities()
     *
     * @param string $course Course table alias of the outer query
     * @return string
     */
    private function get_trackable_sql(string $course): string {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $cm = database::generate_alias();

        return "(SELECT COUNT(1)
                   FROM {course_modules} {$cm}
                  WHERE {$cm}.course = {$course}.id
                        AND {$cm}.completion <> " . COMPLETION_TRACKING_NONE . "
                        AND {$cm}.deletioninprogress = 0)";
    }

    /**
     * Correlated subquery counting the trackable activities of the course that
     * the user has completed (states COMPLETION_COMPLETE/COMPLETION_COMPLETE_PASS)
     *
     * Unlike core's \completion_info::count_modules_completed() this applies the
     * cm.completion <> 0 filter of the denominator, so stale completion rows of
     * activities whose tracking was later disabled do not count (see class docblock).
     *
     * @param string $course Course table alias of the outer query
     * @param string $user User table alias of the outer query
     * @return string
     */
    private function get_completed_sql(string $course, string $user): string {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        [$cm, $cmc] = database::generate_aliases(2);

        return "(SELECT COUNT(1)
                   FROM {course_modules} {$cm}
                   JOIN {course_modules_completion} {$cmc} ON {$cmc}.coursemoduleid = {$cm}.id
                        AND {$cmc}.userid = {$user}.id
                  WHERE {$cm}.course = {$course}.id
                        AND {$cm}.completion <> " . COMPLETION_TRACKING_NONE . "
                        AND {$cm}.deletioninprogress = 0
                        AND {$cmc}.completionstate IN (" . COMPLETION_COMPLETE . ", " . COMPLETION_COMPLETE_PASS . "))";
    }

    /**
     * Returns list of all available columns
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        [
            'course' => $course,
            'user' => $user,
        ] = $this->get_table_aliases();

        // Trackable activities (the denominator). Course-level, so no user guard.
        $columns[] = (new column(
            'trackableactivities',
            new lang_string('activityprogress:trackable', 'local_wb_dashboard'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field($this->get_trackable_sql($course), 'trackableactivities')
            ->set_is_sortable(true);

        // Completed activities. NULL rather than 0 when there is no user in the row.
        $columns[] = (new column(
            'completedactivities',
            new lang_string('activityprogress:completed', 'local_wb_dashboard'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field(
                $this->get_user_guarded_sql($this->get_completed_sql($course, $user), $user),
                'completedactivities'
            )
            ->set_is_sortable(true);

        // Remaining activities: trackable minus completed.
        $columns[] = (new column(
            'remainingactivities',
            new lang_string('activityprogress:remaining', 'local_wb_dashboard'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field($this->get_remaining_sql($course, $user), 'remainingactivities')
            ->set_is_sortable(true);

        // Progress percentage, NULL when the course has no trackable activities.
        $columns[] = (new column(
            'progresspercentage',
            new lang_string('activityprogress:progress', 'local_wb_dashboard'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_FLOAT)
            ->add_field($this->get_progress_sql($course, $user), 'progresspercentage')
            ->set_is_sortable(true)
            ->add_callback(static function (?float $value): string {
                if ($value === null) {
                    return '';
                }
                return get_string('percents', 'moodle', format_float($value, 1));
            });

        return $columns;
    }

    /**
     * Wrap a per-user subquery so that a row without user (LEFT JOIN miss)
     * yields NULL rather than a bogus count
     *
     * @param string $sql Correlated subquery
     * @param string $user User table alias of the outer query
     * @return string
     */
    private function get_user_guarded_sql(string $sql, string $user): string {
        return "CASE WHEN {$user}.id IS NULL THEN NULL ELSE {$sql} END";
    }

    /**
     * Expression for the number of activities the user still needs to complete
     *
     * @param string $course Course table alias of the outer query
     * @param string $user User table alias of the outer query
     * @return string
     */
    private function get_remaining_sql(string $course, string $user): string {
        $sql = "({$this->get_trackable_sql($course)} - {$this->get_completed_sql($course, $user)})";

        return $this->get_user_guarded_sql($sql, $user);
    }

    /**
     * Expression for the user's progress percentage, guarding against division
     * by zero in courses without trackable activities
     *
     * @param string $course Course table alias of the outer query
     * @param string $user User table alias of the outer query
     * @return string
     */
    private function get_progress_sql(string $course, string $user): string {
        $trackable = $this->get_trackable_sql($course);
        $completed = $this->get_completed_sql($course, $user);

        return "CASE
                    WHEN {$user}.id IS NULL THEN NULL
                    WHEN {$trackable} = 0 THEN NULL
                    ELSE (100.0 * {$completed}) / {$trackable}
                END";
    }

    /**
     * Field SQL for the completed_all_except filter: the user's remaining
     * activities, with the {@see completed_all_except::TOKEN_EXCLUSION}
     * placeholder for the filter to inject the exclusion list into
     *
     * @param string $course Course table alias of the outer query
     * @param string $user User table alias of the outer query
     * @param string $cm Alias to use for {course_modules} within the subquery
     * @return string
     */
    private function get_completed_all_except_sql(string $course, string $user, string $cm): string {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $cmc = database::generate_alias();

        return "(SELECT COUNT(1)
                   FROM {course_modules} {$cm}
              LEFT JOIN {course_modules_completion} {$cmc} ON {$cmc}.coursemoduleid = {$cm}.id
                        AND {$cmc}.userid = {$user}.id
                  WHERE {$cm}.course = {$course}.id
                        AND {$cm}.completion <> " . COMPLETION_TRACKING_NONE . "
                        AND {$cm}.deletioninprogress = 0
                        AND ({$cmc}.id IS NULL OR {$cmc}.completionstate NOT IN (" .
                            COMPLETION_COMPLETE . ", " . COMPLETION_COMPLETE_PASS . "))
                        " . completed_all_except::TOKEN_EXCLUSION . ")";
    }

    /**
     * Returns list of all available filters
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        [
            'course' => $course,
            'user' => $user,
        ] = $this->get_table_aliases();

        // Number filters over the column expressions. The remaining activities
        // filter answers both "needs more than N" (greater than) and "needs
        // fewer than N" (less than) via the standard number operators.
        $numberfilterfields = [
            'trackableactivities' => [
                new lang_string('activityprogress:trackable', 'local_wb_dashboard'),
                $this->get_trackable_sql($course),
            ],
            'completedactivities' => [
                new lang_string('activityprogress:completed', 'local_wb_dashboard'),
                $this->get_user_guarded_sql($this->get_completed_sql($course, $user), $user),
            ],
            'remainingactivities' => [
                new lang_string('activityprogress:remaining', 'local_wb_dashboard'),
                $this->get_remaining_sql($course, $user),
            ],
            'progresspercentage' => [
                new lang_string('activityprogress:progress', 'local_wb_dashboard'),
                $this->get_progress_sql($course, $user),
            ],
        ];

        foreach ($numberfilterfields as $filtername => [$header, $fieldsql]) {
            $filters[] = (new filter(
                number::class,
                $filtername,
                $header,
                $this->get_entity_name(),
                $fieldsql
            ))
                ->add_joins($this->get_joins());
        }

        // Completed all activities except a given list, see the filter class docblock
        // for the placeholder/options contract.
        $cm = database::generate_alias();
        $filters[] = (new filter(
            completed_all_except::class,
            'completedallexcept',
            new lang_string('activityprogress:completedallexcept', 'local_wb_dashboard'),
            $this->get_entity_name(),
            $this->get_completed_all_except_sql($course, $user, $cm)
        ))
            ->add_joins($this->get_joins())
            ->set_options([
                'cmalias' => $cm,
                'coursealias' => $course,
                'useralias' => $user,
            ]);

        return $filters;
    }
}
