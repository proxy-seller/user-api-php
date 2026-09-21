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
    'fingerprint' => 'my-installation-id',   // required for resident/scraper orders, see below
    'timeout' => 15,
    'connect_timeout' => 5,
]);

echo $api->balance();
```

Nothing else is required — the client talks to `https://proxy-seller.com/personal/api/v2/` by default.

**Paying for orders.** Every order and renewal needs a payment system. Take one from
`balancePaymentsList()` and set it once:

```php
$payments = $api->balancePaymentsList();   // [['id' => '69e7…', 'name' => 'PayPal'], …]
$api->setPaymentId($payments[0]['id']);
```

This is the one place where an id is unavoidable: several payment systems share the same
internal code (a single `cryptomus` covers "USDT (TRC-20)", "All cryptocurrencies" and more),
so the code cannot tell them apart. Everywhere else you use human-readable codes.

<details>
<summary>Pointing the client at another host (local testing)</summary>

```php
$api = new Api(['key' => 'YOUR_API_KEY', 'baseUrl' => 'http://localhost:7995/personal/api/v2/']);
```

`baseUrl` must include `/personal/api/v2/`. You may also inject an already configured
Guzzle-compatible client through the `client` option — useful for a custom transport, tracing,
TLS settings or local stubs.
</details>

## IDs in v2 are strings, not numbers

Every identifier the API returns — `orderId`, IP address ids, auth ids, `paymentId` — is a MongoDB **ObjectId**: a 24-character hex string such as `66f0c2a1b4d3e5f6a7b8c9d0`. Do not cast them to `int`, do not compare them numerically, and store them as strings:

```php
$order = $api->orderMakeIpv4('USA', '1m', 1, null, null, 'my target');

$orderId = $order['orderId'];          // string, keep it as a string
$proxies = $api->proxyList('ipv4', ['orderId' => $orderId]);
```

Casting to `int` truncates an ObjectId to a meaningless number (often `0`), which silently returns the wrong page of data instead of failing.

**One exception:** resident *list* ids are numeric (`Long`), not ObjectIds. They are used by `residentListRename()`, `residentListRotation()`, `residentListDelete()`, `residentSubUserListRename()` and `proxyDownloadResident($id)`.

## Current order API

Every `*Id` argument accepts **either** an ObjectId **or** the matching stable code. The server tries
the value as an id first and falls back to a code lookup when it is not a valid id
(`ClientApiService.normalizeOrderReferenceCodes`). Codes therefore go in **positionally** — there is
no need for a chain of `null`s and an options array just to carry them:

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

Every filter is optional and every name is the snake_case one of v1: `order_id`, `start_date`,
`end_date`, `status`, `is_extend`, `auto_order`, `page`, `limit`, `sort_by`, `order`. The same
endpoint answers legacy-API clients through the reverse mirror, so the spelling is theirs.

`data` is not a flat list but a `metadata` + `items` pair, and `metadata` is always present:
without `limit` it reports `total_pages => 1`, `current_limit => 0` and the whole list in `items`.
`summ` and `items[]['price']` are **strings with the currency already in them** (`'$25.00'`),
`auto_order` and `is_extend` are `'Y'`/`'N'` rather than booleans, and the dates are ISO 8601 with offset (`2026-09-01T14:15:26+00:00`)
strings. `id` is the legacy bitrix number as a string; our ObjectId is `order_id` — the same value
`proxyList()` returns as `order_id`.

### Residential and scraper orders need a fingerprint

`order/make` carries an `X-Fingerprint` header. Most sections ignore it, but **residential and scraper orders are not created without it at all** — the order service answers `Header X-Fingerprint is required` and nothing is ordered.

```php
$api = new Api(['key' => 'YOUR_API_KEY', 'fingerprint' => 'my-installation-id']);
// or later:
$api->setFingerprint('my-installation-id');
// or for a single call:
$api->orderMakeResident('tarif-code', null, ['fingerprint' => 'my-installation-id']);
```

