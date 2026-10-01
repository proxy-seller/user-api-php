# Proxy-Seller Client API SDK for PHP

This package is a small Client API v2 client. It builds requests, adds the API key, parses the common `{status,data,errors}` envelope and supports raw/binary responses.

Breaking changes against 1.x are listed in [CHANGELOG.md](CHANGELOG.md).

## Install

```sh
composer require proxy-seller/user-api-php:^2.0
```

**Pin the major.** 1.x is the client for `/personal/api/v1/` and is a different API, so a bare
`composer require proxy-seller/user-api-php` can resolve to 1.x and none of this document applies.
2.0.0 is not tagged yet (see [CHANGELOG.md](CHANGELOG.md)); until it is, install this branch
directly:

```sh
composer require proxy-seller/user-api-php:dev-feature/client-api-v2
```

## Configuration

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use ProxySeller\Userapi\Api;
use ProxySeller\Userapi\ApiException;

$api = new Api([
    'key' => 'YOUR_API_KEY',
    'fingerprint' => 'my-installation-id',   // optional X-Fingerprint for order/make, see below
    'timeout' => 30,          // seconds, every call except payments (the default)
    'moneyTimeout' => 120,    // seconds, order/make, prolong/make, balance/add (the default)
    'connect_timeout' => 5,
]);

