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

use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\course;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\helpers\database;
use local_wb_dashboard\reportbuilder\local\entities\quiz_completion;

/**
 * Quiz completions datasource: one row per quiz and per user with an activity
 * completion record on that quiz, so counting rows counts quiz completions.
 *
 * By default a new report restricts itself to rows where the quiz is actually
 * completed (activity completion state complete or complete-pass) via the
 * "Quiz completed" condition, and ships the quiz select filter so dashboards
 * can filter on a specific quiz. Removing the condition also surfaces
 * "viewed"/failed completion rows and one user-less row per quiz without any
 * completion data.
 *
 * Notes:
 * - Completion is the *activity completion* of the quiz course module, not
 *   finished attempts; the finished attempt count is available as its own
 *   column/filter in the quiz completion entity.
 * - Quizzes whose completion tracking is disabled have no completion rows and
 *   therefore never produce completed rows.
 * - Deleted users are excluded; whether a user is still enrolled is not
 *   checked (a completion of a meanwhile unenrolled user still counts).
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_completions extends datasource {
    /**
     * Return user friendly name of the datasource
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource:quizcompletions', 'local_wb_dashboard');
    }

    /**
     * Initialise report
     */
    protected function initialise(): void {
        $quizentity = new quiz_completion();
        $quiz = $quizentity->get_table_alias('quiz');
        $cm = $quizentity->get_table_alias('course_modules');
        $cmc = $quizentity->get_table_alias('course_modules_completion');

        $this->set_main_table('quiz', $quiz);

        // Restrict {course_modules} to quiz modules and join the per-user
        // activity completion rows. These joins are part of the report base so
        // every column/filter can rely on them.
        $module = database::generate_alias();
        $this->add_join("JOIN {modules} {$module} ON {$module}.name = 'quiz'");
        $this->add_join("JOIN {course_modules} {$cm} ON {$cm}.module = {$module}.id
            AND {$cm}.instance = {$quiz}.id AND {$cm}.deletioninprogress = 0");
        $this->add_join("LEFT JOIN {course_modules_completion} {$cmc} ON {$cmc}.coursemoduleid = {$cm}.id");

        // Join the course entity.
        $courseentity = new course();
        $coursealias = $courseentity->get_table_alias('course');
        $this->add_entity($courseentity
            ->add_join("JOIN {course} {$coursealias} ON {$coursealias}.id = {$quiz}.course"));

        // Join the user entity via the completion rows.
        $userentity = new user();
        $useralias = $userentity->get_table_alias('user');
        $this->add_entity($userentity
            ->add_join("LEFT JOIN {user} {$useralias} ON {$useralias}.id = {$cmc}.userid
                AND {$useralias}.deleted = 0"));

        // Add the quiz completion entity itself; it needs the user alias for
        // the per-user attempt counts.
        $this->add_entity($quizentity
            ->set_table_alias('user', $useralias)
            ->add_joins($userentity->get_joins()));

        // Add all entities columns/filters/conditions.
        $this->add_all_from_entities([
            $courseentity->get_entity_name(),
            $quizentity->get_entity_name(),
            $userentity->get_entity_name(),
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
            'quiz_completion:namewithlink',
            'user:fullnamewithlink',
            'quiz_completion:completed',
            'quiz_completion:timecompleted',
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
            'quiz_completion:namewithlink' => SORT_ASC,
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
            'quiz_completion:quizselect',
            'quiz_completion:completed',
            'quiz_completion:timecompleted',
        ];
    }

    /**
     * Return the conditions that will be added to the report once it is created
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [
            'quiz_completion:quizselect',
            'quiz_completion:completed',
        ];
    }

    /**
     * Return the condition values that will be set for the report upon creation
     *
     * A fresh report should count completions, so it is restricted to completed
     * rows by default; remove or change the condition to see everything.
     *
     * @return array
     */
    public function get_default_condition_values(): array {
        return [
            'quiz_completion:completed_operator' => boolean_select::CHECKED,
        ];
    }
}
