# local_wb_dashboard — shortcode reference

Three shortcodes are provided. They require the third-party **`filter_shortcodes`**
filter to be installed and enabled in the context where the content is shown.

- **`[chart ...]`** — renders one chart. Data is loaded client-side from a web
  service, so the tag only emits a canvas; the chart draws once the data arrives.
- **`[digits ...]`** — renders one **single value** (a number, count or percentage)
  as a styleable text field with a constant DOM id (see §4). Same client-side load
  and filter behaviour as `[chart]`, but the value is plain DOM text, not a canvas.
- **`[chartfilter ...]`** — renders one page-level filter control. Every chart or
  digits field on the same page (same `pageid`) that "consumes" the filter's key
  re-queries when the filter changes.

---

## 1. `[chart]`

### Common flags (all chart types)

| Flag | Values | Default | Description |
|------|--------|---------|-------------|
| `type` | `doughnut`, `bar`, `horizontalbar`, `stackedbar`, `progress` | `bar` | The chart type (see §3). |
| `source` | `reportbuilder` | — (required) | The data source (see §2). |
| `title` | text | plugin name | Chart title, also used as the canvas `aria-label`. |
| `width` | number (rem) | `32` | Max width of the chart container. |
| `height` | number (rem) | `20` | Height of the chart container. Use a small value (≈`8`) for `progress`. |
| `consumes` | comma-separated filter keys | *(all)* | Which page filter keys this chart reacts to. Omit to react to every filter the source can map. |
| `pageid` | alphanumeric | `default` | Groups the chart with the filters and other charts that share the same `pageid`. |
| `centertext` | `1`/`0` | `1` | **Doughnut only.** `0` hides the centre value/label text. |
| `target` | number | *(automatic)* | Manual maximum of the value axis. For `progress` this is the goal the bar fills towards (e.g. `target=1000` for "users of 1000"); without it the bar's own total is the maximum, so the bar is always full. Also caps the axis on `bar`/`horizontalbar`/`stackedbar`; ignored by `doughnut`. |

Any flag **not** in the table above is passed to the source as a *source parameter*
(see §2) — unknown parameters are dropped server-side.

Colours are **not** set on the shortcode. Each chart follows the active palette by
default and can be individually recoloured through the per-chart settings gear (see
§7).

### Notes
- Charts never query data during page render; they emit a canvas and load via the
  `local_wb_dashboard_get_chart_data` web service.
- On invalid input (missing/unknown `source`, unknown `type`) the shortcode returns a
  short error message instead of breaking the page.

---

## 2. Source: `reportbuilder`

Pulls from a **core Report Builder** report. There are three shaping modes, chosen
by which parameters you supply. (The modes themselves are shared by every source —
future sources accept the same shaping parameters, with their own ids in place of
report ids.) Access is enforced per report: a viewer who lacks permission on a
referenced report gets an error, not data.

### Mode A — two-report delta (a value and its remainder)

Reads a single number from the first row of each of two reports and renders
`[base, total − base]`. This is the "logged vs remaining" shape.

| Param | Description |
|-------|-------------|
| `idbase` | Report id supplying the **achieved** value. |
| `fieldbase` | Field in that report holding the number. |
| `idtotal` | Report id supplying the **target/total** value. |
| `fieldtotal` | Field in that report holding the number. |

Best rendered as `doughnut` or `progress` (also works as `bar` / `horizontalbar`,
which then show two bars). The source also exposes `total` as the axis maximum, which
`progress` uses to fill the bar to 100 %.

```
[chart type=doughnut source=reportbuilder idbase=3 fieldbase=minuteslogged idtotal=5 fieldtotal=minutestarget width=32 height=20]
[chart type=progress  source=reportbuilder idbase=3 fieldbase=minuteslogged idtotal=5 fieldtotal=minutestarget width=40 height=8 title="Completion"]
```

### Mode B — rows (one data point per report row)

Reads every row of one report; each row becomes one data point. An optional
`stackfield` groups rows into series (for stacked/grouped bars).