echo $api->balance();
```

Do not cut the payment timeout short: a large order can take the server well over 30 seconds, and
a call that times out may still have created and paid for the order. Payment calls always wait at
least `moneyTimeout`, whatever `timeout` says — see
[Timeouts and retries on payments](#timeouts-and-retries-on-payments).

Nothing else is required — the client talks to `https://proxy-seller.com/personal/api/v2/` by default.
It also paces its own requests to stay under the API's limits; see
[Rate limits and the request queue](#rate-limits-and-the-request-queue).

**Paying for orders.** Every order and renewal needs a payment system, and `order/make` accepts
only two: `balance` (the account balance) and `paddle_subscription` (the saved card, which needs an
active card subscription). Set the code once, or pass it per call:

```php
$api->setPaymentCode('balance');     // or 'paddle_subscription' to pay with the saved card

// per call, in the final options array:
$api->orderMakeIpv4('USA', '1m', 1, null, null, 'my target', ['paymentCode' => 'balance']);
```

`setPaymentCode()` and `setPaymentId()` only set the **client default**. A payment named in the call
itself — `paymentId` or `paymentCode` in the options array, or the `$paymentId` argument of
`balanceAdd()` — wins, and then the client's pair is not sent at all, neither the id nor the code.
So `setPaymentCode('paddle_subscription')` followed by a call with `['paymentId' => 'balance']`
pays from the balance, not with the card. Within one level the old rule stands: given both a code
and an id in the same call, the code wins. An empty value (`null`, `''`) in the call counts as not
given.

`balancePaymentsList()` is **not** where order payments come from. It lists the systems for
topping up the balance with `balanceAdd()`, never contains the balance itself, and none of its
entries can pay for an order. For a top-up an id is unavoidable: several payment systems share the
same internal code (a single `cryptomus` covers "USDT (TRC-20)", "All cryptocurrencies" and more),
so the code cannot tell them apart — see [Balance and auto top-up](#balance-and-auto-top-up).
Everywhere else you use human-readable codes.

<details>
<summary>Pointing the client at another host (local testing)</summary>

```php
$api = new Api(['key' => 'YOUR_API_KEY', 'baseUrl' => 'http://localhost:7995/personal/api/v2/']);
```

`baseUrl` must include `/personal/api/v2/`. You may also inject an already configured
Guzzle-compatible client through the `client` option — useful for a custom transport, tracing,
TLS settings or local stubs.

On payments and writes the SDK adds two request options to whatever client is used: `curl` with
`CURLOPT_FRESH_CONNECT => true`, and on payments `timeout` (see
[Timeouts and retries on payments](#timeouts-and-retries-on-payments)). For a Guzzle client the SDK
reads its `curl` and `timeout` defaults through `getConfig()` and merges with them — your cURL
options stay in place, and your timeout is only ever raised, never lowered; a Guzzle client without
a timeout keeps waiting without a limit. A custom transport without `getConfig()` receives the
options as they are, and should merge a request-level `curl` array with its own defaults.
</details>

## IDs in v2 are strings, not numbers

Every identifier the API returns — `orderId`, IP address ids, auth ids, `paymentId` — is an **ObjectId**: a 24-character hex string such as `66f0c2a1b4d3e5f6a7b8c9d0`. Do not cast them to `int`, do not compare them numerically, and store them as strings:

```php
$order = $api->orderMakeIpv4('USA', '1m', 1, null, null, 'my target');

$orderId = $order['orderId'];          // string, keep it as a string
$proxies = $api->proxyList('ipv4', ['orderId' => $orderId]);
```

Casting to `int` truncates an ObjectId to a meaningless number (often `0`), which silently returns the wrong page of data instead of failing.

**One exception:** resident *list* ids are numeric (64-bit integers), not ObjectIds. They are used by `residentListRename()`, `residentListRotation()`, `residentListDelete()`, `residentSubUserListRename()` and `proxyDownloadResident($id)`.

## Current order API

Every `*Id` argument accepts **either** an ObjectId **or** the matching stable code. The server tries
the value as an id first and falls back to a code lookup when it is not a valid id. Codes therefore
go in **positionally** — there is no need for a chain of `null`s and an options array just to carry
them:

```php
// referenceList('mobile') returns ['items' => <section>]; without a type it returns a map of sections
$section  = $api->referenceList('mobile')['items'];
$operator = $section['country'][0]['operators']['dedicated'][0];
// $operator = ['id' => 'ee_unitedkingdom', 'name' => 'EE', 'rotations' => [['id' => 5, 'name' => '5 minutes'], …]]

$mobile = $api->orderCalcMobile(
    'USA',              // from reference/list -> country[].id
    '1m',               // from reference/list -> period[].id
    1,                  // quantity
    null,               // authorization — optional
    null,               // coupon — optional
    $operator['id'],    // operator code, e.g. 'ee_unitedkingdom' (case-sensitive)
    5,                  // rotationId: MINUTES, 0 = By Link. Not a code — '5m' is rejected
    'dedicated'         // shared or dedicated; required for mobile
);

$uptime = $api->orderCalcIpv4('USA', '1m', 1, null, null, 'my target', ['uptime' => true]);

// MIX is addressed by its package code, which goes first — no options array, no nulls up front
$package = $api->referenceList('mix')['items']['quantities'][0];
// $package = ['id' => 'europe-2-mix_IPv4', 'name' => '…', 'quantities' => [10, 20, …]]
$mix = $api->orderCalcMix($package['id'], '1m', 10);
```

The remaining `null`s above are genuine optional values (`authorization`, `coupon`), not placeholders.

The final options array is for fields **without** a positional argument: `uptime`, `mixId`/`mixCode`,
`generateAuth`, and `protocol` outside the IPv6 helpers. The explicit `*Code` keys (`countryCode`,
`periodCode`, `operatorCode`, `mixCode`, `tarifCode`, `paymentCode`) still work and win over the
paired `*Id`, so use them when a self-documenting payload matters more than a short call.

**`rotationCode` is a trap.** It is the one field without a code lookup: the server checks that the
value is an integer and copies it into `rotationId` unchanged, so `'5m'` fails with
`Set existed [rotationCode] from reference` and nothing else happens. Pass the number of minutes —
in `rotationId`, which is what `rotationCode` would become anyway.

### What you can pass, and where to get it

Every field in the reference is called `id`, and its value is a readable code — not an ObjectId.
Read `id`, put it in the matching `*Id` argument. That is the whole rule:

| Argument | Pass this | Read it from |
| --- | --- | --- |
| `countryId` | alpha-3 country code, e.g. `USA` (upper-cased server-side, so `usa` works) | `reference/list` → `country[].id` |
| `periodId` | period code, e.g. `1m` (lower-cased server-side) | `reference/list` → `period[].id` |
| `operatorId` | mobile operator code — exact match, case-sensitive | `reference/list/mobile` → `country[].operators.dedicated[]` / `.shared[]` → `id` |
| `rotationId` | **minutes as an integer**, `0` = By Link — the one `id` that is a number, not a code | `reference/list/mobile` → `country[].operators.*[].rotations[].id` — that value *is* the minute count (`name` is `"5 minutes"` / `"By Link"`) |
| `mix` (first argument of `orderCalcMix` / `orderMakeMix`) | mix package code — exact match | `reference/list/mix` → `quantities[].id`, e.g. `europe-2-mix_IPv4` |
| `tarifId` | resident tariff code — exact match, e.g. `1-gb` | `reference/list/resident` → `items.tarifs[].id` |
| `paymentId` / `paymentCode` | `balance` or `paddle_subscription` (the saved card) — the only two accepted for orders and renewals | these two codes, no lookup needed (see "Paying for orders" above); `balance/payments/list` lists top-up systems for `balanceAdd()` only |

ObjectIds are still accepted everywhere if you happen to have them; the reference simply no longer
publishes them. Code resolution happens in `order/calc`, `order/make`, `prolong/calc` and
`prolong/make`.

`ipv4`, `ipv6` and `isp` orders need a goal (`customTargetName`) — what you use the proxies for. The
SDK checks it locally so the server does not have to answer `Incorrect goal` (code 14). A `mix` order
needs one only when the server cannot tell which package you mean, so naming the package removes the
need for it.

For a fully custom payload use `orderCalc(array $payload)` or `orderMake(array $payload)`.

### Listing orders

```php
$orders = $api->orderList([
    'status'  => 'PAYED',       // PAYED | NOT_PAYED | RETURN — the status_type of the response
    'sort_by' => 'date_insert', // date_insert | summ | status
    'order'   => 'desc',
    'page'    => 1,
    'limit'   => 20,
]);

$all = $api->orderList(); // the same call with no filters at all
```

Every filter is optional. Query filters and response fields use snake_case names such as
`start_date` and `is_extend`; the full filter set is `order_id`, `start_date`, `end_date`,
`status`, `is_extend`, `auto_order`, `page`, `limit`, `sort_by`, `order`. `order_id` matches either
of the two order identifiers described below.

`data` is not a flat list but a `metadata` + `items` pair, and `metadata` is always present:
without `limit` it reports `total_pages => 1`, `current_limit => 0` and the whole list in `items`.
`summ` and `items[]['price']` are **strings with the currency already in them** (`'$25.00'`),
`auto_order` and `is_extend` are `'Y'`/`'N'` rather than booleans, and the dates are ISO 8601 with offset (`2026-09-01T14:15:26+00:00`)
strings. `id` is a numeric order ID sent as a string; the ObjectId is `order_id` — the same value
`proxyList()` returns as `order_id`, and the one to pass when renewing `ipv6`, `mix` and `mix_isp`
(see [Renewing proxies](#renewing-proxies)).

### The optional `X-Fingerprint` header

`order/make` accepts an optional `X-Fingerprint` header, used for anti-fraud checks and affiliate attribution when present. **It is not required**: orders placed with an API key are created without it in every section, residential and scraper included. The SDK sends the header whenever a value is configured and never refuses an order when it is missing.

```php
$api = new Api(['key' => 'YOUR_API_KEY', 'fingerprint' => 'my-installation-id']);
// or later:
$api->setFingerprint('my-installation-id');
// or for a single call:
$api->orderMakeResident('tarif-code', null, ['fingerprint' => 'my-installation-id']);
```

Any opaque string is accepted — the server does not validate its shape — but if you send one, make it a **stable identifier of your installation**. The SDK deliberately does not generate one: a value randomized per process would break the anti-fraud and affiliate attribution the header exists for. An empty value counts as unset, and no header is sent.

## Renewing proxies

What a renewal is addressed by depends on the type:

- **`ipv4`, `isp` and `mobile` are renewed per proxy**: by `ids` (the proxy `id` from
  `proxyList()`) or by `ips` (the address — `ip` for `ipv4` / `isp`, `ip:port_http:port_socks`
  for `mobile`).
- **`ipv6`, `mix` and `mix_isp` are renewed as whole orders, by `orderIds` only** — pass the
  `order_id` of each order (from `proxyList()` or `orderList()`), and every active proxy of that
  type in it is renewed (for `mix` / `mix_isp`: the mix packages of those orders).

```php
// ipv4 / isp / mobile — per proxy, by address (or by the proxy id)
$ipv4 = $api->proxyList('ipv4')['items'];
$ips  = array_column($ipv4, 'ip');                // ['1.2.3.4', '5.6.7.8']

$quote = $api->prolongCalc('ipv4', $ips, '1m');   // price first
echo $quote['total'];

$order = $api->prolongMake('ipv4', $ips, '1m');   // deducts money
echo $order['orderId'];                           // the first of $order['orderIds']

// ipv6 / mix / mix_isp — whole orders, by order id
$ipv6   = $api->proxyList('ipv6')['items'];
$orders = array_values(array_unique(array_column($ipv6, 'order_id')));

$order = $api->prolongMake('ipv6', $orders, '1m');
print_r($order['orderIds']);                      // every renewed order
```

What to pass per type, and which `proxyList()` field it comes from:

| Type | Pass this | Built from | Sent as |
| --- | --- | --- | --- |
| `ipv4`, `isp` | the plain address, `"1.2.3.4"` — or the proxy id | `ip` — or `id` | `ips` — or `ids` |
| `mobile` | `"ip:port_http:port_socks"`, e.g. `"1.2.3.4:50100:50101"` — or the proxy id | `ip`, `port_http`, `port_socks` — or `id` | `ips` — or `ids` |
| `ipv6`, `mix`, `mix_isp` | the order id | `order_id` (the same value as in `orderList()`) | `orderIds` |

The SDK routes each value by its shape: a value with a dot or a colon is an address and goes to
`ips`, anything else is an id and goes to `ids` for `ipv4` / `isp` / `mobile` and to `orderIds` for
`ipv6` / `mix` / `mix_isp`. To set a field yourself, pass `ids`, `ips` or `orderIds` in the final
options array; empty lists are never sent.

**For `ipv4`, `isp` and `mobile` pass either ids or addresses in one call, not both.** Given both
`ids` and `ips`, the server renews by `ids` and ignores `ips`, so the addresses would silently drop
out of a paid renewal. The SDK therefore throws `\InvalidArgumentException` — "Mixing proxy ids and
addresses in one call is not supported: pass either ids or addresses" — before anything is sent,
whether the mix sits in one list or is split between the list and the options array. Renew ids and
addresses in two calls. For `ipv6`, `mix` and `mix_isp` a mixed list is still routed (ids to
`orderIds`, addresses to `ips`), and the server rejects the address part itself.

The server refuses a selection field of the wrong kind instead of guessing, with code 0: `ids` or
`ips` for `ipv6` / `mix` / `mix_isp` fails with
`[ids] is not applicable for ipv6: prolong by [orderIds]` (`[ips] …` for addresses), and
`orderIds` for `ipv4` / `isp` / `mobile` fails with
`[orderIds] is not applicable for ipv4: prolong by [ids]`. An order that is not yours or has no
active proxies of that type — or an empty list — fails the whole request with `Incorrect orderIds`
(code 29), and nothing is renewed. `quantity` and `items` in the `prolongCalc()` answer show what a
renewal actually covers.

`prolongMake()` answers `orderId`, `orderIds`, `total`, `listBaseOrderNumbers` and `balance`.
`orderIds` lists every renewed order — one request can renew several — and `orderId` is
`orderIds[0]`. `listBaseOrderNumbers` holds one base order number per renewed order (per package
for `mix` / `mix_isp`).

`prolongMake()` throws `ApiException` when the balance is short — the renewal did not happen.
Check the price with `prolongCalc()` first if you want to handle that gracefully.

> **`orderSeparatorIds` and `orderSeparatorId` are gone** from the renewal body — the server no
> longer reads them. Passing either in the options array throws `\InvalidArgumentException` that
> names the replacement, `orderIds`, instead of dropping it silently. The list is
> `Api::PROLONG_REMOVED_FIELDS`.

## Automatic renewal

`prolongMake()` charges you now. `autoprolong/*` only arms a charge that happens later, without you present — a separate branch of the API, not a flag on prolong.

The selection works exactly as in [Renewing proxies](#renewing-proxies): `ids` (proxy ids) or `ips` (addresses) for `ipv4`, `isp` and `mobile` (one kind per call — a mix throws), `orderIds` (order ids) for `ipv6`, `mix` and `mix_isp`, and the removed `orderSeparatorIds` / `orderSeparatorId` are refused by name.

```php
$api->autoProlongCalc('ipv4', ['1.2.3.4'], '1m', ['paymentId' => 'balance']);
$api->autoProlongEnable('ipv4', ['1.2.3.4'], '1m', ['paymentId' => 'balance']);
$api->autoProlongDisable('ipv4', ['1.2.3.4']);

// ipv6 / mix / mix_isp — the whole order at once
$api->autoProlongEnable('ipv6', [$orderId], '1m', ['paymentId' => 'balance']);
$api->autoProlongDisable('ipv6', [$orderId]);
```

`paymentId` is **mandatory** for `calc` and `enable` — the charge happens while you are away, so the payment system cannot be guessed. Only `balance` and `paddle_subscription` are accepted: a one-off Paddle checkout needs a browser redirect a headless client cannot complete. `paddle_subscription` charges the card saved on the account: pass `subscriptionId` only when the account has several saved cards (the server answers `Set [subscriptionId]`), with one card it is picked automatically.

Residential packages renew as a package, not as addresses — send no selection:

```php
$api->autoProlongCalc('resident', [], null, ['paymentId' => 'balance']);
$api->autoProlongEnable('resident', [], null, ['paymentId' => 'balance', 'tarifId' => 'trial']);
$api->autoProlongDisable('resident');
```

**For `resident` pass no selection at all.** A non-empty `$ids` list, or a non-empty `ids`, `ips` or `orderIds` in the options array, throws `\InvalidArgumentException` — "resident auto-prolong applies to the whole package: do not pass proxy or order ids" — before anything is sent. The SDK deliberately does not strip the selection: a `disable` meant for a few addresses would otherwise switch off auto-renewal for the whole package. (The server refuses such a body as well: any of `ids`, `ips` and `orderIds` fails with `[ids] is not applicable for resident: auto-prolong applies to the whole package`.) An empty list is fine, and a period is neither needed nor sent.

Three things about the answers before you parse them:

* **`ids` and `orderIds` are not an echo.** `enable` and `disable` report the proxies actually affected in `ids` (the `id` of `proxyList()`) and their orders in `orderIds`. For `ipv6`, `mix` and `mix_isp` the whole order is switched at once, so `quantity` and `ids` cover every active proxy of the orders you sent. For `resident` both lists are empty and `quantity` is `1`.
* **Not enough money is not an exception.** `calc` answers `status: "error"` with a *filled* `data` and an empty `errors[]` — the same shape `prolong/calc` uses. Read `data['warning']`.
* **Residential fills different fields.** `chargeDate` is null there (a package renews on expiry *or* on traffic exhaustion, so no single date describes it); `tarifId` and `dateEnd` carry the meaning instead, and `days` is the tariff's own period.

`scraper` has no auto-renewal: it is extended by buying traffic through `order/make`.

> Replaces `resident/autorenew/{enable,disable,calculate}`, **removed** from the server.

## Errors

**Business errors arrive with HTTP 200.** The envelope carries them in `errors[]`, so status codes alone tell you nothing. Catch `ApiException` to retain both layers:

```php
try {
    $api->authList();
} catch (ApiException $e) {
    echo $e->getMessage();      // errors[0].message
    echo $e->getApiCode();      // errors[0].code
    echo $e->getHttpStatus();   // usually 200
    var_dump($e->getErrors());  // the WHOLE errors array
    var_dump($e->getData(), $e->getResponseBody());
}
```

The same `ApiException` covers what is not a business error:

- **No answer at all** — a timeout, a refused or dropped connection, a DNS failure. `getHttpStatus()`
  is `0`, `getErrors()` is empty, and the message names the Guzzle exception class and the cURL
  error, e.g. `Transport error (ConnectException): cURL error 28: Operation timed out after 120001
  milliseconds …`. Guzzle exceptions do not escape the SDK, and the raw one is not chained as
  `getPrevious()`: its request carries the URL with your key.
- **An answer without the envelope** — HTML from a load balancer or the front, a framework error
  page. `getHttpStatus()` is the real status, and the message and `getResponseBody()` carry the
  first 500 bytes of the body.
- **A payment or a write that did not report success** — see
  [Timeouts and retries on payments](#timeouts-and-retries-on-payments).

**The API key never shows up in an error.** It is a segment of the URL path, and cURL messages and
some error pages repeat that path — the front even lower-cases it. The SDK replaces the key with
`***` everywhere in an `ApiException` (message, `getResponseBody()`, `getErrors()`, `getData()`),
ignoring case and in its URL-encoded forms too, and `var_dump($api)` / `print_r($api)` show the base
URL masked. The Guzzle client returned by `getClient()` is untouched: its `base_uri` still holds the
key, so do not dump it into logs.

### Access errors are a fixed triple — read the whole array

A bad API key, a caller IP outside the key's allowlist and an exceeded request limit (1000 requests per calendar minute per key) all come back as **HTTP 200** with the same three-element `errors` array:

```php
[
    ['message' => 'Error api key',            'code' => 503],
    ['message' => 'IP not allowed 1.2.3.4',   'code' => 503],
    ['message' => 'Request limit reached',    'code' => 503],
]
```

Because the exception message is built from `errors[0]`, it always reads `Error api key` in all three cases. The API itself never answers this with HTTP 429 — a 429 comes only from the edge in front of it, and the SDK retries it (see [Rate limits and the request queue](#rate-limits-and-the-request-queue)). Never branch on the message alone:

```php
try {
    $api->proxyList('ipv4');
} catch (ApiException $e) {
    if ($e->isAccessError()) {
        // key / IP allowlist / rate limit — the server does not say which, so the SDK
        // does not retry it. Log every message; retry later only if key and IP are right.
        error_log(implode(' | ', $e->getMessages()));
    }
    throw $e;
}
```

### Validation bounds live in `customData`

Some validation errors carry the acceptable values alongside the message. `getCustomData()` returns the `customData` of the first error that has one:

```php
try {
    $api->balanceAutoTopupSet(['amount' => 1]);
} catch (ApiException $e) {
    if ($e->hasApiCode(51)) {                 // top-up amount below minimum
        $limits = $e->getCustomData();        // ['minAmount' => 5]
    }
}
```

Calculation responses (`order/calc`, `prolong/calc`, `autoprolong/calc`) with `status=error`, useful `data`, and an empty `errors` array are returned as warning data instead of causing a parser failure. Inspect `$api->getLastResponseStatus()` if this distinction matters. Payments and writes are strict: for them only `status: "success"` is a success, and the same shape throws `ApiException` — see [Timeouts and retries on payments](#timeouts-and-retries-on-payments).

## Rate limits and the request queue

The API accepts up to 1000 requests per minute per key and answers anything above that with the
[access-error triple](#access-errors-are-a-fixed-triple--read-the-whole-array), which cannot be told
apart from a wrong key or a blocked IP. So the client paces its own requests — by default, with
nothing to set up:

- **One window for everything.** At most `requestsPerMinute` (1000) requests start within any
  60 seconds — reads, writes and payments together. It is a sliding window, not a token bucket, so
  there is no burst above the limit: once 1000 requests have started, the next one waits until the
  oldest of them is 60 seconds old.
- **One lane for writes.** Requests that change something go through a single queue per `Api`
  instance, one at a time. Each starts no earlier than `writeIntervalMs` (1000 ms) after the
  previous write or payment started; a payment also no earlier than `moneyIntervalMs` (2000 ms)
  after the previous payment started. The intervals count from the *start* of the previous request, so a slow
  request does not add to the wait.
- **Reads do not queue.** They wait only for the window — the `*Calc()` methods included: they are
  `POST`, but they change nothing.
- **HTTP 429 is retried.** It comes from the rate limit at the edge in front of the API: the request
  never reached the API, so repeating it is safe, even for a payment. The SDK waits what
  `Retry-After` says (seconds or an HTTP date; 2 seconds when it is missing or unreadable, never
  more than 60 seconds) and sends the same request again, up to `maxRetries` (3) times. After that
  it throws the usual `ApiException`, with `getHttpStatus() === 429`. A retried write keeps its place
  in the lane, every attempt counts against the window, and the next write's intervals count from
  the last attempt.
- **Nothing else is retried.** Code 57, `Prolong for this order is already in progress`, reaches you
  as it is — repeating a renewal automatically could renew the order twice. So does the access-error
  triple, which may just as well mean a wrong key or IP. Transport errors (timeouts, refused or
  dropped connections) are not retried either — not by the SDK and, for payments and writes, not by
  the transport: those always go out on a fresh connection (`CURLOPT_FRESH_CONNECT`). On a reused
  keep-alive connection libcurl silently sends a request again when the connection dies before any
  answer arrives, and the server may already have executed it — before 2.0.1 a single
  `orderMake()` could create two orders that way and report success. Reads still reuse
  connections, so libcurl may re-send one of them, which is harmless.

What goes where — by the endpoint a method calls, not by its HTTP method:

| Kind | Methods |
| --- | --- |
| payment: lane, 2 s apart | `orderMake()` and every `orderMake*()` helper, `prolongMake()`, `balanceAdd()` |
| write: lane, 1 s apart | `autoProlongEnable()`, `autoProlongDisable()`, `authAdd()`, `authAddIp()`, `authChange()`, `authDelete()`, `proxyReplace()`, `proxyCommentSet()`, `balanceAutoTopupSet()`, `residentListAdd()`, `residentListRename()`, `residentListRotation()`, `residentListTools()`, `residentListDelete()`, `residentSubUserCreate()`, `residentSubUserUpdate()`, `residentSubUserDelete()`, `residentSubUserListAdd()`, `residentSubUserListRename()`, `residentSubUserListRotation()`, `residentSubUserListTools()`, `residentSubUserListDelete()` |
| read: window only | everything else — lists and `get` calls, `orderCalc*()`, `prolongCalc()`, `autoProlongCalc()`, `referenceList()`, downloads, `residentGeo*()`, consumption and traffic statistics |

Change the defaults, or switch the queue off, with the `rateLimit` config key:

```php
$api = new Api([
    'key' => 'YOUR_API_KEY',
    'rateLimit' => [
        'requestsPerMinute' => 600,   // default 1000
        'writeIntervalMs'   => 1500,  // default 1000
        'moneyIntervalMs'   => 3000,  // default 2000
        'maxRetries'        => 5,     // retries of HTTP 429, default 3; 0 = none
    ],
]);

// the behaviour from before the queue: no waiting and no retries at all
$api = new Api(['key' => 'YOUR_API_KEY', 'rateLimit' => false]);  // short for ['enabled' => false]
```

`'rateLimit' => false` is short for `['enabled' => false]`, and `'rateLimit' => true` means all
defaults — the same as leaving the key out. In an array, omitted keys keep their defaults. An
unknown key or an invalid value — anything other than an array or a boolean, too — throws
`\InvalidArgumentException` when the client is created, so a typo cannot silently fall back to a
default.

**The queue belongs to one `Api` instance.** Separate instances and separate processes using the
same key know nothing about each other. Under php-fpm or mod_php nothing survives from one web
request to the next, so every request starts with a new, empty queue, and requests served in
parallel run in separate processes. The queue therefore helps scripts, workers and daemons that make
several calls in one process — create the instance once there and reuse it. When several processes share a key they
can still exceed the limits together, and the server may then answer with the access-error triple
or with code 57. Both reach you as `ApiException`; the SDK does not retry them.

**Waiting blocks.** The SDK waits with `usleep()`, so the call simply returns later; there is no
busy-waiting. Calls on one instance run one after another, so writes cannot overlap. If your
transport yields (fibers, an event loop) and a second write starts on the same instance while the
first is still in flight, the second is not sent: it throws `\LogicException`.

For tests, `rateLimit` also accepts `clock` — a callable returning the current time in milliseconds
(monotonic) — and `sleeper`, a callable that receives the milliseconds to wait. Together they let a
test run the queue on fake time.

## Timeouts and retries on payments

`order/make` (every `orderMake*()`), `prolong/make` (`prolongMake()`) and `balance/add`
(`balanceAdd()`) move money. They get their own timeout, `moneyTimeout` — **120 seconds** by
default — because the server builds some orders synchronously: a large MIX order takes about a second
per country. Every other call keeps `timeout`, 30 seconds by default. A payment waits for the longer
of the two, so a short `timeout` never cuts it off; `0` in either means no limit, as in Guzzle.

```php
$api = new Api(['key' => 'YOUR_API_KEY', 'moneyTimeout' => 180]);   // seconds
```

**A timeout, a dropped connection or a 5xx on a payment means the outcome is unknown.** The request
may have reached the server, and the order may have been created and paid for — or the balance
topped up — although no answer came back. The SDK never repeats such a call itself (its only
automatic retry is HTTP 429 from the edge, which means the request never reached the API), and
neither does the transport (see [Rate limits and the request queue](#rate-limits-and-the-request-queue)).
Do not repeat it blindly either — check first:

| Call | Check before repeating |
| --- | --- |
| `orderMake*()` | `orderList(['sort_by' => 'date_insert', 'order' => 'desc', 'limit' => 10])` — is the order there? |
| `prolongMake()` | `proxyList()` — have the end dates moved? — or `orderList(['is_extend' => 'Y'])` |
| `balanceAdd()` | `balance()`; an unpaid payment link charges nothing, so asking for a new one is safe |

What reaches you is always an `ApiException`, and when the outcome is unknown its message says so —
"the request may have been executed — check before retrying":

- `getHttpStatus() === 0` — no answer at all: a timeout, a refused or dropped connection;
- `getHttpStatus() >= 500` — an error page from a gateway in front of the API;
- `Unexpected response (no JSON envelope) …` — a 2xx without the envelope: an HTML maintenance
  page, an empty body, a `204`, broken JSON;
- `Unexpected response: status "error" without errors …` — an envelope that reports neither success
  nor a reason. For a payment or a write only `status: "success"` counts as success.

```php
try {
    $order = $api->orderMakeIpv4('USA', '1m', 1, null, null, 'my target');
} catch (ApiException $e) {
    if (!$e->getErrors() && $e->getHttpStatus() !== 429) {
        // no verdict from the API: the order may exist — look it up with orderList() before retrying
    }
    throw $e;
}
```

A business error — `getErrors()` is not empty, e.g. insufficient funds — and a 429 left after all
retries are answers from the API or its edge: the call was refused. Writes (`auth*()`,
`proxyReplace()`, `autoProlongEnable()` and the rest of the write row above) follow the same strict
rules; an unknown outcome there costs no money, but re-read the state before repeating.

Under php-fpm the web server has limits of its own: nginx's `fastcgi_read_timeout` (60 seconds by
default) or php-fpm's `request_terminate_timeout` can end the PHP request before a 120-second
payment returns — the order then completes on the server while your script never learns about it.
Place orders from a CLI worker or a queue job, or raise those limits.

## Balance and auto top-up

```php
echo $api->balance();                       // float

foreach ($api->balancePaymentsList() as $ps) {
    echo $ps['id'], ' ', $ps['name'], PHP_EOL;   // id is an ObjectId string
}

$url = $api->balanceAdd(25, '66f0c2a1b4d3e5f6a7b8c9d0');
```

`balance/add` accepts **only** `paymentId`. Unlike order and prolong endpoints, it does not resolve a `paymentCode`, so `balanceAdd()` throws `\InvalidArgumentException` when only a code is configured instead of sending `paymentId: null` and returning the opaque `Set existed [paymentId]` — pass the top-up system's id explicitly, as above, even when `setPaymentCode('balance')` is configured for orders. The internal balance itself is not in the payment list — you cannot top up the balance with the balance — and the list is for top-ups only: orders and renewals are paid with `balance` or `paddle_subscription`.

Auto top-up charges a saved Paddle payment method when the balance drops below `threshold`:

```php
$state = $api->balanceAutoTopupGet();
// configured, enabled, state (NO_PAYMENT_METHOD | DISABLED | ACTIVE | PAYMENT_INVALID | PAUSED_FAILURES),
// threshold, amount, subscriptionId, paymentMethod,
// failCount, lastAttemptAt, lastEvent

// Partial update: only the fields you pass are sent, everything else keeps its stored value.
$after = $api->balanceAutoTopupSet(['threshold' => 10]);
$after = $api->balanceAutoTopupSet(['enabled' => false]);
```

Allowed keys are `enabled`, `threshold`, `amount`, `subscriptionId`. Anything else raises `\InvalidArgumentException` locally — the server ignores unknown JSON fields, so a typo such as `daily_count_cap` would otherwise look like a successful call that changed nothing. `set` returns the state *after* saving, so no second `get` is needed.

> **`dailyCountCap` and `monthlyAmountCap` are gone.** Removed from the contract on 2026-08-18: the server silently ignores them and they are absent from the response. The SDK now rejects them by name for exactly the reason above — otherwise `balanceAutoTopupSet(['dailyCountCap' => 3])` would report success and change nothing.

Validation runs server-side on the **merged** result, which means changing one field can fail because of another one that was already stored. Error codes: `49` feature unavailable, `50` threshold below minimum, `51` amount below minimum, `52` amount does not cover the threshold, `53` no saved payment method, `56` saved card expired. Codes `54` and `55` were removed together with the caps and are not reused. Bounds come back in `customData` (`minAmount`, `minThreshold`).

## Proxy replacement

`proxyReplace($ids, $type, $comment)` — `$type` is the **reason** for the replacement, not a proxy type:

```php
$api->proxyReplace(['66f0c2a1b4d3e5f6a7b8c9d0'], 'NOT_WORK');
$api->proxyReplace($ids, 'CUSTOM', 'IPs are blocked by the target site');
```

Valid reasons: `NOT_WORK`, `INCORRECT_LOCATION`, `CANT_CHANGE_NETWORK`, `LOW_SPEED`, `CUSTOM`. `CUSTOM` requires a non-empty `$comment`. Both rules are checked locally before the request leaves.

## Downloads, prolong and resident subusers

- `proxyDownload`, `proxyDownloadResident`, `residentGeo` and `residentGeoIsp` return the exact raw bytes of a file (the server sends them as an attachment, not as an envelope). Pass `true` as their final `$returnStream` argument to receive a PSR-7 stream.
- `residentGeo()` returns a **JSON** file (`geo.json`) with the full geo tree — countries, regions, cities, ISPs — and `residentGeoIsp()` returns `isp.json`. Neither is a zip archive.
- `ext` on the download endpoints is `txt`, `csv` or a custom line template built from `%ip%`, `%port%`, `%login%`, `%user%`, `%password%`, `%protocol%`, `%rotation_link%`. It must be at most 250 characters and must not contain CR, LF, `/` or `\` — the server rejects those with a bare plain-text HTTP 400 outside the envelope, so the SDK validates it first. `ext` may be given positionally or inside the `$filters` array.
- `package_key` works only on `proxyDownload('subresident', ...)`. The literal `/proxy/download/resident` route ignores it and would export the parent package instead, so passing it there throws.
- `prolongCalc` / `prolongMake` take addresses or proxy ids for `ipv4` / `isp` / `mobile` (one kind per call) and order ids (`order_id`) for `ipv6` / `mix` / `mix_isp`, which are renewed only as whole orders, plus a period code — see [Renewing proxies](#renewing-proxies).
- `residentList()` returns `data` as a flat array of lists — there is no `items` wrapper.
- `residentTrafficDetails()` takes the package key as `packageKey` **or** `key` (plus optional `login`, `date_start`, `date_end`). `package_key`, the name used across `residentsubuser/*`, is not accepted there and yields `key is required`.
- `residentPackage()` reports `expired_at` as a string (`d.m.Y H:i:s`), while `residentSubUserPackages()` reports it as a PHP date **object** — read `$item['expired_at']['date']`.
- `residentListAdd` and `residentSubUserListAdd` accept geo, rotation and the resident export object (`['ports' => 1000, 'ext' => 'txt']`).
- Resident subuser create/update support `traffic_limit`, `expired_at`, rotation, active state and `is_link_date`; delete sends the required JSON body.
- Delete endpoints (`residentListDelete`, `residentSubUserDelete`, `residentSubUserListDelete`) return `data` as a string on the wire; the SDK normalizes it to an array. A successful envelope can still say `['status' => 'not-found']`, so check the status.
- `authChange` omits unspecified credentials, so changing only `active` does not send empty login/password/IP fields.

## Local verification

```bash
composer install
composer test          # or: vendor/bin/phpunit
```

The suite is **offline**: `Api` accepts an injected HTTP client through the `client` config key, so the tests drive the SDK with canned envelopes and inspect the request it built. They answer "does the SDK assemble and parse correctly", not "is the server up" — nothing is mocked away that the SDK itself is responsible for. Two transport tests are the exception that proves it: the re-send they guard against is libcurl's own, so they start a small HTTP server on `127.0.0.1` in a separate PHP process (`tests/Support/drop_after_body_server.php`, via `proc_open`) and are skipped without the curl extension.

Every assertion follows the behaviour of the v2 API server, not of this README: docs can drift from the server without anyone noticing, a test cannot. What is covered:

- the api key as a **path segment** (v2 puts it in the URL, not a header) and URL-encoding of it;
- the envelope — business errors arrive with **HTTP 200**, so the status code proves nothing; the deliberately uninformative three-error access triple must stay fully visible;
- local gates that save a round trip on a certain refusal: `customTargetName` for ipv4/ipv6/isp, `balanceAdd` with only a `paymentCode`;
- `X-Fingerprint` sent on `order/make` when configured (or passed per call) and never required — residential and scraper orders go out without it;
- the **split precedence** of `*Id` / `*Code` — the code wins for country/period/payment, the *id* wins for mix/operator/rotation/tarif — and the fact that the raw `orderCalc(array)` form leaves a caller-built body untouched;
- `generateAuth` reaching `order/make` only, since `order/calc` silently drops it;
- auto top-up: the caps removed from the contract on 2026-08-18 are rejected **by name**, partial updates send only what was passed, and `false` / `0` are not mistaken for "unset";
- download routing — a custom `ext` with a slash is legal on `/proxy/download/{type}` but not on the literal `/proxy/download/resident`, which also ignores `package_key`;
- `data: null` inside a `status: "success"` delete being reported as `not-found` rather than as a successful deletion;
- renewal routing by type and shape: addresses into `ips`, proxy ids into `ids` for ipv4/isp/mobile, order ids into `orderIds` for ipv6/mix/mix_isp (whatever the spelling of the type), no empty lists; ids mixed with addresses refused locally for ipv4/isp/mobile, and the removed `orderSeparatorIds` / `orderSeparatorId` refused by name, all before the request;
- auto-renewal: `scraper` refused locally, `paymentId` required for calc and enable but not for disable, the package-shaped residential body, and any residential selection refused rather than stripped;
- the request queue, on a fake clock so no test waits: payments 2 s and writes 1 s apart counted from the previous start, a payment after a write waiting for the later of the two, reads never held by the lane, the sliding window shared by all kinds of requests, HTTP 429 retried after `Retry-After` (2 s by default, 60 s at most, HTTP dates too) and given up after `maxRetries` with status 429, code 57 and the access triple never retried, the disabled mode and the `false` / `true` shorthand, a second write on a busy instance refused, and every SDK method paced by the endpoint it calls;
- no re-send on a dropped connection, on a real socket: the local server reads a payment or a write on a warmed-up keep-alive connection and closes it without answering — the request must arrive exactly once and end in `ApiException`, while a control test shows bare Guzzle sending it twice; `CURLOPT_FRESH_CONNECT` on payments and writes only, merged with the cURL options of the config or of an injected Guzzle client;
- transport errors as `ApiException` with HTTP status 0 and no Guzzle exception chained, and the key masked everywhere an error can be printed — message, string form, body, `errors`, `data`, the SDK frames of the trace — for cURL messages, a Spring 500 echoing the path, the front's lower-cased HTML 404 and URL-encoded keys; bodies cut to 500 bytes after masking; `var_dump()` / `print_r()` of the client;
- strict success for payments and writes — a 2xx without the envelope (HTML, empty, 204, a list, broken JSON) and `status: "error"` without errors throw — while `*/calc` still returns its warning data and reads, downloads and deletes parse as before;
- the payment named in a call outranking `setPaymentId()` / `setPaymentCode()` in every order, renewal and auto-renewal call and in `balanceAdd()`;
- timeouts: payments wait `max(timeout, moneyTimeout)` (120 s by default), everything else `timeout`, `0` means no limit, an injected client's timeout is only raised, and `moneyTimeout` is validated.

To exercise a locally running API server, point the local `baseUrl` shown above at it (port 7995 in that example), use a development API key and call read-only endpoints first (`balance`, `authList`, `residentList`).