Any opaque string is accepted — the server does not validate its shape — but it must be a **stable identifier of your installation**. The SDK deliberately does not generate one: a value randomized per process would break the anti-fraud and affiliate attribution the header exists for.

Ordering resident or scraper without a fingerprint raises `\InvalidArgumentException` locally, rather than spending a round trip on a request the server is certain to reject.

## Renewing proxies

You renew by the same IP addresses `proxyList()` gave you. No ids, no separators:

```php
$ipv4 = $api->proxyList('ipv4')['items'];
$ips  = array_column($ipv4, 'ip');            // ['1.2.3.4', '5.6.7.8']

$quote = $api->prolongCalc('ipv4', $ips, '1m');   // price first
echo $quote['total'];

$order = $api->prolongMake('ipv4', $ips, '1m');   // deducts money
echo $order['orderId'];
```

What to send per type, and which `proxyList()` field it is built from:

| Type | Pass this | Built from |
| --- | --- | --- |
| `ipv4`, `isp`, `mix`, `mix_isp` | the plain address, `"1.2.3.4"` | `ip` |
| `ipv6` | the address, `"host:port"` — e.g. `"1.2.3.4:26000"` | `ip` |
| `mobile` | `"ip:port_http:port_socks"`, e.g. `"1.2.3.4:50100:50101"` | `ip`, `port_http`, `port_socks` |

For `ipv6` the `ip` field already holds the gateway together with the port
(`"1.2.3.4:26000"`), while `ip_only` holds the bare gateway — so pass `ip` as it comes, exactly
like every other type.

`prolongMake()` throws `ApiException` when the balance is short — the renewal did not happen.
Check the price with `prolongCalc()` first if you want to handle that gracefully.

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
| `paymentId` | payment-system ObjectId — the one unavoidable id | `balance/payments/list` → `items[].id` (see "Paying for orders" above) |

ObjectIds are still accepted everywhere if you happen to have them; the reference simply no longer
publishes them. Code resolution happens in `order/calc`, `order/make`, `prolong/calc` and
`prolong/make`.

`ipv4`, `ipv6` and `isp` orders need a goal (`customTargetName`) — what you use the proxies for. The
SDK checks it locally so the server does not have to answer `Incorrect goal` (code 14). A `mix` order
needs one only when the server cannot tell which package you mean, so naming the package removes the
need for it.

For a fully custom payload use `orderCalc(array $payload)` or `orderMake(array $payload)`.

## Automatic renewal

`prolongMake()` charges you now. `autoprolong/*` only arms a charge that happens later, without you present — a separate branch of the API, not a flag on prolong.

```php
$api->autoProlongCalc('ipv4', ['1.2.3.4'], '1m', ['paymentId' => 'balance']);
$api->autoProlongEnable('ipv4', ['1.2.3.4'], '1m', ['paymentId' => 'balance']);
$api->autoProlongDisable('ipv4', ['1.2.3.4']);
```

`paymentId` is **mandatory** for `calc` and `enable` — the charge happens while you are away, so the payment system cannot be guessed. Only `balance` and `paddle_subscription` are accepted: a one-off Paddle checkout needs a browser redirect a headless client cannot complete. With `paddle_subscription` also pass `subscriptionId`.

Residential packages renew as a package, not as addresses — send no selection:

```php
$api->autoProlongCalc('resident', [], null, ['paymentId' => 'balance']);
$api->autoProlongEnable('resident', [], null, ['paymentId' => 'balance', 'tarifId' => 'trial']);
$api->autoProlongDisable('resident');
```

Three things about the answers before you parse them:

* **`ids` is not an echo.** For `ipv6` the whole order is switched at once, so `quantity` and `ids` can cover more proxies than you sent.
* **Not enough money is not an exception.** `calc` answers `status: "error"` with a *filled* `data` and an empty `errors[]` — the same shape `prolong/calc` uses. Read `data['warning']`.
* **Residential fills different fields.** `days` and `chargeDate` are null there (a package renews on expiry *or* on traffic exhaustion, so no single date describes it); `tarifId` and `dateEnd` carry the meaning instead.

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

