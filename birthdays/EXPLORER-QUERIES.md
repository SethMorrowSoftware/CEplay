# Finding the employee list and birthdays with the Database Explorer

Copy/paste queries for **`#/explorer` → "Run a query"** (the free-form SELECT
box).

`php birthdays/discover.php` runs all of this for you and prints the finished
roster query — use these if you would rather see it yourself in the UI.

**Before you start**

- The Explorer needs the `data_explorer` permission (admin-only by default).
- The box accepts **one** statement, must start with `SELECT` or `WITH`, no
  semicolons, and rejects `INTO` along with every write keyword. Every query
  below is a plain read.
- Results are capped at **500 rows**.
- Every run is audit-logged to the action log — that's expected.
- These only work on the venue server. There is no MSSQL driver anywhere else.

---

## What this venue's database actually says

Confirmed August 2026 by running steps 1–2 below:

| | |
|---|---|
| Staff roster table | **`dbo.Employees`** |
| Birthday column | **`DateOfBirth`** (`datetime`) |
| Status lookup table | **`dbo.EmployeeStatus`** (a real table — the codes are *in* the database) |
| Does **not** exist | `TimeClock_Employees` — the time-clock module is punches and scheduling only |

Steps 1 and 2 are recorded for when you want to re-derive this (a POS upgrade,
another venue). If you just want the bot working, **start at step 3**.

---

## 1. Where is the birthday column?

```sql
SELECT c.TABLE_SCHEMA AS sch, c.TABLE_NAME AS tbl, c.COLUMN_NAME AS col, c.DATA_TYPE AS typ
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.COLUMN_NAME LIKE '%birth%' OR c.COLUMN_NAME LIKE '%bday%' OR c.COLUMN_NAME LIKE '%dob%'
ORDER BY c.TABLE_NAME, c.COLUMN_NAME
```

On this database that returns 16 rows and **exactly one of them is staff**:
`Employees.DateOfBirth`. Everything else is the guest side of the house —
`Customers`, `CustomersWithName`, `ChildCustomers`, `Customer_Waivers`,
`Customers_WaiverQueue`, `WaiverCommonView`, `GroupChildren`, `TicketDetails`.
The `BirthdayEvent` bits on `GroupArrivals`, `DepositsReceived`,
`DepositsRedeemed` and `EventsWithARInfo` are party bookings, not people.

## 2. Which tables look like the staff roster?

```sql
SELECT t.TABLE_NAME AS tbl, t.TABLE_TYPE AS kind
FROM INFORMATION_SCHEMA.TABLES t
WHERE t.TABLE_NAME LIKE '%employ%' OR t.TABLE_NAME LIKE 'Emp%'
   OR t.TABLE_NAME LIKE '%staff%'  OR t.TABLE_NAME LIKE '%personnel%'
   OR t.TABLE_NAME LIKE 'TimeClock%'
ORDER BY t.TABLE_NAME
```

Around 45 rows. The roster is the one that holds **one row per person**:
`Employees`. The rest are satellites (`EmpCards`, `EmpJobCodes`, `EmpPositions`,
`Employee_Notes`, `Employee_PayRateChanges`, `Employees_Modifications`), the
time-clock module (`TimeClock_Weekly` is one row per *punch*), or scheduling.

Two worth noticing: **`EmployeeStatus`** is a base table, which means the
employment codes are stored with labels rather than left for you to guess; and
`EmployeesScheduledOrWorked` is a VIEW that may be an easier definition of
"currently active" than any status flag — see step 6.

## 3. What columns do `Employees` and `EmployeeStatus` have?

```sql
SELECT TABLE_NAME AS tbl, ORDINAL_POSITION AS pos, COLUMN_NAME AS col,
       DATA_TYPE AS typ, CHARACTER_MAXIMUM_LENGTH AS len, IS_NULLABLE AS nullable
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME IN ('Employees', 'EmployeeStatus')
ORDER BY TABLE_NAME, ORDINAL_POSITION
```

Metadata only — no personal data leaves the database. Look for: the employee
number, first/last name, `DateOfBirth`, and whatever links to `EmployeeStatus`
(a `StatusNo`/`Stus`/`EmpStatus` column), plus any hire/termination dates.

> Deliberately **not** `SELECT TOP 5 * FROM Employees`. That would show every
> column at once, but an HR table can carry SSNs, addresses and pay rates, and
> the Explorer renders whatever it's given. Read the column list first, then
> select only the columns you need.

## 4. What do the status codes mean?

This is the query that settles "still employed", and it's the reason a separate
lookup table is good news — the labels are written down:

```sql
SELECT TOP 50 * FROM CenterEdge.dbo.EmployeeStatus
```

A lookup table holds codes and their names, no personal data. You should get a
handful of rows along the lines of Active / Terminated / Leave of Absence.

## 5. How many people sit on each status?

Substitute the real status column name from step 3 for `StatusNo`:

```sql
SELECT StatusNo AS value, COUNT(*) AS people,
       MIN(LastName) AS example_a, MAX(LastName) AS example_b
FROM CenterEdge.dbo.Employees
GROUP BY StatusNo
ORDER BY COUNT(*) DESC
```

