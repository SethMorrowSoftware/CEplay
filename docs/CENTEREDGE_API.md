# CenterEdge / Kiosoft Card System API Reference

Date generated: 2026-05-02
Source spec: `docs/api-reference/centeredge-cardsystemapi.yaml` (OpenAPI 3.0.2,
interface version **1.8.0**, contact `bburnett@centeredgesoftware.com`).

This is the **upstream** integration API exposed by a card-system vendor
(Kiosoft, Embed, SACOA, etc.) that CenterEdge Advantage — and our local
NewCEPlay backend via `lib/centeredge_client.php` — calls into. It is the
counterpart to the internal `/api/*` routes documented in code comments
of `api/*.php`.

This document is intended for two audiences:

1. **Developers / integrators** wiring NewCEPlay into a card system: every
   endpoint, request shape, response schema, and error code is summarised
   here so you don't have to scroll through 2,500 lines of YAML.
2. **Operators / product owners** evaluating what the upstream system
   *can* do beyond what we currently use. Each section ends with a
   "How we use it" / "How we could use it" note linking endpoints to the
   PHP client method that calls them and flagging endpoints we haven't
   wired up yet.

> **Naming**: Kiosoft / Embed / SACOA are vendor-specific implementations
> of this spec. CenterEdge calls it "the Card System Integration API";
> when this document says "CenterEdge API" or "the upstream" it means the
> instance of this spec hosted by whichever vendor is configured in
> Settings.

---

## Conventions

- **Base URL** comes from the `base_url` config row (e.g.
  `http://example.com/api/v1`). Set in Settings → CenterEdge.
- **Authentication**: `Authorization: Bearer <token>` for everything
  except `/login`. Token is acquired by `POST /login` and may expire — a
  401 means "fetch a new token and retry". `lib/centeredge_client.php`
  caches the token in `app_config.bearer_token` (encrypted) and
  re-authenticates lazily on 401 or when the token age exceeds
  `TOKEN_MAX_AGE`.
- **Optional API key**: The vendor may require an `X-Api-Key` header.
  Configured per location in CenterEdge back office. NewCEPlay sets this
  header automatically when `app_config.api_key` is non-empty.
- **Pagination**: list endpoints accept `skip` (default 0) and `take`
  (default and max 100). Responses include `skipped` and may include
  `totalCount`. Our client paginates until either fewer than `take`
  records are returned or `skipped + records >= totalCount`, with a
  `MAX_PAGINATION_LOOPS = 1000` safety cap.
- **Errors**: Every error returns `{ "code": "...", "message": "..." }`.
  Codes from the spec: `cardNotFound`, `cardExists`, `pinNotFound`,
  `badRequest`, `invalidLogin`, `unauthorized`, `notAllowed`,
  `cardNotEmpty`. Our client converts non-2xx responses to
  `RuntimeException("CenterEdge API error: $msg (HTTP $code)")`.
- **Retry policy** in `centeredge_client.php`:
  - 401 → reauth and retry once
  - Other 4xx (except 408 and 429) → no retry
  - 5xx, network errors, 408, 429 → exponential backoff (2 s, 4 s, 8 s)
    up to 3 retries
- **Timestamps** are ISO 8601 with timezone designators (e.g.
  `2020-05-01T15:00:00.000-04:00` or `...Z`). The play-feed cache
  preserves these as-is and the analytics module bins them into the
  configured app timezone.

---

## Endpoint Index

