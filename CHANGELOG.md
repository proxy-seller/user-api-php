# Changelog

All notable changes to this package. This project follows [Semantic Versioning](https://semver.org/).

## 2.0.1 — unreleased

Catching up with server changes made after 2.0.

### Breaking

- **`ipv6`, `mix` and `mix_isp` are renewed by order.** `prolongCalc()`, `prolongMake()` and
  `autoProlongCalc()` / `autoProlongEnable()` / `autoProlongDisable()` send `orderIds` (`order_id`
  from `proxyList()` or `orderList()`) instead of `ids` for these three types — the server renews
  them only as whole orders, every active proxy of the type in them (the mix packages for `mix` /
  `mix_isp`). `ipv4`, `isp` and `mobile` are renewed per proxy as before, by `ids` (the proxy `id`
  from `proxyList()`) or by `ips` (the address — `ip` for `ipv4` / `isp`,
  `ip:port_http:port_socks` for `mobile`). Values are still routed by shape: an address goes to
  `ips`, anything else to `ids` or `orderIds` depending on the type, which is normalized the way
  the server does it (`MIX-ISP` counts as `mix_isp`). Code that renewed `ipv6`, `mix` or `mix_isp`
  by address (`host:port` / `ip`) or by proxy id must pass order ids now. The server refuses a
  selection field of the wrong kind with code 0 —
  `[ids] is not applicable for ipv6: prolong by [orderIds]` (`[ips] …` for addresses) and, the
  other way round, `[orderIds] is not applicable for ipv4: prolong by [ids]` — and an order that
  is not yours or has no active proxies of that type fails the whole request with
  `Incorrect orderIds` (code 29).
- **`orderSeparatorIds` and `orderSeparatorId` are gone from the renewal body.** The server no
  longer reads them. Passing either in the options array throws `\InvalidArgumentException` naming
  the replacement instead of being dropped silently:
  `` `orderSeparatorIds`/`orderSeparatorId` were removed: use `orderIds` ``.
- **Proxy ids and addresses cannot be mixed in one call for `ipv4`, `isp` and `mobile`.** Given both
  `ids` and `ips`, the server renews by `ids` and ignores `ips`, so the addresses would silently
  drop out of a paid renewal. The SDK throws `\InvalidArgumentException` ("Mixing proxy ids and
  addresses in one call is not supported: pass either ids or addresses") instead of sending both —
  for a mixed list as well as for a list combined with the options array. `ipv6` / `mix` /
  `mix_isp` still route a mixed list (ids → `orderIds`, addresses → `ips`) and leave the address
  part to the server.
- **Residential auto-renewal refuses a selection.** With `type = 'resident'` a non-empty `$ids` list,
  or a non-empty `ids`, `ips` or `orderIds` in the options array, throws
  `\InvalidArgumentException` ("resident auto-prolong applies to the whole package: do not pass
  proxy or order ids") instead of being stripped silently: a `disable` meant for a few addresses
  would otherwise switch off the whole package. The server refuses each of the three as well, with
  `[ids] is not applicable for resident: auto-prolong applies to the whole package`. An empty list
  is fine, and the period is still dropped.

### Added

- **`orderList()`** for `GET order/list`. Every filter is optional; query filters and response
  fields use snake_case names (`order_id`, `start_date`, `end_date`, `status`, `is_extend`,
  `auto_order`, `page`, `limit`, `sort_by`, `order`). `data` is a `metadata` + `items` pair rather
  than a flat list, `summ` / `items[]['price']` are currency strings (`'$25.00'`), not numbers, and
  `id` is a numeric order ID sent as a string next to the ObjectId `order_id`.
- **`autoProlongCalc()` / `autoProlongEnable()` / `autoProlongDisable()`** for
  `autoprolong/{calc,enable,disable}/{type}`. `paymentId` is mandatory on calc and enable and is
  restricted to `balance` / `paddle_subscription`; `type = 'resident'` sends the package-shaped
  body (`paymentId`, optional `tarifId`) and takes no selection — passing one throws (see Breaking).
  `enable` and `disable` answer `ids` — the proxies actually affected, the `id` of `proxyList()` —
  and `orderIds` with their orders; both lists are empty for `resident`.
- **Optional `X-Fingerprint` on `order/make`.** Configure it as `['fingerprint' => ...]`, with
  `setFingerprint()`, or per call via `$options['fingerprint']`; the SDK sends it whenever a value
  is set and never requires it. Orders placed with an API key are created without it in every
  section, residential and scraper included, so there is no local gate any more — earlier builds of
  this branch refused residential and scraper orders without it with `\InvalidArgumentException`.
- **`maxLine`** on `proxy/download/resident` — the only route that accepts it.
- **`Api::ORDER_PROLONG_TYPES`** — the types renewed as whole orders by `orderIds` (`ipv6`, `mix`,
  `mix_isp`) — and **`Api::PROLONG_REMOVED_FIELDS`**, the removed selection fields with the message
  that names their replacement.

### Changed

- **Behaviour change: requests are now paced by default** (see
  [Rate limits and the request queue](README.md#rate-limits-and-the-request-queue)). All requests
  share a sliding window of `requestsPerMinute` (1000) starts per 60 seconds. Writes and payments go
  through one lane per `Api` instance — one at a time, each at least `writeIntervalMs` (1000 ms)
  after the previous write or payment started, payments (`orderMake*()`, `prolongMake()`,
  `balanceAdd()`) also at least `moneyIntervalMs` (2000 ms) after the previous payment; reads,
  `*Calc()` included, wait only for the window. HTTP 429 from the edge in front of the API is retried after `Retry-After`
  (2 s when missing or unreadable, 60 s at most) up to `maxRetries` (3) times, then thrown as
  `ApiException` with HTTP status 429. Envelope errors — code 57 and the access-error triple — are
  never retried. A call may therefore block (`usleep()`) where it used to go out at once. Tune it
  with the new `rateLimit` config key (`enabled`, `requestsPerMinute`, `writeIntervalMs`,
  `moneyIntervalMs`, `maxRetries`; unknown keys and invalid values throw
  `\InvalidArgumentException`); `'rateLimit' => false`, short for `['enabled' => false]`, restores
  the previous behaviour exactly, and `true` means all defaults. The queue lives in the instance: separate instances and processes do not
  coordinate, and under php-fpm every web request starts with a new, empty queue.
- **`proxyList()` filters follow the server.** `latest = 'Y'` now means the latest order of the
  requested type (of the MIX orders for `mix` / `mix_isp`) instead of the latest order of the whole
  account, which left the list empty whenever another type had been bought last; without a type it
  is still one latest order for the whole response, and it no longer empties `resident` and
  `scraper`. `orderId` also accepts the numeric `id` of an `orderList()` row, `base_order_number`
  and an earlier `order_number` of a renewed order, not only `order_id` and the exact current
  `order_number`. No SDK code changed — both values go to the server as they are.
- **`prolongMake()` returns `orderIds`** — every renewed order, since one request can renew several.
  `orderId` stays and equals `orderIds[0]`; `listBaseOrderNumbers` carries one base order number per
  renewed order (per package for `mix` / `mix_isp`).
- **Empty selection lists are not sent**, whether they come from the positional argument or from the
  options array; a single value passed there is wrapped into a list.

### Removed

- **`resident/autorenew/{enable,disable,calculate}`** — deleted on the server, replaced by
  `autoprolong/*` with `type = 'resident'`.
- **`dailyCountCap` / `monthlyAmountCap`** from `balanceAutoTopupSet()`. Removed from the contract
  on 2026-08-18 and silently ignored by the server, so passing them made a call that reported
  success and changed nothing. They are now rejected by name. Error codes `54` and `55` are gone
  with them, and `customData` no longer carries `minDailyCountCap`.

### Tests

- **A test suite, for the first time.** PHPUnit as a dev dependency, `composer test`,
  64 offline tests. `Api` already accepted an injected HTTP client through the `client`
  config key, so no production code had to change to make it testable.
  Coverage matches the guard suites the other four SDKs carry: the api key as a path
  segment, the `200`-with-an-error envelope and the access triple, every local gate
  (`customTargetName`, `balanceAdd`), `X-Fingerprint` sent when set but never required, the split
  `*Id` / `*Code` precedence, `generateAuth` on `order/make` only, the removed auto top-up caps,
  download routing, `not-found` deletes, type-aware renewal routing (`ids` / `ips` /
  `orderIds`, no empty lists), the local refusal of ids mixed with addresses and of the removed
  `orderSeparatorIds` / `orderSeparatorId`, auto-renewal with the residential selection
  refused, and the snake_case filter names of `order/list`.
- **Request-queue tests** (`tests/RateLimitTest.php`) run on an injected fake clock and sleeper, so
  they take no real time: payment and write spacing, the later-of-both wait of a payment after a
  write, reads never held by the lane, the sliding window, HTTP 429 retries (`Retry-After` in
  seconds or as an HTTP date, the 2 s default, the 60 s cap, giving up with status 429), code 57
  and the access triple not retried, the disabled mode and the `false` / `true` shorthand, a second
  write refused while one is in flight, and the category of every SDK method.

### Fixed

- **`*Code` no longer overrides a paired `*Id`** for `mixId`, `operatorId`, `rotationId` and
  `tarifId`. The server applies the code on those four only while the id is empty, so a caller who
  filled both halves silently got the wrong package, operator, rotation or tariff. `countryCode`,
  `periodCode` and `paymentCode` keep priority — there the server does prefer the code.

## 2.0.0 — unreleased

Client API **v2** (`https://proxy-seller.com/personal/api/v2/`) is a different API from v1, not a
compatible extension of it. This release targets v2 only; 1.x remains the client for
`/personal/api/v1/`.

### Breaking

- **Base URL is v2.** `Api::$URL` is now `https://proxy-seller.com/personal/api/v2/`. The API key
  still goes in the path, but is URL-encoded now. A v1 key/base URL combination will not work.
- **All identifiers are ObjectId strings, not integers.** `orderId`, IP address ids, auth ids and
  `paymentId` are 24-char hex strings. Code that casts them to `int`, compares them numerically or
  stores them in an integer column breaks. The one exception: resident **list** ids stay numeric
  (64-bit integers) — `residentListRename`, `residentListRotation`, `residentListDelete`,
  `proxyDownloadResident($id)`.
- **Errors throw `ProxySeller\Userapi\ApiException`** instead of a plain `\Exception`.
  `getCode()` is now the business error code from the envelope; the transport status moved to
  `getHttpStatus()`. The full `errors` array, the `data` payload and the raw body are available
  through `getErrors()`, `getData()`, `getResponseBody()`.
- **`setPaymentId()` no longer defaults to `1`** (v1's inner balance) and `balanceAdd()` no longer
  defaults to `29`; there are no hardcoded numeric payment ids in v2. Orders and renewals are paid
  with `balance` or `paddle_subscription` (the saved card) — set the code with `setPaymentCode()`
  or pass `'paymentCode' => 'balance'` per call. `balancePaymentsList()` lists only the systems
  for topping up with `balanceAdd()`; the balance itself is never in it.
- **`balanceAdd()` accepts only `paymentId`.** If just a `paymentCode` is configured, the call now
  throws `\InvalidArgumentException` instead of silently sending `paymentId: null` and coming back
  with `Set existed [paymentId]`. `paymentCode` is resolved on order/prolong endpoints only.
- **`authActive($id, $active)` is gone.** Use `authChange($id, $active, $login, $password, $ip)`
  where `$active` is a boolean, not `'Y'`/`'N'`.
- **`proxyCheck()` and `ping()` are gone.** `tools/proxy/check` and `system/ping` do not exist in
  v2; there is only an unauthenticated health route outside the SDK's base path.
- **`proxyList()` signature changed** to `proxyList($type = null, $filters = [])`. Without a type it
  hits `proxy/list` (v1 sent `proxy/list/` with an empty segment). Filters: `latest`, `orderId`,
  `country`, `ends`, `page`, `per_page`.
- **`proxyDownload()` signature changed** to
  `proxyDownload($type, $ext = null, $proto = null, $listId = null, $filters = [], $returnStream = false)`.
  `$listId` defaults to `null` instead of `0`, `ext` is validated client-side, and `package_key`
  goes through `$filters`.
- **`proxyReplace($ids, $type, $comment)`: `type` is the replacement reason**, not a proxy type —
  `NOT_WORK | INCORRECT_LOCATION | CANT_CHANGE_NETWORK | LOW_SPEED | CUSTOM`. Unknown values and a
  missing/blank `comment` for `CUSTOM` now raise `\InvalidArgumentException` locally instead of
  producing a server-side `Set coorect type: ...` / `Set comment` error.
- **`residentListDelete()` sends a JSON body** (v1 used a query string) and its result is normalized
  to an array: the server returns `data` as a *string* on delete endpoints.
- **`orderCalc*` / `orderMake*` require a goal for `ipv4`, `ipv6`, `isp` and unresolved `mix`.**
  Without `customTargetName` the SDK throws locally rather than letting the server answer
  `Incorrect goal` (code 14). A `mix` order is considered resolved — and therefore goal-free — when
  `mixId`/`mixCode` is present, or `countryId` carries a `packageId:quantity` pair, or `countryId`
  is set together with `quantity > 0` — the same three ways the server recognises a MIX package.

### Added

- `balanceAutoTopupGet()` — auto top-up configuration and state (`configured`, `enabled`, `state`,
  `threshold`, `amount`, `subscriptionId`, `paymentMethod`, `dailyCountCap`, `monthlyAmountCap`,
  `failCount`, `lastAttemptAt`, `lastEvent`).
  *(`dailyCountCap` / `monthlyAmountCap` were dropped again in 2.0.1 — see above.)*
- `balanceAutoTopupSet(array $settings)` — **partial update**: only the keys you pass are sent, the
  rest keep their stored values. Allowed keys: `enabled`, `threshold`, `amount`, `subscriptionId`,
  `dailyCountCap`, `monthlyAmountCap`. Unknown keys, wrong types and an empty payload raise
  `\InvalidArgumentException` (the server ignores unknown JSON fields, so a typo would otherwise be
  a silent no-op).
  *(The two cap keys are rejected as of 2.0.1 — see above.)*
- `ApiException::getFirstError()`, `getMessages()`, `getApiCodes()`, `hasApiCode()`,
  `getCustomData()`, `isAccessError()`. `getCustomData()` exposes the validation bounds the server
  puts in `errors[0].customData` (`minAmount`, `minThreshold`, `minDailyCountCap` for auto top-up).
- Auth management: `authAdd()`, `authAddIp()`, `authChange()`, `authDelete()`.
- Renewals: `prolongCalc()`, `prolongMake()` with an options array
  (`orderSeparatorIds`, `orderSeparatorId`, `periodCode`, `paymentCode`).
  *(`orderSeparatorIds` / `orderSeparatorId` were dropped again in 2.0.1 — passing them now throws,
  use `orderIds` — see above.)*
- Proxy: `proxyReplace()`, `proxyDownloadResident()`.
- Resident: `residentConsumption()`, `residentTrafficDetails()`, `residentGeoIsp()`,
  `residentGeoCount()`, `residentListAdd()`, `residentListRotation()`, `residentListTools()`.
- Resident subpackages: `residentSubUserCreate()`, `residentSubUserUpdate()`,
  `residentSubUserDelete()`, `residentSubUserPackages()`, `residentSubUserLists()`,
  `residentSubUserListAdd()`, `residentSubUserListRename()`, `residentSubUserListRotation()`,
  `residentSubUserListTools()`, `residentSubUserListDelete()`.
- Stable codes instead of environment-specific ids on the order/prolong endpoints:
  `setPaymentCode()` plus `countryCode`, `periodCode`, `mixCode`, `operatorCode`, `tarifCode` and
  `paymentCode` in the options array. The server resolves a non-id value straight from the paired
  positional `*Id` argument as well, so the options array is not needed just to carry a code.
  Two limits worth knowing: `rotationCode` is **not** a code — the server accepts only an integer
  there and copies it into `rotationId`, which is a number of minutes (`0` = By Link) — and
  `reference/list` publishes a code only for countries (`alpha3`); periods, mobile operators, MIX
  packages, resident tariffs and payment systems come back as ids (plus names) only.
- Injectable transport (`'client' => $guzzleLikeObject`) and `getLastResponseStatus()` for
  `status=error` responses that still carry useful `data` (calc warnings such as an insufficient
  balance).
- Raw/binary endpoints can return a PSR-7 stream via a trailing `$returnStream = true`.

### Fixed

- `proxyDownload()` dropped `ext` when it was passed inside `$filters`: the positional `$ext`
  (usually `null`) overwrote it after `array_merge`, so the export silently fell back to the default
  format and skipped the length/forbidden-character check.
- `proxyDownload('resident', ..., ['package_key' => ...])` used to look like it exported a
  subpackage while the server ignored the parameter and returned the **parent** package. It now
  throws and points at `proxyDownload('subresident', ...)`, the only route that honours
  `package_key`.
- Endpoints whose `data` arrives as a string (`resident/list/delete`, `residentsubuser/delete`,
  `residentsubuser/list/delete`) are normalized to an array, so `['status' => 'not-found']` inside a
  successful envelope is no longer indistinguishable from a successful delete on PHP 8.
- Request bodies that would serialize to `[]` (no filters passed) are sent as `{}` — the server
  treats a JSON array where it expects an object as a malformed body and answers HTTP 200 with
  `Incorrect request body` (code 0, sometimes followed by `: <field>`) in the usual envelope.
- `authChange()` omits credentials that were not provided, so changing only `active` no longer sends
  empty `login`/`password`/`ip`.

### Documentation

- `resident/geo` returns a JSON file `geo.json` and `resident/geo/isp` returns `isp.json` — neither
  is a zip archive (the old "zip ~300Kb, unzip ~3Mb" note was wrong).
- The `packageKey`/`key` naming split in `resident/traffic/details` (`package_key` does **not** work
  there) versus `package_key` everywhere under `residentsubuser/*`.
- `expired_at` is a string (`d.m.Y H:i:s`) for the resident package but a PHP date **object**
  (`{date, timezone_type, timezone}`) for subpackages.
- `resident/lists` returns `data` as a flat array — no `items` wrapper.
- Access errors (bad key / IP not allowed / rate limit) arrive as HTTP 200 with a fixed triple whose
  first message is always `Error api key`; there is no HTTP 429.

## 1.x

Client for `/personal/api/v1/`. See the `main` branch history.
