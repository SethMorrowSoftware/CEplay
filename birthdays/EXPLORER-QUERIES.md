# Finding the employee list and birthdays with the Database Explorer

Copy/paste queries for **`#/explorer` → "Run a query"** (the free-form SELECT
box). Work down them in order; each one narrows the answer.

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

## 1. Where is the birthday column?

The fastest route in: ask the catalog which columns anywhere in the database
look like a date of birth.

```sql
SELECT c.TABLE_SCHEMA AS sch, c.TABLE_NAME AS tbl, c.COLUMN_NAME AS col, c.DATA_TYPE AS typ
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.COLUMN_NAME LIKE '%birth%' OR c.COLUMN_NAME LIKE '%bday%' OR c.COLUMN_NAME LIKE '%dob%'
ORDER BY c.TABLE_NAME, c.COLUMN_NAME
```

⚠️ **Several hits will be guests, not staff.** `Customers`, `GroupBirthdays`,
anything with *party*, *player* or *member* in the name is the guest side of
the house — a birthday there is a kid's party booking. You want the table that
also carries an employee number, a pay rate or a hire date.

## 2. Which tables look like the staff roster?

```sql
SELECT t.TABLE_NAME AS tbl, t.TABLE_TYPE AS kind
FROM INFORMATION_SCHEMA.TABLES t
WHERE t.TABLE_NAME LIKE '%employ%' OR t.TABLE_NAME LIKE 'Emp%'
   OR t.TABLE_NAME LIKE '%staff%'  OR t.TABLE_NAME LIKE '%personnel%'
   OR t.TABLE_NAME LIKE 'TimeClock%'
ORDER BY t.TABLE_NAME
```

We already know `TimeClock_Weekly`, `TimeClock_JobCodes` and
`TimeClock_WorkHours` exist — the Go-Kart Labor report reads all three — and
that `PlayerCardTrans` carries an `EmpNo`. The employee *master* is whichever
of these holds one row per person rather than one row per punch.

## 3. What columns does that table have?

Swap in the table name from step 2. (The Explorer's **table browser** shows the
same thing with sample rows, if you prefer clicking.)

```sql
SELECT ORDINAL_POSITION AS pos, COLUMN_NAME AS col, DATA_TYPE AS typ,
       CHARACTER_MAXIMUM_LENGTH AS len, IS_NULLABLE AS nullable
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'TimeClock_Employees'
ORDER BY ORDINAL_POSITION
```

Look for: an employee number, first/last name, the birthday column from step 1,
and a status column (`Stus`, `Status`, `Active`, `Retired`) or a termination
date.

## 4. What does the status column actually contain?

**This is the important one.** A `Stus` column is meaningless until you know
which value means "still works here". Count the people on each value and print
two example names per value:

```sql
SELECT TOP 20 Stus AS value, COUNT(*) AS people,
       MIN(LastName) AS example_a, MAX(LastName) AS example_b
FROM CenterEdge.dbo.TimeClock_Employees
GROUP BY Stus
ORDER BY COUNT(*) DESC
```

The value with the most people is *usually* current staff, but check an example
name against somebody you know is on the schedule this week before trusting it.
A decades-old database normally has far more leavers than current staff, so
don't assume the biggest bucket is the active one.

If there's a termination-date column instead of (or as well as) a status flag:

```sql
SELECT COUNT(*) AS rows_total,
       SUM(CASE WHEN TermDate IS NULL THEN 1 ELSE 0 END) AS still_here,
       SUM(CASE WHEN TermDate IS NOT NULL THEN 1 ELSE 0 END) AS gone
FROM CenterEdge.dbo.TimeClock_Employees
```

## 5. Are the birthdays real, or mostly placeholders?

Old systems stamp a default date when the field was never filled in. If you
skip this check, the bot wishes half the staff a happy birthday on 1 January.

```sql
SELECT TOP 12 CONVERT(VARCHAR(10), BirthDate, 120) AS birth_date, COUNT(*) AS people
FROM CenterEdge.dbo.TimeClock_Employees
WHERE BirthDate IS NOT NULL
GROUP BY CONVERT(VARCHAR(10), BirthDate, 120)
ORDER BY COUNT(*) DESC
```

Any date with a double-digit count is a placeholder, not a birthday. Add it to
`ignore_birth_dates` in `birthdays/config.php`. (`1900-01-01`, `1899-12-30`,
`1970-01-01` and anything before 1901 are ignored automatically.)

Then sanity-check the spread — real birthdays are roughly even across the year:

```sql
SELECT MONTH(BirthDate) AS birth_month, COUNT(*) AS people
FROM CenterEdge.dbo.TimeClock_Employees
WHERE BirthDate IS NOT NULL AND YEAR(BirthDate) >= 1901
GROUP BY MONTH(BirthDate)
ORDER BY MONTH(BirthDate)
```

A spike in one month means you're looking at a hire date, not a birthday.

## 6. How much coverage do you actually have?

```sql
SELECT COUNT(*) AS active_staff,
       SUM(CASE WHEN BirthDate IS NOT NULL AND YEAR(BirthDate) >= 1901 THEN 1 ELSE 0 END) AS with_birthday
FROM CenterEdge.dbo.TimeClock_Employees
WHERE Stus = 1
```

If only a handful of current staff have a birthday on file, the bot will work
but stay quiet most of the year — worth knowing before you announce it.

## 7. The roster query

This is what goes into `roster_sql` in `birthdays/config.php`. The bot does the
date matching itself, so this returns **everyone currently employed**, not just
today's birthdays:

```sql
SELECT EmpNo AS emp_no,
       FirstName AS first_name,
       LastName AS last_name,
       CONVERT(VARCHAR(10), BirthDate, 120) AS birth_date
FROM CenterEdge.dbo.TimeClock_Employees
WHERE Stus = 1
  AND BirthDate IS NOT NULL
  AND YEAR(BirthDate) >= 1901
```

To spot-check it in the Explorer, add today's date filter — this is exactly who
the bot would greet if it ran right now:

```sql
SELECT EmpNo AS emp_no, FirstName AS first_name, LastName AS last_name,
       CONVERT(VARCHAR(10), BirthDate, 120) AS birth_date
FROM CenterEdge.dbo.TimeClock_Employees
WHERE Stus = 1
  AND BirthDate IS NOT NULL AND YEAR(BirthDate) >= 1901
  AND MONTH(BirthDate) = MONTH(GETDATE())
  AND DAY(BirthDate) = DAY(GETDATE())
```

`CONVERT(VARCHAR(10), …, 120)` is there on purpose: it hands the bot a clean
`YYYY-MM-DD` instead of whatever textual datetime format the FreeTDS driver
feels like producing.

---

## If the table isn't called what you expect

Point the CLI probe at it directly and it will work out the rest:

```bash
php birthdays/discover.php --table=WhateverItIsCalled
```

## Table names in this database that are *not* the staff roster

| Table | What it really is |
|---|---|
| `Customers`, `CustPasses`, `CustVisits` | Guests / members |
| `GroupBirthdays`, `GroupSales`, `GroupArrivals` | Birthday **parties** — bookings, not staff |
| `TimeClock_Weekly` | One row per punch, not per person (it does carry `PayRate` and `JobCode` though) |
| `CashierSales`, `PlayerCardTrans.EmpNo` | Transactions stamped with an employee number |