| Tag | Path | Method | OperationId | Used by NewCEPlay? |
| --- | --- | --- | --- | --- |
| Login | `/login` | POST | `login` | yes — `authenticate()` |
| Capabilities | `/capabilities` | GET | `getCapabilities` | yes — `getCapabilities()` |
| Capabilities | `/cardNumberFormats` | GET | `getCardNumberFormats` | **no** |
| Cards | `/cards/{cardNumber}` | GET | `getCard` | yes — `getCard()` |
| Cards | `/cards/{cardNumber}` | POST | `createEmptyCard` | **no** |
| Cards | `/cards/{cardNumber}` | DELETE | `wipeCard` | **no** |
| Cards | `/cards/{cardNumber}/combine` | POST | `combineCards` | **no** |
| Cards | `/cards/{cardNumber}/pin` | GET | `validateCardPin` | yes — `getCardPin()` |
| Cards | `/cards/{cardNumber}/transactions` | GET | `getCardTransactions` | yes — `getCardTransactions()` |
| Cards | `/cards/{cardNumber}/transactions` | POST | `createCardTransaction` | **no** |
| Cards | `/cards/{cardNumber}/transactions/{transactionId}` | GET | `getCardTransaction` | **no** |
| Cards | `/cards/bulkIssue` | POST | `bulkIssueCards` | **no** |
| Games | `/games` | GET | `getGames` | yes — `getGames()` |
| Games | `/games` | PATCH | `patchGames` | yes — `patchGames()` |
| Games | `/games/{gameId}` | GET | `getGame` | yes — `getGame()` |
| Games | `/games/{gameId}/performAction` | POST | `performGameAction` | yes — `performGameAction()` |
| Games | `/games/transactions` | GET | `getGameTransactions` | yes — `getGameTransactions()` / `pollGameTransactions()` |
| Games | `/games/categories` | GET | `getGameCategories` | yes — `getCategories()` |
| Privileges | `/privilegeGroups` | GET | `getPrivilegeGroups` | yes — `getPrivilegeGroups()` / `getPrivilegeGroupsCached()` (used by `api/cards.php`) |
| Time Play | `/timePlayGroups` | GET | `getTimePlayGroups` | yes — `getTimePlayGroups()` / `getTimePlayGroupsCached()` (used by `api/cards.php`) |
| System | `/system/transactions` | GET | `getSystemTransactions` | yes — `getSystemTransactions()` / `pollSystemTransactions()` (watchdog, every minute) |
| Kiosks (out-of-spec extension) | `/kiosks` | GET | — | yes — `getKiosks()` / `getAllKiosks()` |
| Kiosks (extension) | `/kiosks/{id}` | GET | — | yes — `getKiosk()` |
| Kiosks (extension) | `/kiosks` | PATCH | — | yes — `patchKiosks()` |
| Kiosks (extension) | `/kiosks/{id}/performAction` | POST | — | yes — `performKioskAction()` |

