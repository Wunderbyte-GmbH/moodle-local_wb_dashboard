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

use html_writer;
use lang_string;
use moodle_url;
use stdClass;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;

/**
 * Quiz completion entity: activity completion state of a quiz per user, plus
 * the number of finished attempts.
 *
 * The entity expects the report to provide the following table aliases via
 * {@see base::set_table_aliases} before adding the entity:
 * - 'quiz': the {quiz} table,
 * - 'course_modules': the {course_modules} row of the quiz,
 * - 'course_modules_completion': LEFT JOINed per-user completion rows,
 * - 'user': the {user} table (LEFT JOINed via the completion rows).
 *
 * "Completed" means the activity completion state of the quiz course module is
 * COMPLETION_COMPLETE or COMPLETION_COMPLETE_PASS. COMPLETION_COMPLETE_FAIL
 * (completed without reaching the pass grade) is exposed through the state
 * column but does not count as completed, matching
 * \local_wb_dashboard\reportbuilder\local\entities\activity_progress.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_completion extends base {
    /**
     * Database tables that this entity uses
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            'quiz',
            'course_modules',
            'course_modules_completion',
            'user',
        ];
    }

    /**
     * The default title for this entity
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity:quizcompletion', 'local_wb_dashboard');
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
     * SQL expression evaluating to 1 when the completion row marks the quiz as
     * completed (states COMPLETION_COMPLETE/COMPLETION_COMPLETE_PASS), else 0
     *
     * @param string $cmc Completion table alias
     * @return string
     */
    private function get_completed_sql(string $cmc): string {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        return "CASE WHEN {$cmc}.completionstate IN (" . COMPLETION_COMPLETE . ", " . COMPLETION_COMPLETE_PASS . ")
                     THEN 1 ELSE 0 END";
    }

    /**
     * SQL expression for the time the quiz was completed, NULL while not completed
     *
     * @param string $cmc Completion table alias
     * @return string
     */
    private function get_timecompleted_sql(string $cmc): string {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        return "CASE WHEN {$cmc}.completionstate IN (" . COMPLETION_COMPLETE . ", " . COMPLETION_COMPLETE_PASS . ")
                     THEN {$cmc}.timemodified ELSE NULL END";
    }

    /**
     * Correlated subquery counting the user's finished (non-preview) attempts
     * on the quiz, NULL for rows without a user
     *
     * @param string $quiz Quiz table alias
     * @param string $user User table alias
     * @return string
     */
    private function get_attempts_sql(string $quiz, string $user): string {
        $qa = database::generate_alias();

        return "CASE WHEN {$user}.id IS NULL THEN NULL ELSE
                    (SELECT COUNT(1)
                       FROM {quiz_attempts} {$qa}
                      WHERE {$qa}.quiz = {$quiz}.id
                            AND {$qa}.userid = {$user}.id
                            AND {$qa}.preview = 0
                            AND {$qa}.state = 'finished')
                END";
    }

    /**
     * Returns list of all available columns
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        [
            'quiz' => $quiz,
            'course_modules' => $cm,
            'course_modules_completion' => $cmc,
            'user' => $user,
        ] = $this->get_table_aliases();

        // Quiz name.
        $columns[] = (new column(
            'name',
            new lang_string('quizcompletion:name', 'local_wb_dashboard'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$quiz}.name")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $value): string {
                return $value !== null ? format_string($value) : '';
            });

        // Quiz name as link to the quiz view page.
        $columns[] = (new column(
            'namewithlink',
            new lang_string('quizcompletion:namewithlink', 'local_wb_dashboard'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$quiz}.name, {$cm}.id AS cmid")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $value, stdClass $row): string {
                if ($value === null || empty($row->cmid)) {
                    return '';
                }
                $url = new moodle_url('/mod/quiz/view.php', ['id' => $row->cmid]);
                return html_writer::link($url, format_string($value));
            });

        // Whether the user completed the quiz (yes/no).
        $columns[] = (new column(
            'completed',
            new lang_string('quizcompletion:completed', 'local_wb_dashboard'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field($this->get_completed_sql($cmc), 'completed')
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                // Rows without user (quiz without any completion data) stay empty.
                if ($value === null) {
                    return '';
                }
                return format::boolean_as_text((bool) $value);
            });

        // Raw completion state, rendered human readable.
        $columns[] = (new column(
            'state',
            new lang_string('quizcompletion:state', 'local_wb_dashboard'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$cmc}.completionstate", 'state')
            ->set_is_sortable(true)
            ->add_callback(static function (?int $value): string {
                global $CFG;
                require_once($CFG->libdir . '/completionlib.php');

                switch ($value) {
                    case COMPLETION_COMPLETE:
                        return get_string('quizcompletion:state:completed', 'local_wb_dashboard');
                    case COMPLETION_COMPLETE_PASS:
                        return get_string('quizcompletion:state:passed', 'local_wb_dashboard');
                    case COMPLETION_COMPLETE_FAIL:
                        return get_string('quizcompletion:state:failed', 'local_wb_dashboard');
                    case COMPLETION_INCOMPLETE:
                        return get_string('quizcompletion:state:notcompleted', 'local_wb_dashboard');
                    default:
                        return '';
                }
            });

        // Time the quiz was completed.
        $columns[] = (new column(
            'timecompleted',
            new lang_string('quizcompletion:timecompleted', 'local_wb_dashboard'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field($this->get_timecompleted_sql($cmc), 'timecompleted')
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        // Finished attempts of the user on the quiz.
        $columns[] = (new column(
            'attempts',
            new lang_string('quizcompletion:attempts', 'local_wb_dashboard'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field($this->get_attempts_sql($quiz, $user), 'attempts')
            ->set_is_sortable(true);

        return $columns;
    }

    /**
     * Returns list of all available filters
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        [
            'quiz' => $quiz,
            'course_modules' => $cm,
            'course_modules_completion' => $cmc,
            'user' => $user,
        ] = $this->get_table_aliases();

        // Select a specific quiz (course module).
        $filters[] = (new filter(
            select::class,
            'quizselect',
            new lang_string('quizcompletion:quizselect', 'local_wb_dashboard'),
            $this->get_entity_name(),
            "{$cm}.id"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback([static::class, 'get_quiz_options']);

        // Quiz name text filter.
        $filters[] = (new filter(
            text::class,
            'name',
            new lang_string('quizcompletion:name', 'local_wb_dashboard'),
            $this->get_entity_name(),
            "{$quiz}.name"
        ))
            ->add_joins($this->get_joins());

        // Completed yes/no.
        $filters[] = (new filter(
            boolean_select::class,
            'completed',
            new lang_string('quizcompletion:completed', 'local_wb_dashboard'),
            $this->get_entity_name(),
            $this->get_completed_sql($cmc)
        ))
            ->add_joins($this->get_joins());

        // Time completed date filter.
        $filters[] = (new filter(
            date::class,
            'timecompleted',
            new lang_string('quizcompletion:timecompleted', 'local_wb_dashboard'),
            $this->get_entity_name(),
            $this->get_timecompleted_sql($cmc)
        ))
            ->add_joins($this->get_joins())
            ->set_limited_operators([
                date::DATE_ANY,
                date::DATE_RANGE,
                date::DATE_LAST,
                date::DATE_CURRENT,
            ]);

        // Number of finished attempts.
        $filters[] = (new filter(
            number::class,
            'attempts',
            new lang_string('quizcompletion:attempts', 'local_wb_dashboard'),
            $this->get_entity_name(),
            $this->get_attempts_sql($quiz, $user)
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }

    /**
     * Options for the quiz select filter: every non-deleted quiz on the site,
     * keyed by course module ID and labelled "Course fullname: Quiz name"
     *
     * @return string[]
     */
    public static function get_quiz_options(): array {
        global $DB;

        $sql = "SELECT cm.id AS cmid, q.name AS quizname, c.fullname AS coursefullname
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                  JOIN {quiz} q ON q.id = cm.instance
                  JOIN {course} c ON c.id = cm.course
                 WHERE cm.deletioninprogress = 0
              ORDER BY c.fullname, q.name";

        $options = [];
        foreach ($DB->get_records_sql($sql) as $record) {
            $options[$record->cmid] = format_string($record->coursefullname) . ': ' . format_string($record->quizname);
        }

        return $options;
    }
}