| Param | Description |
|-------|-------------|
| `report` | The report id. |
| `categoryfield` | Field used as the category / x-axis label (one bar/point per distinct value). |
| `valuefield` | Field used as the **numeric** value (bar height). Required unless `aggregation=count` or `valuefields` is used. |
| `valuefields` | *(optional)* Comma-separated list of numeric fields — one series per field, rendered as grouped bars (e.g. `valuefields=sent,delivered,opened`). Not combinable with `stackfield` or `aggregation=count`. |
| `valuelabels` | *(optional)* Comma-separated legend labels for the `valuefields` (or single `valuefield`) series, matched by position (e.g. `valuelabels="Sent,Delivered,Opened"`). Missing or empty entries fall back to the field name; the remainder segment keeps `remainderlabel`. |
| `remainderof` | *(optional)* Field supplying a **whole** that the `valuefields` are subsets of. The listed fields are stacked and topped up with a computed remainder (`remainderof` − listed fields, never below 0), so the total bar height equals this field — a part-of-whole bar per category. Same combination rules as `valuefields`. |
| `remainderlabel` | *(optional)* Legend label for the computed remainder segment (default "Remaining"). |
| `normalize` | *(optional)* `percent` scales every **stacked** bar (from `remainderof` or `stackfield`) to 100%: each category's segments become its percentage split and the value axis is pinned at 0–100, so all bars are equally tall and compare as rates. Ignored for non-stacked shapes. |
| `stackfield` | *(optional)* Field whose distinct values become separate stacked series. |
| `aggregation` | *(optional)* `sum` (default) adds up `valuefield` per category; `count` tallies one per row (no `valuefield` needed). |
| `top` | *(optional)* Keep only the N highest categories (a "top N"). Ranks by each category's total value (its stacked height when `stackfield` is used). Omit for all categories. |
| `order` | *(optional)* Direction for `top`: `desc` (default) keeps the highest N, `asc` keeps the lowest N (a "bottom N"). No effect without `top`. |

Best rendered as `bar`, `horizontalbar`, or `stackedbar` (with `stackfield`).
`top`/`order` are **rows-mode only** — the delta and multi-report-totals modes are
inherently bounded already.

```
[chart type=bar        source=reportbuilder report=3 categoryfield=month valuefield=total width=40 height=24]
[chart type=stackedbar source=reportbuilder report=3 categoryfield=month valuefield=total stackfield=status width=40 height=24]
```

**Top N** — the 5 categories with the highest total (drop `order` for the default
`desc`, or set `order=asc` for the bottom 5):

```
[chart type=horizontalbar source=reportbuilder report=3 categoryfield=coursename valuefield=completions order=desc top=5 title="Top 5 courses"]
[chart type=bar           source=reportbuilder report=3 categoryfield=country aggregation=count top=5 title="Top 5 countries by users"]
```

**Several fields per category** — grouped bars, one series per listed field:

```
[chart type=bar source=reportbuilder report=3 categoryfield=month valuefields=sent,delivered,opened width=40 height=24]
```

**Part of a whole** — `delivered` as a subset of all `sent`: delivered and the
computed rest are stacked, so every bar's full height is the sent total. Works
with `bar` and `horizontalbar` (stacking is applied automatically):

```
[chart type=bar source=reportbuilder report=3 categoryfield=month valuefields=delivered valuelabels="Delivered" remainderof=sent remainderlabel="Not delivered"]
```

**Counting rows** — for a report where each row is an entity (e.g. a user), count
rows per category instead of summing a number. No numeric field is needed:

```
[chart type=bar source=reportbuilder report=3 categoryfield=country aggregation=count width=40 height=24]
[chart type=stackedbar source=reportbuilder report=3 categoryfield=country stackfield=role aggregation=count width=40 height=24]
```

### Mode C — multi-report totals (one bar per report)

Renders one data point per report: its **row count**, or the sum of a value field
across its rows. Labels are the report names. Use this to compare "how many users
(rows) are in report A vs report B vs …".

| Param | Description |
|-------|-------------|
| `reports` | Comma-separated report ids, e.g. `reports=6,3`. |
| `aggregation` | `count` (default here) counts rows per report; `sum` adds `valuefield`. |
| `valuefield` | *(optional)* Field to sum per report when `aggregation=sum`. |

```
[chart type=bar source=reportbuilder reports=6,3 aggregation=count title="Users per report"]
```

Combined with `type=progress` and a manual `target`, a single report becomes a
goal-progress bar — the row count fills the bar towards the configured goal:

```
[chart type=progress source=reportbuilder reports=6 aggregation=count target=1000 height=8 title="Registered users of 1000"]
```

### Field names & values
- `valuefield` must resolve to a **numeric** value; text coerces to `0`. Category and
  stack fields are treated as labels.
