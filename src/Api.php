<?php

namespace ProxySeller\Userapi;

class Api {

    static $URL = 'https://proxy-seller.com/personal/api/v1/';
    protected $client;
    protected $paymentId = 1;
    protected $generateAuth = 'N';

    /**
     * Key placed in https://proxy-seller.com/personal/api/
     * @param array $config
     * @throws \Exception
     */
    public function __construct($config = []) {
        if (!$config['key']) {
            throw new \Exception("Need key, placed in https://proxy-seller.com/personal/api/");
        }
        $config['base_uri'] = static::$URL . $config['key'] . "/";
        $this->client = new \GuzzleHttp\Client($config);
    }

    public function getClient() {
        return $this->client;
    }

    public function getPaymentId() {
        return $this->paymentId;
    }

    public function getGenerateAuth() {
        return $this->generateAuth;
    }

    /**
     * Payment id=1(inner balance), id=43(subscribed card)
     * @param type $paymentId
     * @return void
     */
    public function setPaymentId($paymentId): void {
        $this->paymentId = $paymentId;
    }

    /**
     * Generate new auths Y/N, default N
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
    protected function request($method, $uri, $options = []) {
        $response = $this->client->request($method, $uri, $options);

        $data = $response->getBody()->getContents();
        $json = \json_decode($data, true);

        if (isset($json['status']) && $json['status'] == 'success') { // Normal response
            return $json['data'];
        } elseif (isset($json['errors'])) { // Normal error response
            throw new \Exception($json['errors'][0]['message'], $response->getStatusCode());
        } else { // raw data
            return (string) $response->getBody();
        }
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
     * Set auth active state
     * @param integer $id auth id
     * @param string $active active state (Y/N)
     * @return string Returns current auth
     */
    function authActive($id, $active) {
        return $this->request('POST', 'auth/active', ['json' => compact('id', 'active')]);
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
     * Replenish the balance
     * @param float $summ
     * @param integer $paymentId
     * @return string Returns a link to the payment page
     * https://proxy-seller.com/personal/pay/?ORDER_ID=123456789&PAYMENT_ID=987654321&HASH=343bd596fb97c04bfb76557710837d34
     */
    function balanceAdd($summ = 5, $paymentId = 29) {
        return $this->request('POST', 'balance/add', ['json' => compact('summ', 'paymentId')])['url'];
    }

    /**
     * List of payment systems for balance replenishing
     * @return array Example items:
     * [
     *   [
     *      'id' => '29',
     *      'name' =>'PayPal'
     *   ],
     *   [
     *      'id' => '37',
     *      'name' => 'Visa / MasterCard'
     *   ]
     * ]
     */
    function balancePaymentsList() {
        return $this->request('GET', 'balance/payments/list')['items'];
    }

    /////////////////////////////// Order ///////////////////////////////

    /**
     * Necessary guides for creating an order
     * - Countries + operators and rotation periods (mobile only)
     * - Proxy periods
     * - Purposes and services (only for ipv4,ipv6,isp,mix,mix_isp,resident)
     * - Quantities allowed (only for mix proxy)
     * @param string $type - ipv4 | ipv6 | mobile | isp | mix | null
     * @return array
     */
    function referenceList($type = null) {
        return $this->request('GET', 'reference/list/' . $type);
    }

    /**
     * Calculate the order IPv4
     * Preliminary order calculation
     * An error in warning must be corrected before placing an order.
     * @param integer $countryId
     * @param integer $periodId
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName
     * @return array Example
     * [
     *     'warning' => 'Insufficient funds. Total $2. Not enough $33.10',
     *     'balance' => 2,
     *     'total' => 35.1,
     *     'quantity' => 5,
     *     'currency' => 'USD',
     *     'discount' => 0.22,
     *     'price' => 7.02
     * ]
     */
    function orderCalcIpv4($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName) {
        return $this->orderCalc($this->prepareIpv4($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName));
    }

    /**
     * Calculate the order ISP
     * Preliminary order calculation
     * An error in warning must be corrected before placing an order.
     * @param integer $countryId
     * @param integer $periodId
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName
     * @return array Example
     * [
     *     'warning' => 'Insufficient funds. Total $2. Not enough $33.10',
     *     'balance' => 2,
     *     'total' => 35.1,
     *     'quantity' => 5,
     *     'currency' => 'USD',
     *     'discount' => 0.22,
     *     'price' => 7.02
     * ]
     */
    function orderCalcIsp($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName) {
        return $this->orderCalc($this->prepareIpv4($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName));
    }

    /**
     * Calculate the order MIX
     * Preliminary order calculation
     * An error in warning must be corrected before placing an order.
     * @param integer $countryId
     * @param integer $periodId
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName
     * @return array Example
     * [
     *     'warning' => 'Insufficient funds. Total $2. Not enough $33.10',
     *     'balance' => 2,
     *     'total' => 35.1,
     *     'quantity' => 5,
     *     'currency' => 'USD',
     *     'discount' => 0.22,
     *     'price' => 7.02
     * ]
     */
    function orderCalcMix($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName) {
        return $this->orderCalc($this->prepareIpv4($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName));
    }

    /**
     * Calculate the order IPv6
     * Preliminary order calculation
     * An error in warning must be corrected before placing an order.
     * @param integer $countryId
     * @param integer $periodId
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName
     * @param string $protocol
     * @return array Example
     * [
     *     'warning' => 'Insufficient funds. Total $2. Not enough $33.10',
     *     'balance' => 2,
     *     'total' => 35.1,
     *     'quantity' => 5,
     *     'currency' => 'USD',
     *     'discount' => 0.22,
     *     'price' => 7.02
     * ]
     */
    function orderCalcIpv6($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $protocol) {
        return $this->orderCalc($this->prepareIpv6($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $protocol));
    }

    /**
     * Calculate the order Mobile
     * Preliminary order calculation
     * An error in warning must be corrected before placing an order.
     * @param integer $countryId
     * @param integer $periodId
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param integer $operatorId
     * @param integer $rotationId
     * @return array Example
     * [
     *     'warning' => 'Insufficient funds. Total $2. Not enough $33.10',
     *     'balance' => 2,
     *     'total' => 35.1,
     *     'quantity' => 5,
     *     'currency' => 'USD',
     *     'discount' => 0.22,
     *     'price' => 7.02
     * ]
     */
    function orderCalcMobile($countryId, $periodId, $quantity, $authorization, $coupon, $operatorId, $rotationId) {
        return $this->orderCalc($this->prepareMobile($countryId, $periodId, $quantity, $authorization, $coupon, $operatorId, $rotationId));
    }

    /**
     * Calculate the order Resident
     * Preliminary order calculation
     * An error in warning must be corrected before placing an order.
     * @param integer $tarifId
     * @param string $coupon
     * @return array Example
     * [
     *     'warning' => 'Insufficient funds. Total $2. Not enough $33.10',
     *     'balance' => 2,
     *     'total' => 35.1,
     *     'quantity' => 5,
     *     'currency' => 'USD',
     *     'discount' => 0.22,
     *     'price' => 7.02
     * ]
     */
    function orderCalcResident($tarifId, $coupon) {
        return $this->orderCalc($this->prepareResident($tarifId, $coupon));
    }

    /**
     * Create an order IPv4
     * Attention! Calling this method will deduct $ from your balance!
     * The parameters are identical to the /order/calc method. Practice there before calling the /order/make method.
     * @param integer $countryId
     * @param integer $periodId
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName
     * @return array Example
     * [
     *     'orderId' => 1000000,
     *     'total' => 35.1,
     *     'balance' => 10.19
     * ]
     */
    function orderMakeIpv4($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName) {
        return $this->orderMake($this->prepareIpv4($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName));
    }

    /**
     * Create an order ISP
     * Attention! Calling this method will deduct $ from your balance!
     * The parameters are identical to the /order/calc method. Practice there before calling the /order/make method.
     * @param integer $countryId
     * @param integer $periodId
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName
     * @return array Example
     * [
     *     'orderId' => 1000000,
     *     'total' => 35.1,
     *     'balance' => 10.19
     * ]
     */
    function orderMakeIsp($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName) {
        return $this->orderMake($this->prepareIpv4($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName));
    }

    /**
     * Create an order MIX
     * Attention! Calling this method will deduct $ from your balance!
     * The parameters are identical to the /order/calc method. Practice there before calling the /order/make method.
     * @param integer $countryId
     * @param integer $periodId
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName
     * @return array Example
     * [
     *     'orderId' => 1000000,
     *     'total' => 35.1,
     *     'balance' => 10.19
     * ]
     */
    function orderMakeMix($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName) {
        return $this->orderMake($this->prepareIpv4($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName));
    }

    /**
     * Create an order IPv6
     * Attention! Calling this method will deduct $ from your balance!
     * The parameters are identical to the /order/calc method. Practice there before calling the /order/make method.
     * @param integer $countryId
     * @param integer $periodId
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param string $customTargetName
     * @param string $protocol
     * @return array Example
     * [
     *     'orderId' => 1000000,
     *     'total' => 35.1,
     *     'balance' => 10.19
     * ]
     */
    function orderMakeIpv6($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $protocol) {
        return $this->orderMake($this->prepareIpv6($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $protocol));
    }

    /**
     * Create an order Mobile
     * Attention! Calling this method will deduct $ from your balance!
     * The parameters are identical to the /order/calc method. Practice there before calling the /order/make method.
     * @param integer $countryId
     * @param integer $periodId
     * @param integer $quantity
     * @param string $authorization
     * @param string $coupon
     * @param integer $operatorId
     * @param integer $rotationId
     * @return array Example
     * [
     *     'orderId' => 1000000,
     *     'total' => 35.1,
     *     'balance' => 10.19
     * ]
     */
    function orderMakeMobile($countryId, $periodId, $quantity, $authorization, $coupon, $operatorId, $rotationId) {
        return $this->orderMake($this->prepareMobile($countryId, $periodId, $quantity, $authorization, $coupon, $operatorId, $rotationId));
    }

    /**
     * Create an order Resident
     * Attention! Calling this method will deduct $ from your balance!
     * The parameters are identical to the /order/calc method. Practice there before calling the /order/make method.
     * @param integer $tarifId
     * @param string $coupon
     * @return array Example
      * [
     *     'orderId' => 1000000,
     *     'total' => 35.1,
     *     'balance' => 10.19
     * ]
     */
    function orderMakeResident($tarifId, $coupon) {
        return $this->orderMake($this->prepareResident($tarifId, $coupon));
    }

    protected function prepareIpv4($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName) {
        $paymentId = $this->getPaymentId();
        $generateAuth = $this->getGenerateAuth();
        return compact(
                'paymentId',
                'generateAuth',
                'countryId',
                'periodId',
                'quantity',
                'authorization',
                'coupon',
                'customTargetName',
        );
    }

    protected function prepareIpv6($countryId, $periodId, $quantity, $authorization, $coupon, $customTargetName, $protocol) {
        $paymentId = $this->getPaymentId();
        $generateAuth = $this->getGenerateAuth();
        return compact(
                'paymentId',
                'generateAuth',
                'countryId',
                'periodId',
                'quantity',
                'authorization',
                'coupon',
                'customTargetName',
                'protocol',
        );
    }

    protected function prepareMobile($countryId, $periodId, $quantity, $authorization, $coupon, $operatorId, $rotationId) {
        $paymentId = $this->getPaymentId();
        $generateAuth = $this->getGenerateAuth();
        return compact(
                'paymentId',
                'generateAuth',
                'countryId',
                'periodId',
                'quantity',
                'authorization',
                'coupon',
                'operatorId',
                'rotationId'
        );
    }

    protected function prepareResident($tarifId, $coupon) {
        $paymentId = $this->getPaymentId();
        return compact(
                'paymentId',
                'tarifId',
                'coupon'
        );
    }

    /**
     * Calculate the order
     * @param array $json Free format array to send into endpoint
     * @return array
     */
    function orderCalc($json) {
        return $this->request('POST', 'order/calc', ['json' => $json]);
    }

    /**
     * Create an order
     * @param array $json Free format array to send into endpoint
     * @return array
     */
    function orderMake($json) {
        return $this->request('POST', 'order/make', ['json' => $json]);
    }

    /////////////////////////////// Prolong ///////////////////////////////
    protected function prepareProlong($ids, $periodId, $coupon) {
        return compact(
                'ids',
                'periodId',
                'coupon'
        );
    }

    /**
     * Calculate the renewal
     * @param string $type - ipv4 | ipv6 | mobile | isp | mix
     * @param array $ids
     * @param string $periodId
     * @param string $coupon
     * @return array Example
     * [
     *     'warning' => 'Insufficient funds. Total $2. Not enough $33.10',
     *     'balance' => 2,
     *     'total' => 35.1,
     *     'quantity' => 5,
     *     'currency' => 'USD',
     *     'discount' => 0.22,
     *     'price' => 7.02,
     *     'items' => [],
     *     'orders' => 1
     * ]
     */
    function prolongCalc($type, $ids, $periodId, $coupon = '') {
        return $this->request('POST', 'prolong/calc' . $type, ['json' => $this->prepareProlong($ids, $periodId, $coupon)]);
    }

    /**
     * Create a renewal order
     * Attention! Calling this method will deduct $ from your balance!
     * The parameters are identical to the /prolong/calc method. Practice there before calling the /prolong/make method.
     * @param string $type - ipv4 | ipv6 | mobile | isp | mix
     * @param array $ids
     * @param string $periodId
     * @param string $coupon
     * @return array Example
     * [
     *     'orderId' => 1000000,
     *     'total' => 35.1,
     *     'balance' => 10.19
     * ]
     */
    function prolongMake($type, $ids, $periodId, $coupon = '') {
        return $this->request('POST', 'prolong/make/' . $type, ['json' => $this->prepareProlong($ids, $periodId, $coupon)]);
    }

    /////////////////////////////// Proxy ///////////////////////////////

    /**
     * Proxies list
     * @param string $type - ipv4 | ipv6 | mobile | isp | mix | null
     * @return array Example
     * [
     *     'id' => 9876543,
     *     'order_id' => 123456,
     *     'basket_id' => 9123456,
     *     'ip' => 127.0.0.2,
     *     'ip_only' => 127.0.0.2,
     *     'protocol' => 'HTTP',
     *     'port_socks' => 50101,
     *     'port_http' => 50100,
     *     'login' => 'login',
     *     'password' => 'password',
     *     'auth_ip' => '',
     *     'rotation' => '',
     *     'link_reboot' => '#',
     *     'country' => 'France',
     *     'country_alpha3' => 'FRA',
     *     'status' => 'Active',
     *     'status_type' => 'ACTIVE',
     *     'can_prolong' => 1,
     *     'date_start' => '26.06.2023',
     *     'date_end' => '26.07.2023',
     *     'comment' => '',
     *     'auto_renew' => 'Y',
     *     'auto_renew_period' => ''
     * ]
     */
    function proxyList($type = null) {
        return $this->request('GET', 'proxy/list/' . $type);
    }

    /**
     * Proxy export of certain type in txt or csv
     * @param string $type - ipv4 | ipv6 | mobile | isp | mix | resident
     * @param string $proto - https | socks5 | ''
     * @param string $ext - txt | csv | ''
     * @param integer $listId - only for resident, if not set - will return ip from all sheets
     * @return string Example
     * login:password@127.0.0.2:50100
     */
    function proxyDownload($type, $ext = null, $proto = null, $listId = 0) {
        return $this->request('GET', 'proxy/download/' . $type, ['query' => compact('ext', 'proto', 'listId')]);
    }

    /**
     * Set proxy comment
     * @param array $ids Any id, regardless of the type of proxy
     * @param string $comment
     * @return integer Count updated proxy
     */
    function proxyCommentSet($ids, $comment = null) {
        return $this->request('POST', 'proxy/comment/set', ['json' => compact('ids', 'comment')])['updated'];
    }

    /////////////////////////////// Tools ///////////////////////////////

    /**
     * Check single proxy
     * @param string $proxy Available values - user:password@127.0.0.1:8080, user@127.0.0.1:8080, 127.0.0.1:8080
     * @return array Example result:
     *  [
     *      'ip' => '127.0.0.1',
     *      'port' => 8080,
     *      'user' => 'user',
     *      'password' => 'password',
     *      'valid' => true,
     *      'protocol' => 'HTTP',
     *      'time' => 1234
     *  ]
     */
    public function proxyCheck($proxy) {
        return $this->request('GET', 'tools/proxy/check', ['query' => compact('proxy')]);
    }

    /////////////////////////////// System ///////////////////////////////

    /**
     * Check service availability
     * @return timestamp
     */
    public function ping() {
        return $this->request('GET', 'system/ping')['pong'];
    }

    /////////////////////////////// Resident ///////////////////////////////

    /**
     * Package Information
     * Remaining traffic, end date
     * @return array Example
     * [
     *     'is_active': true,
     *     'rotation': 60,
     *     'tarif_id': 2,
     *     'traffic_limit': 7516192768,
     *     'traffic_usage': 10,
     *     'expired_at': "d.m.Y H:i:s",
     *     'auto_renew': false
     * ]
     */
    function residentPackage() {
        return $this->request('GET', 'resident/package');
    }

    /**
     * Database geo locations (zip ~300Kb, unzip ~3Mb)
     * @return binary
     */
    function residentGeo() {
        return $this->request('GET', 'resident/geo');
    }

    /**
     * List of existing ip list in a package
     * You can download the list via endpoint /proxy/download/resident?listId=123
     * @return array
     */
    function residentList() {
        return $this->request('GET', 'resident/lists');
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
     * Remove list from user package
     * @param integer $id - listId
     * @return array Updated list model
     */
    function residentListDelete($id) {
        return $this->request('DELETE', 'resident/list/delete', ['query' => compact('id')]);
    }
}
