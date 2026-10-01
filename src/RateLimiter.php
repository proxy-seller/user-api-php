<?php

namespace ProxySeller\Userapi;

/**
 * Очередь запросов: SDK сам держит свои запросы в лимитах API, вызывающему ничего делать не нужно.
 *
 * Правила (в скобках — значения по умолчанию; ключи задаются в конфиге Api под 'rateLimit'):
 *  1. Общее окно. Все запросы — чтение, запись и деньги — делят одно СКОЛЬЗЯЩЕЕ окно: не больше
 *     requestsPerMinute (1000) стартов за любые 60 с. Это журнал стартов, а не token bucket:
 *     ведро пропускает всплески, которые превышают N за 60 с. Старт N+1 ждёт, пока самому
 *     старому из последних N стартов не исполнится 60 с.
 *  2. Полоса записи. write и money идут по ОДНОЙ полосе на экземпляр: по одному, следующий
 *     стартует только после окончания предыдущего и не раньше writeIntervalMs (1000) после
 *     СТАРТА предыдущего write/money; money — ещё и не раньше moneyIntervalMs (2000) после старта
 *     предыдущего money. Чтение полосу не ждёт — только окно.
 *  3. HTTP 429 отдаёт edge-лимит перед API: запрос до API не дошёл, поэтому повтор безопасен даже
 *     для денег. Ждём Retry-After (секунды или HTTP-date; нет или не разобрать — 2 с; не больше
 *     60 с) и повторяем до maxRetries (3) раз, потом отдаём обычную ошибку SDK со статусом 429.
 *     Повтор write/money не отпускает полосу, каждый повтор — новый старт в окне; интервалы
 *     следующего запроса полосы отсчитываются от последней попытки.
 *  4. Других автоповторов нет. Ошибки конверта уходят вызывающему как есть: код 57 ("Prolong for
 *     this order is already in progress") — повтор мог бы продлить заказ дважды; тройка отказа в
 *     доступе (код 503: ключ / IP / "Request limit reached") неотличима от неверного ключа или IP.
 *  5. enabled = false возвращает прежнее поведение один в один: без ожиданий и без повторов.
 *
 * Состояние живёт в экземпляре: один Api — одна очередь. Экземпляры и процессы с одним ключом друг
 * о друге не знают. Под php-fpm между веб-запросами ничего не сохраняется: каждый начинает с новой,
 * пустой очереди, а параллельные запросы идут в разных процессах. Так что очередь помогает скриптам
 * и воркерам, которые делают несколько вызовов в одном процессе.
 *
 * PHP однопоточный: вызовы одного экземпляра идут друг за другом, ожидание блокирует скрипт
 * (usleep через подменяемый sleeper), активного ожидания нет. Если транспорт отдаёт управление
 * (файберы, event loop) и на том же экземпляре стартует второй write/money, пока первый не
 * закончился, второй не отправляется, а бросает \LogicException: наложиться они не могут.
 */
class RateLimiter {

    const READ = 'read';
    const WRITE = 'write';
    const MONEY = 'money';

    /** Значения по умолчанию ключей конфига 'rateLimit'. */
    const DEFAULTS = [
        'enabled' => true,
        'requestsPerMinute' => 1000,
        'writeIntervalMs' => 1000,
        'moneyIntervalMs' => 2000,
        'maxRetries' => 3,
    ];

    /** Длина скользящего окна, мс. */
    const WINDOW_MS = 60000;

    /** Пауза перед повтором HTTP 429, когда Retry-After нет или его не разобрать, мс. */
    const DEFAULT_RETRY_AFTER_MS = 2000;

    /** Потолок паузы по Retry-After, мс. */
    const MAX_RETRY_AFTER_MS = 60000;

