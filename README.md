# proxy-seller php api

Install from [packagist.org](https://packagist.org/packages/proxy-seller/user-api-php)
```sh
composer require proxy-seller/user-api-php:dev-master
```

Or manual install from [proxy-seller/user-api-php](https://github.com/proxy-seller/user-api-php) repository
```sh
git clone https://github.com/proxy-seller/user-api-php
cd user-api-php
composer install
```

## Quick start
Get API key [here](https://proxy-seller.com/personal/api/)
```php
require __DIR__ . '/vendor/autoload.php';
$api = new \ProxySeller\Userapi\Api(['key' => 'YOUR_API_KEY']);
//$api->setPaymentId(43);
//$api->setGenerateAuth('Y');
echo $api->balance();
```

## Changelog
```
22.01.2024
Breaking changes:
! remove $targetId and $targetSectionId from all calc/make requests
! add $listId into proxyDownload method

New methods:
+ setPaymentId() - used in all calc/make requests (payment id=1(inner balance), id=43(subscribed card))
+ setGenerateAuth() - used in all calc/make requests (Y/N, default N)

+ authList
+ authActive
+ orderCalcResident
+ orderMakeResident
+ residentPackage
+ residentGeo
+ residentList
+ residentListRename
+ residentListDelete
```

## Methods available:
* authList
* authActive
* balance
* balanceAdd
* balancePaymentsList
* referenceList
* orderCalcIpv4
* orderCalcIsp
* orderCalcMix
* orderCalcIpv6
* orderCalcMobile
* orderCalcResident
* orderMakeIpv4
* orderMakeIsp
* orderMakeMix
* orderMakeIpv6
* orderMakeMobile
* orderMakeResident
* prolongCalc
* prolongMake
* proxyList
* proxyDownload
* proxyCommentSet
* proxyCheck
* ping
* residentPackage
* residentGeo
* residentList
* residentListRename
* residentListDelete