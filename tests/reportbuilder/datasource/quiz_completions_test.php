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
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use stdClass;

/**
 * Quiz completions datasource tests
 *
 * @package    local_wb_dashboard
 * @covers     \local_wb_dashboard\reportbuilder\datasource\quiz_completions
 * @covers     \local_wb_dashboard\reportbuilder\local\entities\quiz_completion
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class quiz_completions_test extends core_reportbuilder_testcase {
    /**
     * Load required libraries
     */
    public static function setUpBeforeClass(): void {
        global $CFG;

        require_once("{$CFG->libdir}/completionlib.php");
        parent::setUpBeforeClass();
    }

    /**
     * Create a course with completion enabled, two quizzes with completion
     * tracking, two enrolled users and a few completion/attempt records
     *
     * @return stdClass Object with course, quiz1, quiz2, user1, user2
     */
    private function create_quiz_completion_data(): stdClass {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 1);

        $generator = $this->getDataGenerator();

        $data = new stdClass();
        $data->course = $generator->create_course(['fullname' => 'Course A', 'enablecompletion' => 1]);
        $data->quiz1 = $generator->create_module('quiz', [
            'course' => $data->course->id,
            'name' => 'Quiz one',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $data->quiz2 = $generator->create_module('quiz', [
            'course' => $data->course->id,
            'name' => 'Quiz two',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $data->user1 = $generator->create_user(['username' => 'user1']);
        $data->user2 = $generator->create_user(['username' => 'user2']);
        $generator->enrol_user($data->user1->id, $data->course->id, 'student');
        $generator->enrol_user($data->user2->id, $data->course->id, 'student');

        // User 1 completed quiz 1, user 2 only viewed it (incomplete row).
        $this->set_completion_state((int) $data->quiz1->cmid, (int) $data->user1->id, COMPLETION_COMPLETE);
        $this->set_completion_state((int) $data->quiz1->cmid, (int) $data->user2->id, COMPLETION_INCOMPLETE);
        // User 1 completed quiz 2 without reaching the pass grade.
        $this->set_completion_state((int) $data->quiz2->cmid, (int) $data->user1->id, COMPLETION_COMPLETE_FAIL);

        // User 1 has two finished attempts and one in-progress attempt on quiz 1.
        $this->create_attempt((int) $data->quiz1->id, (int) $data->user1->id, 1, 'finished');
        $this->create_attempt((int) $data->quiz1->id, (int) $data->user1->id, 2, 'finished');
        $this->create_attempt((int) $data->quiz1->id, (int) $data->user1->id, 3, 'inprogress');

        return $data;
    }

    /**
     * Record a completion state for a user on a course module directly
     *
     * @param int $cmid
     * @param int $userid
     * @param int $state One of the COMPLETION_* state constants
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
     * Insert a quiz attempt record directly (bypassing the attempt API)
     *
     * @param int $quizid
     * @param int $userid
     * @param int $attempt Attempt number
     * @param string $state Attempt state, e.g. 'finished' or 'inprogress'
     */
    private function create_attempt(int $quizid, int $userid, int $attempt, string $state): void {
        global $DB;
        static $uniqueid = 990000;

        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $quizid,
            'userid' => $userid,
            'attempt' => $attempt,
            'uniqueid' => $uniqueid++,
            'layout' => '1,0',
            'currentpage' => 0,
            'preview' => 0,
            'state' => $state,
            'timestart' => time(),
            'timefinish' => $state === 'finished' ? time() : 0,
            'timemodified' => time(),
            'sumgrades' => null,
        ]);
    }

    /**
     * Create a non-default report over the datasource with the given columns
     *
     * @param string[] $columns Column unique identifiers
     * @param string[] $filters Filter unique identifiers
     * @return int Report ID
     */
    private function create_report(array $columns, array $filters = []): int {
        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Quiz completions',
            'source' => quiz_completions::class,
            'default' => 0,
        ]);

        foreach ($columns as $column) {
            $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $column]);
        }
        foreach ($filters as $filter) {
            $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => $filter]);
        }

        return $report->get('id');
    }

    /**
     * Test the default datasource setup: only completed rows are shown thanks
     * to the default "completed" condition
     */
    public function test_datasource_default(): void {
        $data = $this->create_quiz_completion_data();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name' => 'Quiz completions',
            'source' => quiz_completions::class,
            'default' => 1,
        ]);

        $content = $this->get_custom_report_content($report->get('id'));

        // Only user 1's completion of quiz 1 counts as completed: the viewed
        // row of user 2 and the failed completion of quiz 2 are filtered out.
        $this->assertCount(1, $content);
        $row = array_values($content[0]);
        $this->assertStringContainsString('Course A', $row[0]);
        $this->assertStringContainsString('Quiz one', $row[1]);
        $this->assertStringContainsString(fullname($data->user1), $row[2]);
        $this->assertEquals(get_string('yes'), $row[3]);
        $this->assertNotEmpty($row[4]);
    }

    /**
     * Test all rows and the entity columns without the default condition
     */
    public function test_datasource_columns(): void {
        $this->create_quiz_completion_data();

        $reportid = $this->create_report([
            'user:username',
            'quiz_completion:name',
            'quiz_completion:completed',
            'quiz_completion:state',
            'quiz_completion:attempts',
        ]);

        $content = array_map('array_values', $this->get_custom_report_content($reportid));

        // Three completion rows, sorted by username/quiz name in the assertion.
        usort($content, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $this->assertEquals([
            ['user1', 'Quiz one', get_string('yes'),
                get_string('quizcompletion:state:completed', 'local_wb_dashboard'), '2'],
            ['user1', 'Quiz two', get_string('no'),
                get_string('quizcompletion:state:failed', 'local_wb_dashboard'), '0'],
            ['user2', 'Quiz one', get_string('no'),
                get_string('quizcompletion:state:notcompleted', 'local_wb_dashboard'), '0'],
        ], $content);
    }

    /**
     * Test filtering on a specific quiz
     */
    public function test_quiz_select_filter(): void {
        $data = $this->create_quiz_completion_data();

        $reportid = $this->create_report(
            ['user:username', 'quiz_completion:name'],
            ['quiz_completion:quizselect']
        );

        $content = array_map('array_values', $this->get_custom_report_content($reportid, 30, [
            'quiz_completion:quizselect_operator' => select::EQUAL_TO,
            'quiz_completion:quizselect_value' => $data->quiz2->cmid,
        ]));

        $this->assertEquals([['user1', 'Quiz two']], $content);
    }

    /**
     * Test the completed filter both ways
     */
    public function test_completed_filter(): void {
        $this->create_quiz_completion_data();

        $reportid = $this->create_report(
            ['user:username', 'quiz_completion:name'],
            ['quiz_completion:completed']
        );

        $completed = array_map('array_values', $this->get_custom_report_content($reportid, 30, [
            'quiz_completion:completed_operator' => boolean_select::CHECKED,
        ]));
        $this->assertEquals([['user1', 'Quiz one']], $completed);

        $notcompleted = array_map('array_values', $this->get_custom_report_content($reportid, 30, [
            'quiz_completion:completed_operator' => boolean_select::NOT_CHECKED,
        ]));
        usort($notcompleted, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $this->assertEquals([['user1', 'Quiz two'], ['user2', 'Quiz one']], $notcompleted);
    }

    /**
     * Test the quiz select filter options
     */
    public function test_quiz_options(): void {
        $data = $this->create_quiz_completion_data();

        $options = \local_wb_dashboard\reportbuilder\local\entities\quiz_completion::get_quiz_options();

        $this->assertEquals([
            $data->quiz1->cmid => 'Course A: Quiz one',
            $data->quiz2->cmid => 'Course A: Quiz two',
        ], $options);
    }
}
