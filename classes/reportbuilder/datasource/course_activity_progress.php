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

namespace local_wb_dashboard\reportbuilder\datasource;

use core_course\reportbuilder\local\entities\completion;
use core_course\reportbuilder\local\entities\enrolment;
use core_enrol\reportbuilder\local\entities\enrol;
use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\course;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\helpers\database;
use core_role\reportbuilder\local\entities\role;
use core_user\output\status_field;
use local_wb_dashboard\reportbuilder\local\entities\activity_progress;

/**
 * Activity completion progress datasource: one row per course, enrolment method
 * and enrolled user, with per-user counts of trackable, completed and remaining
 * activities of the course.
 *
 * Documented limitations (see also the activity_progress entity docblock and
 * docs/activity_progress.md):
 * - Visibility ("visible", stealth) and availability restrictions are not
 *   applied, matching \completion_info::get_activities(): an activity the user
 *   cannot access still counts in the denominator, so such users can never
 *   reach 0 remaining activities. Per-user visibility ($cm->uservisible) is not
 *   expressible in SQL and is deliberately not approximated.
 * - All enrolled users are included, not only "tracked" users as per
 *   \completion_info::is_tracked_user() (which requires the
 *   moodle/course:isincompletionreports capability and normally excludes
 *   teachers). Use the role condition/filter to narrow the report.
 * - A user enrolled through two enrolment methods produces two rows, exactly as
 *   in the core course participants datasource.
 * - Deleting an activity cascades away its completion rows: there is no history
 *   of past completions of deleted activities.
 * - Stale completion rows of activities whose completion tracking was later
 *   disabled are not counted, deliberately unlike the core progress block
 *   (which can report over 100% in that case).
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_activity_progress extends datasource {
    /**
     * Return user friendly name of the datasource
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource:courseactivityprogress', 'local_wb_dashboard');
    }

    /**
     * Initialise report
     */
    protected function initialise(): void {
        $courseentity = new course();
        $context = $courseentity->get_table_alias('context');
        $course = $courseentity->get_table_alias('course');
        $this->set_main_table('course', $course);
        $this->add_entity($courseentity);

        // Exclude the site course.
        $paramsiteid = database::generate_param_name();
        $this->add_base_condition_sql("{$course}.id != :{$paramsiteid}", [$paramsiteid => SITEID]);

        // Join the enrolment method entity.
        $enrolentity = new enrol();
        $enrol = $enrolentity->get_table_alias('enrol');
        $this->add_entity($enrolentity
            ->add_join("LEFT JOIN {enrol} {$enrol} ON {$enrol}.courseid = {$course}.id"));

        // Join the enrolments entity.
        $enrolmententity = (new enrolment())
            ->set_table_alias('enrol', $enrol);
        $userenrolment = $enrolmententity->get_table_alias('user_enrolments');
        $this->add_entity($enrolmententity
            ->add_joins($enrolentity->get_joins())
            ->add_join("LEFT JOIN {user_enrolments} {$userenrolment} ON {$userenrolment}.enrolid = {$enrol}.id"));

        // Join the user entity.
        $userentity = new user();
        $user = $userentity->get_table_alias('user');
        $this->add_entity($userentity
            ->add_joins($enrolmententity->get_joins())
            ->add_join("LEFT JOIN {user} {$user} ON {$user}.id = {$userenrolment}.userid AND {$user}.deleted = 0"));

        // Join the role entity, mainly to allow narrowing the report down to the
        // roles whose progress is actually tracked (e.g. students).
        $roleentity = (new role())
            ->set_table_alias('context', $context);
        $role = $roleentity->get_table_alias('role');
        $roleassignment = database::generate_alias();
        $this->add_entity($roleentity
            ->add_joins($userentity->get_joins())
            ->add_join($courseentity->get_context_join())
            ->add_join("LEFT JOIN {role_assignments} {$roleassignment} ON {$roleassignment}.contextid = {$context}.id
                AND {$roleassignment}.userid = {$user}.id")
            ->add_join("LEFT JOIN {role} {$role} ON {$role}.id = {$roleassignment}.roleid"));

        // Join the course completion entity, so course-level completion columns
        // are available alongside the activity-level ones.
        $completionentity = (new completion())
            ->set_table_aliases([
                'course' => $course,
                'user' => $user,
            ]);
        $coursecompletion = $completionentity->get_table_alias('course_completions');
        $this->add_entity($completionentity
            ->add_joins($userentity->get_joins())
            ->add_join("
                LEFT JOIN {course_completions} {$coursecompletion}
                       ON {$coursecompletion}.course = {$course}.id AND {$coursecompletion}.userid = {$user}.id"));

        // Add the activity progress entity. It joins no tables of its own (all
        // counts are correlated subqueries) but needs the course and user aliases.
        $activityprogressentity = (new activity_progress())
            ->set_table_aliases([
                'course' => $course,
                'user' => $user,
            ]);
        $this->add_entity($activityprogressentity
            ->add_joins($userentity->get_joins()));

        // Add all entities columns/filters/conditions.
        $this->add_all_from_entities([
            $courseentity->get_entity_name(),
            $enrolentity->get_entity_name(),
            $enrolmententity->get_entity_name(),
            $userentity->get_entity_name(),
            $roleentity->get_entity_name(),
            $completionentity->get_entity_name(),
            $activityprogressentity->get_entity_name(),
        ]);
    }

    /**
     * Return the columns that will be added to the report once it is created
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        return [
            'course:coursefullnamewithlink',
            'user:fullnamewithlink',
            'activity_progress:completedactivities',
            'activity_progress:remainingactivities',
            'activity_progress:progresspercentage',
        ];
    }

    /**
     * Return the default sorting that will be added to the report once it is created
     *
     * @return int[]
     */
    public function get_default_column_sorting(): array {
        return [
            'course:coursefullnamewithlink' => SORT_ASC,
            'user:fullnamewithlink' => SORT_ASC,
        ];
    }

    /**
     * Return the filters that will be added to the report once it is created
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return [
            'activity_progress:remainingactivities',
            'activity_progress:completedallexcept',
        ];
    }

    /**
     * Return the conditions that will be added to the report once it is created
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [
            'course:courseselector',
            'course:enablecompletion',
            'enrolment:status',
        ];
    }

    /**
     * Return the condition values that will be set for the report upon creation
     *
     * A report row for a course with completion disabled is meaningless, so
     * courses are restricted to those with completion enabled by default.
     *
     * @return array
     */
    public function get_default_condition_values(): array {
        return [
            'course:enablecompletion_operator' => boolean_select::CHECKED,
            'enrolment:status_operator' => select::EQUAL_TO,
            'enrolment:status_value' => status_field::STATUS_ACTIVE,
        ];
    }
}
