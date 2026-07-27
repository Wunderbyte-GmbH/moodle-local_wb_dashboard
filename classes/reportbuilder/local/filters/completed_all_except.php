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

namespace local_wb_dashboard\reportbuilder\local\filters;

use MoodleQuickForm;
use coding_exception;
use core_reportbuilder\local\filters\base;
use core_reportbuilder\local\helpers\database;

/**
 * "Completed all activities except ..." report filter.
 *
 * Matches users who have completed every trackable activity of the course apart
 * from a given comma-separated list of activities, identified either by course
 * module id or by course module idnumber.
 *
 * Template substitution contract (unusual for report builder - not a bug):
 * the filter needs to inject a dynamic exclusion list into the middle of the
 * correlated "remaining activities" subquery, but a report builder filter only
 * carries a single field SQL string. The entity therefore provides field SQL of
 * the shape
 *
 *     (SELECT COUNT(1)
 *        FROM {course_modules} cmx
 *   LEFT JOIN {course_modules_completion} cmcx ON cmcx.coursemoduleid = cmx.id
 *             AND cmcx.userid = u.id
 *       WHERE cmx.course = c.id
 *             AND cmx.completion <> 0
 *             AND cmx.deletioninprogress = 0
 *             AND (cmcx.id IS NULL OR cmcx.completionstate NOT IN (1, 2))
 *             [[EXCLUSION]])
 *
 * containing the literal {@see self::TOKEN_EXCLUSION} placeholder, and
 * {@see self::get_sql_filter} replaces that placeholder with an
 * "AND cmx.id NOT IN (...)" / "AND cmx.idnumber NOT IN (...)" clause before
 * emitting "<field> = 0". Because the placeholder replacement needs to know the
 * table aliases used inside (and outside) the subquery, the entity must also
 * pass them via {@see \core_reportbuilder\local\report\filter::set_options}:
 *
 *     ['cmalias' => ..., 'coursealias' => ..., 'useralias' => ...]
 *
 * The second operator additionally asserts that none of the listed activities
 * are complete (state COMPLETION_COMPLETE or COMPLETION_COMPLETE_PASS) for the
 * user, via a NOT EXISTS clause built from the course/user aliases. Consistent
 * with the activity_progress entity (and deliberately unlike core's
 * count_modules_completed()), stale completion rows of activities whose
 * completion tracking was later disabled do not count as "complete" here.
 *
 * An empty activity list results in no filtering at all.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completed_all_except extends base {

    /** @var string Placeholder in the field SQL replaced by the exclusion list clause */
    public const TOKEN_EXCLUSION = '[[EXCLUSION]]';

    /** @var int Completed everything except the listed activities */
    public const OPERATOR_EXCEPT = 1;

    /** @var int Completed everything except the listed activities, and none of those are complete */
    public const OPERATOR_EXCEPT_NONE_COMPLETE = 2;

    /** @var int Interpret the list as course module idnumbers */
    public const IDENTIFIER_IDNUMBER = 0;

    /** @var int Interpret the list as course module ids */
    public const IDENTIFIER_CMID = 1;

    /**
     * Returns an array of the operators
     *
     * @return array of operators
     */
    private function get_operators(): array {
        $operators = [
            self::OPERATOR_EXCEPT =>
                get_string('completedallexcept:operator:except', 'local_wb_dashboard'),
            self::OPERATOR_EXCEPT_NONE_COMPLETE =>
                get_string('completedallexcept:operator:exceptnonecomplete', 'local_wb_dashboard'),
        ];

        return $this->filter->restrict_limited_operators($operators);
    }

    /**
     * Adds controls specific to this filter in the form
     *
     * @param MoodleQuickForm $mform
     */
    public function setup_form(MoodleQuickForm $mform): void {
        $elements = [];

        $elements['operator'] = $mform->createElement('select', "{$this->name}_operator",
            get_string('filterfieldoperator', 'core_reportbuilder', $this->get_header()), $this->get_operators());

        $elements['identifier'] = $mform->createElement('select', "{$this->name}_identifier",
            get_string('completedallexcept:identifier', 'local_wb_dashboard'), [
                self::IDENTIFIER_IDNUMBER => get_string('completedallexcept:identifier:idnumber', 'local_wb_dashboard'),
                self::IDENTIFIER_CMID => get_string('completedallexcept:identifier:cmid', 'local_wb_dashboard'),
            ]);

        $elements['values'] = $mform->createElement('text', "{$this->name}_values",
            get_string('completedallexcept:activities', 'local_wb_dashboard'), ['size' => 20]);

        $mform->addGroup($elements, "{$this->name}_grp", $this->get_header(), '', false)
            ->setHiddenLabel(true);
        $mform->addHelpButton("{$this->name}_grp", 'completedallexcept:activities', 'local_wb_dashboard');

        $mform->setType("{$this->name}_operator", PARAM_INT);
        $mform->setType("{$this->name}_identifier", PARAM_INT);
        $mform->setDefault("{$this->name}_identifier", self::IDENTIFIER_IDNUMBER);
        $mform->setType("{$this->name}_values", PARAM_RAW_TRIMMED);
    }

    /**
     * Return filter SQL
     *
     * @param array $values
     * @return array array of two elements - SQL query and named parameters
     */
    public function get_sql_filter(array $values): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $operator = (int) ($values["{$this->name}_operator"] ?? self::OPERATOR_EXCEPT);
        $identifier = (int) ($values["{$this->name}_identifier"] ?? self::IDENTIFIER_IDNUMBER);

        $identifiers = $this->parse_identifiers((string) ($values["{$this->name}_values"] ?? ''), $identifier);
        if ($identifiers === []) {
            // No (valid) activities given, do not filter at all.
            return ['', []];
        }

        $options = (array) $this->filter->get_options();
        if (empty($options['cmalias']) || empty($options['coursealias']) || empty($options['useralias'])) {
            throw new coding_exception('The completed_all_except filter requires the cmalias, coursealias and ' .
                'useralias options, see the class docblock');
        }

        $fieldsql = $this->filter->get_field_sql();
        $params = $this->filter->get_field_params();
        if (strpos($fieldsql, self::TOKEN_EXCLUSION) === false) {
            throw new coding_exception('The completed_all_except filter field SQL must contain the ' .
                self::TOKEN_EXCLUSION . ' placeholder, see the class docblock');
        }

        // Replace the placeholder with the exclusion clause: the listed activities
        // must not count towards the user's remaining activities.
        [$notinsql, $notinparams] = $DB->get_in_or_equal($identifiers, SQL_PARAMS_NAMED,
            database::generate_param_name() . '_', false);
        $matchsql = $this->get_match_sql($options['cmalias'], $identifier);
        $fieldsql = str_replace(self::TOKEN_EXCLUSION, "AND {$matchsql} {$notinsql}", $fieldsql);
        $params += $notinparams;

        $sql = "{$fieldsql} = 0";

        if ($operator === self::OPERATOR_EXCEPT_NONE_COMPLETE) {
            // Additionally assert that none of the listed activities are complete for the user.
            [$cm, $cmc] = database::generate_aliases(2);
            [$insql, $inparams] = $DB->get_in_or_equal($identifiers, SQL_PARAMS_NAMED,
                database::generate_param_name() . '_');

            $sql .= "
                AND NOT EXISTS (
                    SELECT 1
                      FROM {course_modules} {$cm}
                      JOIN {course_modules_completion} {$cmc} ON {$cmc}.coursemoduleid = {$cm}.id
                           AND {$cmc}.userid = {$options['useralias']}.id
                     WHERE {$cm}.course = {$options['coursealias']}.id
                           AND {$cm}.completion <> " . COMPLETION_TRACKING_NONE . "
                           AND {$cm}.deletioninprogress = 0
                           AND {$cmc}.completionstate IN (" . COMPLETION_COMPLETE . ", " . COMPLETION_COMPLETE_PASS . ")
                           AND " . $this->get_match_sql($cm, $identifier) . " {$insql}
                )";
            $params += $inparams;
        }

        return [$sql, $params];
    }

    /**
     * Parse the user supplied comma-separated activity list
     *
     * @param string $raw
     * @param int $identifier IDENTIFIER_CMID or IDENTIFIER_IDNUMBER
     * @return array of clean identifiers (ints for cmids, strings for idnumbers), empty if none are usable
     */
    private function parse_identifiers(string $raw, int $identifier): array {
        $values = array_filter(array_map('trim', explode(',', $raw)), static function(string $value): bool {
            return $value !== '';
        });

        if ($identifier === self::IDENTIFIER_CMID) {
            // Cast to int, dropping anything non-numeric.
            $values = array_filter(array_map('intval', $values));
        }

        return array_values($values);
    }

    /**
     * Return the SQL expression the exclusion list is matched against
     *
     * @param string $cmalias Alias of the {course_modules} table to match against
     * @param int $identifier IDENTIFIER_CMID or IDENTIFIER_IDNUMBER
     * @return string
     */
    private function get_match_sql(string $cmalias, int $identifier): string {
        if ($identifier === self::IDENTIFIER_CMID) {
            return "{$cmalias}.id";
        }

        // The idnumber column is nullable; without COALESCE a "NOT IN" comparison
        // on a NULL idnumber is NULL, silently dropping the module from the count.
        return "COALESCE({$cmalias}.idnumber, '')";
    }

    /**
     * Return sample filter values
     *
     * @return array
     */
    public function get_sample_values(): array {
        return [
            "{$this->name}_operator" => self::OPERATOR_EXCEPT,
            "{$this->name}_identifier" => self::IDENTIFIER_CMID,
            "{$this->name}_values" => '1',
        ];
    }
}