- Field names are matched case-insensitively against the report column's **name** or
  its **unique identifier** (e.g. `user:fullname`). Use identifiers **without spaces**
  — `filter_shortcodes` splits arguments on whitespace.

---

## 3. Chart types

| `type` | Shape it expects | Renders as |
|--------|------------------|-----------|
| `doughnut` | one series of N slices (e.g. two-report delta) | Ring chart; optional centre text (`centertext`), no legend/tooltip. |
| `bar` | categories × one or more series | Vertical bars. |
| `horizontalbar` | categories × one or more series | Horizontal bars (`indexAxis: y`). |
| `stackedbar` | categories × multiple series (use `stackfield`) | Grouped **stacked** bars. |
| `progress` | one series of N segments + a total | Single horizontal stacked bar with a fixed maximum — a progress/percentage bar. The maximum is, in order: an explicit `target` flag, an axis maximum provided by the shaping (two-report delta), else the series total. |

---

## 4. `[digits]`

Renders a **single value** — a number, a count, or a percentage — as plain DOM
text you can style freely. Like `[chart]` it emits an empty field on page render and
loads the value client-side from the `local_wb_dashboard_get_digits_data` web
service, so it reacts to page filters (`consumes` / `pageid`) exactly the same way.

It reuses the same `reportbuilder` source (§2): whatever numbers a chart could draw,
a digits field can reduce to one value. The whole first data series is collapsed to a
single scalar — for `count`/`number` that is the **sum** of the series; for `percent`
it is `base ÷ total × 100`.

### Flags

| Flag | Values | Default | Description |
|------|--------|---------|-------------|
| `source` | `reportbuilder` | — (required) | The data source (see §2). |
| `display` | `number`, `count`, `percent` | `number` | How to reduce the source data (see below). `number` and `count` are equivalent (both sum the series). |
| `label` | text | source-derived | Text shown under the value. Overrides any label the source provides. |
| `decimals` | `0`–`6` | `0` | Decimal places for the formatted value (locale-aware via `format_float`). |
| `unit` | text | — | Suffix appended after the value (e.g. `pts`, `€`). Ignored for `percent`, which always uses `%`. |
| `consumes` | comma-separated filter keys | *(all)* | Which page filter keys this field reacts to. |
| `pageid` | alphanumeric | `default` | Groups the field with the filters/charts sharing the same `pageid`. |

Any flag **not** in this table is passed to the source as a *source parameter* (see
§2) — so `reports`, `report`, `valuefield`, `idbase`/`idtotal`, `aggregation`, etc.
all work exactly as they do for `[chart]`.

### `display` modes

- **`count` / `number`** — sum of the first data series. This is meaningful when the
  points are **parts of one whole**: a single report's row count, or a rows-mode
  `aggregation=count` totalled across its categories. Summing the counts of *unrelated*
  reports (`reports=6,3`) is **not** meaningful — for that, use `percent` (below) or one
  `[digits]` per report.
- **`percent`** — `base ÷ total × 100`. The total is resolved in this order:
  1. **base/total delta** — `idbase`/`fieldbase` (the achieved value) over
     `idtotal`/`fieldtotal` (the target). This reads a numeric field from each report.
  2. **two-report ratio** — `reports=<part>,<whole>` with `aggregation=count`: the
     first report's row count over the second's (e.g. *subset of users ÷ all users*).
  3. a single value on its own resolves to `100%` (or `0%` when it is zero).

  Division by zero yields `0`.

A report that runs but matches no rows renders as `0` (or `0%`) — unlike a chart,
which reports an error because it has nothing to draw.

### Examples

```
# Row count of a single report (report 6):
[digits source=reportbuilder display=count reports=6 label="Active users"]

# Sum of a numeric field across a report's rows:
[digits source=reportbuilder display=number report=3 categoryfield=month valuefield=total label="Total hours"]

# Percentage — subset vs all (count of report 6 ÷ count of report 3):
[digits source=reportbuilder display=percent reports=6,3 aggregation=count label="% enrolled" decimals=1]

# Percentage — a base/total field pair, reacting to a page date filter:
[digits source=reportbuilder display=percent idbase=3 fieldbase=minuteslogged idtotal=5 fieldtotal=minutestarget label="Completion" decimals=1 consumes=period pageid=team]
```

> **Comparing two reports.** For "how much of the whole is this part" (a subset of users
> vs all users), use `display=percent reports=<part>,<whole> aggregation=count` — the ratio,
> not the sum. To show each report's raw count, use one `[digits]` per report; for a
> side-by-side bar comparison use `[chart type=bar reports=6,3 aggregation=count]`.

