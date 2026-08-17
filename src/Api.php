<?php

namespace ProxySeller\Userapi;

/**
 * Клиент Proxy-Seller Client API v2 (https://proxy-seller.com/personal/api/v2/, apiKey В ПУТИ).
 *
 * Что важно знать про контракт v2:
 *  - Все ответы завёрнуты в {status, data, errors}. HTTP почти всегда 200, в том числе на
 *    ошибках — их надо искать в errors[], а не в статусе ответа.
 *  - Идентификаторы (orderId, ipAddressId, paymentId, id авторизаций) — ObjectId-СТРОКИ по 24
 *    hex-символа. Приводить их к int/float нельзя. Единственное исключение — id резидентских
 *    листов: они числовые (Long).
 *  - Ошибки доступа (битый ключ, IP не в allowlist, превышение 1000 запросов в минуту)
 *    приходят HTTP 200 с ФИКСИРОВАННОЙ тройкой errors, где errors[0].message всегда
 *    "Error api key" (LegacyClientApiErrorResponseAdvice). HTTP 429 не существует. Что именно
 *    случилось, по errors[0] не понять — смотрите весь массив через ApiException::getErrors().
 *  - proxy/download/*, resident/geo и resident/geo/isp отдают ФАЙЛ (attachment), а не конверт.
 *
 * @see ApiException
 */
class Api {

    static $URL = 'https://proxy-seller.com/personal/api/v2/';

    /**
     * Причины замены для proxy/replace (поле type). Это НЕ тип прокси: сервер разбирает
     * значение через ProxyReplaceType.fromString() (регистр не важен) и при CUSTOM требует
     * непустой comment — см. ClientApiService.replaceProxies.
     */
    const REPLACE_TYPES = ['NOT_WORK', 'INCORRECT_LOCATION', 'CANT_CHANGE_NETWORK', 'LOW_SPEED', 'CUSTOM'];

    /**
     * Поля тела balance/autotopup/set (AutoTopupSetRequestClientDto). Partial update:
     * отправляем только реально переданные ключи, опущенные сервер берёт из сохранённых
     * настроек (AutoTopupService.saveSettings мержит присланное поверх существующего).
     */
    const AUTO_TOPUP_FIELDS = ['enabled', 'threshold', 'amount', 'subscriptionId', 'dailyCountCap', 'monthlyAmountCap'];

    protected $client;
    protected $requestBaseUri;
    protected $paymentId = null;
    protected $paymentCode = null;
    protected $generateAuth = 'N';
    protected $lastResponseStatus = null;

    /**
     * Key placed in https://proxy-seller.com/personal/api/
     * @param array $config
     * @throws \Exception
     */
    public function __construct($config = []) {
        $key = isset($config['key']) ? $config['key'] : null;
        if (!$key) {
            throw new \Exception("Need key, placed in https://proxy-seller.com/personal/api/");
        }

        $baseUrl = isset($config['baseUrl']) ? $config['baseUrl'] :
                (isset($config['base_url']) ? $config['base_url'] : static::$URL);
        $injectedClient = isset($config['client']) ? $config['client'] : null;
        unset($config['key'], $config['baseUrl'], $config['base_url'], $config['client']);

        $this->requestBaseUri = rtrim($baseUrl, '/') . '/' . rawurlencode($key) . '/';

        if ($injectedClient !== null) {
            if (!is_object($injectedClient) || !method_exists($injectedClient, 'request')) {
                throw new \InvalidArgumentException('client must provide a request() method');
            }
            $this->client = $injectedClient;
            return;
        }

        if (!isset($config['timeout'])) {
            $config['timeout'] = 30;
        }
        if (!isset($config['connect_timeout'])) {
            $config['connect_timeout'] = 10;
        }
        $config['base_uri'] = $this->requestBaseUri;
        $this->client = new \GuzzleHttp\Client($config);
    }

    public function getClient() {
        return $this->client;
    }

    public function getPaymentId() {
        return $this->paymentId;
    }

    public function getPaymentCode() {
        return $this->paymentCode;
    }

    public function getGenerateAuth() {
        return $this->generateAuth;
    }

    public function getLastResponseStatus() {
        return $this->lastResponseStatus;
    }

    /**
     * Payment system id (MongoDB ObjectId from balance/payments/list).
     * На order/prolong сервер принимает здесь и код (тот же фолбэк, что для paymentCode),
     * но balance/add коды не резолвит — для него нужен именно ObjectId.
     * @param string $paymentId ObjectId, or a payment code on the order/prolong endpoints
     * @return void
     */
    public function setPaymentId($paymentId): void {
        $this->paymentId = $paymentId;
        if ($paymentId !== null) {
            $this->paymentCode = null;
        }
    }

    /**
     * Stable payment system code, for example "balance".
     * Codes are preferred to environment-specific MongoDB ids.
     * Значение — PaymentSystem.code либо имя типа (balance, paddle_subscription): в
     * balance/payments/list кода нет, оттуда приходят только id и name.
     * Резолвится только на order/calc, order/make, prolong/calc и prolong/make.
     * @param string $paymentCode
     * @return void
     */
    public function setPaymentCode($paymentCode): void {
        $this->paymentCode = $paymentCode;
        if ($paymentCode !== null) {
            $this->paymentId = null;
        }
    }

    /**
     * Generate new auths Y/N, default N.
     * Only applied to order/make, the order/calc endpoint ignores the field.
     * @param string $yn
     * @return void
     */
    public function setGenerateAuth($yn): void {
        $this->generateAuth = ($yn == 'Y' ? "Y" : "N");
    }

    /**
     * Send request into server
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return mixed
     * @throws \Exception
     */
    protected function request($method, $uri, $options = [], $returnStream = false) {
        if (!isset($options['http_errors'])) {
            $options['http_errors'] = false;
        }

        $response = $this->client->request(
            $method,
            $this->requestBaseUri . ltrim($uri, '/'),
            $options
        );
        $body = (string) $response->getBody();
        $httpStatus = (int) $response->getStatusCode();
        $httpOk = $httpStatus >= 200 && $httpStatus < 300;
        $json = \json_decode($body, true);

        // Конверт client-api — это status в виде строки ("success"/"error"). У дефолтной
        // ошибки Spring Boot тоже есть ключ status, но числовой (400) — принимать её за
        // конверт нельзя, иначе настоящая причина теряется.
        if (is_array($json) && array_key_exists('status', $json) && is_string($json['status'])) {
            $this->lastResponseStatus = $json['status'];
            $data = array_key_exists('data', $json) ? $json['data'] : null;
            $errors = isset($json['errors']) && is_array($json['errors']) ? $json['errors'] : [];

            if ($httpOk && $json['status'] === 'success') {
                return $data;
            }

            // Calculation endpoints use status=error + data + an empty errors list
            // for actionable warnings such as an insufficient balance.
            if ($httpOk && !$errors && $data !== null) {
                return $data;
            }

            $this->throwApiException($errors, $data, $httpStatus, $body);
        }

        // Some validation handlers return a single ApiError rather than an envelope.
        if (is_array($json) && isset($json['message']) && isset($json['code'])) {
            $this->throwApiException([$json], null, $httpStatus, $body);
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $message = $body !== '' ? $body : 'Client API returned HTTP ' . $httpStatus;
            throw new ApiException($message, 0, $httpStatus, [], null, $body);
        }

        $this->lastResponseStatus = null;
        if ($returnStream) {
            return \GuzzleHttp\Psr7\Utils::streamFor($body);
        }
        return $body;
    }

