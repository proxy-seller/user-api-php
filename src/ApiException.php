<?php

namespace ProxySeller\Userapi;

/**
 * Error returned by Client API.
 *
 * The HTTP status is intentionally stored separately from the legacy business
 * error code because Client API commonly returns business errors with HTTP 200.
 *
 * Сообщение исключения — это errors[0].message. Одного его недостаточно: ошибки доступа
 * (битый ключ / IP не в allowlist / превышенный лимит запросов) сервер отдаёт фиксированной
 * тройкой, у которой errors[0] всегда "Error api key", а настоящая причина — во втором и
 * третьем элементе. Поэтому весь массив доступен через getErrors(), а границы значений
 * валидации (например для balance/autotopup/set) — через getCustomData().
 */
class ApiException extends \RuntimeException {

    protected $apiCode;
    protected $httpStatus;
    protected $errors;
    protected $data;
    protected $responseBody;

    public function __construct(
        $message,
        $apiCode = 0,
        $httpStatus = 0,
        array $errors = [],
        $data = null,
        $responseBody = ''
    ) {
        $numericCode = is_numeric($apiCode) ? (int) $apiCode : 0;
        parent::__construct((string) $message, $numericCode);
        $this->apiCode = $apiCode;
        $this->httpStatus = (int) $httpStatus;
        $this->errors = $errors;
        $this->data = $data;
        $this->responseBody = (string) $responseBody;
    }

    public function getApiCode() {
        return $this->apiCode;
    }

    public function getHttpStatus() {
        return $this->httpStatus;
    }

    /**
     * Полный массив errors из конверта. Единственный способ отличить битый ключ от
     * запрещённого IP и от превышенного rate limit.
     * @return array
     */
    public function getErrors() {
        return $this->errors;
    }

    public function getData() {
        return $this->data;
    }

    public function getResponseBody() {
        return $this->responseBody;
    }

    /**
     * Первый элемент errors как массив (или пустой массив, если errors пуст).
     * @return array
     */
    public function getFirstError() {
        if (!$this->errors) {
            return [];
        }
        $first = reset($this->errors);
        return is_array($first) ? $first : ['message' => $first];
    }

    /**
     * Сообщения всех ошибок конверта по порядку.
     * @return array
     */
    public function getMessages() {
        $messages = [];
        foreach ($this->errors as $error) {
            if (is_array($error) && isset($error['message'])) {
                $messages[] = $error['message'];
            } elseif (is_string($error)) {
                $messages[] = $error;
            }
        }
        return $messages;
    }

    /**
     * Коды всех ошибок конверта по порядку.
     * @return array
     */
    public function getApiCodes() {
        $codes = [];
        foreach ($this->errors as $error) {
            if (is_array($error) && array_key_exists('code', $error)) {
                $codes[] = $error['code'];
            }
        }
        return $codes;
    }

    /**
     * Есть ли среди ошибок конкретный код (например 51 — сумма авто-пополнения меньше минимума).
     * @param integer $code
     * @return boolean
     */
    public function hasApiCode($code) {
        foreach ($this->getApiCodes() as $item) {
            if ((int) $item === (int) $code) {
                return true;
            }
        }
        return false;
    }

    /**
     * customData первой ошибки, у которой оно есть. Сюда сервер кладёт границы значений:
     * для balance/autotopup/set это ['minAmount' => ..., 'minThreshold' => ...,
     * 'minDailyCountCap' => ...] (ClientApiService.setAutoTopup).
     * @return mixed|null
     */
    public function getCustomData() {
        foreach ($this->errors as $error) {
            if (is_array($error) && isset($error['customData']) && $error['customData'] !== null) {
                return $error['customData'];
            }
        }
        return null;
    }

    /**
     * Это ошибка доступа (битый apiKey / IP не разрешён / превышен лимит запросов)?
     * Повторяет LegacyClientApiErrorHelper.isAccessError на бэкенде. Различить три причины
     * нельзя — сервер намеренно отдаёт одинаковую тройку, — но отличить их от бизнес-ошибок
     * (нет денег, неверная страна) можно и нужно: ретраить имеет смысл только rate limit.
     * @return boolean
     */
    public function isAccessError() {
        foreach ($this->errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            $code = isset($error['code']) ? (int) $error['code'] : null;
            $message = isset($error['message']) ? (string) $error['message'] : '';
            if ($code === 2 || $code === 3
                || $message === 'Error api key'
                || $message === 'Request limit reached'
                || strpos($message, 'Error auth.') === 0) {
                return true;
            }
        }
        return false;
    }
}
