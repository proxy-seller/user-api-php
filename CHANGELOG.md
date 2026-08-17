# Changelog

All notable changes to this package. This project follows [Semantic Versioning](https://semver.org/).

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
  (`Long`) — `residentListRename`, `residentListRotation`, `residentListDelete`,
  `proxyDownloadResident($id)`.
- **Errors throw `ProxySeller\Userapi\ApiException`** instead of a plain `\Exception`.
  `getCode()` is now the business error code from the envelope; the transport status moved to
  `getHttpStatus()`. The full `errors` array, the `data` payload and the raw body are available
  through `getErrors()`, `getData()`, `getResponseBody()`.
- **`setPaymentId()` no longer defaults to `1`** (v1's inner balance) and `balanceAdd()` no longer
  defaults to `29`. Payment systems must be taken from `balancePaymentsList()`; there are no
  hardcoded numeric payment ids in v2.
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
  is set together with `quantity > 0` (mirrors `ClientApiService.parseMixSelection`).

### Added

- `balanceAutoTopupGet()` — auto top-up configuration and state (`configured`, `enabled`, `state`,
  `threshold`, `amount`, `subscriptionId`, `paymentMethod`, `dailyCountCap`, `monthlyAmountCap`,
  `failCount`, `lastAttemptAt`, `lastEvent`).
- `balanceAutoTopupSet(array $settings)` — **partial update**: only the keys you pass are sent, the
  rest keep their stored values. Allowed keys: `enabled`, `threshold`, `amount`, `subscriptionId`,
  `dailyCountCap`, `monthlyAmountCap`. Unknown keys, wrong types and an empty payload raise
  `\InvalidArgumentException` (the server ignores unknown JSON fields, so a typo would otherwise be
  a silent no-op).
- `ApiException::getFirstError()`, `getMessages()`, `getApiCodes()`, `hasApiCode()`,
  `getCustomData()`, `isAccessError()`. `getCustomData()` exposes the validation bounds the server
  puts in `errors[0].customData` (`minAmount`, `minThreshold`, `minDailyCountCap` for auto top-up).
- Auth management: `authAdd()`, `authAddIp()`, `authChange()`, `authDelete()`.
- Renewals: `prolongCalc()`, `prolongMake()` with an options array
  (`orderSeparatorIds`, `orderSeparatorId`, `periodCode`, `paymentCode`).
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
- Request bodies that would serialize to `[]` (no filters passed) are sent as `{}` — Spring answers
  a bare plain-text HTTP 400 for a JSON array where it expects an object.
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