    protected function throwApiException(array $errors, $data, $httpStatus, $body) {
        $first = $errors ? reset($errors) : [];
        $message = isset($first['message']) ? $first['message'] : 'Client API error';
        $apiCode = isset($first['code']) ? $first['code'] : 0;
        throw new ApiException($message, $apiCode, $httpStatus, $errors, $data, $body);
    }

    protected function requestRaw($method, $uri, $options = [], $returnStream = false) {
        return $this->request($method, $uri, $options, $returnStream);
    }

    /**
     * Drop null values, used for optional query filters
     * @param array $params
     * @return array
     */
    protected function filterNull($params) {
        return array_filter($params, function ($v) {
            return $v !== null;
        });
    }

    /**
     * ext длиннее 250 символов либо с CR/LF/'/'/'\' сервер отклоняет ГОЛЫМ HTTP 400 с
     * plain-text телом, мимо конверта {status,data,errors}
     * (ResidentUserApiService.downloadProxyList). Поэтому проверяем на клиенте.
     *
     * ext — это либо txt, либо csv, либо свой шаблон строки с плейсхолдерами
     * %ip% %port% %login% %user% %password% %protocol% %rotation_link%.
     * @param string $ext
     * @return string
     * @throws \Exception
     */
    protected function assertExt($ext) {
        if ($ext === null) {
            return null;
        }
        if (strlen($ext) > 250) {
            throw new \Exception("ext is too long (max 250)");
        }
        if (preg_match('#[\r\n/\\\\]#', $ext)) {
            throw new \Exception("ext contains forbidden characters");
        }
        return $ext;
    }

    /**
     * proxy/replace: type — это ПРИЧИНА замены, а не тип прокси. Сервер сначала резолвит её
     * через ProxyReplaceType.fromString() (нераспознанное значение → ошибка
     * "Set coorect type: ...") и только для CUSTOM требует непустой comment
     * (ClientApiService.replaceProxies). Повторяем проверку локально, чтобы не платить
     * сетевым запросом за очевидную опечатку.
     *
     * @param string $type
     * @param string $comment
     * @return string нормализованное (верхний регистр) значение
     * @throws \InvalidArgumentException
     */
    protected function assertReplaceType($type, $comment) {
        $normalized = strtoupper(trim((string) $type));
        if ($normalized === '') {
            throw new \InvalidArgumentException(
                'proxy/replace: type is a replacement reason and is required, one of ' . implode(', ', self::REPLACE_TYPES)
            );
        }
        if (!in_array($normalized, self::REPLACE_TYPES, true)) {
            throw new \InvalidArgumentException(
                'proxy/replace: unknown type "' . $type . '", expected one of ' . implode(', ', self::REPLACE_TYPES)
            );
        }
        if ($normalized === 'CUSTOM' && trim((string) $comment) === '') {
            throw new \InvalidArgumentException('proxy/replace: comment is required when type is CUSTOM');
        }
        return $normalized;
    }

    /**
     * Тело balance/autotopup/set. Сервер делает PARTIAL UPDATE: saveSettings мержит присланное
     * поверх сохранённого, и «поле не пришло» для него равно «поле = null». Полагаться на это
     * равенство не будем — в JSON кладём ТОЛЬКО реально переданные ключи. Так запрос честно
     * описывает намерение, и его видно в логах: набор из шести null неотличим от «ничего не
     * меняем», а сериализованный null на любом поле, где сервер однажды перестанет считать его
     * «не прислали», молча затёр бы настройку.
     *
     * Неизвестные ключи отбиваем: Spring Boot по умолчанию игнорирует лишние поля JSON, то есть
     * опечатка (daily_count_cap вместо dailyCountCap) на сервере прошла бы как успешный запрос,
     * который ничего не изменил.
     *
     * Границы значений (минимальная сумма/порог/лимит) НЕ проверяем локально: их владелец —
     * AutoTopupService.saveSettings, а конкретные допустимые числа приходят в
     * errors[0].customData (minAmount / minThreshold / minDailyCountCap).
     *
     * @param array $settings
     * @return array
     * @throws \InvalidArgumentException
     */
    protected function prepareAutoTopupSettings($settings) {
        if ($settings === null) {
            $settings = [];
        }
        if (!is_array($settings)) {
            throw new \InvalidArgumentException('balance/autotopup/set: settings must be an array');
        }

        $unknown = array_diff(array_keys($settings), self::AUTO_TOPUP_FIELDS);
        if ($unknown) {
            throw new \InvalidArgumentException(
                'balance/autotopup/set: unknown field(s) ' . implode(', ', $unknown)
                . '; allowed: ' . implode(', ', self::AUTO_TOPUP_FIELDS)
            );
        }

        $json = [];
        foreach (self::AUTO_TOPUP_FIELDS as $field) {
            if (!array_key_exists($field, $settings) || $settings[$field] === null) {
                continue;
            }
            $value = $settings[$field];

            if ($field === 'enabled') {
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException('balance/autotopup/set: enabled must be a boolean');
                }
                $json[$field] = $value;
                continue;
            }
            if ($field === 'subscriptionId') {
                if (!is_string($value) || trim($value) === '') {
                    throw new \InvalidArgumentException('balance/autotopup/set: subscriptionId must be a non-empty string');
                }
                $json[$field] = trim($value);
                continue;
            }
            if ($field === 'dailyCountCap') {
                if (is_bool($value) || !is_numeric($value) || (int) $value != $value) {
                    throw new \InvalidArgumentException('balance/autotopup/set: dailyCountCap must be an integer');
                }
                $json[$field] = (int) $value;
                continue;
            }
            // threshold / amount / monthlyAmountCap — BigDecimal на сервере. Значение отдаём
            // как передали (int/float/числовая строка): строку Jackson тоже принимает, а
            // приведение к float на больших суммах теряло бы точность.
            if (is_bool($value) || !is_numeric($value)) {
                throw new \InvalidArgumentException('balance/autotopup/set: ' . $field . ' must be a number');
            }
            $json[$field] = $value;
        }

