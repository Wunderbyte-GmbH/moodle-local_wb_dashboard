# local_wb_dashboard — Report Builder datasources

Besides consuming existing Report Builder reports, the plugin provides its own
Report Builder **datasources**. Reports built on them are regular custom reports:
they work with the `[chart]` / `[digits]` / `[chartfilter]` shortcodes through the
normal `source=reportbuilder` mechanism, including locked filters and per-report
access control.

## Active users (unique per month)

**Datasource:** *Active users (unique per month)*
(`local_wb_dashboard\reportbuilder\datasource\active_users`)

One row per **user per calendar month with at least one login** — a user logging
in three times in April yields a single "user / April" row. Counting rows per
month therefore counts **unique active users per month**; counting all rows after
a date filter counts *user-months*, not users.

Data comes from login events (`\core\event\user_loggedin`) in the **standard
logstore**, so:

- The **Standard log** store must be enabled (*Site administration → Plugins →
  Logging → Manage log stores*). Months where it was disabled show no data —
  nothing can backfill them.
- History is bounded by the store's retention (*"Keep logs for"* in the Standard
  log settings).
- Month boundaries follow the **database server's timezone**.
- Guest and deleted users are excluded. Supported databases: PostgreSQL,
  MySQL/MariaDB.

### Entities

- **Active month** (`active_month`) — columns: *Month* (displayed e.g. "April
  2026", sorts chronologically), *Logins in month*, *First/Last login in month*.
  Filters/conditions: *Month* (date), *Logins in month* (number), *Last login in
  month* (date).
- **User** — the full core user entity, including custom profile fields, so
  region-style filters work exactly as on a users-source report.

### Recipes

Unique active users per month (bar chart): report with column `active_month:month`
(sorted ascending), then

```
[chart type=bar source=reportbuilder report=<id> categoryfield=month aggregation=count]
```

Unique active users in one month: same report — the month is one bar of the chart
above; or add a date filter and read a single month's value with

```
[chartfilter key=month type=date label="From" pageid=...]
[digits source=reportbuilder display=count report=<id> consumes=month pageid=...]
```

(the digits then count user-months from the chosen date, so they equal unique
users only while a single month is in range).

Regional split: add the user *Region* profile field filter to the report and a
matching `[chartfilter key=region ...]` on the page.

## Quiz completions

**Datasource:** *Quiz completions*
(`local_wb_dashboard\reportbuilder\datasource\quiz_completions`)

One row per **quiz and per user with an activity completion record** on that
quiz. A new report starts with the *Quiz completed* condition set to *Yes*, so
by default **counting rows counts quiz completions** ("completed" = activity
completion state *complete* or *complete-pass*; *failed* does not count).
Removing the condition also surfaces viewed/failed completion rows plus one
user-less row per quiz without any completion data.

Notes:

- "Completion" is the **activity completion** of the quiz course module, not a
  finished attempt. The number of finished (non-preview) attempts is available
  as its own column and number filter.
- Quizzes with completion tracking disabled never produce completed rows.
- Deleted users are excluded; enrolment status is not checked (completions of
  meanwhile unenrolled users still count).

### Entities

- **Quiz completion** (`quiz_completion`) — columns: *Quiz name* (plain and
  with link), *Quiz completed* (yes/no), *Completion state* (not completed /
  completed / pass / fail), *Time completed*, *Finished attempts*.
  Filters/conditions: **Quiz** (select listing every quiz as "Course: Quiz",
  the filter to pin a report to one quiz), *Quiz name* (text),
  *Quiz completed* (yes/no), *Time completed* (date), *Finished attempts*
  (number).
- **Course** and **User** — the full core entities, so course filters and user
  profile field filters work as usual.

### Recipes

Completions of one specific quiz as a number: report on this source (the
default *Quiz completed = Yes* condition already restricts it to completions),
set the **Quiz** condition to the quiz — or leave it a filter and let the page
choose:

```
[chartfilter key=quizselect type=select optionsfield=quizselect label="Quiz" report=<id> pageid=...]
[digits source=reportbuilder display=count report=<id> pageid=...]
```

(The dropdown options come from the report's own *Quiz* select filter, so they
are always the "Course: Quiz" list and pass core's filter validation.)

Completions per quiz (bar chart): add the quiz name column and

```
[chart type=bar source=reportbuilder report=<id> categoryfield=name aggregation=count]
```
