# Proxy-Seller Client API SDK for PHP

This package is a small Client API v2 client. It builds requests, adds the API key, parses the common `{status,data,errors}` envelope and supports raw/binary responses.

Breaking changes against 1.x are listed in [CHANGELOG.md](CHANGELOG.md).

## Install

```sh
composer require proxy-seller/user-api-php
```

## Configuration

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use ProxySeller\Userapi\Api;
use ProxySeller\Userapi\ApiException;

$api = new Api([
    'key' => 'YOUR_API_KEY',
    // The default is https://proxy-seller.com/personal/api/v2/
    'baseUrl' => 'http://127.0.0.1:7995/personal/api/v2/',
    'timeout' => 15,
    'connect_timeout' => 5,
]);

$api->setPaymentCode('balance'); // stable across prod/dev
echo $api->balance();
```

`baseUrl` must include `/personal/api/v2/`. You may inject an already configured Guzzle-compatible client through the `client` option; this is useful for a custom transport, tracing, TLS settings or local stubs.

`setPaymentId()` remains available, but stable codes are safer when database IDs differ between environments. Two caveats: codes are resolved on order/prolong endpoints only (see [Balance and auto top-up](#balance-and-auto-top-up)), and `balance/payments/list` returns ids and names but no codes — see [What you can pass, and where to get it](#what-you-can-pass-and-where-to-get-it).

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
// $operator = ['id' => '66f0…', 'name' => '…', 'rotations' => [['id' => 5, 'name' => '5 minutes'], …]]

$mobile = $api->orderCalcMobile(
    'USA',              // countryId: ObjectId or country code (alpha3)
    '1m',               // periodId: ObjectId or period code
    1,                  // quantity
    null,               // authorization — optional
    null,               // coupon — optional
    $operator['id'],    // operatorId: ObjectId (or the operator tag, which the reference never returns)
    5,                  // rotationId: MINUTES, 0 = By Link. Not a code — '5m' is rejected
    'dedicated'         // shared or dedicated; required for mobile
);

$uptime = $api->orderCalcIpv4('USA', '1m', 1, null, null, 'my target', ['uptime' => true]);

$mixId = $api->referenceList('mix')['items']['quantities'][0]['id'];
$mix   = $api->orderCalcMix(null, '1m', 10, null, null, null, ['mixId' => $mixId]);
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

`reference/list` does **not** return a code for every field. Where it does not, the ObjectId from the
reference is the only value you can discover programmatically:

| Argument | Accepts | Where the value comes from |
| --- | --- | --- |
| `countryId` | ObjectId **or** country code — upper-cased server-side | `reference/list` → `country[].id`; the code is `country[].alpha3` — the one code the reference really gives you |
| `periodId` | ObjectId **or** period code — lower-cased server-side | `reference/list` → `period[].id` only. **No code in the response** — `1m`/`3m` are values you already know, not something you can read from the reference |
| `operatorId` | ObjectId **or** operator tag — exact match | `reference/list/mobile` → `country[].operators.dedicated[].id` / `.shared[].id`. **The tag is not returned** |
| `rotationId` | **Minutes, integer.** `0` = By Link. Codes do not exist here | `reference/list/mobile` → `country[].operators.*[].rotations[].id` — that id *is* the minute count (`name` is `"5 minutes"` / `"By Link"`) |
| `mixId` (options) | ObjectId **or** package tag — exact match. The `countryId` shortcut (`"packageId:quantity"`) takes the **ObjectId only**, it is looked up by id | `reference/list/mix` → `quantities[].id`. The tag appears only as `country[].tag` of the same section, next to the same id |
| `tarifId` | ObjectId **or** tariff code — exact match | `reference/list/resident` → `items.tarifs[].id` (plus `name`). **No code field** |
| `paymentId` / `setPaymentCode()` | ObjectId **or** payment code (`PaymentSystem.code`, or a type name such as `balance`) — on order/prolong endpoints only | `balance/payments/list` → `items[].id`. **No code field**, and `balance/add` resolves neither |

Codes are resolved by `order/calc`, `order/make`, `prolong/calc` and `prolong/make` only. Every other
endpoint takes ids.

`ipv4`, `ipv6`, `isp` and an unresolved `mix` order require a goal (`customTargetName`); the SDK checks this locally so the server does not have to answer `Incorrect goal` (code 14). A `mix` order counts as resolved without a goal when `mixId`/`mixCode` is set, or `countryId` holds a `packageId:quantity` pair, or `countryId` comes together with `quantity > 0`.

For a fully custom payload use `orderCalc(array $payload)` or `orderMake(array $payload)`.

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
// threshold, amount, subscriptionId, paymentMethod, dailyCountCap, monthlyAmountCap,
// failCount, lastAttemptAt, lastEvent

// Partial update: only the fields you pass are sent, everything else keeps its stored value.
$after = $api->balanceAutoTopupSet(['threshold' => 10]);
$after = $api->balanceAutoTopupSet(['enabled' => false]);
```

Allowed keys are `enabled`, `threshold`, `amount`, `subscriptionId`, `dailyCountCap`, `monthlyAmountCap`. Anything else raises `\InvalidArgumentException` locally — the server ignores unknown JSON fields, so a typo such as `daily_count_cap` would otherwise look like a successful call that changed nothing. `set` returns the state *after* saving, so no second `get` is needed.

Validation runs server-side on the **merged** result, which means changing one field can fail because of another one that was already stored. Error codes: `49` feature unavailable, `50` threshold below minimum, `51` amount below minimum, `52` amount does not cover the threshold, `53` no saved payment method, `54` `dailyCountCap` below minimum, `55` `monthlyAmountCap` below a single top-up amount, `56` saved card expired. Bounds come back in `customData` (`minAmount`, `minThreshold`, `minDailyCountCap`).

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
- `prolongCalc` / `prolongMake` resolve codes the same way as the order endpoints, so the positional `$periodId` takes a period code as well: `prolongCalc('ipv4', $ids, '1m')`. The final options array adds `orderSeparatorIds`, `orderSeparatorId`, and the `periodCode` / `paymentCode` twins.
- `residentList()` returns `data` as a flat array of lists — there is no `items` wrapper.
- `residentTrafficDetails()` takes the package key as `packageKey` **or** `key` (plus optional `login`, `date_start`, `date_end`). `package_key`, the name used across `residentsubuser/*`, is not accepted there and yields `key is required`.
- `residentPackage()` reports `expired_at` as a string (`d.m.Y H:i:s`), while `residentSubUserPackages()` reports it as a PHP date **object** — read `$item['expired_at']['date']`.
- `residentListAdd` and `residentSubUserListAdd` accept geo, rotation and the resident export object (`['ports' => 1000, 'ext' => 'txt']`).
- Resident subuser create/update support `traffic_limit`, `expired_at`, rotation, active state and `is_link_date`; delete sends the required JSON body.
- Delete endpoints (`residentListDelete`, `residentSubUserDelete`, `residentSubUserListDelete`) return `data` as a string on the wire; the SDK normalizes it to an array. A successful envelope can still say `['status' => 'not-found']`, so check the status.
- `authChange` omits unspecified credentials, so changing only `active` does not send empty login/password/IP fields.

## Local verification

```powershell
composer install
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
composer dump-autoload
```

To exercise a local `client-api-service`, run it on port 7995, configure the local `baseUrl` shown above, use a development API key and call read-only endpoints first (`balance`, `authList`, `residentList`).
