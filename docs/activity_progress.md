# Course activity completion progress datasource

Report builder datasource (`Course activity completion progress`) with one row
per course, enrolment method and enrolled user, exposing per-user activity
completion counts through the `activity_progress` entity:

- **Trackable activities** — activities with completion tracking enabled and not
  flagged for deletion (`cm.completion <> 0 AND cm.deletioninprogress = 0`),
  mirroring `completion_info::get_activities()`.
- **Completed activities** — trackable activities with a completion row in state
  `COMPLETION_COMPLETE` (1) or `COMPLETION_COMPLETE_PASS` (2). A missing row and
  `COMPLETION_COMPLETE_FAIL` (3) both count as incomplete.
- **Remaining activities** — trackable minus completed. The standard number
  filter on this column answers "who needs more than / fewer than N activities
  to finish the course".
- **Progress percentage** — completed / trackable, `NULL` (blank) for courses
  without trackable activities.

The **Completed all activities except** filter matches users who completed every
trackable activity apart from a comma-separated list (by course module id or by
idnumber; idnumbers are more portable across staging/production). The stricter
operator additionally requires that none of the listed activities are complete.
An empty list disables the filter.

## Deliberate divergence from core

Core's `completion_info::count_modules_completed()` does not filter on
`cm.completion <> 0`, so if completion tracking is switched off on an activity
after users completed it, the stale rows keep counting and the core progress
block can report over 100%. This datasource applies the same trackable filter to
numerator and denominator, so stale rows are ignored and progress never exceeds
100%. In that edge case our numbers legitimately differ from the core progress
block.

## Limitations

- **Visibility and access restrictions are not applied** (same as
  `get_activities()`): hidden/stealth activities and activities with
  availability restrictions still count in the denominator. A user who cannot
  access an activity can therefore never reach 0 remaining. Per-user visibility
  (`$cm->uservisible`) requires `get_fast_modinfo()` per user and is not
  expressible in SQL; it is deliberately not approximated.
- **All enrolled users are included**, not only users passing
  `completion_info::is_tracked_user()` (enrolled holding
  `moodle/course:isincompletionreports`, which normally excludes teachers). Use
  the role condition/filter to narrow the report to students.
- **Duplicate rows**: a user enrolled through two enrolment methods produces two
  rows, exactly as in the core course participants datasource.
- **No history**: deleting an activity cascades away its completion rows; past
  completions of deleted activities are unrecoverable.

## Performance

The counts are correlated subqueries evaluated per result row. Report builder
paginates report output, so per-page cost is bounded; the completion lookup uses
the unique `(userid, coursemoduleid)` index on `{course_modules_completion}`.