    /**
     * Категории по ПУТИ (от базового URL API, без ключа), а не по HTTP-методу: calc-ручки — POST,
     * но только считают. {type} — один сегмент пути в любом написании. Всё, чего здесь нет, — чтение.
     */
    const PATHS = [
        'order/make' => self::MONEY,
        'prolong/make/{type}' => self::MONEY,
        'balance/add' => self::MONEY,

        'autoprolong/enable/{type}' => self::WRITE,
        'autoprolong/disable/{type}' => self::WRITE,
        'auth/add' => self::WRITE,
        'auth/add/ip' => self::WRITE,
        'auth/change' => self::WRITE,
        'auth/delete' => self::WRITE,
        'proxy/replace' => self::WRITE,
        'proxy/comment/set' => self::WRITE,
        'balance/autotopup/set' => self::WRITE,
        'resident/list' => self::WRITE, // POST-алиас resident/list/add; resident/lists — чтение
        'resident/list/add' => self::WRITE,
        'resident/list/delete' => self::WRITE,
        'resident/list/rename' => self::WRITE,
        'resident/list/rotation' => self::WRITE,
        'resident/list/tools' => self::WRITE,
        'residentsubuser/create' => self::WRITE,
        'residentsubuser/update' => self::WRITE,
        'residentsubuser/delete' => self::WRITE,
        'residentsubuser/list/add' => self::WRITE,
        'residentsubuser/list/delete' => self::WRITE,
        'residentsubuser/list/rename' => self::WRITE,
        'residentsubuser/list/rotation' => self::WRITE,
        'residentsubuser/list/tools' => self::WRITE,
    ];

    /**
     * Остаток ожидания меньше микросекунды считаем нулём: у поддельных часов на float разность
     * «цель минус сейчас» может не обнулиться из-за округления, и цикл ожидания не закончился бы.
     */
    private const EPSILON_MS = 0.001;

    private $enabled;
    private $requestsPerMinute;
    private $writeIntervalMs;
    private $moneyIntervalMs;
    private $maxRetries;

    /** @var callable () → текущее время в мс, монотонное */
    private $clock;

    /** @var callable (float $ms) → ждёт столько миллисекунд */
    private $sleeper;

    /** @var \SplQueue старты последних requestsPerMinute запросов, мс, от старых к новым */
    private $starts;

    /** @var float|int|null старт последней попытки write/money, мс */
    private $lastWriteStart = null;

    /** @var float|int|null старт последней попытки money, мс */
    private $lastMoneyStart = null;

    /** @var bool полосу занимает write/money, который ещё не закончился */
    private $laneBusy = false;

    /** @var array|null регулярка → категория, собирается из PATHS один раз */
    private static $patterns = null;

    /**
     * @param array|bool|null $config ключи DEFAULTS; для тестов ещё clock (callable без аргументов,
     *                           возвращает текущее время в мс, монотонное) и sleeper
     *                           (callable(float $ms), ждёт столько мс — либо двигает поддельные часы).
     *                           Сокращения, как в других SDK: false — это ['enabled' => false],
     *                           true и null — все значения по умолчанию
     * @throws \InvalidArgumentException при неизвестном ключе или негодном значении
     */
    public function __construct($config = null) {
        if ($config === null || $config === true) {
            $config = [];
        } elseif ($config === false) {
            $config = ['enabled' => false];
        }
        if (!is_array($config)) {
            throw new \InvalidArgumentException(
                "rateLimit must be an array or a boolean, e.g. false or ['enabled' => false]"
            );
        }
        $allowed = array_merge(array_keys(self::DEFAULTS), ['clock', 'sleeper']);
        $unknown = array_diff(array_keys($config), $allowed);
        if ($unknown) {
            throw new \InvalidArgumentException(
                'rateLimit: unknown option(s) ' . implode(', ', $unknown) . '; allowed: ' . implode(', ', $allowed)
            );
        }
        $config = array_merge(self::DEFAULTS, array_filter($config, function ($value) {
            return $value !== null;
        }));

        if (!is_bool($config['enabled'])) {
            throw new \InvalidArgumentException('rateLimit.enabled must be a boolean');
        }
        $this->enabled = $config['enabled'];
        $this->requestsPerMinute = self::integerOption($config, 'requestsPerMinute', 1);
        $this->writeIntervalMs = self::millisecondsOption($config, 'writeIntervalMs');
        $this->moneyIntervalMs = self::millisecondsOption($config, 'moneyIntervalMs');
        $this->maxRetries = self::integerOption($config, 'maxRetries', 0);
        $this->clock = self::callableOption($config, 'clock') ?: self::defaultClock();
        $this->sleeper = self::callableOption($config, 'sleeper') ?: self::defaultSleeper();
        $this->starts = new \SplQueue();
    }