### DOM ids & styling

Each field is wrapped in a `<div>` with a **deterministic, constant** id derived from
its configuration (source + params + display mode), so it survives reloads and can be
targeted from CSS or theme SCSS. The inner value and label carry sub-ids:

```html
<div class="local-dashboard-digits" id="local-dashboard-digits-ab12cd34ef56">
    <div class="local-dashboard-digits-value" id="local-dashboard-digits-ab12cd34ef56-value">42%</div>
    <div class="local-dashboard-digits-label" id="local-dashboard-digits-ab12cd34ef56-label">Completion</div>
</div>
```

- Wrapper class `.local-dashboard-digits`, value class `.local-dashboard-digits-value`
  (percentages also get `.local-dashboard-digits-value--percent`), label class
  `.local-dashboard-digits-label`.
- The id is a `local-dashboard-digits-` prefix plus a short hash. **Two fields with an
  identical configuration on one page share the same id by design** — give them a
  different `label`/config, or rely on the class, if you need to distinguish them.

### Notes
- On invalid input (missing/unknown `source`, unknown `display`) the shortcode returns
  a short error message instead of breaking the page.
- If the source returns no rows it raises an error server-side; the field shows the
  standard notification rather than a value.

---

## 5. `[chartfilter]`

Renders a page-level control. Its value is shared via the URL (`?ldf_<key>=…`) and a
per-user cache, and fans out to every chart on the page that `consumes` the key.

### Flags

| Flag | Values | Default | Description |
|------|--------|---------|-------------|
| `key` | alphanumeric, or a comma-separated list | — (required) | The logical filter key(s). Charts reference them via `consumes=`, and the source maps them to its own filtering. With several keys one control publishes its value under every key — see *One control, several keys* below. |
| `type` | `select`, `groupedselect`, `date`, `daterange`, `text`, `number`, `map` | `text` | The control type. |
| `pageid` | alphanumeric | `default` | Must match the charts' `pageid`. |
| `label` | text | the key | Visible label. |
| `default` | text | — | Initial value. For `daterange`: `"YYYY-MM-DD\|YYYY-MM-DD"`, either side may be empty. |
| `options` | `value:Label,value:Label` | — | **`select` only.** Static dropdown options. |
| `optionsfield` | field name | — | **`select`/`groupedselect`.** Populate options dynamically from this source field instead of a static list. |
| `source` | source name | `reportbuilder` | **`select`/`groupedselect`.** Which data source supplies dynamic options. |
| `groupfield` | field name | — | **`groupedselect` only.** The field whose value groups the options (rendered as optgroups). |
| `groups` | `GroupA=v:Label;v:Label\|\|GroupB=v` | — | **`groupedselect` only.** Static fallback used when `optionsfield`/`groupfield` yield nothing. |
| `dependson` | filter key | — | **`groupedselect` only.** When that key is *locked* for the viewer, options are scoped to the matching group only. |
| `cascadefrom` | filter key | — | **`select`/`groupedselect` with `optionsfield`.** Follow that filter live: whenever it changes, the options are re-fetched scoped by its value and the first one is auto-selected — see *Cascading select* below. |
| `operator` | `eq`, `gte`, `lte` | `eq` | **`number` only.** Comparison used when applying the value. |
| `hidewhenlocked` | `1` | — | Render nothing (instead of a static value) for users whose value for this key is locked. |

### How each type applies

Each filter emits a neutral constraint; the source applies it natively. For the
`reportbuilder` source, the `key` must match one of the report's own active filters
(by unique identifier, or the short name after the `:`), and it is applied as that
report filter:

| Filter type | Constraint | Report Builder mapping |
|-------------|-----------|------------------------|
| `select` | equals | select filter, "is equal to" |
| `groupedselect` | equals | select filter, "is equal to" (same as `select`; grouping is presentational) |
| `text` | contains | text filter, "contains" |
| `number` | eq / gte / lte (per `operator`) | number filter, matching operator |
| `date` | on/after the chosen date | date filter, range from the chosen date |
| `daterange` | between the two chosen dates | date filter, custom range (from 00:00:00 to 23:59:59; either side may be left empty for an open-ended range) |

A filter whose `key` a report does not have is simply ignored by that chart.

### Date range (`daterange`)

