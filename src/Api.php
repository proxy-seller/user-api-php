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
 *  - order/make объявляет обязательный заголовок X-Fingerprint. Резидентские и скраперные заказы
 *    без него не создаются вообще, остальные секции его игнорируют. Значение задаётся один раз
 *    ('fingerprint' в конфиге либо setFingerprint()) и должно быть СТАБИЛЬНЫМ для установки —
 *    см. assertFingerprint().
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
    const AUTO_TOPUP_FIELDS = ['enabled', 'threshold', 'amount', 'subscriptionId'];

    /**
     * Поля, УДАЛЁННЫЕ из AutoTopupSetRequestClientDto 18.08.2026. Сервер их больше не читает:
     * присланные молча игнорируются, вызов отвечает success и не делает ничего. Коды ошибок 54/55
     * и ключ minDailyCountCap в customData исчезли вместе с ними и не переиспользуются.
     * Держим отдельным списком, чтобы отбить с внятным текстом, а не общим "unknown field(s)".
     */
    const AUTO_TOPUP_REMOVED_FIELDS = ['dailyCountCap', 'monthlyAmountCap'];

    protected $client;
    protected $requestBaseUri;
    protected $paymentId = null;
    protected $paymentCode = null;
    protected $generateAuth = 'N';
    protected $fingerprint = null;
    protected $lastResponseStatus = null;

    /**
     * Key placed in https://proxy-seller.com/personal/api/
     * Кроме key понимает baseUrl/base_url, client (свой транспорт) и fingerprint (значение
     * заголовка X-Fingerprint для order/make, см. setFingerprint); остальное уходит в Guzzle.
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
        $fingerprint = isset($config['fingerprint']) ? $config['fingerprint'] : null;
        unset($config['key'], $config['baseUrl'], $config['base_url'], $config['client'], $config['fingerprint']);

        $this->requestBaseUri = rtrim($baseUrl, '/') . '/' . rawurlencode($key) . '/';
        $this->setFingerprint($fingerprint);

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

    public function getFingerprint() {
        return $this->fingerprint;
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
     * Значение заголовка X-Fingerprint для order/make.
     *
     * Форму сервер не проверяет («any opaque string is accepted»), но требует СТАБИЛЬНОСТИ в
     * пределах установки клиента: заголовок введён ради анти-фрода и affiliate-атрибуции, и
     * случайная строка на каждый процесс ломает ровно их. Поэтому SDK значение не генерирует —
     * возьмите что-то долгоживущее (id инсталляции, машинный uuid, хеш конфига) и сохраните.
     *
     * Пустая строка равнозначна «не задано»: заголовок с пустым значением сервер всё равно
     * считает отсутствующим.
     * @param string $fingerprint
     * @return void
     */
    public function setFingerprint($fingerprint): void {
        $this->fingerprint = ($fingerprint === null || trim((string) $fingerprint) === '')
                ? null : trim((string) $fingerprint);
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
     * Добавляет заголовок к $options, не трогая уже заданные. Отдельного механизма транспорту не
     * нужно: и Guzzle, и любой инжектированный клиент с тем же request($method, $uri, $options)
     * читают заголовки из options['headers'] — нужен был только вход для них.
     *
     * Пустое значение не отправляем: заголовок с пустым значением сервер всё равно считает
     * отсутствующим, а в логах он выглядит как заполненный.
     *
     * @param array $options
     * @param string $name
     * @param string|null $value
     * @return array
     */
    protected function withHeader($options, $name, $value) {
        if ($value === null || trim((string) $value) === '') {
            return $options;
        }
        if (!isset($options['headers']) || !is_array($options['headers'])) {
            $options['headers'] = [];
        }
        $options['headers'][$name] = trim((string) $value);
        return $options;
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
     * Проверка на слеши относится ТОЛЬКО к литеральному /proxy/download/resident: общий
     * /proxy/download/{type} обслуживает ProxyController.downloadProxies, где ext не валидируется
     * вовсе. Раньше SDK запрещал '/' и '\' на обоих маршрутах, и валидный кастомный шаблон со
     * слешем ("%ip%:%port%@%login%/%password%") отклонялся локально — запрос не уходил.
     * Длину и CR/LF режем везде: перенос строки в query-параметре не нужен ни одному маршруту,
     * а 250 символов — потолок шаблона, а не особенность роутинга.
     *
     * ext — это либо txt, либо csv, либо свой шаблон строки с плейсхолдерами
     * %ip% %port% %login% %user% %password% %protocol% %rotation_link%.
     * @param string $ext
     * @param boolean $residentRoute выгрузка идёт через /proxy/download/resident
     * @return string
     * @throws \Exception
     */
    protected function assertExt($ext, $residentRoute = false) {
        if ($ext === null) {
            return null;
        }
        if (strlen($ext) > 250) {
            throw new \Exception("ext is too long (max 250)");
        }
        if (preg_match('#[\r\n]#', $ext)) {
            throw new \Exception("ext contains forbidden characters");
        }
        if ($residentRoute && preg_match('#[/\\\\]#', $ext)) {
            throw new \Exception("ext contains forbidden characters ('/' and '\\' are rejected by /proxy/download/resident)");
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
     * описывает намерение, и его видно в логах: набор из четырёх null неотличим от «ничего не
     * меняем», а сериализованный null на любом поле, где сервер однажды перестанет считать его
     * «не прислали», молча затёр бы настройку.
     *
     * Неизвестные ключи отбиваем: Spring Boot по умолчанию игнорирует лишние поля JSON, то есть
     * опечатка (thresh0ld вместо threshold) на сервере прошла бы как успешный запрос, который
     * ничего не изменил. По той же причине отдельно отбиваем dailyCountCap и monthlyAmountCap —
     * их убрали из контракта 18.08.2026, и молчаливый no-op тут ровно тот же.
     *
     * Границы значений (минимальная сумма/порог) НЕ проверяем локально: их владелец —
     * AutoTopupService.saveSettings, а конкретные допустимые числа приходят в
     * errors[0].customData (minAmount / minThreshold).
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

        $removed = array_intersect(array_keys($settings), self::AUTO_TOPUP_REMOVED_FIELDS);
        if ($removed) {
            throw new \InvalidArgumentException(
                'balance/autotopup/set: field(s) ' . implode(', ', $removed)
                . ' were removed from the contract on 2026-08-18 and are ignored by the server'
                . ' (the call would answer success and change nothing); drop them from the payload'
            );
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
            // threshold / amount — BigDecimal на сервере. Значение отдаём как передали
            // (int/float/числовая строка): строку Jackson тоже принимает, а приведение к float
            // на больших суммах теряло бы точность.
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
     * Включить/выключить авто-пополнение или поправить его порог и сумму.
     *
     * PARTIAL UPDATE: передавайте только те поля, которые меняете — опущенные сервер берёт из
     * сохранённых настроек. Допустимые ключи (AutoTopupSetRequestClientDto):
     *   enabled          boolean
     *   threshold        number — баланс, ниже которого срабатывает списание
     *   amount           number — сумма одного автопополнения; должна покрывать threshold
     *   subscriptionId   string — подписка Paddle из paymentMethod.id (или subscriptionId) ответа get
     *
     * dailyCountCap и monthlyAmountCap из контракта УБРАНЫ (18.08.2026): сервер их не читает,
     * присланные игнорирует, и вызов с ними возвращал бы success, ничего не изменив. SDK теперь
     * отбивает их локально — см. AUTO_TOPUP_REMOVED_FIELDS.
     *
     * Валидация серверная и применяется к РЕЗУЛЬТАТУ мержа, поэтому правка одного поля может
     * упасть из-за уже сохранённого другого. Коды ошибок: 49 фича выключена, 50 порог меньше
     * минимума, 51 сумма меньше минимума, 52 сумма не покрывает порог, 53 нет привязанного
     * способа оплаты, 56 карта истекла. Коды 54/55 удалены вместе с полями и не переиспользуются.
     * Граничные значения приходят в errors[0].customData (minAmount / minThreshold) —
     * см. ApiException::getCustomData().
     *
     * На успехе отдаёт состояние ПОСЛЕ сохранения, в том же виде, что balanceAutoTopupGet().
     *
     * @param array $settings подмножество полей выше
     * @return array
     * @throws \InvalidArgumentException при удалённом/неизвестном поле, неверном типе или пустом наборе
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
     * Всюду идентификатор называется id, а внутри лежит читаемый код, не ObjectId.
     * Значение id кладётся в одноимённый *Id заказа:
     *   country[]     id, name — id это alpha3 страны ("USA")
     *   period[]      id, name — id это код периода ("1m")
     *   mobile        country[].operators.{dedicated,shared}[] → id, name,
     *                 rotations[{id, name}]. У оператора id — его тег, регистр значим;
     *                 у rotations id — это МИНУТЫ (0 = "By Link"), единственный id-число
     *   mix           quantities[] → id, name, quantities[] — id это код пакета, рядом сразу
     *                 доступные количества, так что mixId берите именно отсюда
     *   resident      tarifs[] → id, name, personal — id это код тарифа ("1-gb")
     * Исключение одно — balance/payments/list: там id это настоящий ObjectId, потому что
     * на один код шлюза приходится несколько систем и код их не различает.
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
     * @param string $mix the MIX package — its code (tag) from reference/list/mix -> quantities[].id,
     *                    or its ObjectId. The value goes into mixId, not countryId: that is where
     *                    the server resolves a tag (see prepareMix)
     * @param string $periodId ObjectId or period code (e.g. "1m")
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName not needed once the MIX package is resolved (see isMixResolved)
     * @param array $options mixId/mixCode live here — there is no positional argument for them
     * @return array
     */
    function orderCalcMix($mix, $periodId, $quantity, $authorization = null, $coupon = null, $customTargetName = null, $options = []) {
        return $this->orderCalc($this->prepareMix('mix', $mix, $periodId, $quantity, $authorization, $coupon, $customTargetName, $options));
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
     * @param string $operatorId ObjectId or operator tag (case-sensitive) from reference/list/mobile
     *                           → country[].operators.{dedicated,shared}[].id or .tag
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
     *                       plus fingerprint — X-Fingerprint just for this call, see setFingerprint()
     * @return array
     */
    function orderMakeIpv4($countryId, $periodId, $quantity, $authorization = null, $coupon = null, $customTargetName = null, $options = []) {
        $fingerprint = $this->takeFingerprint($options);
        return $this->orderMake($this->withGenerateAuth($this->prepareRegular('ipv4', $countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $options)), $fingerprint);
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
     *                       plus fingerprint — X-Fingerprint just for this call, see setFingerprint()
     * @return array
     */
    function orderMakeIsp($countryId, $periodId, $quantity, $authorization = null, $coupon = null, $customTargetName = null, $options = []) {
        $fingerprint = $this->takeFingerprint($options);
        return $this->orderMake($this->withGenerateAuth($this->prepareRegular('isp', $countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $options)), $fingerprint);
    }

    /**
     * Create an order MIX. Attention! Deducts money from the balance.
     * @param string $mix the MIX package — its code (tag) from reference/list/mix -> quantities[].id,
     *                    or its ObjectId. The value goes into mixId, not countryId: that is where
     *                    the server resolves a tag (see prepareMix)
     * @param string $periodId ObjectId or period code (e.g. "1m")
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName not needed once the MIX package is resolved (see isMixResolved)
     * @param array $options mixId/mixCode live here — there is no positional argument for them;
     *                       fingerprint sets X-Fingerprint just for this call, see setFingerprint()
     * @return array
     */
    function orderMakeMix($mix, $periodId, $quantity, $authorization = null, $coupon = null, $customTargetName = null, $options = []) {
        $fingerprint = $this->takeFingerprint($options);
        return $this->orderMake($this->withGenerateAuth($this->prepareMix('mix', $mix, $periodId, $quantity, $authorization, $coupon, $customTargetName, $options)), $fingerprint);
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
     *                       plus fingerprint — X-Fingerprint just for this call, see setFingerprint()
     * @return array
     */
    function orderMakeIpv6($countryId, $periodId, $quantity, $authorization = null, $coupon = null, $customTargetName = null, $protocol = null, $options = []) {
        $fingerprint = $this->takeFingerprint($options);
        return $this->orderMake($this->withGenerateAuth($this->prepareIpv6($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $protocol, $options)), $fingerprint);
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
     * @param string $operatorId ObjectId or operator tag (case-sensitive) from reference/list/mobile
     *                           → country[].operators.{dedicated,shared}[].id or .tag
     * @param integer $rotationId rotation in MINUTES (0 = By Link). Not a code — "5m" is rejected
     * @param string $mobileServiceType shared | dedicated, required for mobile
     * @param array $options fields without a positional argument (generateAuth, *Code twins)
     *                       plus fingerprint — X-Fingerprint just for this call, see setFingerprint()
     * @return array
     */
    function orderMakeMobile($countryId, $periodId, $quantity, $authorization = null, $coupon = null, $operatorId = null, $rotationId = null, $mobileServiceType = 'dedicated', $options = []) {
        $fingerprint = $this->takeFingerprint($options);
        return $this->orderMake($this->withGenerateAuth($this->prepareMobile($countryId, $periodId, $quantity, $authorization, $coupon, $operatorId, $rotationId, $mobileServiceType, $options)), $fingerprint);
    }

    /**
     * Create an order Resident. Attention! Deducts money from the balance.
     * ТРЕБУЕТ X-Fingerprint: без него сервер заказ не создаёт (см. assertFingerprint).
     * @param string $tarifId ObjectId or tariff code; reference/list publishes only the ObjectId
     * @param string $coupon
     * @param array $options fields without a positional argument (generateAuth, paymentCode, ...)
     *                       plus fingerprint — X-Fingerprint just for this call, see setFingerprint()
     * @return array
     */
    function orderMakeResident($tarifId, $coupon = null, $options = []) {
        $fingerprint = $this->takeFingerprint($options);
        return $this->orderMake($this->withGenerateAuth($this->prepareResident($tarifId, $coupon, $options)), $fingerprint);
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
     * Вынимает fingerprint из options: это ЗАГОЛОВОК, а не поле тела, и в JSON ему делать нечего.
     * Работает по ссылке, чтобы дальше в prepare* уехал уже очищенный набор.
     *
     * @param array|boolean|null $options
     * @return string|null
     */
    protected function takeFingerprint(&$options) {
        if (!is_array($options) || !array_key_exists('fingerprint', $options)) {
            return null;
        }
        $fingerprint = $options['fingerprint'];
        unset($options['fingerprint']);
        return $fingerprint;
    }

    /**
     * Значение задано? Серверный trimToNull считает незаданными и null, и пустую строку, и строку
     * из пробелов — иначе `countryCode => ''` стирал бы валидный парный countryId.
     * Ноль (rotationId = 0, «By Link») пустым НЕ считается.
     *
     * @param array $request
     * @param string $key
     * @return boolean
     */
    protected function isFilled($request, $key) {
        return array_key_exists($key, $request)
            && $request[$key] !== null
            && trim((string) $request[$key]) !== '';
    }

    /**
     * Мержит options в тело заказа и разводит пары *Id / *Code по правилам сервера.
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
     *  - Если заполнены ОБЕ половины пары, старшинство зависит от пары: у payment/country/period
     *    старше *Code, у operator/rotation/mix/tarif — *Id. Таблицу повторяем ниже по коду.
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

        // Приоритет внутри пары у сервера НЕ единый (normalizeOrderReferenceCodes):
        //  - payment/country/period: ветка *Code идёт ПЕРВОЙ и перезаписывает *Id;
        //  - operator/rotation/mix/tarif: ветка кода стоит под `&& !trimToNull(*Id)`, то есть
        //    старше *Id, а код в этом случае не смотрят вовсе.
        // SDK снимал *Id для всех семи пар, и по нижним четырём клиент, заполнивший обе половины,
        // молча получал не тот пакет/оператора/ротацию/тариф, который выбрал бы сервер.
        $codeWins = ['countryCode' => 'countryId', 'periodCode' => 'periodId', 'paymentCode' => 'paymentId'];
        $idWins = ['operatorCode' => 'operatorId', 'rotationCode' => 'rotationId',
            'mixCode' => 'mixId', 'tarifCode' => 'tarifId'];

        foreach ($codeWins as $code => $id) {
            if ($this->isFilled($request, $code)) {
                unset($request[$id]);
            }
        }
        foreach ($idWins as $code => $id) {
            if ($this->isFilled($request, $code) && $this->isFilled($request, $id)) {
                unset($request[$code]);
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
     *
     * Единственный эндпоинт, который отправляет X-Fingerprint: заголовок объявлен required именно
     * на order/make, и когда значение задано, мы шлём его для ЛЮБОЙ секции — прочие секции его
     * игнорируют, а резидентская и скраперная без него не создаются (см. assertFingerprint).
     *
     * @param array $json Free format array to send into endpoint
     * @param string $fingerprint X-Fingerprint только для этого вызова; по умолчанию берётся
     *                            значение клиента (getFingerprint)
     * @return array
     */
    function orderMake($json, $fingerprint = null) {
        $this->assertTargetName($json);
        $fingerprint = ($fingerprint === null || trim((string) $fingerprint) === '')
                ? $this->getFingerprint() : $fingerprint;
        $this->assertFingerprint($json, $fingerprint);
        return $this->request('POST', 'order/make', $this->withHeader(['json' => $json], 'X-Fingerprint', $fingerprint));
    }

    /**
     * X-Fingerprint обязателен для резидентских и скраперных заказов: OrderService
     * (createResidentOrder / createScraperOrder) отвечает "Header X-Fingerprint is required" и
     * заказ НЕ создаёт. Проверяем локально — тем же приёмом, что assertTargetName: не платить
     * сетевым запросом за заведомый отказ.
     *
     * Значение SDK не генерирует СПЕЦИАЛЬНО. Контракт просит стабильный идентификатор установки,
     * а случайная строка на процесс ломает ровно то, ради чего заголовок и вводили — анти-фрод и
     * affiliate-атрибуцию; отказ здесь честнее молчаливо испорченной атрибуции.
     *
     * @param array $json
     * @param string|null $fingerprint
     * @throws \InvalidArgumentException
     */
    protected function assertFingerprint($json, $fingerprint) {
        if ($fingerprint !== null && trim((string) $fingerprint) !== '') {
            return;
        }
        $section = isset($json['sectionCode']) ? $json['sectionCode'] : null;
        if (!in_array($section, ['resident', 'scraper'], true)) {
            return;
        }
        throw new \InvalidArgumentException(
            "X-Fingerprint is required for {$section} orders (client api answers "
            . '"Header X-Fingerprint is required" and creates nothing). Set a stable per-installation '
            . "value once — new Api(['key' => ..., 'fingerprint' => ...]) or setFingerprint() — or pass "
            . "it as ['fingerprint' => ...] in the options array. Do not generate a random one per run."
        );
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
            // data = null внутри status="success" означает, что удалять было НЕЧЕГО:
            // ResidentSubUserController заполняет data только когда deleteSubPackage вернул true,
            // иначе поле остаётся пустым, а статус всё равно success. Пустой массив здесь
            // выглядел как удавшееся удаление — отдаём то же not-found, что соседняя ручка
            // пишет строкой.
            return ['status' => 'not-found'];
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

    /**
     * MIX-заказ идентифицируется пакетом, а не страной. Значение уезжает в mixId, потому что
     * именно там сервер резолвит символьный код: normalizeOrderReferenceCodes ищет пакет по
     * tag и подменяет его на ObjectId ДО parseMixSelection, а тот умеет только findById.
     * Если положить код в countryId, он будет искаться среди стран и mix не соберётся.
     *
     * @param string $sectionCode mix | mix_isp
     * @param string $mix package code from reference/list/mix -> quantities[].id, or its ObjectId
     * @return array
     */
    protected function prepareMix($sectionCode, $mix, $periodId, $quantity, $authorization, $coupon, $customTargetName, $options = []) {
        $request = array_merge(
            $this->paymentFields(),
            compact('sectionCode', 'periodId', 'quantity', 'authorization', 'coupon', 'customTargetName'),
            ['mixId' => $mix]
        );
        return $this->applyOrderOptions($request, $options);
    }

    /**
     * Продление адресуется теми же IP, которые вернул proxy/list — никаких id знать не нужно.
     * Сервер сам находит их через resolveProlongIpsToIds (вызывается и в prolong/calc, и в
     * prolong/make, без всяких условий), берёт столько строк, сколько адресов прислали, и
     * первыми — истекающие раньше.
     *
     * Разделение простое и однозначное: значение с точкой или двоеточием — это адрес, всё
     * остальное — ObjectId. Формат адреса зависит от типа: ipv4/isp/mix/mix_isp — "ip",
     * ipv6 — "host:port", mobile — "ip:port_http:port_socks" (ровно те поля, что отдаёт
     * proxy/list).
     *
     * У ipv6 поле "ip" из proxy/list уже содержит шлюз вместе с портом ("1.2.3.4:26000"),
     * а "ip_only" — только шлюз. Передаём "ip" как есть, точно так же, как для
     * остальных типов: двоеточие внутри само уводит строку в ips.
     *
     * @param array|string $ipsOrIds
     * @return array{ips: array, ids: array}
     */
    protected function splitProlongTargets($ipsOrIds) {
        $ips = [];
        $ids = [];
        foreach ((array) $ipsOrIds as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (strpos($value, '.') !== false || strpos($value, ':') !== false) {
                $ips[] = $value;
            } else {
                $ids[] = $value;
            }
        }
        return ['ips' => $ips, 'ids' => $ids];
    }

    protected function prepareProlong($ids, $periodId, $coupon, $options = []) {
        $targets = $this->splitProlongTargets($ids);
        $request = array_merge(
            $this->paymentFields(),
            compact('periodId', 'coupon'),
            $targets['ips'] ? ['ips' => $targets['ips']] : [],
            $targets['ids'] ? ['ids' => $targets['ids']] : []
        );
        // orderSeparator* — внутренняя механика MIX-заказов, клиенту она не нужна; оставлены
        // только чтобы не ломать тех, кто их уже передаёт.
        $allowed = [
            'ips', 'ids', 'orderSeparatorIds', 'orderSeparatorId', 'periodId',
            'periodCode', 'coupon', 'paymentId', 'paymentCode'
        ];
        $options = array_intersect_key($this->normalizeOptions($options), array_flip($allowed));
        $request = array_merge($request, $options);
        // У обеих пар prolong'а (normalizeProlongReferenceCodes) *Code разбирается ПЕРВЫМ и
        // перезаписывает *Id, так что снимаем парный id. Пустое значение считаем незаданным —
        // см. isFilled: иначе periodCode => '' стирал бы валидный periodId.
        if ($this->isFilled($request, 'periodCode')) {
            unset($request['periodId']);
        }
        if ($this->isFilled($request, 'paymentCode')) {
            unset($request['paymentId']);
        }
        return $this->filterNull($request);
    }

    /**
     * Calculate the renewal
     * @param string $type - ipv4 | ipv6 | mobile | isp | mix
     * @param array $ips IP addresses exactly as proxy/list returned them — no ids needed:
     *                   ipv4/isp/mix/mix_isp "1.2.3.4", ipv6 "host:port", mobile
     *                   "ip:port_http:port_socks". For ipv6 the "ip" field already carries the
     *                   gateway with the port ("1.2.3.4:26000"), "ip_only" the bare gateway.
     *                   ObjectIds still work if you happen to have them.
     * @param string $periodId period code from reference/list, e.g. "1m"
     * @param string $coupon
     * @return array
     */
    function prolongCalc($type, $ids, $periodId, $coupon = '', $options = []) {
        return $this->request('POST', 'prolong/calc/' . rawurlencode($type), ['json' => $this->prepareProlong($ids, $periodId, $coupon, $options)]);
    }

    /**
     * Create a renewal order. Attention! Deducts money from the balance.
     * @param string $type - ipv4 | ipv6 | mobile | isp | mix | mix_isp
     * @param array $ips IP addresses exactly as proxy/list returned them — no ids needed:
     *                   ipv4/isp/mix/mix_isp "1.2.3.4", ipv6 "host:port", mobile
     *                   "ip:port_http:port_socks". For ipv6 the "ip" field already carries the
     *                   gateway with the port ("1.2.3.4:26000"), "ip_only" the bare gateway.
     *                   ObjectIds still work if you happen to have them.
     * @param string $periodId period code from reference/list, e.g. "1m"
     * @param string $coupon
     * @return array ['orderId' => ObjectId-строка, 'total' => …, 'balance' => …, 'listBaseOrderNumbers' => []]
     * @throws ApiException при нехватке средств — продление НЕ состоялось; данные расчёта
     *                      (warning/total/balance) остаются в ApiException::getData()
     */
    function prolongMake($type, $ids, $periodId, $coupon = '', $options = []) {
        // Своего гарда здесь больше нет. ProlongMakeResponseClientDto::ofInsufficientFunds теперь
        // кладёт причину в errors[{code:16, "Insufficient funds on balance"}], а не оставляет
        // errors[] пустым, — значит нехватку средств отбивает общая ветка разбора конверта, и
        // calc-данные приезжают в ApiException::getData(). Прежняя проверка «нет orderId — значит
        // провал» под новую форму уже не срабатывала, а единственным её оставшимся эффектом было
        // превращать ЛЕГИТИМНЫЙ status="success" с пустым orderId в фальшивую ошибку — теряя
        // total/balance/listBaseOrderNumbers уже ПОСЛЕ списания денег.
        return $this->request('POST', 'prolong/make/' . rawurlencode($type), ['json' => $this->prepareProlong($ids, $periodId, $coupon, $options)]);
    }

    /////////////////////////////// Autoprolong ///////////////////////////////

    /**
     * Тело autoprolong/* — это ProlongRequest плюс subscriptionId и tarifId
     * (AutoProlongRequestClientDto наследует ProlongRequestClientDto специально). Поэтому собираем
     * его тем же prepareProlong: адресация прокси у ручного и авто-продления общая, и сервер
     * разбирает оба тела одним normalizeProlongReferenceCodes.
     *
     * Купона тут нет СОЗНАТЕЛЬНО, хотя поле унаследовано и в prolong/calc работает: автопродление
     * промокод не применяет нигде (autoProlongCalc не передаёт его в расчёт, в домене Autoextend
     * его негде хранить), и превью со скидкой врало бы ровно про ту сумму, ради которой ручку и
     * зовут.
     *
     * Алиасы payment_id / subscription_id / tarif_id / tariffId сервер принимает, но приоритет у
     * camelCase — каноническое написание и шлём.
     *
     * @param string $type
     * @param array|string $ids
     * @param string $periodId
     * @param array|null $options subscriptionId, tarifId плюс всё, что понимает prepareProlong
     * @return array
     */
    protected function prepareAutoProlong($type, $ids, $periodId, $options = []) {
        $options = $this->normalizeOptions($options);
        $extra = array_intersect_key($options, array_flip(['subscriptionId', 'tarifId']));
        $request = array_merge($this->prepareProlong($ids, $periodId, null, $options), $extra);

        if ($this->isResidentAutoProlong($type)) {
            // Резидентка автопродлевается ПАКЕТОМ: адресов и периода у неё нет. Роутер собирает
            // из тела только платёжку и тариф (ClientApiAutoProlongRouter -> AutoRenewCalculate/
            // EnableRequestDto), остальное не читается — не отправляем, чтобы запрос не обещал
            // выборку, которой не будет.
            unset($request['ids'], $request['ips'], $request['orderSeparatorIds'],
                  $request['orderSeparatorId'], $request['periodId'], $request['periodCode']);
        }
        return $this->filterNull($request);
    }

    /**
     * Написания типа, которые роутер отправляет в резидентскую ветку (RESIDENT_TYPES).
     * @param string $type
     * @return boolean
     */
    protected function isResidentAutoProlong($type) {
        return in_array(strtolower(trim((string) $type)), ['resident', 'residential'], true);
    }

    /**
     * scraper автопродления не имеет: трафик к нему докупают заказом, и сервер отвечает
     * "Create new order to add traffic, prolong options not available" (prepareAutoProlong,
     * ветка isTariffBasedSection). Отбиваем локально, как assertReplaceType.
     *
     * @param string $type
     * @throws \InvalidArgumentException
     */
    protected function assertAutoProlongType($type) {
        if (strtolower(trim((string) $type)) === 'scraper') {
            throw new \InvalidArgumentException(
                'autoprolong: scraper is not supported (client api answers "Create new order to add '
                . 'traffic, prolong options not available") — buy traffic with orderMake() instead'
            );
        }
    }

    /**
     * paymentId обязателен для calc и enable, в отличие от prolong/*: списание произойдёт БЕЗ
     * клиента, и «по умолчанию с баланса» было бы догадкой за него — сервер отвечает
     * "Set [paymentId]" (resolveAutoProlongPaymentSystem). Проверяем локально, раз остальные
     * обязательные поля SDK уже проверяет.
     *
     * Допустимы только balance и paddle_subscription: разовый чекаут Paddle требует редиректа в
     * браузер, которого у headless-клиента нет. Сам список не проверяем — за ObjectId-ом платёжки
     * её тип отсюда не виден, это сделает сервер ("Set [paymentId] from: balance / paddle_subscription").
     *
     * subscriptionId спрашиваем только когда платёжка названа буквально paddle_subscription; в
     * остальных случаях требование проверит сервер ("Set [subscriptionId]").
     *
     * @param array $json
     * @throws \InvalidArgumentException
     */
    protected function assertAutoProlongPayment($json) {
        $payment = null;
        foreach (['paymentId', 'paymentCode'] as $key) {
            if ($this->isFilled($json, $key)) {
                $payment = trim((string) $json[$key]);
                break;
            }
        }
        if ($payment === null) {
            throw new \InvalidArgumentException(
                'autoprolong: paymentId is required for calc/enable (client api answers "Set [paymentId]"), '
                . 'only balance and paddle_subscription are accepted. Set it once via setPaymentId()/'
                . 'setPaymentCode(), or pass paymentId/paymentCode in the options array.'
            );
        }
        if ($payment === 'paddle_subscription' && !$this->isFilled($json, 'subscriptionId')) {
            throw new \InvalidArgumentException(
                'autoprolong: subscriptionId is required when paying with paddle_subscription '
                . '(client api answers "Set [subscriptionId]")'
            );
        }
    }

    /**
     * Сколько спишется за автопродление и КОГДА. Ничего не меняет.
     *
     * data: warning, balance, total, quantity, currency, discount, orders, items[], days,
     * dateEnd, chargeDate, paymentId (канонический КОД платёжки, не ObjectId), autoProlong;
     * у резидентки дополнительно tarifId. Даты — строки "yyyy-MM-dd HH:mm:ss".
     *
     * chargeDate — это НЕ дата окончания: сервер держит два механизма автопродления, один списывает
     * за сутки до, другой в день окончания, и значение считается по действующему. У type=resident
     * оно всегда null (пакет продлевается по дате ИЛИ по исчерпанию трафика), там смотрите dateEnd;
     * days у резидентки — период её собственного тарифа.
     *
     * Нехватка баланса — НЕ исключение: приходит status="error" с ЗАПОЛНЕННЫМ data и ПУСТЫМ
     * errors[], та же форма, что у prolong/calc, и SDK отдаёт данные как обычно.
     * Проверить статус можно через getLastResponseStatus().
     *
     * @param string $type - ipv4 | ipv6 | mobile | isp | mix | mix_isp | resident
     * @param array $ids IP addresses as proxy/list returned them, ровно как в prolongCalc;
     *                   для resident не нужны — единица правки там пакет
     * @param string $periodId period code from reference/list, e.g. "1m"; для resident не нужен
     * @param array $options subscriptionId, tarifId, paymentId/paymentCode, orderSeparatorIds
     * @return array
     * @throws \InvalidArgumentException при type=scraper и без платёжки
     */
    function autoProlongCalc($type, $ids = [], $periodId = null, $options = []) {
        $this->assertAutoProlongType($type);
        $json = $this->prepareAutoProlong($type, $ids, $periodId, $options);
        $this->assertAutoProlongPayment($json);
        return $this->request('POST', 'autoprolong/calc/' . rawurlencode($type), ['json' => $json]);
    }

    /**
     * Включить автопродление. Сейчас НИЧЕГО не списывает, только вооружает будущее списание.
     *
     * data: warning, autoProlong, quantity, ids[], days, paymentId, chargeDate, dateEnd.
     * quantity/ids — это РЕАЛЬНО затронутые прокси, а не эхо запроса: ipv6 включается целым
     * заказом, поэтому один адрес включает все. У type=resident приходит quantity=1 и пустой ids —
     * единица правки там пакет; тело тогда пакетное (paymentId, необязательно tarifId).
     *
     * Заменяет удалённый resident/autorenew/enable.
     *
     * @param string $type - ipv4 | ipv6 | mobile | isp | mix | mix_isp | resident
     * @param array $ids IP addresses as proxy/list returned them; для resident не нужны
     * @param string $periodId period code from reference/list, e.g. "1m"; для resident не нужен
     * @param array $options subscriptionId, tarifId, paymentId/paymentCode, orderSeparatorIds
     * @return array
     * @throws \InvalidArgumentException при type=scraper и без платёжки
     */
    function autoProlongEnable($type, $ids = [], $periodId = null, $options = []) {
        $this->assertAutoProlongType($type);
        $json = $this->prepareAutoProlong($type, $ids, $periodId, $options);
        $this->assertAutoProlongPayment($json);
        return $this->request('POST', 'autoprolong/enable/' . rawurlencode($type), ['json' => $json]);
    }

    /**
     * Выключить автопродление и сбросить привязанные период с платёжкой — следующий enable
     * придётся звать с ними снова. Прокси никуда не деваются, просто перестают продлеваться сами.
     *
     * data: warning, autoProlong, quantity, ids[], days, paymentId, chargeDate, dateEnd, где
     * days/paymentId/chargeDate всегда null, а dateEnd показывает, до какого числа всё ещё оплачено.
     * Ни период, ни платёжка здесь не нужны. У type=resident не нужна и выборка: выключение
     * адресуется пакетом вызывающего аккаунта.
     *
     * Заменяет удалённый resident/autorenew/disable.
     *
     * @param string $type - ipv4 | ipv6 | mobile | isp | mix | mix_isp | resident
     * @param array $ids IP addresses as proxy/list returned them; для resident не нужны
     * @param array $options orderSeparatorIds и прочее, что понимает prepareProlong
     * @return array
     * @throws \InvalidArgumentException при type=scraper
     */
    function autoProlongDisable($type, $ids = [], $options = []) {
        $this->assertAutoProlongType($type);
        $json = $this->prepareAutoProlong($type, $ids, null, $options);
        // См. residentConsumption: пустой PHP-массив json_encode превращает в [], а Spring ждёт
        // объект и отвечает голым HTTP 400 мимо конверта. Для resident тело как раз пустое.
        return $this->request('POST', 'autoprolong/disable/' . rawurlencode($type), ['json' => $json ? $json : new \stdClass()]);
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

        // $type = resident уводит запрос на ЛИТЕРАЛЬНЫЙ /proxy/download/resident (Spring отдаёт
        // точное совпадение пути раньше {type}), а там ext валидирует ResidentUserApiService —
        // см. assertExt. На остальных типах слеши в шаблоне законны.
        $residentRoute = strtolower(trim((string) $type)) === 'resident';

        if ($residentRoute
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
        $validatedExt = $this->assertExt($ext, $residentRoute);
        if ($validatedExt !== null) {
            $query['ext'] = $validatedExt;
        }
        return $this->requestRaw('GET', 'proxy/download/' . rawurlencode($type), ['query' => $query], $returnStream);
    }

    /**
     * Export the resident proxy list. Возвращает ФАЙЛ (attachment), не конверт JSON.
     * @param string|integer $id list id — числовой id листа (резидентские листы, в отличие от
     *                           остальных сущностей v2, живут под Long-идентификаторами)
     * @param string $ext - txt | csv | свой шаблон с плейсхолдерами. Слеши здесь запрещены
     *                      сервером (см. assertExt), в отличие от общего /proxy/download/{type}
     * @param integer $maxLine
     * @return string|\Psr\Http\Message\StreamInterface
     */
    function proxyDownloadResident($id = null, $ext = null, $maxLine = null, $returnStream = false) {
        $ext = $this->assertExt($ext, true);
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