        if (!$json) {
            throw new \InvalidArgumentException(
                'balance/autotopup/set: nothing to update, pass at least one of ' . implode(', ', self::AUTO_TOPUP_FIELDS)
            );
        }
        return $json;
    }

    /////////////////////////////// Auth ///////////////////////////////

    /**
     * Get auths
     * @return array Returns list auths
     */
    function authList() {
        return $this->request('GET', 'auth/list');
    }

    /**
     * Create login/password authorization
     * @param string $orderNumber
     * @param string $generateAuth Y/N
     * @return array Created auth
     */
    function authAdd($orderNumber, $generateAuth = 'N') {
        return $this->request('POST', 'auth/add', ['json' => compact('orderNumber', 'generateAuth')]);
    }

    /**
     * Create IP authorization
     * @param string $orderNumber
     * @param string $ip
     * @return array Created auth
     */
    function authAddIp($orderNumber, $ip) {
        return $this->request('POST', 'auth/add/ip', ['json' => compact('orderNumber', 'ip')]);
    }

    /**
     * Change authorization.
     * Replaces the v1 auth/active method, the active flag is a boolean now.
     * @param string $id auth id
     * @param boolean $active active state
     * @param string $login
     * @param string $password
     * @param string $ip
     * @return array Returns current auth
     */
    function authChange($id, $active, $login = null, $password = null, $ip = null) {
        return $this->request('POST', 'auth/change', ['json' => $this->filterNull(compact('id', 'active', 'login', 'password', 'ip'))]);
    }

    /**
     * Delete authorization
     * @param string $id auth id
     * @return array
     */
    function authDelete($id) {
        return $this->request('DELETE', 'auth/delete', ['json' => compact('id')]);
    }

    /////////////////////////////// Balance ///////////////////////////////

    /**
     * Get balance statistic
     * @return float
     */
    function balance() {
        return $this->request('GET', 'balance/get')['summ'];
    }

    /**
     * Replenish the balance.
     *
     * ВАЖНО: здесь работает ТОЛЬКО paymentId (ObjectId-строка из balance/payments/list).
     * paymentCode, который понимают order/* и prolong/*, на этом эндпоинте не резолвится —
     * ClientApiService.addBalance не вызывает normalizeOrderReferenceCodes и сверяет
     * dto.paymentId со списком доступных платёжек напрямую.
     *
     * @param float $summ минимальная сумма настраивается на сервере (Property
     *                    client_api_balance_add_min_summ, дефолт 1); сумма, равная минимуму, проходит
     * @param string $paymentId ObjectId-строка из balance/payments/list
     * @return string Returns a link to the payment page
     * @throws \InvalidArgumentException если задан только paymentCode
     */
    function balanceAdd($summ = 5, $paymentId = null) {
        if ($paymentId === null) {
            $paymentId = $this->getPaymentId();
        }
        if ($paymentId === null && $this->getPaymentCode() !== null) {
            // Раньше в этом случае на сервер уезжал paymentId=null и приходила глухая
            // "Set existed [paymentId]" — при том что paymentCode у клиента задан и он ждёт,
            // что тот сработает как в order/make.
            throw new \InvalidArgumentException(
                'balance/add does not resolve paymentCode ("' . $this->getPaymentCode() . '"): '
                . 'this endpoint accepts only paymentId. Take the id from balancePaymentsList() '
                . 'and pass it as the second argument or via setPaymentId().'
            );
        }
        return $this->request('POST', 'balance/add', ['json' => compact('summ', 'paymentId')])['url'];
    }

    /**
     * List of payment systems for balance replenishing.
     * id — ObjectId-строка, БАЛАНС в списке отсутствует (балансом баланс не пополняют).
     * @return array [['id' => '...', 'name' => '...'], ...]
     */
    function balancePaymentsList() {
        return $this->request('GET', 'balance/payments/list')['items'];
    }

    /**
     * Текущая конфигурация и состояние авто-пополнения баланса.
     *
     * Отдаёт data-объект AutoTopupStateClientDto:
     *   configured        boolean — есть ли сохранённые настройки
     *   enabled           boolean
     *   state             string  — NO_PAYMENT_METHOD | DISABLED | ACTIVE | PAYMENT_INVALID | PAUSED_FAILURES
     *   threshold         number  — списываем, когда баланс становится меньше этого значения
     *   amount            number  — сумма одного автопополнения
     *   subscriptionId    string|null — закреплённая в настройках подписка Paddle
     *   paymentMethod     array|null  — ['id','status','paymentMethod','brand','last4','exp']
     *   dailyCountCap     int     — действующий лимит числа списаний в сутки
     *   monthlyAmountCap  number  — действующий лимит суммы за 30 дней
     *   failCount         int     — подряд идущие неудачные попытки
     *   lastAttemptAt     string|null
     *   lastEvent         array|null — ['status','amount','at','reason'],
     *                                  status: TRIGGERED|SUCCEEDED|FAILED|SKIPPED_CAP|SETTINGS_SAVED|PAUSED
     *
     * Если фича выключена на сервере (Property enabled_autopopup_balance), приходит ошибка
     * "Auto top-up is not available" с кодом 49.
     *
     * @return array
     */
    function balanceAutoTopupGet() {
        return $this->request('GET', 'balance/autotopup/get');
    }

    /**
     * Включить/выключить авто-пополнение или поправить его порог, сумму и лимиты.
     *
     * PARTIAL UPDATE: передавайте только те поля, которые меняете — опущенные сервер берёт из
     * сохранённых настроек. Допустимые ключи (AutoTopupSetRequestClientDto):
     *   enabled          boolean
     *   threshold        number — баланс, ниже которого срабатывает списание
     *   amount           number — сумма одного автопополнения; должна покрывать threshold
     *   subscriptionId   string — подписка Paddle из paymentMethod.id (или subscriptionId) ответа get
     *   dailyCountCap    int    — свой лимит числа списаний в сутки
     *   monthlyAmountCap number — свой лимит суммы за 30 дней
     *
     * Валидация серверная и применяется к РЕЗУЛЬТАТУ мержа, поэтому правка одного поля может
     * упасть из-за уже сохранённого другого. Коды ошибок: 49 фича выключена, 50 порог меньше
     * минимума, 51 сумма меньше минимума, 52 сумма не покрывает порог, 53 нет привязанного
     * способа оплаты, 54 dailyCountCap меньше минимума, 55 monthlyAmountCap меньше amount,
     * 56 карта истекла. Граничные значения приходят в errors[0].customData
     * (minAmount / minThreshold / minDailyCountCap) — см. ApiException::getCustomData().
     *
     * На успехе отдаёт состояние ПОСЛЕ сохранения, в том же виде, что balanceAutoTopupGet().
     *
     * @param array $settings подмножество полей выше
     * @return array
     * @throws \InvalidArgumentException при неизвестном поле, неверном типе или пустом наборе
     */
    function balanceAutoTopupSet($settings = []) {
        return $this->request('POST', 'balance/autotopup/set', ['json' => $this->prepareAutoTopupSettings($settings)]);
    }

    /////////////////////////////// Order ///////////////////////////////

    /**
     * Necessary guides for creating an order
     *
     * Форма ответа: referenceList('mobile') отдаёт ['items' => <объект раздела>], а
     * referenceList() без типа — карту разделов, ['ipv4' => <объект>, 'mobile' => <объект>, ...].
     *
     * Что реально лежит в объекте раздела:
     *   country[]     id, name, alpha3 — alpha3 это ЕДИНСТВЕННЫЙ код, который отдаёт справочник
     *   period[]      id, name — кода периода здесь нет
     *   mobile        country[].operators.{dedicated,shared}[] → id, name,
     *                 rotations[{id, name}], где rotations[].id — это МИНУТЫ (0 = "By Link");
     *                 тега оператора в ответе нет
     *   mix           quantities[] → id, name, quantities[]; тег пакета виден только как
     *                 country[].tag того же раздела
     *   resident      список тарифов → id, name; кода тарифа нет
     * Кода платёжной системы нет и в balance/payments/list — там только id и name.
     *
     * @param string $type - ipv4 | ipv6 | mobile | isp | mix | resident | null
     * @return array
     */
    function referenceList($type = null) {
        return $this->request('GET', $type === null ? 'reference/list' : 'reference/list/' . rawurlencode($type));
    }

    /**
     * Calculate the order IPv4
     * @param string $countryId ObjectId or country code (alpha3, e.g. "USA")
     * @param string $periodId ObjectId or period code (e.g. "1m")
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName required for ipv4 (see assertTargetName)
     * @param array $options fields without a positional argument (uptime, generateAuth, *Code twins)
     * @return array
     */
    function orderCalcIpv4($countryId, $periodId, $quantity, $authorization = null, $coupon = null, $customTargetName = null, $options = []) {
        return $this->orderCalc($this->prepareRegular('ipv4', $countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $options));
    }

    /**
     * Calculate the order ISP
     * @param string $countryId ObjectId or country code (alpha3, e.g. "USA")
     * @param string $periodId ObjectId or period code (e.g. "1m")
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName required for isp (see assertTargetName)
     * @param array $options fields without a positional argument (uptime, generateAuth, *Code twins)
     * @return array
     */
    function orderCalcIsp($countryId, $periodId, $quantity, $authorization = null, $coupon = null, $customTargetName = null, $options = []) {
        return $this->orderCalc($this->prepareRegular('isp', $countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $options));
    }

    /**
     * Calculate the order MIX
     * @param string $countryId ObjectId, country code, or the MIX package: its ObjectId (with
     *                          quantity) or "packageObjectId:quantity". A package TAG works only
     *                          through mixId/mixCode — parseMixSelection looks countryId up by id
     * @param string $periodId ObjectId or period code (e.g. "1m")
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName not needed once the MIX package is resolved (see isMixResolved)
     * @param array $options mixId/mixCode live here — there is no positional argument for them
     * @return array
     */
    function orderCalcMix($countryId, $periodId, $quantity, $authorization = null, $coupon = null, $customTargetName = null, $options = []) {
        return $this->orderCalc($this->prepareRegular('mix', $countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $options));
    }

    /**
     * Calculate the order IPv6
     * @param string $countryId ObjectId or country code (alpha3, e.g. "USA")
     * @param string $periodId ObjectId or period code (e.g. "1m")
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName required for ipv6 (see assertTargetName)
     * @param string $protocol HTTPS | SOCKS5
     * @param array $options fields without a positional argument (uptime, generateAuth, *Code twins)
     * @return array
     */
    function orderCalcIpv6($countryId, $periodId, $quantity, $authorization = null, $coupon = null, $customTargetName = null, $protocol = null, $options = []) {
        return $this->orderCalc($this->prepareIpv6($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $protocol, $options));
    }

    /**
     * Calculate the order Mobile
     *
     * Коды передаются ПОЗИЦИОННО, цепочка null и options ради них не нужны:
     *   orderCalcMobile('USA', '1m', 1, null, null, $operatorId, 5, 'dedicated');
     * Оставшиеся null здесь — законные необязательные authorization и coupon.
     *
     * @param string $countryId ObjectId or country code (alpha3, e.g. "USA")
     * @param string $periodId ObjectId or period code (e.g. "1m")
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $operatorId ObjectId or operator tag; reference/list publishes only the ObjectId
     * @param integer $rotationId rotation in MINUTES (0 = By Link). Not a code — "5m" is rejected
     * @param string $mobileServiceType shared | dedicated, required for mobile
     * @param array $options fields without a positional argument (generateAuth, *Code twins)
     * @return array
     */
    function orderCalcMobile($countryId, $periodId, $quantity, $authorization = null, $coupon = null, $operatorId = null, $rotationId = null, $mobileServiceType = 'dedicated', $options = []) {
        return $this->orderCalc($this->prepareMobile($countryId, $periodId, $quantity, $authorization, $coupon, $operatorId, $rotationId, $mobileServiceType, $options));
    }

    /**
     * Calculate the order Resident
     * @param string $tarifId ObjectId or tariff code; reference/list publishes only the ObjectId
     * @param string $coupon
     * @param array $options fields without a positional argument (generateAuth, paymentCode, ...)
     * @return array
     */
    function orderCalcResident($tarifId, $coupon = null, $options = []) {
        return $this->orderCalc($this->prepareResident($tarifId, $coupon, $options));
    }

    /**
     * Create an order IPv4. Attention! Deducts money from the balance.
     * @param string $countryId ObjectId or country code (alpha3, e.g. "USA")
     * @param string $periodId ObjectId or period code (e.g. "1m")
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName required for ipv4 (see assertTargetName)
     * @param array $options fields without a positional argument (uptime, generateAuth, *Code twins)
     * @return array
     */
    function orderMakeIpv4($countryId, $periodId, $quantity, $authorization = null, $coupon = null, $customTargetName = null, $options = []) {
        return $this->orderMake($this->withGenerateAuth($this->prepareRegular('ipv4', $countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $options)));
    }

    /**
     * Create an order ISP. Attention! Deducts money from the balance.
     * @param string $countryId ObjectId or country code (alpha3, e.g. "USA")
     * @param string $periodId ObjectId or period code (e.g. "1m")
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName required for isp (see assertTargetName)
     * @param array $options fields without a positional argument (uptime, generateAuth, *Code twins)
     * @return array
     */
    function orderMakeIsp($countryId, $periodId, $quantity, $authorization = null, $coupon = null, $customTargetName = null, $options = []) {
        return $this->orderMake($this->withGenerateAuth($this->prepareRegular('isp', $countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $options)));
    }

    /**
     * Create an order MIX. Attention! Deducts money from the balance.
     * @param string $countryId ObjectId, country code, or the MIX package: its ObjectId (with
     *                          quantity) or "packageObjectId:quantity". A package TAG works only
     *                          through mixId/mixCode — parseMixSelection looks countryId up by id
     * @param string $periodId ObjectId or period code (e.g. "1m")
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName not needed once the MIX package is resolved (see isMixResolved)
     * @param array $options mixId/mixCode live here — there is no positional argument for them
     * @return array
     */
    function orderMakeMix($countryId, $periodId, $quantity, $authorization = null, $coupon = null, $customTargetName = null, $options = []) {
        return $this->orderMake($this->withGenerateAuth($this->prepareRegular('mix', $countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $options)));
    }

    /**
     * Create an order IPv6. Attention! Deducts money from the balance.
     * @param string $countryId ObjectId or country code (alpha3, e.g. "USA")
     * @param string $periodId ObjectId or period code (e.g. "1m")
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName required for ipv6 (see assertTargetName)
     * @param string $protocol HTTPS | SOCKS5
     * @param array $options fields without a positional argument (uptime, generateAuth, *Code twins)
     * @return array
     */
    function orderMakeIpv6($countryId, $periodId, $quantity, $authorization = null, $coupon = null, $customTargetName = null, $protocol = null, $options = []) {
        return $this->orderMake($this->withGenerateAuth($this->prepareIpv6($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $protocol, $options)));
    }

    /**
     * Create an order Mobile. Attention! Deducts money from the balance.
     *
     * Пример: orderMakeMobile('USA', '1m', 1, null, null, $operatorId, 5, 'dedicated');
     *
     * @param string $countryId ObjectId or country code (alpha3, e.g. "USA")
     * @param string $periodId ObjectId or period code (e.g. "1m")
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $operatorId ObjectId or operator tag; reference/list publishes only the ObjectId
     * @param integer $rotationId rotation in MINUTES (0 = By Link). Not a code — "5m" is rejected
     * @param string $mobileServiceType shared | dedicated, required for mobile
     * @param array $options fields without a positional argument (generateAuth, *Code twins)
     * @return array
     */
    function orderMakeMobile($countryId, $periodId, $quantity, $authorization = null, $coupon = null, $operatorId = null, $rotationId = null, $mobileServiceType = 'dedicated', $options = []) {
        return $this->orderMake($this->withGenerateAuth($this->prepareMobile($countryId, $periodId, $quantity, $authorization, $coupon, $operatorId, $rotationId, $mobileServiceType, $options)));
    }

    /**
     * Create an order Resident. Attention! Deducts money from the balance.
     * @param string $tarifId ObjectId or tariff code; reference/list publishes only the ObjectId
     * @param string $coupon
     * @param array $options fields without a positional argument (generateAuth, paymentCode, ...)
     * @return array
     */
    function orderMakeResident($tarifId, $coupon = null, $options = []) {
        return $this->orderMake($this->withGenerateAuth($this->prepareResident($tarifId, $coupon, $options)));
    }

    protected function prepareRegular($sectionCode, $countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $options = []) {
        $request = array_merge(
            $this->paymentFields(),
            compact('sectionCode', 'countryId', 'periodId', 'quantity', 'authorization', 'coupon', 'customTargetName')
        );
        return $this->applyOrderOptions($request, $options);
    }

    protected function prepareIpv6($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $protocol, $options = []) {
        $sectionCode = 'ipv6';
        $request = array_merge(
            $this->paymentFields(),
            compact('sectionCode', 'countryId', 'periodId', 'quantity', 'authorization', 'coupon', 'customTargetName', 'protocol')
        );
        return $this->applyOrderOptions($request, $options);
    }

    protected function prepareMobile($countryId, $periodId, $quantity, $authorization, $coupon, $operatorId, $rotationId, $mobileServiceType = 'dedicated', $options = []) {
        $sectionCode = 'mobile';
        $request = array_merge(
            $this->paymentFields(),
            compact('sectionCode', 'countryId', 'periodId', 'quantity', 'authorization', 'coupon', 'operatorId', 'rotationId', 'mobileServiceType')
        );
        return $this->applyOrderOptions($request, $options);
    }

    protected function prepareResident($tarifId, $coupon, $options = []) {
        $sectionCode = 'resident';
        $request = array_merge(
            $this->paymentFields(),
            compact('sectionCode', 'tarifId', 'coupon')
        );
        return $this->applyOrderOptions($request, $options);
    }

    protected function paymentFields() {
        if ($this->getPaymentCode() !== null) {
            return ['paymentCode' => $this->getPaymentCode()];
        }
        return ['paymentId' => $this->getPaymentId()];
    }

    protected function normalizeOptions($options) {
        if ($options === null) {
            return [];
        }
        if (is_bool($options)) {
            return ['uptime' => $options];
        }
        if (!is_array($options)) {
            throw new \InvalidArgumentException('options must be an array');
        }
        return $options;
    }

    /**
     * Мержит options в тело заказа и убирает *Id, если пришёл парный непустой *Code.
     *
     * Как сервер разбирает id и коды (ClientApiService.normalizeOrderReferenceCodes):
     *  - countryId, periodId, operatorId, mixId, tarifId, paymentId принимают ObjectId ЛИБО код:
     *    если значение не является валидным id, а парный *Code пуст, сервер резолвит его КАК КОД.
     *    Значит код можно передавать позиционно прямо в *Id-аргумент — options и цепочка null
     *    ради этого не нужны. Регистр: countryId/countryCode приводится к UPPER, periodId/periodCode
     *    к lower, operatorId (tag), mixId (tag) и tarifId (code) сравниваются точно.
     *  - rotationId — это ЧИСЛО МИНУТ (0 = By Link), кодов у ротации не существует.
     *    rotationCode — единственное поле без резолва: сервер требует целое число и копирует его
     *    в rotationId как есть, поэтому строка вида "5m" в rotationCode гарантированно даёт
     *    "Set existed [rotationCode] from reference". Передавайте минуты числом в rotationId.
     *  - Коды резолвятся только на order/calc, order/make, prolong/calc и prolong/make. Остальные
     *    эндпоинты (в том числе balance/add) принимают исключительно id.
     *
     * @param array $request
     * @param array|boolean|null $options
     * @return array
     */
    protected function applyOrderOptions($request, $options) {
        $allowed = [
            'countryId', 'countryCode', 'sectionCode', 'periodId', 'periodCode',
            'coupon', 'paymentId', 'paymentCode', 'quantity', 'authorization',
            'customTargetName', 'mixId', 'mixCode', 'uptime', 'protocol',
            'mobileServiceType', 'operatorId', 'operatorCode', 'rotationId',
            'rotationCode', 'tarifId', 'tarifCode', 'generateAuth'
        ];
        $options = array_intersect_key($this->normalizeOptions($options), array_flip($allowed));
        $request = array_merge($request, $options);

        $pairs = [
            'countryCode' => 'countryId',
            'periodCode' => 'periodId',
            'paymentCode' => 'paymentId',
            'mixCode' => 'mixId',
            'operatorCode' => 'operatorId',
            'rotationCode' => 'rotationId',
            'tarifCode' => 'tarifId'
        ];
        foreach ($pairs as $code => $id) {
            if (array_key_exists($code, $options) && $options[$code] !== null) {
                unset($request[$id]);
            }
        }

        return $this->filterNull($request);
    }

    /**
     * generateAuth is accepted by order/make only, order/calc silently drops it
     * @param array $json
     * @return array
     */
    protected function withGenerateAuth($json) {
        if (!array_key_exists('generateAuth', $json)) {
            $json['generateAuth'] = $this->getGenerateAuth();
        }
        return $json;
    }

    /**
     * Calculate the order
     * @param array $json Free format array to send into endpoint
     * @return array
     */
    function orderCalc($json) {
        $this->assertTargetName($json);
        return $this->request('POST', 'order/calc', ['json' => $json]);
    }

    /**
     * Create an order.
     * data: ['orderId' => ObjectId-СТРОКА, 'total' => number,
     *        'listBaseOrderNumbers' => [...], 'balance' => number].
     * orderId нельзя приводить к int — это 24-символьный ObjectId
     * (OrderMakeResponseClientDto.OrderMakeDataClientDto.orderId).
     * @param array $json Free format array to send into endpoint
     * @return array
     */
    function orderMake($json) {
        $this->assertTargetName($json);
        return $this->request('POST', 'order/make', ['json' => $json]);
    }

    /**
     * Повторяет проверку цели из client-api v1: для ipv4/ipv6/isp заказ без цели не принимается.
     * В v1 цель задавалась targetId+targetSectionId либо своим текстом, в v2 остался только
     * customTargetName. Для mix проверка не нужна, если передан mixId/mixCode — иначе сервер
     * резолвит тип в ipv4, и цель снова обязательна.
     *
     * Проверяем локально, чтобы не платить сетевым запросом за "Incorrect goal" (код 14).
     * @param array $json
     * @throws \InvalidArgumentException
     */
    protected function assertTargetName($json) {
        $section = isset($json['sectionCode']) ? $json['sectionCode'] : null;
        if (!in_array($section, ['ipv4', 'ipv6', 'isp', 'mix', 'mix_isp'], true)) {
            return;
        }
        if ($this->isMixResolved($section, $json)) {
            return;
        }
        if (isset($json['customTargetName']) && trim((string) $json['customTargetName']) !== '') {
            return;
        }
        throw new \InvalidArgumentException(
            "customTargetName is required for {$section} orders (client api returns \"Incorrect goal\", code 14)"
        );
    }

    /**
     * Повторяет ClientApiService.parseMixSelection: сервер распознаёт mix не только по
     * mixId/mixCode, но и через countryId — строкой "packageId:quantity" либо
     * countryId=packageId вместе с quantity. Если mix распознан, requiresClientApiGoal
     * возвращает false и цель НЕ требуется.
     *
     * Раньше проверялись только mixId/mixCode, из-за чего legacy-путь orderCalcMix/orderMakeMix
     * (пакет уезжает в countryId) блокировался локально и запрос вообще не уходил.
     * Сомнительные случаи трактуем в пользу отправки: лишний сетевой запрос дешевле отказа
     * SDK на валидном заказе.
     *
     * @param string|null $section
     * @param array $json
     * @return boolean
     */
    protected function isMixResolved($section, $json) {
        if (!in_array($section, ['mix', 'mix_isp'], true)) {
            return false;
        }
        foreach (['mixId', 'mixCode'] as $key) {
            if (isset($json[$key]) && trim((string) $json[$key]) !== '') {
                return true;
            }
        }
        $countryId = isset($json['countryId']) ? trim((string) $json['countryId']) : '';
        if ($countryId === '') {
            return false;
        }
        if (strpos($countryId, ':') !== false) {
            return true;
        }
        return isset($json['quantity']) && (int) $json['quantity'] > 0;
    }

    /**
     * data delete-эндпоинтов приходит СТРОКОЙ, а не объектом:
     * resident/list/delete → "delete", residentsubuser/delete и residentsubuser/list/delete
     * → JSON внутри строки (например {"status":"not-found"} при конверте status="success").
     * Докблоки обещают array, поэтому клиентский код на PHP 8 падал на обращении к строке
     * как к массиву, а неудавшееся удаление было неотличимо от успешного.
     *
     * @param mixed $data
     * @return array
     */
    protected function normalizeDeleteResult($data) {
        if (is_array($data)) {
            return $data;
        }
        if ($data === null) {
            return [];
        }
        if (is_string($data)) {
            $trimmed = trim($data);
            if ($trimmed !== '' && $trimmed[0] === '{') {
                $parsed = json_decode($trimmed, true);
                if (is_array($parsed)) {
                    return $parsed;
                }
            }
            return ['status' => $trimmed];
        }
        return ['status' => $data];
    }

    /////////////////////////////// Prolong ///////////////////////////////

    protected function prepareProlong($ids, $periodId, $coupon, $options = []) {
        $request = array_merge(
            $this->paymentFields(),
            compact('ids', 'periodId', 'coupon')
        );
        $allowed = [
            'ids', 'orderSeparatorIds', 'orderSeparatorId', 'periodId',
            'periodCode', 'coupon', 'paymentId', 'paymentCode'
        ];
        $options = array_intersect_key($this->normalizeOptions($options), array_flip($allowed));
        $request = array_merge($request, $options);
        if (array_key_exists('periodCode', $options) && $options['periodCode'] !== null) {
            unset($request['periodId']);
        }
        if (array_key_exists('paymentCode', $options) && $options['paymentCode'] !== null) {
            unset($request['paymentId']);
        }
        return $this->filterNull($request);
    }

    /**
     * Calculate the renewal
     * @param string $type - ipv4 | ipv6 | mobile | isp | mix
     * @param array $ids
     * @param string $periodId ObjectId or period code (e.g. "1m")
     * @param string $coupon
     * @param array $options orderSeparatorIds, orderSeparatorId, periodCode, paymentCode
     * @return array
     */
    function prolongCalc($type, $ids, $periodId, $coupon = '', $options = []) {
        return $this->request('POST', 'prolong/calc/' . rawurlencode($type), ['json' => $this->prepareProlong($ids, $periodId, $coupon, $options)]);
    }

    /**
     * Create a renewal order. Attention! Deducts money from the balance.
     * @param string $type - ipv4 | ipv6 | mobile | isp | mix | mix_isp
     * @param array $ids
     * @param string $periodId ObjectId or period code (e.g. "1m")
     * @param string $coupon
     * @param array $options orderSeparatorIds, orderSeparatorId, periodCode, paymentCode
     * @return array ['orderId' => ObjectId-строка, 'total' => …, 'balance' => …, 'listBaseOrderNumbers' => []]
     * @throws ApiException при нехватке средств — продление НЕ состоялось
     */
    function prolongMake($type, $ids, $periodId, $coupon = '', $options = []) {
        return $this->assertProlongMade(
            $this->request('POST', 'prolong/make/' . rawurlencode($type), ['json' => $this->prepareProlong($ids, $periodId, $coupon, $options)])
        );
    }

    /**
     * При нехватке средств prolong/make отдаёт конверт status="error" с ПУСТЫМ errors[] и
     * calc-данными в data (ProlongMakeResponseClientDto::ofInsufficientFunds,
     * ClientApiService.groovy:3185) — то есть ровно ту же форму, что легитимный warning у
     * prolong/calc. Из-за этого общая ветка разбора конверта возвращала данные как успех, и
     * несостоявшееся продление выглядело как состоявшееся. Признак провала — отсутствие
     * orderId (на успехе это ProlongMakeDataClientDto с непустым orderId).
     *
     * order/make этой проблемы не имеет: у OrderMakeResponseClientDto есть только
     * ofSuccess/ofError, и при ошибке errors[] всегда заполнен.
     *
     * @param mixed $data
     * @return array
     * @throws ApiException
     */
    protected function assertProlongMade($data) {
        if (!is_array($data)) {
            return $data;
        }
        $orderId = isset($data['orderId']) ? trim((string) $data['orderId']) : '';
        if ($orderId !== '') {
            return $data;
        }
        $warning = isset($data['warning']) ? trim((string) $data['warning']) : '';
        throw new ApiException(
            $warning !== '' ? $warning : 'prolong/make did not create an order (insufficient funds)',
            0,
            200,
            [],
            $data,
            ''
        );
    }

    /////////////////////////////// Proxy ///////////////////////////////

    /**
     * List of proxies
     * @param string $type - ipv4 | ipv6 | mobile | isp | mix | resident | null
     * @param array $filters latest | orderId | country | ends | page | per_page.
     *                       orderId — ObjectId-СТРОКА (не число), country — код страны
     * @return array
     */
    function proxyList($type = null, $filters = []) {
        $uri = $type === null ? 'proxy/list' : 'proxy/list/' . rawurlencode($type);
        return $this->request('GET', $uri, ['query' => $this->filterNull($filters)]);
    }

    /**
     * Proxy export of certain type. Возвращает ФАЙЛ (attachment), не конверт JSON.
     *
     * @param string $type - ipv4 | ipv6 | mobile | isp | mix | resident | subresident
     * @param string $ext - txt | csv | свой шаблон с плейсхолдерами | ''
     * @param string $proto - https | socks5 | '' (всё остальное отдаёт оба порта)
     * @param string $listId - only for resident, if not set - will return ip from all sheets
     * @param array $filters package_key | country | ends | ext.
     *                       package_key работает ТОЛЬКО при $type = 'subresident': литеральный
     *                       маршрут /proxy/download/resident его вообще не принимает
     *                       (ResidentUserController.downloadProxyList знает только listId/id/ext/maxLine),
     *                       а ветку package_key в ClientApiService.getProxyDownload видит лишь
     *                       typeKey == "subresident".
     * @return string|\Psr\Http\Message\StreamInterface
     * @throws \InvalidArgumentException при package_key на $type = 'resident'
     */
    function proxyDownload($type, $ext = null, $proto = null, $listId = null, $filters = [], $returnStream = false) {
        if ($filters === null) {
            $filters = [];
        }
        if (!is_array($filters)) {
            throw new \InvalidArgumentException('filters must be an array');
        }

        if (strtolower(trim((string) $type)) === 'resident'
            && isset($filters['package_key'])
            && trim((string) $filters['package_key']) !== '') {
            // Иначе тихо выгружался бы РОДИТЕЛЬСКИЙ пакет: сервер параметр игнорирует и
            // отдаёт валидный файл, отличить его от выгрузки субпакета клиент не может.
            throw new \InvalidArgumentException(
                "package_key is ignored by /proxy/download/resident; use proxyDownload('subresident', ...) to export a subpackage"
            );
        }

        // ext может приезжать и позиционным аргументом, и внутри $filters. Раньше значение из
        // $filters затиралось позиционным (обычно null) и до сервера не доходило вообще —
        // выгрузка молча уходила в дефолтный формат и валидацию длины/символов не проходила.
        if ($ext === null && array_key_exists('ext', $filters)) {
            $ext = $filters['ext'];
        }
        unset($filters['ext']);

        $query = $this->filterNull(array_merge(compact('proto', 'listId'), $filters));
        $validatedExt = $this->assertExt($ext);
        if ($validatedExt !== null) {
            $query['ext'] = $validatedExt;
        }
        return $this->requestRaw('GET', 'proxy/download/' . rawurlencode($type), ['query' => $query], $returnStream);
    }

    /**
     * Export the resident proxy list. Возвращает ФАЙЛ (attachment), не конверт JSON.
     * @param string|integer $id list id — числовой id листа (резидентские листы, в отличие от
     *                           остальных сущностей v2, живут под Long-идентификаторами)
     * @param string $ext - txt | csv | свой шаблон с плейсхолдерами
     * @param integer $maxLine
     * @return string|\Psr\Http\Message\StreamInterface
     */
    function proxyDownloadResident($id = null, $ext = null, $maxLine = null, $returnStream = false) {
        $ext = $this->assertExt($ext);
        return $this->requestRaw('GET', 'proxy/download/resident', ['query' => $this->filterNull(compact('id', 'ext', 'maxLine'))], $returnStream);
    }

    /**
     * Replace proxy IPs.
     *
     * @param array|string $ids ObjectId-строки адресов (или один id)
     * @param string $type ПРИЧИНА замены, а не тип прокси:
     *                     NOT_WORK | INCORRECT_LOCATION | CANT_CHANGE_NETWORK | LOW_SPEED | CUSTOM.
     *                     Сервер подставляет её в комментарий заявки на замену.
     * @param string $comment необязателен, КРОМЕ type = CUSTOM — там обязателен и непустой
     * @return array карта статусов вида ['replaced' => ['ips' => [...], 'msg' => '...'], ...]
     * @throws \InvalidArgumentException при неизвестной причине или пустом comment для CUSTOM
     */
    function proxyReplace($ids, $type = null, $comment = null) {
        $type = $this->assertReplaceType($type, $comment);
        return $this->request('POST', 'proxy/replace', ['json' => compact('ids', 'type', 'comment')]);
    }

    /**
     * Set proxy comment
     * @param array $ids Any id, regardless of the type of proxy (ObjectId-строки)
     * @param string $comment
     * @return integer Count updated proxy
     */
    function proxyCommentSet($ids, $comment = null) {
        return $this->request('POST', 'proxy/comment/set', ['json' => compact('ids', 'comment')])['updated'];
    }

    /////////////////////////////// Resident ///////////////////////////////

    /**
     * Package Information. Remaining traffic, end date.
     * expired_at здесь — СТРОКА в легаси-формате d.m.Y H:i:s (toLegacyExpiredAt); у субпакетов
     * это, наоборот, объект PHP-даты, см. residentSubUserPackages().
     * @return array
     */
    function residentPackage() {
        return $this->request('GET', 'resident/package');
    }

    /**
     * Traffic consumption of the resident package.
     * Читаются только login, date_start, date_end — пакет сервер берёт сам, по владельцу
     * apiKey (ResidentUserApiService.getConsumption), ключ пакета в фильтре не участвует.
     * @param array $filter например ['login' => '...', 'date_start' => '2026-08-01']
     * @return array
     */
    function residentConsumption($filter = []) {
        // Пустой PHP-массив json_encode превращает в [], а Spring ждёт объект и отвечает
        // голым HTTP 400 мимо конверта ошибок. Документированный вызов без аргументов
        // из-за этого гарантированно падал.
        return $this->request('POST', 'resident/consumption', ['json' => $filter ? $filter : new \stdClass()]);
    }

    /**
     * Detailed traffic statistics of the resident package.
     *
     * Ключ пакета в этом фильтре называется packageKey ЛИБО key (сервер читает
     * request.packageKey ?: request.key) — package_key, как в residentsubuser/*, здесь НЕ
     * работает и приводит к ошибке "key is required". Остальные ключи: login, date_start, date_end.
     *
     * @param array $filter например ['key' => 'PACKAGE_KEY', 'date_start' => '2026-08-01']
     * @return array
     */
    function residentTrafficDetails($filter = []) {
        // См. residentConsumption: пустой массив уезжает как [] и ломает запрос.
        return $this->request('POST', 'resident/traffic/details', ['json' => $filter ? $filter : new \stdClass()]);
    }

    /**
     * Database geo locations. Отдаёт ФАЙЛ geo.json (attachment, application/json) —
     * это НЕ zip: сервер сериализует полную гео-структуру
     * (страны -> регионы -> города -> ISP) прямо в JSON, см. ResidentUserApiService.downloadGeoFile.
     * @return string|\Psr\Http\Message\StreamInterface сырые байты JSON-файла
     */
    function residentGeo($returnStream = false) {
        return $this->requestRaw('GET', 'resident/geo', [], $returnStream);
    }

    /**
     * Database of ISP codes. Отдаёт ФАЙЛ isp.json (attachment, application/json), не zip.
     * @return string|\Psr\Http\Message\StreamInterface сырые байты JSON-файла
     */
    function residentGeoIsp($returnStream = false) {
        return $this->requestRaw('GET', 'resident/geo/isp', [], $returnStream);
    }

    /**
     * Number of available IPs by geo
     * @return array
     */
    function residentGeoCount() {
        return $this->request('GET', 'resident/geo/count');
    }

    /**
     * List of existing ip list in a package.
     * data приходит ПЛОСКИМ массивом листов — враппера items у этого эндпоинта нет.
     * id листа числовой (Long), в отличие от ObjectId-строк остальной части v2.
     * @return array
     */
    function residentList() {
        return $this->request('GET', 'resident/lists');
    }

    /**
     * Create list in package
     * @param string $title
     * @param string $whitelist comma separated ip list
     * @param string $country
     * @param string $region
     * @param string $city
     * @param string $isp
     * @param integer $rotation -1 sticky, 0 per request, 1-3600 seconds
     * @return array Created list model
     */
    function residentListAdd($title, $whitelist = null, $country = null, $region = null, $city = null, $isp = null, $rotation = null, $export = null) {
        $geo = $this->filterNull(compact('country', 'region', 'city', 'isp'));
        $json = $this->filterNull(compact('title', 'whitelist', 'rotation', 'export'));
        // Пустой PHP-массив json_encode превращает в [], а сервер ждёт объект geo и отвечает
        // голым HTTP 400 мимо конверта ошибок. Приводим к объекту явно.
        $json['geo'] = (object) $geo;
        return $this->request('POST', 'resident/list/add', ['json' => $json]);
    }

    /**
     * Rename list in user package
     * @param integer $id - listId
     * @param string $title
     * @return array Updated list model
     */
    function residentListRename($id, $title = null) {
        return $this->request('POST', 'resident/list/rename', ['json' => compact('id', 'title')]);
    }

    /**
     * Change the rotation interval of a list
     * @param integer $id - listId
     * @param integer $rotation -1 sticky, 0 per request, 1-3600 seconds
     * @return array Updated list model
     */
    function residentListRotation($id, $rotation) {
        return $this->request('POST', 'resident/list/rotation', ['json' => compact('id', 'rotation')]);
    }

    /**
     * Create the tools list for the package
     * @return array
     */
    function residentListTools() {
        return $this->request('PUT', 'resident/list/tools');
    }

    /**
     * Remove list from user package
     * @param integer $id - listId
     * @return array ['status' => 'delete']
     */
    function residentListDelete($id) {
        return $this->normalizeDeleteResult(
            $this->request('DELETE', 'resident/list/delete', ['json' => compact('id')])
        );
    }

    /////////////////////////////// Resident subpackages ///////////////////////////////

    /**
     * Create a resident subpackage.
     * В ОТВЕТЕ expired_at приходит объектом PHP-даты ['date' => ..., 'timezone_type' => ...,
     * 'timezone' => ...] (SubPackageDto.expired_at = PhpDateDto), хотя в ЗАПРОСЕ это строка.
     * @param boolean $is_link_date
     * @param integer $rotation
     * @param string $traffic_limit
     * @param string $expired_at строка даты
     * @return array
     */
    function residentSubUserCreate($is_link_date = null, $rotation = null, $traffic_limit = null, $expired_at = null) {
        // См. residentConsumption: при всех null filterNull даёт пустой массив, который
        // json_encode превращает в [], и Spring отвечает голым HTTP 400 мимо конверта.
        $json = $this->filterNull(compact('is_link_date', 'rotation', 'traffic_limit', 'expired_at'));
        return $this->request('POST', 'residentsubuser/create', ['json' => $json ? $json : new \stdClass()]);
    }

    /**
     * Update a resident subpackage.
     * В ответе expired_at — объект PHP-даты, см. residentSubUserPackages().
     * @param string $package_key
     * @param boolean $is_link_date
     * @param integer $rotation
     * @param string $traffic_limit
     * @param boolean $is_active
     * @param string $expired_at строка даты
     * @return array
     */
    function residentSubUserUpdate($package_key, $is_link_date = null, $rotation = null, $traffic_limit = null, $is_active = null, $expired_at = null) {
        return $this->request('POST', 'residentsubuser/update', ['json' => $this->filterNull(compact('package_key', 'is_link_date', 'rotation', 'traffic_limit', 'is_active', 'expired_at'))]);
    }

    /**
     * Delete a resident subpackage
     * @param string $package_key
     * @return array ['status' => 'delete'] либо ['status' => 'not-found']
     */
    function residentSubUserDelete($package_key) {
        return $this->normalizeDeleteResult(
            $this->request('DELETE', 'residentsubuser/delete', ['json' => compact('package_key')])
        );
    }

    /**
     * List of resident subpackages.
     * expired_at у каждого субпакета — ОБЪЕКТ PHP-даты, а не строка:
     * ['date' => '2026-09-01 00:00:00.000000', 'timezone_type' => 3, 'timezone' => 'UTC'].
     * Читать нужно $item['expired_at']['date'] (у резидентского пакета верхнего уровня,
     * residentPackage(), это, наоборот, строка d.m.Y H:i:s).
     * @return array
     */
    function residentSubUserPackages() {
        return $this->request('GET', 'residentsubuser/packages');
    }

    /**
     * List of existing ip lists in a subpackage
     * @param string $package_key
     * @return array
     */
    function residentSubUserLists($package_key = null) {
        return $this->request('GET', 'residentsubuser/lists', ['query' => $this->filterNull(compact('package_key'))]);
    }

    /**
     * Create a list inside a subpackage
     * @param string $package_key
     * @return array
     */
    function residentSubUserListAdd($package_key, $title = null, $whitelist = null, $country = null, $region = null, $city = null, $isp = null, $rotation = null, $export = null) {
        $geo = $this->filterNull(compact('country', 'region', 'city', 'isp'));
        $json = $this->filterNull(compact('package_key', 'title', 'whitelist', 'rotation', 'export'));
        // Пустой PHP-массив json_encode превращает в [], а сервер ждёт объект geo и отвечает
        // голым HTTP 400 мимо конверта ошибок. Приводим к объекту явно.
        $json['geo'] = (object) $geo;
        return $this->request('POST', 'residentsubuser/list/add', ['json' => $json]);
    }

    /**
     * Rename a list inside a subpackage
     * @param string $package_key
     * @param integer $id
     * @param string $title
     * @return array
     */
    function residentSubUserListRename($package_key, $id, $title = null) {
        return $this->request('POST', 'residentsubuser/list/rename', ['json' => compact('package_key', 'id', 'title')]);
    }

    /**
     * Change the rotation interval of a list inside a subpackage
     * @param string $package_key
     * @param integer $id
     * @param integer $rotation
     * @return array
     */
    function residentSubUserListRotation($package_key, $id, $rotation) {
        return $this->request('POST', 'residentsubuser/list/rotation', ['json' => compact('package_key', 'id', 'rotation')]);
    }

    /**
     * Create the tools list inside a subpackage
     * @param string $package_key
     * @return array
     */
    function residentSubUserListTools($package_key) {
        return $this->request('PUT', 'residentsubuser/list/tools', ['json' => compact('package_key')]);
    }

    /**
     * Delete a list inside a subpackage
     * @param string $package_key
     * @param string $id
     * @return array ['status' => 'delete'] либо ['status' => 'not-found'] — ВАЖНО: сервер
     *               отдаёт not-found внутри успешного конверта, проверяйте status
     */
    function residentSubUserListDelete($package_key, $id) {
        return $this->normalizeDeleteResult(
            $this->request('DELETE', 'residentsubuser/list/delete', ['json' => compact('package_key', 'id')])
        );
    }
}