Two date inputs (from/to) that publish a single `"from|to"` value under one
`key`. Either side may be left empty for an open-ended range; the to-day is
included in full (up to 23:59:59). Locking a `daterange` key via *Locked
filters* is not supported — locks force a single scalar value.

Besides a literal `"from|to"` range, `default` accepts the relative keyword
`last<N>months` (e.g. `last12months`), which resolves at render time to the
range from N months ago until today, in the viewing user's timezone. It is the
*initial* value like any default — once a viewer picks their own range, their
choice is remembered instead.

```
[chartfilter key=period type=daterange label="Period" pageid=demo]
[chartfilter key=period type=daterange label="Period" default="2026-01-01|2026-06-30" pageid=demo]
[chartfilter key=period type=daterange label="Period" default=last12months pageid=demo]
```

### One control, several keys

`key` accepts a comma-separated list. The control then publishes its value under
**every** listed key — one date-range picker can drive charts filtering on user
creation *and* charts filtering on course completion:

```
[chartfilter key=usercreated,coursecompleted type=daterange label="Period" pageid=demo]
[chart ... consumes=usercreated ...]
[chart ... consumes=coursecompleted ...]
```

Semantics:

- The same value is submitted once per key; each report applies the keys its own
  filters support and ignores the rest (as always). A report that has **both**
  date filters gets both applied (AND) — use `consumes=` on the chart to limit it
  to one of them.
- The URL carries one `ldf_<key>` param per key; a deep link with only one of
  the keys set seeds the control and republishes under all keys.
- This works for every filter type, not just `daterange`.
- If **any** of the keys is locked for the viewer, the whole control renders as
  locked (first locked key's value) — do not combine multi-key with locked keys
  for `daterange`, which cannot be locked anyway.

### Grouped select (`groupedselect`)

A dropdown whose options are grouped under a second field, rendered as HTML
optgroups. It emits a single value for its own `key` — exactly like `select` —
so charts, URL state and the constraint pipeline treat it identically; the
grouping is purely presentational.

Options are read from the source: `optionsfield` gives the option values,
`groupfield` gives the group each option falls under (both are distinct,
formatted values of those fields in the data). A static `groups=…` string is the
fallback when no source options are found.

With `dependson=<key>`, if that key is **locked** for the viewer (see *Locked
filters* below), the options are scoped to the single matching group — so a
regional manager frozen to their region sees only that region's options
(rendered flat, without an optgroup header), while an unscoped admin sees every
group. This scoping is server-side and locked-only; for a live client-side
cascade add `cascadefrom=<key>` (see *Cascading select* below).

```
# ASL options grouped by REGION. Managers locked to a region see only theirs.
[chartfilter key=region type=select        label="Region" optionsfield=region source=reportbuilder report=42 pageid=ops]
[chartfilter key=asl    type=groupedselect label="ASL"    optionsfield=asl groupfield=region dependson=region source=reportbuilder report=42 pageid=ops]
```

### Cascading select (`cascadefrom`)

A `select` or `groupedselect` with dynamic options (`optionsfield`) can **follow
another filter live**. With `cascadefrom=<key>`, every time that filter's value
changes on the page:

1. the options are re-fetched from the control's own source/report, scoped by
   the other filter's value (i.e. the distinct values of `optionsfield` among the
   rows matching it — the value goes through the report's own filter, exactly as
   for charts);
2. the option list is rebuilt, and the control is set to its **first option**
   (its current value is kept when it is still available);
3. the new value is published like a user change, so charts consuming the key
   reload.

Clearing the other filter restores the full option list and clears the control.
A page opened with the other filter already set (URL or remembered state) scopes
the control on load in the same way.

```
# Choosing a course sets the edition dropdown to that course's first edition.
[chartfilter key=course  type=select label="Course"  optionsfield=course  report=42 pageid=ops]
[chartfilter key=edition type=select label="Edition" optionsfield=edition report=42 cascadefrom=course pageid=ops]
[chart ... consumes=course,edition ...]
```

Notes:

- The control's report must have an **active filter** for the `cascadefrom` key;
  otherwise the value cannot be applied and the options stay unscoped (the same
  rule charts follow).
- When the `cascadefrom` key is **locked** for the viewer, nothing happens on the
  client: the locked value is applied server-side and the control is rendered
  already scoped. `dependson` (locked-only, `groupedselect`) and `cascadefrom`
  can therefore be combined on one control.
- Static `options=`/`groups=` controls cannot cascade (there is nothing to
  re-fetch); the flag is ignored for them.