> **17 paths** are in the OpenAPI 1.8.0 spec; we now use **12** of them
> (`privilegeGroups`, `timePlayGroups`, and `system/transactions` were wired
> up after this doc's first draft). Plus multi-feed play polling —
> `capabilities.games.transactionFeedNames` is read and every advertised feed
> is polled (`getTransactionFeedNames()` / `pollAllGameTransactionFeeds()`).
> The remaining unused paths are all card-administration WRITES (create/wipe/
> combine/bulkIssue/adjust cards) plus `cardNumberFormats` and single-
> transaction lookup — see Coverage Gaps. The kiosk endpoints (4 more) are an
> out-of-spec extension implemented by some vendors and present in our client;
> they do not appear in this version of the YAML.

---

## Login

### `POST /login`

Authenticate and receive an opaque bearer token. The token may be a JWT
but should be treated as opaque by the client. When a subsequent request
returns 401, request a new token and retry.

Request body — `Login` schema:

```json
{
  "username": "CenterEdge",
  "passwordHash": "DA7LKDA5yZcZcbIjfIBh8nIfUi0=",
  "password": "MyPassword",
  "requestTimestamp": "2020-05-26T13:00:05.102-05:00"
}
```

- `passwordHash` = Base64( SHA-1( UTF-8( username + password +
  requestTimestamp ) ) ). The literal string is exactly:
  `CenterEdgeMyPassword2020-05-26T13:00:05.102.9896754-00.00`.
- `password` (plaintext) is **opt-in per system** — only send when the
  vendor's CenterEdge integration specialist enables it.
- `requestTimestamp` should be UTC and within ±5 minutes of the server's
  clock; otherwise the login is declined (replay-attack protection).

Responses:
- 200 → `{ "bearerToken": "..." }`
- 403 → `{ code: "invalidLogin", message: "Incorrect username or password" }`

Used by: `CenterEdgeClient::authenticate()`. The local client formats
`requestTimestamp` as `Y-m-d\TH:i:s.v\Z` (UTC, milliseconds), sends both
`passwordHash` and `password`, and persists the returned token + fetch
timestamp into the encrypted config table.

How we could use it: nothing further — this is a thin auth handshake.
The interesting downstream behaviour (token caching, lazy refresh on
401) is already implemented.

---

## Capabilities

### `GET /capabilities`

Discover what the upstream system supports. CenterEdge — and the SPA via
`/api/capabilities` — reads this once per session to decide which UI
affordances to enable.

Response — `Capabilities` schema. Headline fields:

- `systemName` (required) — machine-readable name, e.g. `SuperCards`
- `interfaceVersion` (required) — semver-ish, e.g. `1.3`
- `pointTypes`: `regularPoints`, `bonusPoints`, `redemptionTickets` —
  each with `isSupported`, `maxDecimalPlaces`, `maximumBalance`
- `adjustments.maximumAdjustmentsPerTransaction` (required) and
  `adjustments.allowedAdjustmentCombinations`
- `timePlay.maximumTimePlaysPerCard`, `timePlay.suppressTickets`,
  `timePlay.minutes.{ isSupported, canAddMinutes, canExpire, startTypes }`,
  `timePlay.dateTimeRange.isSupported`
- `privileges.{ isSupported, canExpire }`
- `bulkIssue.{ range, list, canUnlinkEmptyCards, canRequireNewCards }`
- `cardCombineToExistingCard` (boolean)
- `games.{ operationStatus, categories, getSingleGame, transactionFeedNames }`
- `systemTransactionReporting.{ isSupported, transactionTypes }`
- `wipeCard` (boolean), `virtualPlay` (boolean)

Used by: `CenterEdgeClient::getCapabilities()` → exposed to the SPA via
`GET /api/capabilities` and consumed by `public/js/kiosks.js`.

How we could use it: capabilities currently drives kiosk UI affordances
only. We could extend that pattern across the board so the SPA hides
features the connected vendor doesn't support — for example, hide the
"reboot" game action when `games.supportedActions` is empty, or hide
the time-play / privilege panes if the vendor doesn't support them.

`games.transactionFeedNames` is now consumed: `getTransactionFeedNames()` +
`pollAllGameTransactionFeeds()` read every advertised feed with independent
cursors (the watchdog calls the multi-feed poller). A vendor with a separate
`creditCard` feed is no longer silently dropped — this was previously a
`default`-only risk and is now resolved.

### `GET /cardNumberFormats?skip=N&take=N`

Lists the card-number formats accepted at this facility. Each format
specifies `minLength`, `maxLength`, optional `prefix`, `suffix`, and
`regex` (default `^\d+$`). The spec recommends avoiding 14-character
formats for compatibility.

Response: `{ formats: CardNumberFormat[], skipped, totalCount? }`.

Used by: **not currently called**.

How we could use it: the cards page (`public/js/cards.js`) would benefit
from pre-validating the operator's keyed-in card number against the list
of known formats — today we just send anything 1-32 alphanumeric to the
upstream and let it 404. A configured-formats picker could also drive a
"new card" flow if/when we wire up `POST /cards/{cardNumber}`.

---

## Cards

### `GET /cards/{cardNumber}`

Fetch the current state of a card.

Response — `Card`:

```json
{
  "cardNumber": "12345678",
  "issuedAtTime": "2020-05-01T15:00:00.000-04:00",
  "balance": { "regularPoints": 10, "bonusPoints": 5, "redemptionTickets": 100 },
  "timePlays": [ { "type": "minutes", "groupId": 1, "started": true, "minutesRemaining": 30 } ],
  "privileges": [ { "groupId": 0, "count": 3 } ]
}
```

- 404 `cardNotFound` if the card has never been issued or has been wiped.

Used by: `getCard()` → SPA `/api/cards/{n}`.

### `POST /cards/{cardNumber}`

Create an empty card. Used to link a card to a customer or season pass
without a sale.

Request body: `{ operator: Operator, customer?: CustomerInfo }`. The
`operator` block (employee name/number, station name/number) is
**required**.

Responses: 200 → `Card`, 409 `cardExists`.

Used by: **not currently called**.

How we could use it: a "register a new pass" workflow on the venue
side — useful for season-pass setups where a guest brings a fresh,
un-issued card.

### `DELETE /cards/{cardNumber}`

Wipe a card so it can be reused. Idempotent (returns 204 even if the
card never existed).

Some vendors return 200 with `CardDeleteConfirmation { cardNumber,
wasUnlinked }` instead — meaning the card number was unlinked from a
primary account but the value still lives there. In that case
deferred revenue should **not** be recognised as a sale.

If the card is not wipeable on this system, the upstream returns 405
`notAllowed`.

Used by: **not currently called**.

How we could use it: a manager-only "retire card" action on the cards
page. Per the spec, the operator block is required, so we'd capture
`operator` from the logged-in user (same pattern we use for game/kiosk
RPC actions).

### `POST /cards/{cardNumber}/combine`

Move balance, time plays, and privileges from a source card to the card
in the path. Atomic (both updated or neither).

Body: `{ sourceCardNumber, operator }` (both required).

If the destination already exists, the source's balance is added on top
— but only when capabilities reports `cardCombineToExistingCard: true`.

Used by: **not currently called**.

How we could use it: standard "merge two cards" flow at the redemption
counter. The `cardCombineToExistingCard` capability gates whether we
allow combining onto an existing card or only onto a fresh one.

### `GET /cards/{cardNumber}/pin?validate=<pin>`

Probe or validate a card PIN.

| Situation | Response |
| --- | --- |
| Card invalid | 404 `pinNotFound` |
| Card has no PIN | 404 `pinNotFound` |
| Has PIN, no `validate=` | 200 `{ cardNumber, isPinValid: false }` |
| Has PIN, `validate=` wrong | 200 `{ cardNumber, isPinValid: false }` |
| Has PIN, `validate=` correct | 200 `{ cardNumber, isPinValid: true }` |

Used by: `getCardPin()` → SPA `/api/cards/{n}/pin` for the cards page.

How we could use it: already wired up. Could be extended into a "PIN
required for cash-out" gate at the redemption counter once we add a
counter UI.

### `GET /cards/{cardNumber}/transactions?skip=N&take=N`

Card transaction history since the last wipe, sorted **descending**
(most recent first).

Response: `{ cardNumber, transactions: CardTransaction[], skipped, totalCount? }`.

A `CardTransaction` is one of:

- `AdjustmentTransaction` — `{ id, cardNumber, type: "adjustment",
  transactionTime, operator?, adjustments: Adjustment[] }`
- `GamePlayTransaction` — `{ id, cardNumber, type: "gamePlay",
  transactionTime, gameId, gameDescription, amount: PointsWithCash,
  usedTimePlay?, usedPlayPrivilege?, creditCardDetails? }`

Used by: `getCardTransactions()` → SPA `/api/cards/{n}/transactions`.

### `POST /cards/{cardNumber}/transactions`

Create a transaction (adjustment or virtual game play). For adjustments
the card is auto-created if value is being added; for value removal a
404 is returned if the card doesn't exist.

`gamePlay` transactions are only allowed when `capabilities.virtualPlay`
is true.

Body — `CreateCardTransaction` (oneOf `CreateAdjustmentTransaction` /
`CreateGamePlayTransaction`):

```json
{
  "type": "adjustment",
  "operator": { "employeeName": "...", "employeeNumber": 1, "stationName": "...", "stationNumber": 1 },
  "customer": { "id": "...", "lastName": "..." },
  "adjustments": [
    { "type": "addValue", "amount": { "regularPoints": 100 }, "amountPaid": 20 },
    { "type": "addMinutes", "groupId": 1, "minutes": 60, "startTimePlay": true }
  ]
}
```

Returns 201 with `Location: /cards/.../transactions/{id}` and the new
transactions list (the response may also include the updated `card`).

Used by: **not currently called**.

How we could use it: a "manual adjust card" admin tool — for example
crediting a guest after a game malfunction. We would need to capture
the operator block, validate the requested adjustment combination
against `capabilities.adjustments.allowedAdjustmentCombinations`, and
then post. The spec lets the vendor produce multiple transactions per
request, so the response may contain more than one transaction row.

### `GET /cards/{cardNumber}/transactions/{transactionId}`

Single-transaction lookup. Returns a `CardTransaction`.

Used by: **not currently called**.

How we could use it: deep-link from a play-feed row in the dashboard
("show me the transaction for this play") or from an audit-log entry.

### `POST /cards/bulkIssue`

Atomically issue value onto a list or range of cards. Useful for
prepaid-card programmes, group sales, season passes, etc.

Body — `BulkIssue` (oneOf `BulkIssueByList` / `BulkIssueByRange`):

```json
{
  "type": "list",
  "cardNumbers": ["10000011", "10000012"],
  "operator": { "employeeNumber": 3, "employeeName": "John Doe", "stationNumber": 1, "stationName": "POS 1" },
  "adjustments": [
    { "type": "addValue", "amount": { "regularPoints": 100 } },
    { "type": "addMinutes", "groupId": 1, "minutes": 60, "startTimePlay": true }
  ],
  "requireNewCards": true,
  "unlinkEmptyCards": false
}
```

Range form replaces `cardNumbers` with `startingCardNumber` +
`numberOfCards`.

Either form returns 200 → `Card[]` (the new balances) or 409
`cardNotEmpty` if any target was non-empty (and `requireNewCards` was
implied/explicit).

Used by: **not currently called**.

How we could use it: birthday-party voucher batches, corporate
group-sale prepaid sets, summer-camp wristband pre-loads. Capabilities
gate which forms are allowed via `bulkIssue.list` /
`bulkIssue.range` / `bulkIssue.canRequireNewCards` /
`bulkIssue.canUnlinkEmptyCards`. A future "bulk issue" admin page would
be a natural pairing for the existing groups / overrides UI patterns.

---

## Games

### `GET /games?skip=N&take=N`

Paginated list of games. Per-game: `id`, `name`, `virtualPlayEnabled`,
`operationStatus` (`enabled | paused | outOfService`), `categories`
(integer IDs), `supportedActions` (array of `GameAction { id, name,
requireManager }`).

Used by: `getGames()` (paginated to completion) → fed into
`syncGamesToCache()` and surfaced via the SPA `/api/games` cache.

### `PATCH /games`

Bulk operation-status update via JSON Patch. Per-game updates are
atomic; the request as a whole may have partial success.

Body:

```json
{
  "games": {
    "12345678": [{ "op": "replace", "path": "/operationStatus", "value": "paused" }],
    "12345679": [{ "op": "replace", "path": "/operationStatus", "value": "enabled" }]
  }
}
```

Response: `{ games: Game[], errors: { "<id>": Error } }`. Successful
no-ops are still listed in `games`; failed games appear in `errors`.

Only call when `capabilities.games.operationStatus` is true.

Used by: `patchGames()` → driven by both the manual SPA flow
(`PATCH /api/games`) and the scheduler (`Scheduler::executeStateChange`).
Local cache is updated only for IDs that did **not** appear in `errors`;
failures are queued into `action_retries` for the watchdog to retry.

### `GET /games/{gameId}`

Live (uncached) single-game lookup. Only available when
`capabilities.games.getSingleGame` is true.

Used by: `getGame()` → SPA `/api/games/{id}`.

### `POST /games/{gameId}/performAction`

RPC action passthrough. Body:

```json
{ "actionId": "reboot", "operator": { ... } }
```

Returns the updated `Game` object. Only `actionId`s present in the
game's `supportedActions` are valid; if `requireManager: true` for the
action, the upstream may enforce that.

Used by: `performGameAction()` → SPA `/api/games/{id}/action`.

### `GET /games/transactions?sinceId=N&feedName=default&take=N`

Forward-only stream of all game-play transactions (across all cards).
This is the heart of the "live floor activity" dashboard.

- `sinceId` is **exclusive** — pass the highest ID you've already
  processed. First-time sync uses `sinceId=0`.
- `feedName` defaults to `default`. Vendors with multiple independent
  feeds enumerate them in `capabilities.games.transactionFeedNames`.
- IDs are unique within a feed and strictly increasing. **The server
  must return transactions in ascending order**, otherwise data can be
  lost.
- `take` is the page size (default 100, max 100).

Response: `{ transactions: GamePlayTransaction[], sinceId }`. The
returned `sinceId` echoes what was sent.

Each `GamePlayTransaction` has `id`, `cardNumber` (use `"000000"` for
credit-card-only plays), `type: "gamePlay"`, `transactionTime`,
`gameId`, `gameDescription`, `amount: PointsWithCash`, optional
`usedTimePlay`, `usedPlayPrivilege`, and `creditCardDetails`. When
`amount.cash > 0`, `creditCardDetails` (last-4, brand, name, approval
code) MUST be present.

Used by: `getGameTransactions()` (low-level page fetch) and
`pollGameTransactions()` (cursor-checkpointed, multi-page). The cursor
is persisted as `app_config.game_tx_last_id_<feed>`; each call walks up
to 20 pages of 200 records before yielding. The watchdog cron polls
**every advertised feed** each minute via `pollAllGameTransactionFeeds()`
(which wraps `pollGameTransactions()` per feed).

Multi-feed is now implemented: `capabilities.games.transactionFeedNames` is
read and each feed is polled with its own cursor, so credit-card plays from
vendors that isolate them into a separate feed are captured (previously only
`default` was polled). The cursor model still leaves room for parallel
"rebuild from N" backfill jobs without disturbing the live cursor.

### `GET /games/categories?skip=N&take=N`

Optional grouping of games (e.g. `Redemption`, `Pinball`,
`Merchandiser`). Per category: `id`, `name`, `numberOfGames`. Only
available when `capabilities.games.categories` is true.

Used by: `getCategories()` → SPA `/api/games/categories`. Drives the
group-membership picker.

How we could use it: categories also support "put the whole category
into / out of service" workflows. We don't expose that in the SPA today
— it would map naturally to a "category-based pause group" shortcut on
the groups page.

---

## Privileges

### `GET /privilegeGroups?skip=N&take=N`

Logical groupings used to scope a play privilege ("free play of any
arcade game"). Per group: `{ id, name }`. The card system decides what
each group means; CenterEdge just lets the operator pick one when
configuring a privilege product.

Used by: `getPrivilegeGroups()` / `getPrivilegeGroupsCached()`, consumed by
`api/cards.php` — the cards page shows the human-readable group name beside
each `Card.privileges[].groupId` from the cached id→name map.

---

## Time Play

### `GET /timePlayGroups?skip=N&take=N`

Same shape and pattern as privilege groups — `{ id, name }`. Time-play
groups gate which games a time play is valid on (e.g. "All Games" vs
"Non-redemption Games" vs "VR Only").

Used by: `getTimePlayGroups()` / `getTimePlayGroupsCached()`, consumed by
`api/cards.php` — shows the human-readable name on the cards page beside
`Card.timePlays[].groupId`. (An "issue time play at POS" workflow remains
unbuilt.)

---

## System

### `GET /system/transactions?sinceId=N&type=merge&type=expiration&take=N`

Forward-only stream of **system** transactions — events the card system
performs on its own (not as a result of a CenterEdge API call).

`type` is **required** and may be repeated; older Advantage versions
only request types they understand, so the server must filter by it
(failing to filter can cause deserialisation failures and halt
processing).

Per the spec, `SystemTransactionType` is one of:

- `merge` — card-A's value moved onto card-B; A is left wiped.
- `expiration` — value, time plays, or privileges removed; can also
  represent a card wipe (`isWiped: true`).

Response: `{ transactions: SystemTransaction[], sinceId }`.

Only enabled when `capabilities.systemTransactionReporting.isSupported`
is true; the supported subtypes are listed in
`capabilities.systemTransactionReporting.transactionTypes`.

Used by: `getSystemTransactions()` / `pollSystemTransactions()` — the watchdog
polls this every minute (cursor pattern, like the play feed) into the local
`system_transactions` table (~400-day retention). This is the "deferred revenue
accounting" feed (card balances that expired or merged outside Advantage). The
events are stored and surface as breakage KPIs in the analytics overview; a
dedicated deferred-revenue ledger page with dollarization is the remaining
future work.

---

## Kiosks (vendor extension, not in the OpenAPI spec)

The OpenAPI 1.8.0 file does not include kiosk endpoints, but the
upstream system in production exposes them, and the local client wires
them up the same way as games. Per the spec note in
`patchKiosks()`, only call PATCH when capabilities reports
`kiosks.operationStatus: true` (older spec versions did include a
kiosks capability).

| Path | Method | Purpose | Client method |
| --- | --- | --- | --- |
| `/kiosks?skip=&take=` | GET | Paginated kiosk list | `getKiosks()` / `getAllKiosks()` |
| `/kiosks/{id}` | GET | Single kiosk | `getKiosk()` |
| `/kiosks` | PATCH | Bulk JSON Patch on `/operationStatus` | `patchKiosks()` |
| `/kiosks/{id}/performAction` | POST | RPC action (e.g. `reboot`) | `performKioskAction()` |

A kiosk object mirrors `Game`: `id`, `name`, `operationStatus`
(`enabled | paused | outOfService` or **missing** = unknown), optional
`categories` and `supportedActions`. **Important:** when
`operationStatus` is missing the spec says clients MUST NOT try to
change it; our scheduler skips those kiosks automatically and the SPA
hides pause controls.

Used by: SPA `/api/kiosks/*` and the scheduler when a pause group has
kiosk members. The same retry queue pattern as games applies — failed
PATCH IDs are queued in `action_retries` and re-tried by the watchdog.

How we could use it: largely already wired. Two opportunities: (1) the
SPA could expose a bulk "pause all kiosks" button (the PATCH endpoint
supports this; today only the scheduler hits it that way), and (2) we
could surface `supportedActions` on the standalone kiosks page the same
way the games page does, so operators can reboot kiosks from the SPA.

---

## Common Schemas

The schemas referenced above are defined under `components.schemas` in
the YAML. The most-used ones at a glance:

- **`Operator`** — `{ employeeName?, employeeNumber?, stationName?,
   stationNumber? }`. Required on most write operations (create card,
   wipe card, combine, perform-action, etc.).
- **`CustomerInfo`** — `{ id (uuid), firstName?, lastName, emailAddress?,
   phoneNumber?, birthDate? }`. `id` and `lastName` required.
- **`CardNumber`** — string, 6–20 chars. (Our internal API guard accepts
   1–32 alphanumeric to be safe.)
- **`Points`** — `{ regularPoints?, bonusPoints?, redemptionTickets? }`,
   missing field == 0.
- **`PointsWithCash`** — `Points` + `cash?`, `creditCard?`.
- **`Adjustment`** (discriminated by `type`) — one of
   `addValue | removeValue | addMinutes | removeMinutes |
   addDateTimeRange | removeDateTimeRange | addPrivilege | removePrivilege`,
   each with its own required fields (e.g. `addMinutes` needs `groupId`
   and `minutes`).
- **`CardTransaction`** (discriminated by `type`) — `adjustment` or
   `gamePlay`.
- **`SystemTransaction`** (discriminated by `type`) — `merge` or
   `expiration`.
- **`Game`** — `{ id, name, virtualPlayEnabled?, operationStatus?,
   categories?, supportedActions? }`.
- **`GameAction`** — `{ id, name, requireManager? }`.
- **`GameOperationStatus`** — `enabled | paused | outOfService`.
- **`Capabilities`** — see the long enumeration in the Capabilities
   section above; this is the single source of truth for what features
   to enable in the UI.
- **`Error`** — `{ code, message }` with code drawn from a fixed enum.

---

## Coverage Gaps Worth Acting On

**Already closed since the first draft** (do NOT re-propose): the
privilege/time-play group name caches (`getPrivilegeGroupsCached` /
`getTimePlayGroupsCached`, shown on the cards page), the deferred-revenue
`system/transactions` polling (`pollSystemTransactions`, into
`system_transactions`), and multi-feed play polling
(`pollAllGameTransactionFeeds`). What remains genuinely unused is the
card-administration WRITE surface:

1. **Card administration at the redemption counter.** `POST
    /cards/{n}` (create empty), `DELETE /cards/{n}` (wipe), `POST
    /cards/{n}/combine` (merge), `POST /cards/{n}/transactions` (manual
    adjust). These would let the SPA replace a separate counter tool —
    every operation captures an `Operator` block, which we already
    synthesise from the logged-in user for the existing RPC actions. The
    highest daily-utility one is the manual adjustment (goodwill comps,
    malfunction credits, ticket corrections).
2. **Bulk issue.** `POST /cards/bulkIssue` enables prepaid voucher
    batches, group sales, and pre-loaded summer-camp wristbands. The
    `Operator` capture pattern is the same; the new wrinkle is letting
    the operator pick from `/games/categories` and / or
    `/privilegeGroups` / `/timePlayGroups` in the adjustment builder.
3. **Deferred-revenue PRESENTATION.** `system/transactions` is already
    polled/stored (see above); the remaining gap is a dedicated
    deferred-revenue ledger page that DOLLARIZES the expirations/merges,
    rather than the points/tickets counts currently in the overview.

One smaller win is still tractable today:

- **Card-number format pre-validation** (`GET /cardNumberFormats`)
  would catch malformed card numbers in the SPA before round-tripping
  to the upstream.

All five rely on patterns we already use (capability-gating, paginated
fetch-all, encrypted token reuse) — they are mostly UI work plus thin
client-method additions.
