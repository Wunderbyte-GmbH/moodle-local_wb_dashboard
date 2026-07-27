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

use core_reportbuilder_generator;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use core\user;
use local_wb_dashboard\reportbuilder\local\filters\completed_all_except;
use stdClass;

/**
 * Course activity progress datasource tests
 *
 * @package    local_wb_dashboard
 * @covers     \local_wb_dashboard\reportbuilder\datasource\course_activity_progress
 * @covers     \local_wb_dashboard\reportbuilder\local\entities\activity_progress
 * @covers     \local_wb_dashboard\reportbuilder\local\filters\completed_all_except
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_activity_progress_test extends core_reportbuilder_testcase {
    /**
     * Load required libraries
     */
    public static function setUpBeforeClass(): void {
        global $CFG;

        require_once("{$CFG->libdir}/completionlib.php");
        parent::setUpBeforeClass();
    }

    /**
     * Record a completion state for a user on a course module, bypassing the
     * completion API so that arbitrary (including stale) states can be created
     *
     * @param int $cmid
     * @param int $userid
     * @param int $state One of the COMPLETION_COMPLETE* constants
     */
    private function set_completion_state(int $cmid, int $userid, int $state): void {
        global $DB;

        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $cmid,
            'userid' => $userid,
            'completionstate' => $state,
            'timemodified' => time(),
        ]);
    }

    /**
     * Create a report over the datasource with the user's username as first
     * column (sorted) followed by the given columns
     *
     * @param string[] $columns Column unique identifiers
     * @param string[] $filters Filter unique identifiers
     * @return stdClass The report persistent record wrapper as returned by the generator
     */
    private function create_username_report(array $columns, array $filters = []): stdClass {
        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Activity progress',
            'source' => course_activity_progress::class,
            'default' => 0,
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'user:username',
            'sortenabled' => 1,
        ]);
        foreach ($columns as $column) {
            $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $column]);
        }
        foreach ($filters as $filter) {
            $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => $filter]);
        }

        return (object) ['id' => $report->get('id')];
    }

    /**
     * Return report content as rows of cell values, without the rows produced
     * by enrolment instances that have no enrolled user (username column empty)
     *
     * @param int $reportid
     * @param array $filtervalues
     * @return array[]
     */
    private function get_user_rows(int $reportid, array $filtervalues = []): array {
        $content = array_map('array_values', $this->get_custom_report_content($reportid, 30, $filtervalues));

        return array_values(array_filter($content, static function (array $row): bool {
            return $row[0] !== '';
        }));
    }

    /**
     * Test the default datasource setup
     */
    public function test_datasource_default(): void {
        $this->resetAfterTest();

        // Course with completion enabled, one trackable activity, completed by the user.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $module = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );
        $user1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->set_completion_state($module->cmid, (int) $user1->id, COMPLETION_COMPLETE);

        // Course without completion, must be excluded by the default enablecompletion condition.
        $coursenocompletion = $this->getDataGenerator()->create_course(['enablecompletion' => 0]);
        $this->getDataGenerator()->create_and_enrol($coursenocompletion, 'student');

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name' => 'Activity progress',
            'source' => course_activity_progress::class,
            'default' => 1,
        ]);

        $content = array_map('array_values', $this->get_custom_report_content($report->get('id')));

        $courseurl = course_get_url($course);
        $userurl = user::get_profile_url($user1);
        $this->assertEquals([
            [
                "<a href=\"{$courseurl}\">{$course->fullname}</a>",
                "<a href=\"{$userurl}\">" . fullname($user1) . "</a>",
                1,
                0,
                '100.0%',
            ],
        ], $content);
    }

    /**
     * Test the trackable/completed/remaining/progress figures, in particular:
     * modules without completion tracking or flagged for deletion are excluded,
     * users without completion rows count as fully incomplete, state
     * COMPLETION_COMPLETE_FAIL is not progress, and stale completion rows of
     * modules whose tracking was later disabled are not counted (deliberate
     * divergence from core's count_modules_completed())
     */
    public function test_datasource_counts(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Never counted: no completion tracking.
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_NONE]);

        // The two modules every count is based on.
        $module1 = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );
        $module2 = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );

        // Trackable, but flagged for deletion: excluded.
        $moduledeletion = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $moduledeletion->cmid]);

        // Completion tracking disabled after the fact, leaving a stale completion row.
        $modulestale = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );

        $user1 = $this->getDataGenerator()->create_and_enrol($course, 'student', ['username' => 'user1']);
        $user2 = $this->getDataGenerator()->create_and_enrol($course, 'student', ['username' => 'user2']);
        $user3 = $this->getDataGenerator()->create_and_enrol($course, 'student', ['username' => 'user3']);

        // User one: untouched course, no completion rows at all.

        // User two: one complete, one explicit fail (fail is not progress).
        $this->set_completion_state($module1->cmid, (int) $user2->id, COMPLETION_COMPLETE);
        $this->set_completion_state($module2->cmid, (int) $user2->id, COMPLETION_COMPLETE_FAIL);

        // User three: everything complete, including the module about to lose its tracking.
        $this->set_completion_state($module1->cmid, (int) $user3->id, COMPLETION_COMPLETE_PASS);
        $this->set_completion_state($module2->cmid, (int) $user3->id, COMPLETION_COMPLETE);
        $this->set_completion_state($modulestale->cmid, (int) $user3->id, COMPLETION_COMPLETE);

        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_NONE, ['id' => $modulestale->cmid]);

        $report = $this->create_username_report([
            'activity_progress:trackableactivities',
            'activity_progress:completedactivities',
            'activity_progress:remainingactivities',
            'activity_progress:progresspercentage',
        ]);

        $this->assertEquals([
            ['user1', 2, 0, 2, '0.0%'],
            ['user2', 2, 1, 1, '50.0%'],
            ['user3', 2, 2, 0, '100.0%'],
        ], $this->get_user_rows($report->id));
    }

    /**
     * Test the "completed all except" filter with both operators, both
     * identifier types and an empty (no-op) activity list
     */
    public function test_completed_all_except_filter(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $modulea = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL, 'idnumber' => 'IDA']
        );
        $moduleb = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL, 'idnumber' => 'IDB']
        );
        $modulec = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );

        $user1 = $this->getDataGenerator()->create_and_enrol($course, 'student', ['username' => 'user1']);
        $user2 = $this->getDataGenerator()->create_and_enrol($course, 'student', ['username' => 'user2']);
        $user3 = $this->getDataGenerator()->create_and_enrol($course, 'student', ['username' => 'user3']);

        // User one completed everything except module A.
        $this->set_completion_state($moduleb->cmid, (int) $user1->id, COMPLETION_COMPLETE);
        $this->set_completion_state($modulec->cmid, (int) $user1->id, COMPLETION_COMPLETE);

        // User two completed everything, including module A.
        $this->set_completion_state($modulea->cmid, (int) $user2->id, COMPLETION_COMPLETE);
        $this->set_completion_state($moduleb->cmid, (int) $user2->id, COMPLETION_COMPLETE_PASS);
        $this->set_completion_state($modulec->cmid, (int) $user2->id, COMPLETION_COMPLETE);

        // User three still has module C left.
        $this->set_completion_state($moduleb->cmid, (int) $user3->id, COMPLETION_COMPLETE);

        $report = $this->create_username_report([], ['activity_progress:completedallexcept']);

        // Completed everything except module A (by course module id): users one and two.
        $rows = $this->get_user_rows($report->id, [
            'activity_progress:completedallexcept_operator' => completed_all_except::OPERATOR_EXCEPT,
            'activity_progress:completedallexcept_identifier' => completed_all_except::IDENTIFIER_CMID,
            'activity_progress:completedallexcept_values' => (string) $modulea->cmid,
        ]);
        $this->assertEquals([['user1'], ['user2']], $rows);

        // Same by idnumber.
        $rows = $this->get_user_rows($report->id, [
            'activity_progress:completedallexcept_operator' => completed_all_except::OPERATOR_EXCEPT,
            'activity_progress:completedallexcept_identifier' => completed_all_except::IDENTIFIER_IDNUMBER,
            'activity_progress:completedallexcept_values' => ' IDA , ',
        ]);
        $this->assertEquals([['user1'], ['user2']], $rows);

        // Stricter operator: module A must additionally not be complete, excluding user two.
        $rows = $this->get_user_rows($report->id, [
            'activity_progress:completedallexcept_operator' => completed_all_except::OPERATOR_EXCEPT_NONE_COMPLETE,
            'activity_progress:completedallexcept_identifier' => completed_all_except::IDENTIFIER_CMID,
            'activity_progress:completedallexcept_values' => (string) $modulea->cmid,
        ]);
        $this->assertEquals([['user1']], $rows);

        // Empty activity list: the filter is a no-op.
        $rows = $this->get_user_rows($report->id, [
            'activity_progress:completedallexcept_operator' => completed_all_except::OPERATOR_EXCEPT,
            'activity_progress:completedallexcept_identifier' => completed_all_except::IDENTIFIER_CMID,
            'activity_progress:completedallexcept_values' => ' , ',
        ]);
        $this->assertEquals([['user1'], ['user2'], ['user3']], $rows);
    }

    /**
     * Test the remaining activities number filter ("needs more/fewer than N
     * activities to finish the course")
     */
    public function test_remaining_activities_filter(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $module1 = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );
        $module2 = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );

        $user1 = $this->getDataGenerator()->create_and_enrol($course, 'student', ['username' => 'user1']);
        $user2 = $this->getDataGenerator()->create_and_enrol($course, 'student', ['username' => 'user2']);
        $user3 = $this->getDataGenerator()->create_and_enrol($course, 'student', ['username' => 'user3']);

        // User one has nothing left, user two one activity, user three both.
        $this->set_completion_state($module1->cmid, (int) $user1->id, COMPLETION_COMPLETE);
        $this->set_completion_state($module2->cmid, (int) $user1->id, COMPLETION_COMPLETE);
        $this->set_completion_state($module1->cmid, (int) $user2->id, COMPLETION_COMPLETE);

        $report = $this->create_username_report([], ['activity_progress:remainingactivities']);

        // Users needing more than one activity to finish.
        $rows = $this->get_user_rows($report->id, [
            'activity_progress:remainingactivities_operator' => number::GREATER_THAN,
            'activity_progress:remainingactivities_value1' => 1,
        ]);
        $this->assertEquals([['user3']], $rows);

        // Users needing fewer than two activities to finish.
        $rows = $this->get_user_rows($report->id, [
            'activity_progress:remainingactivities_operator' => number::LESS_THAN,
            'activity_progress:remainingactivities_value1' => 2,
        ]);
        $this->assertEquals([['user1'], ['user2']], $rows);
    }

    /**
     * Stress test datasource
     */
    public function test_stress_datasource(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $module = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        );
        $user1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->set_completion_state($module->cmid, (int) $user1->id, COMPLETION_COMPLETE);

        $this->datasource_stress_test_columns(course_activity_progress::class);
        $this->datasource_stress_test_columns_aggregation(course_activity_progress::class);
        $this->datasource_stress_test_conditions(course_activity_progress::class, 'course:idnumber');
    }
}