- Locked filters are applied to the options lookup too, so a viewer never sees
  options outside their forced scope.

### Locked filters (per-user forced values)

The **Locked filters** admin setting (`lockedfilters`) maps filter keys to user
profile fields, one per line:

```
region=region
```

A mapping may be **scoped to roles** with a `|role1,role2` suffix — then only
users assigned one of those roles (in the system context) get that key locked,
so different roles can have different filters frozen on the same page:

```
region=region|regionalmanager
asl=asl|aslmanager
```

Here a regional manager has `region` frozen but can still choose `asl` (scoped
to their region via a `dependson=region` grouped select), an ASL manager has
`asl` frozen, and an admin — holding `ignorelockedfilters` — has both free. A
line with no `|roles` suffix applies to everyone. Role names must match existing
role shortnames; an unmatched name locks nobody.

For every affected user (those **without** the
`local/wb_dashboard:ignorelockedfilters` capability, which managers have by
default, and — for role-scoped lines — assigned a listed role), a mapped key is
*locked*:

- Every chart/digits request forces the key to that user's own profile field
  value **server-side** — whatever the browser submits for the key is discarded.
- The `[chartfilter]` control for the key renders as a static value (or nothing
  with `hidewhenlocked=1`); other users keep the normal control.
- A locked user with an **empty** profile field gets an error instead of data
  (fail closed), as does a value that is not a valid option of a report's
  select filter.

The profile field value must exactly match the report filter's option values
(case and spelling). Reports used on restricted pages must include the locked
key among their active filters — a report without it returns *unfiltered* data.

```
[chartfilter key=status  type=select label="Status" options="1:Open,2:Closed" pageid=demo]
[chartfilter key=period  type=date   label="From"    pageid=demo]
[chartfilter key=minhits type=number label="Min hits" operator=gte default=10 pageid=demo]
```

---

## 6. `[downloadreport]`

Renders a **download button** for a Report Builder *custom report* that exports
the report **with the current page filters applied** — the same values the
charts on the page are showing, including server-forced locked filters.

```
[downloadreport report=42 format=csv label="Download course data" consumes=region,period pageid=ops]
```