### Access errors are a fixed triple — read the whole array

A bad API key, a caller IP outside the key's allowlist and an exceeded request limit (1000 requests per calendar minute per key) all come back as **HTTP 200** with the same three-element `errors` array:

```php
[
    ['message' => 'Error api key',            'code' => 503],
    ['message' => 'IP not allowed 1.2.3.4',   'code' => 503],
    ['message' => 'Request limit reached',    'code' => 503],
]
```

Because the exception message is built from `errors[0]`, it always reads `Error api key` in all three cases. There is **no HTTP 429**. Never branch on the message alone:

```php
try {
    $api->proxyList('ipv4');
} catch (ApiException $e) {
    if ($e->isAccessError()) {
        // key / IP allowlist / rate limit — the server does not say which.
        // Log every message, then back off and retry rather than treating it as a hard failure.
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

Calculation/prolong responses with `status=error`, useful `data`, and an empty `errors` array are returned as warning data instead of causing a parser failure. Inspect `$api->getLastResponseStatus()` if this distinction matters.

## Balance and auto top-up

```php
echo $api->balance();                       // float

foreach ($api->balancePaymentsList() as $ps) {
    echo $ps['id'], ' ', $ps['name'], PHP_EOL;   // id is an ObjectId string
}

$url = $api->balanceAdd(25, '66f0c2a1b4d3e5f6a7b8c9d0');
```

`balance/add` accepts **only** `paymentId`. Unlike order and prolong endpoints, it does not resolve a `paymentCode`, so `balanceAdd()` throws `\InvalidArgumentException` when only a code is configured instead of sending `paymentId: null` and returning the opaque `Set existed [paymentId]`. The internal balance itself is not in the payment list — you cannot top up the balance with the balance.

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
- `prolongCalc` / `prolongMake` take the IP addresses from `proxyList()` and a period code — see [Renewing proxies](#renewing-proxies). ObjectIds are still accepted if you happen to have them, and MIX renewals are expanded to the whole package server-side, so there is nothing extra to pass.
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

The suite is **offline**: `Api` accepts an injected HTTP client through the `client` config key, so the tests drive the SDK with canned envelopes and inspect the request it built. They answer "does the SDK assemble and parse correctly", not "is the server up" — nothing is mocked away that the SDK itself is responsible for.

Every assertion mirrors the behaviour of `client-api-service`, not of this README: docs can drift from the server without anyone noticing, a test cannot. What is covered:

- the api key as a **path segment** (v2 puts it in the URL, not a header) and URL-encoding of it;
- the envelope — business errors arrive with **HTTP 200**, so the status code proves nothing; the deliberately uninformative three-error access triple must stay fully visible;
- local gates that save a round trip on a certain refusal: `customTargetName` for ipv4/ipv6/isp, `balanceAdd` with only a `paymentCode`, `X-Fingerprint` for residential and scraper orders;
- the **split precedence** of `*Id` / `*Code` — the code wins for country/period/payment, the *id* wins for mix/operator/rotation/tarif — and the fact that the raw `orderCalc(array)` form leaves a caller-built body untouched;
- `generateAuth` reaching `order/make` only, since `order/calc` silently drops it;
- auto top-up: the caps removed from the contract on 2026-08-18 are rejected **by name**, partial updates send only what was passed, and `false` / `0` are not mistaken for "unset";
- download routing — a custom `ext` with a slash is legal on `/proxy/download/{type}` but not on the literal `/proxy/download/resident`, which also ignores `package_key`;
- `data: null` inside a `status: "success"` delete being reported as `not-found` rather than as a successful deletion;
- renewal by the addresses `proxyList()` returns, routed into `ips` / `ids` by shape;
- auto-renewal: `scraper` refused locally, `paymentId` required for calc and enable but not for disable, and the package-shaped residential body.

To exercise a local `client-api-service`, run it on port 7995, configure the local `baseUrl` shown above, use a development API key and call read-only endpoints first (`balance`, `authList`, `residentList`).
