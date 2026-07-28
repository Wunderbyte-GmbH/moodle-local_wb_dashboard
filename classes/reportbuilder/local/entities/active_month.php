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
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;

/**
 * Entity over one "active month" per user: a user with at least one login in a
 * calendar month yields exactly one row for that month.
 *
 * The entity reads from a grouped subquery over {logstore_standard_log} (built by
 * the datasource, see {@see \local_wb_dashboard\reportbuilder\datasource\active_users}),
 * exposed under this entity's table alias with the fields:
 * userid, monthstart, logins, firstlogin, lastlogin.
 *
 * @package    local_wb_dashboard
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class active_month extends base {

    /**
     * Database tables that this entity uses. The alias generated for
     * {logstore_standard_log} is re-used as the alias of the grouped subquery.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            'logstore_standard_log',
        ];
    }

    /**
     * The default title for this entity
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity:activemonth', 'local_wb_dashboard');
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
     * Returns list of all available columns
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $tablealias = $this->get_table_alias('logstore_standard_log');

        // The month, held as the timestamp of its first second so it sorts and
        // filters chronologically; displayed as "April 2026".
        $columns[] = (new column(
            'month',
            new lang_string('activemonth:month', 'local_wb_dashboard'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$tablealias}.monthstart")
            ->set_is_sortable(true)
            ->add_callback(static function(?int $value): string {
                if (!$value) {
                    return '';
                }
                // The month boundary is computed in the database session timezone; label
                // from a mid-month instant so the month name is right in every display
                // timezone (a month-start instant renders as the previous month for
                // viewers west of the database timezone).
                return userdate($value + (13 * DAYSECS), get_string('strftimemonthyear', 'langconfig'));
            });

        // Number of logins the user performed within the month.
        $columns[] = (new column(
            'logins',
            new lang_string('activemonth:logins', 'local_wb_dashboard'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$tablealias}.logins")
            ->set_is_sortable(true);

        // First and last login within the month.
        $columns[] = (new column(
            'firstlogin',
            new lang_string('activemonth:firstlogin', 'local_wb_dashboard'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$tablealias}.firstlogin")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        $columns[] = (new column(
            'lastlogin',
            new lang_string('activemonth:lastlogin', 'local_wb_dashboard'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$tablealias}.lastlogin")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        return $columns;
    }

    /**
     * Returns list of all available filters
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $tablealias = $this->get_table_alias('logstore_standard_log');

        // Month as a date filter: "from 1 April 2026" keeps April and later months.
        $filters[] = (new filter(
            date::class,
            'month',
            new lang_string('activemonth:month', 'local_wb_dashboard'),
            $this->get_entity_name(),
            "{$tablealias}.monthstart"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            number::class,
            'logins',
            new lang_string('activemonth:logins', 'local_wb_dashboard'),
            $this->get_entity_name(),
            "{$tablealias}.logins"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            date::class,
            'firstlogin',
            new lang_string('activemonth:firstlogin', 'local_wb_dashboard'),
            $this->get_entity_name(),
            "{$tablealias}.firstlogin"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            date::class,
            'lastlogin',
            new lang_string('activemonth:lastlogin', 'local_wb_dashboard'),
            $this->get_entity_name(),
            "{$tablealias}.lastlogin"
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }
}