| Flag | Values | Default | Meaning |
|---|---|---|---|
| `report` | report id | — (required) | The custom report to download. The button is hidden for users without permission to view the report. |
| `format` | an enabled dataformat (`csv`, `excel`, `ods`, ...) | `excel` | Export format. |
| `label` | text | *Download report* | Button label. |
| `consumes` | comma-separated filter keys | *(all)* | Which page filter keys the download applies. Omit to apply every filter the report can map. |
| `pageid` | alphanumeric | `default` | Page identifier (same as the page's filters and charts). |

On click, the current filter values are appended to the link and translated into
the report's **native filters** exactly like the chart pipeline does it: keys
map through the same aliases (full identifier, short name, custom/profile field
shortname), locked filters are enforced server-side (fail closed), and keys the
report has no filter for are ignored. The values are applied only for the
export — the user's own filter state in the report builder UI is untouched.

The report must include the page filters' keys among its **active filters**,
just like for charts — a report without a matching filter downloads unfiltered
for that key.

---

## 7. `[toplist]`

Renders a **ranked top-N list** — rank number, label, progress bar and value per
row — from a Report Builder report. Rows re-rank live when a consumed page
filter changes. The ranking metric and the bar metric may differ (e.g. rank by
completions, bar = completion percentage).

```
# Top 5 courses by completions; the bar shows the completion percentage.
# (One "Course participants" report: course name, Completed with Sum
#  aggregation = ranking value, Completed with Percent aggregation = bar.)
[toplist source=reportbuilder report=12 categoryfield=coursefullname valuefield=completedsum barfield=completedpercent top=5 consumes=region,period pageid=ops title="Top courses"]

# Top 5 newsletters by delivered; bar = delivered / sent.
[toplist source=reportbuilder report=13 categoryfield=communicationname valuefield=delivered bartotalfield=sent top=5 pageid=ops title="Top newsletters"]

# Top 5 feedbacks by average score; bar = score / 5.
[toplist source=reportbuilder report=15 categoryfield=course valuefield=score max=5 decimals=1 suffix="/5" top=5 pageid=ops title="Top feedback"]
```

| Flag | Values | Default | Meaning |
|---|---|---|---|
| `source` | source name | — (required) | Data source (`reportbuilder`). |
| `report`, `categoryfield`, `valuefield`, `aggregation`, ... | | | Source params, exactly as for `[chart]` rows shaping: `categoryfield` is the row label, `valuefield` the ranking value (`aggregation=count` tallies rows instead). |
| `top` | 1-20 | `5` | Number of rows. |
| `order` | `desc` / `asc` | `desc` | `desc` ranks highest first (top N), `asc` lowest first (bottom N). Ties keep report order. |
| `barfield` | field name | *(none)* | A field that already holds the bar percentage (0-100), e.g. a Percent-aggregated boolean column. |
| `bartotalfield` | field name | *(none)* | A field to divide the value by: bar = value ÷ total. Use when the report cannot produce the percentage itself (Percent aggregation is boolean-only). |
| `max` | number | *(none)* | Fixed bar maximum: bar = value ÷ max (e.g. `max=5` for scores). |
| `decimals` | 0-6 | `0` | Decimal places of the displayed value. |
| `suffix` | text | *(none)* | Appended to the value verbatim (e.g. `suffix="/5"` → "4.3/5"). |
| `bars` | `1` / `0` | `1` | `bars=0` renders the list **without** the per-row progress bar (rank, label and value only). |
| `title` | text | *(none)* | Optional heading above the list. |
| `consumes` | comma-separated filter keys, or `none` | *(all)* | Which page filter keys the list reacts to (`none` = isolated from all page filters, see §8). |
| `pageid` | alphanumeric | `default` | Page identifier (same as the page's filters). |
| `fixedfilters` | `key:value[;key:value]` | *(none)* | Filter values pinned on this list (see §8). |
| `idfield` | field name | *(none)* | Report column holding each row's **raw id** (e.g. the course id). Required for `details`. |
| `details` | template name | *(none)* | Adds a **"See details"** button per row that opens a drill-down modal (see §9). |

**Bar resolution order:** `barfield` → `bartotalfield` → `max` → *relative*
(no flag: the highest-ranked value gets a full bar, the rest scale to it).
A zero divisor gives an empty bar; fills are clamped to 0-100%.
With `bars=0` no bar is rendered at all and the bar flags are irrelevant.

The row slots are server-rendered and constant; the JS only fills labels,
values and bar widths. The wrapper id is deterministic
(`local-dashboard-toplist-<hash>`) for CSS targeting, like digits.

---

## 8. Fixed filters and filter isolation (`fixedfilters`, `consumes=none`)

Both flags work on **every display shortcode** — `[chart]`, `[digits]` and
`[toplist]`.

**`fixedfilters`** pins literal filter values on one shortcode instance,
independent of the page's `[chartfilter]` controls:

```
# This chart always shows course 5, whatever the page filters say.
[chart type=bar source=reportbuilder report=12 categoryfield=month valuefield=views fixedfilters="courseid:5"]

# Values are arbitrary strings, not just ids; several pairs separate with ";".
[digits source=reportbuilder display=count report=7 fixedfilters="coursename:Mathematics 101;region:west"]
```

- Pairs are separated by `;`; key and value split at the **first** `:` (values
  may contain further colons, but never `;` or `"`).
- Each pair is applied through the report's **own** filter for that key, as an
  equality match — exactly like a page filter value. Keys the report has no
  filter for are silently ignored.
- A fixed key **beats the page filter** of the same key; server-side
  [locked filters](#locked-filters-per-user-forced-values) still beat both.
- Two otherwise identical shortcodes that differ in `fixedfilters` get
  different DOM/chart ids (they are different charts).

**`consumes=none`** isolates an instance from *all* page filters. This is not
the same as omitting `consumes` — an empty/omitted `consumes` means "react to
**every** key". Use it together with `fixedfilters` when the instance must
show exactly one pinned slice (e.g. inside a detail modal, §9).

---

## 9. Detail modals (per-row drill-down)

A `[toplist]` can open a **modal with further reports about the clicked row**
— e.g. a course list where "See details" opens per-course charts.

Three parts:

**1. The admin setting** *Site administration → Plugins → Local plugins →
Wunderbyte Dashboard Charts → Detail templates* holds any number of **named
templates**, each started by a `=== name ===` marker line. A template body is
arbitrary HTML (divs, Bootstrap grid, inline styles) containing dashboard
shortcodes. The placeholders `{{id}}` and `{{label}}` are replaced with the
clicked row's raw id and label:

```
=== coursedetail ===
<h3>{{label}}</h3>
<div class="row">
    <div class="col-md-6">[digits source=reportbuilder display=count report=12 consumes=none fixedfilters="courseid:{{id}}" label="Enrolments"]</div>
    <div class="col-md-6">[toplist source=reportbuilder report=14 categoryfield=username valuefield=score top=3 consumes=none fixedfilters="courseid:{{id}}" title="Top users"]</div>
</div>

=== userdetail ===
<h3>{{label}}</h3>
[chart type=bar source=reportbuilder report=20 categoryfield=month valuefield=logins consumes=none fixedfilters="userid:{{id}}"]
```

**2. The toplist opts in** with `details=<name>` plus `idfield=<column>` — the
report column holding each row's raw id (add it as a column to the report,
e.g. the course id):

```
[toplist source=reportbuilder report=12 categoryfield=coursefullname valuefield=completedsum top=10 idfield=courseid details=coursedetail title="Top courses"]
```

Different lists can use different template names (`details=userdetail`, …) or
none — without `details` nothing changes. With `details` but a missing/empty
`idfield` the buttons stay hidden.

**3. On click** the modal body is rendered server-side (fragment API): the
placeholders are substituted, the shortcodes filter expands the template, and
every inner widget loads its data pinned to the clicked row via its
`fixedfilters`.

Worth knowing:

- Give inner shortcodes `consumes=none` so the modal content ignores the
  page's filter state (and `fixedfilters="…:{{id}}"` so it shows the clicked
  entity).
- Every inner shortcode still enforces its own report-view permission when
  its data loads — a user without access to a drill-down report sees its
  error/empty state, not its data.
- Substituted values are sanitized (`"`, `[`, `]`, `;` and control characters
  are stripped) so row data can never alter the template's shortcode syntax.
- `[chartfilter]` controls are **not supported** inside detail templates
  (they register on the page's filter bus).
- The Shortcodes text filter must be enabled for content in the context, as
  for any dashboard page.

---

## 10. A full page example

```
[chartfilter key=period type=date label="From" pageid=team]

[digits source=reportbuilder display=count reports=7 label="Members" consumes=period pageid=team]
[digits source=reportbuilder display=percent idbase=3 fieldbase=minuteslogged idtotal=5 fieldtotal=minutestarget label="Completion" decimals=1 consumes=period pageid=team]

[chart type=doughnut  source=reportbuilder idbase=3 fieldbase=minuteslogged idtotal=5 fieldtotal=minutestarget consumes=period pageid=team]
[chart type=progress  source=reportbuilder idbase=3 fieldbase=minuteslogged idtotal=5 fieldtotal=minutestarget consumes=period pageid=team height=8]
[chart type=stackedbar source=reportbuilder report=7 categoryfield=month valuefield=total stackfield=status consumes=period pageid=team]
```

Changing **From** updates the URL, is remembered per user, and re-queries every field
and chart above — each applying `period` through its own report's date filter.

---

## 11. Per-chart colours (settings gear)

Charts follow the **active palette** by default. To recolour an individual chart,
users with the `local/wb_dashboard:configurecharts` capability (managers by default)
see a small **gear** button on each chart. It opens a modal with one **dropdown per
palette slot**, each listing the whole active palette, so a slot can be repointed at
any other palette colour; slots left on their default keep following the palette.
Saving re-draws that chart immediately — no page reload.

Overrides are:

- **Stored server-side**, in `local_wb_dashboard_chartcfg`, and therefore **shared by
  all viewers** (this is authoring config, not a per-user preference).
- **Sparse** — only the slots you actually override are stored; everything else tracks
  the live palette, so changing the palette still updates the untouched slots.
- **Merged over the palette at query time** (`chart_settings::resolve()`), then applied
  by the builder exactly where `color1`, `color2`, … used to apply.

### Chart identity

Each override is keyed to a **stable chart id** derived automatically from the chart's
context and its identity-defining configuration (`source`, `type` and source params).
Consequences worth knowing:

- Cosmetic edits (changing `title`, `width`, `height`) **keep** a chart's saved colours.
- Changing a **data** parameter (e.g. `report=3` → `report=4`) is a different chart, so
  it starts again from the palette.
- The id is namespaced by **context**, so the same shortcode on two different pages is
  configured independently. If a page/block is **duplicated or restored** to a new
  context, its saved colours do not follow and the chart reverts to the palette until
  re-set.
- Two identical charts in the **same** content field are disambiguated by render order.