    /**
     * Категория запроса: money | write | read. Регистр, слеши по краям и query-строка роли не играют.
     *
     * @param string $path путь от базового URL API, например 'prolong/make/ipv4'
     * @return string
     */
    public static function classify($path) {
        $path = (string) $path;
        $path = strtolower(trim(substr($path, 0, strcspn($path, '?#')), '/'));
        foreach (self::patterns() as $regex => $category) {
            if (preg_match($regex, $path)) {
                return $category;
            }
        }
        return self::READ;
    }

    /**
     * Отправляет запрос через очередь: ждёт место в окне (write/money — ещё и полосу), повторяет
     * HTTP 429. Выключенная очередь просто вызывает $send.
     *
     * @param string $path путь от базового URL API — по нему выбирается категория
     * @param callable $send отправляет запрос и возвращает ответ (getStatusCode(), getHeaderLine())
     * @return mixed ответ последней попытки. 429 после исчерпания повторов тоже возвращается: ошибку
     *               из него собирает обычный разбор ответа
     * @throws \LogicException если write/money стартует, пока на этом экземпляре не закончился другой
     */
    public function send($path, callable $send) {
        if (!$this->enabled) {
            return $send();
        }
        $category = self::classify($path);
        if ($category === self::READ) {
            return $this->dispatch($category, $send);
        }
        if ($this->laneBusy) {
            throw new \LogicException(
                'Another write request is still in progress on this Api instance; write and money'
                . ' requests go one at a time, so this one was not sent. Calls on one instance run one'
                . ' after another, so this happens only when the transport yields (fibers, an event loop)'
                . ' or calls back into the SDK: do not start writes from several fibers on one instance.'
            );
        }
        $this->laneBusy = true;
        try {
            return $this->dispatch($category, $send);
        } finally {
            $this->laneBusy = false;
        }
    }

    /**
     * Пауза перед повтором по заголовку Retry-After, мс: число секунд или HTTP-date (IMF-fixdate и
     * оба устаревших формата RFC 7231). Нет заголовка или его не разобрать — DEFAULT_RETRY_AFTER_MS;
     * больше MAX_RETRY_AFTER_MS не ждём; дата в прошлом — повтор сразу.
     *
     * @param string|null $value значение заголовка
     * @param int|null $nowUnix текущее время для HTTP-date, по умолчанию time()
     * @return int
     */
    public static function parseRetryAfterMs($value, $nowUnix = null) {
        $value = trim((string) $value);
        if ($value === '') {
            return self::DEFAULT_RETRY_AFTER_MS;
        }
        if (preg_match('/^\d+$/', $value)) {
            return (int) min((float) $value * 1000, self::MAX_RETRY_AFTER_MS);
        }
        $date = self::parseHttpDate($value);
        if ($date === null) {
            return self::DEFAULT_RETRY_AFTER_MS;
        }
        $seconds = $date - ($nowUnix === null ? time() : (int) $nowUnix);
        return (int) min(max($seconds, 0) * 1000, self::MAX_RETRY_AFTER_MS);
    }

    /**
     * Попытки одного запроса. Полосу вызывающий уже занял (для write/money), здесь — ожидание,
     * учёт стартов и повторы 429.
     */
    private function dispatch($category, callable $send) {
        $notBefore = $this->laneFreeAt($category);
        for ($retry = 0; ; $retry++) {
            $now = $this->waitUntil($notBefore);
            $this->recordStart($category, $now);
            $response = $send();
            if ($retry >= $this->maxRetries || self::statusOf($response) !== 429) {
                return $response;
            }
            // Интервалы полосы к повтору не применяются: он ждёт Retry-After и место в окне.
            $notBefore = $this->now() + self::parseRetryAfterMs(self::headerOf($response, 'Retry-After'));
        }
    }

    /** Раньше какого момента запрос этой категории стартовать не может по правилам полосы. */
    private function laneFreeAt($category) {
        $at = -INF;
        if ($category === self::READ) {
            return $at;
        }
        if ($this->lastWriteStart !== null) {
            $at = max($at, $this->lastWriteStart + $this->writeIntervalMs);
        }
        if ($category === self::MONEY && $this->lastMoneyStart !== null) {
            $at = max($at, $this->lastMoneyStart + $this->moneyIntervalMs);
        }
        return $at;
    }