Check an example name against somebody you know is on this week's schedule
before trusting the mapping. A twenty-year-old database normally has far more
leavers than current staff, so **the biggest bucket is not automatically the
active one** — cross-check the count against the label from step 4.

If there's a termination-date column as well as (or instead of) a status code:

```sql
SELECT COUNT(*) AS rows_total,
       SUM(CASE WHEN TermDate IS NULL THEN 1 ELSE 0 END) AS still_here,
       SUM(CASE WHEN TermDate IS NOT NULL THEN 1 ELSE 0 END) AS gone
FROM CenterEdge.dbo.Employees
```

## 6. Sanity-check "active" against who actually works here

A status flag is only as good as the last person to maintain it. This compares
it with people who have actually clocked in recently:

```sql
SELECT COUNT(DISTINCT EmpNo) AS clocked_in_last_90_days
FROM CenterEdge.dbo.TimeClock_Weekly
WHERE ClockInDate >= DATEADD(DAY, -90, GETDATE())
```

If your "active" count from step 5 is wildly higher than this, the status field
is stale and the bot will greet people who left. That's what the
`EmployeesScheduledOrWorked` view may be for — worth a look at its columns in
step 3 if the numbers disagree.

## 7. Are the birthdays real, or mostly placeholders?

Old systems stamp a default date when the field was never filled in. Skip this
check and the bot wishes half the staff a happy birthday on 1 January.

```sql
SELECT TOP 12 CONVERT(VARCHAR(10), DateOfBirth, 120) AS birth_date, COUNT(*) AS people
FROM CenterEdge.dbo.Employees
WHERE DateOfBirth IS NOT NULL
GROUP BY CONVERT(VARCHAR(10), DateOfBirth, 120)
ORDER BY COUNT(*) DESC
```

Any date with a double-digit count is a placeholder. Add it to
`ignore_birth_dates` in `birthdays/config.php`. (`1900-01-01`, `1899-12-30`,
`1970-01-01` and anything before 1901 are ignored automatically.)

Then check the spread — real birthdays are roughly even across the year:

```sql
SELECT MONTH(DateOfBirth) AS birth_month, COUNT(*) AS people
FROM CenterEdge.dbo.Employees
WHERE DateOfBirth IS NOT NULL AND YEAR(DateOfBirth) >= 1901
GROUP BY MONTH(DateOfBirth)
ORDER BY MONTH(DateOfBirth)
```

A spike in one month means you're looking at a hire date, not a birthday.

## 8. How much coverage do you actually have?

```sql
SELECT COUNT(*) AS active_staff,
       SUM(CASE WHEN DateOfBirth IS NOT NULL AND YEAR(DateOfBirth) >= 1901 THEN 1 ELSE 0 END) AS with_birthday
FROM CenterEdge.dbo.Employees
WHERE StatusNo = 1
```

If only a handful of current staff have a birthday on file, the bot works but
stays quiet most of the year — worth knowing before you announce it.

## 9. The roster query

This is what goes into `roster_sql` in `birthdays/config.php`. The bot does the
date matching itself, so it returns **everyone currently employed**, not just
today's birthdays:

```sql
SELECT EmpNo AS emp_no,
       FirstName AS first_name,
       LastName AS last_name,
       CONVERT(VARCHAR(10), DateOfBirth, 120) AS birth_date
FROM CenterEdge.dbo.Employees
WHERE StatusNo = 1
  AND DateOfBirth IS NOT NULL
  AND YEAR(DateOfBirth) >= 1901
```

Column names other than `DateOfBirth` still need confirming against step 3, and
`StatusNo = 1` against steps 4–5.

To spot-check it, add today's date filter — this is exactly who the bot would
greet if it ran right now:

```sql
SELECT EmpNo AS emp_no, FirstName AS first_name, LastName AS last_name,
       CONVERT(VARCHAR(10), DateOfBirth, 120) AS birth_date
FROM CenterEdge.dbo.Employees
WHERE StatusNo = 1
  AND DateOfBirth IS NOT NULL AND YEAR(DateOfBirth) >= 1901
  AND MONTH(DateOfBirth) = MONTH(GETDATE())
  AND DAY(DateOfBirth) = DAY(GETDATE())
```

`CONVERT(VARCHAR(10), …, 120)` is there on purpose: it hands the bot a clean
`YYYY-MM-DD` instead of whatever textual datetime format the FreeTDS driver
feels like producing.

---

## Table names that are *not* the staff roster

| Table | What it really is |
|---|---|
| `Customers`, `CustomersWithName`, `ChildCustomers`, `CustPasses`, `CustVisits` | Guests / members |
| `Customer_Waivers`, `Customers_WaiverQueue`, `WaiverCommonView` | Signed waivers |
| `GroupChildren`, `GroupBirthdays`, `GroupArrivals`, `GroupSales` | Birthday **parties** — bookings, not staff |
| `TicketDetails` | Admission tickets |
| `TimeClock_Weekly` | One row per punch, not per person (it carries `PayRate` and `JobCode`) |
| `EmpCards`, `EmpJobCodes`, `EmpPositions`, `Employee_Notes` | Satellites hanging off `Employees` |
| `TimeClock_Employees` | **Does not exist.** Don't go looking for it |