    /** Когда в окне освободится место: окно полно — через 60 с после самого старого старта. */
    private function windowFreeAt() {
        if (count($this->starts) < $this->requestsPerMinute) {
            return -INF;
        }
        return $this->starts->bottom() + self::WINDOW_MS;
    }

    /**
     * Ждёт, пока не наступит $notBefore и в окне не появится место; возвращает момент старта.
     * Условия перепроверяются после каждого сна: sleeper может проснуться раньше (сигнал), а
     * sleeper, который отдаёт управление (файберы), — пропустить за это время чужие старты в окно.
     */
    private function waitUntil($notBefore) {
        while (true) {
            $now = $this->now();
            $wait = max($notBefore, $this->windowFreeAt()) - $now;
            if ($wait <= self::EPSILON_MS) {
                return $now;
            }
            call_user_func($this->sleeper, $wait);
        }
    }

    private function recordStart($category, $now) {
        $this->starts->enqueue($now);
        if (count($this->starts) > $this->requestsPerMinute) {
            $this->starts->dequeue();
        }
        if ($category !== self::READ) {
            $this->lastWriteStart = $now;
        }
        if ($category === self::MONEY) {
            $this->lastMoneyStart = $now;
        }
    }

    private function now() {
        return call_user_func($this->clock);
    }

    private static function patterns() {
        if (self::$patterns === null) {
            self::$patterns = [];
            foreach (self::PATHS as $pattern => $category) {
                $regex = '';
                foreach (explode('/', $pattern) as $i => $segment) {
                    // Сегмент {type} необязателен: путь без типа ('prolong/make') тоже относим к
                    // его категории — лишняя пауза дешевле неучтённого платного запроса.
                    $regex .= $segment === '{type}'
                        ? '(?:/[^/]*)?'
                        : ($i === 0 ? '' : '/') . preg_quote($segment, '#');
                }
                self::$patterns['#^' . $regex . '$#'] = $category;
            }
        }
        return self::$patterns;
    }

    private static function parseHttpDate($value) {
        $value = preg_replace('/\s+/', ' ', $value);
        $utc = new \DateTimeZone('UTC');
        // IMF-fixdate, устаревшие RFC 850 и asctime — три формата HTTP-date из RFC 7231.
        foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
            $date = \DateTime::createFromFormat('!' . $format, $value, $utc);
            if ($date !== false) {
                return $date->getTimestamp();
            }
        }
        return null;
    }

    private static function statusOf($response) {
        return is_object($response) && method_exists($response, 'getStatusCode')
            ? (int) $response->getStatusCode() : 0;
    }

    private static function headerOf($response, $name) {
        return is_object($response) && method_exists($response, 'getHeaderLine')
            ? (string) $response->getHeaderLine($name) : '';
    }

    private static function integerOption(array $config, $key, $min) {
        $value = $config[$key];
        if (is_string($value) && preg_match('/^\s*\d+\s*$/', $value)) {
            $value = (int) trim($value);
        } elseif (is_float($value) && is_finite($value) && floor($value) === $value) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min) {
            throw new \InvalidArgumentException("rateLimit.$key must be an integer >= $min");
        }
        return $value;
    }

    private static function millisecondsOption(array $config, $key) {
        $value = $config[$key];
        if (is_bool($value) || !is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            throw new \InvalidArgumentException("rateLimit.$key must be a number of milliseconds >= 0");
        }
        return $value + 0;
    }

    private static function callableOption(array $config, $key) {
        if (!isset($config[$key])) {
            return null;
        }
        if (!is_callable($config[$key])) {
            throw new \InvalidArgumentException("rateLimit.$key must be callable");
        }
        return $config[$key];
    }

    /** Монотонные часы в мс: hrtime (PHP 7.3+), иначе microtime. */
    private static function defaultClock() {
        if (function_exists('hrtime')) {
            return function () {
                return hrtime(true) / 1e6;
            };
        }
        return function () {
            return microtime(true) * 1000;
        };
    }

    /**
     * usleep кусками меньше секунды: POSIX разрешает usleep отказать на значениях от 1 000 000 мкс.
     * Округление вверх — чтобы не проснуться на долю микросекунды раньше срока.
     */
    private static function defaultSleeper() {
        return function ($ms) {
            $us = (int) ceil($ms * 1000);
            while ($us > 0) {
                $chunk = min($us, 999999);
                usleep($chunk);
                $us -= $chunk;
            }
        };
    }
}
