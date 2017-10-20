<?php
class CoinexApiException extends \Exception {}

class CoinexApi
{
    private $_API_KEY;
    private $_API_URL = 'https://coinex.im/api/';
    private $_SESSION_URL = 'https://coinex.im/?session=';
    private $_X_API_USERID = 'COINEX';
    private $_SESSION;

    private $_USER_IP;
    private $_CURRENT_RATE;
    private $_SIBCOIN_ADDRESS;

    function __construct($api_key, $user_ip)
    {
        $this->_API_KEY = $api_key;
        $this->_USER_IP = $user_ip;
    }

    private function _requestApi($params)
    {
        $curl = curl_init();

        $options = array(
            CURLOPT_URL => $this->_API_URL,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_HTTPHEADER => array('Content-type: application/json'),
            CURLOPT_POST => TRUE,
            CURLOPT_SSL_VERIFYPEER => FALSE,
            CURLOPT_POSTFIELDS => json_encode($params)
        );

        curl_setopt_array($curl, $options);

        // Execute the request and decode to an array
        $raw_response = curl_exec($curl);

        $response = json_decode($raw_response, TRUE);

        // If there was no error, this will be an empty string
        $curl_error = curl_error($curl);

        curl_close($curl);

        if (!empty($curl_error)) {
            throw new CoinexApiException($curl_error);
        }

        if ($response['status'] == 'error') {
            throw new CoinexApiException("Ошибка обменника: " . $response['result']['error']);
        }
        else if ($response['status'] != 'success') {
            throw new CoinexApiException("Неизвестная ошибка обменника.");
        }

        return $response['result'];
    }

    private function _startSession() {
        $init_params = array(
            'api_key'       => $this->_API_KEY,
            'command'       => 'session_start',
            'X_API_USERID'  => $this->_X_API_USERID,
            'user_ip'       => $this->_USER_IP);

        $reply = $this->_requestApi($init_params);
        $this->_SESSION = $reply['session'];
        if (!strlen($this->_SESSION)) {
            throw new CoinexApiException("Не удалось получить сессию для платежа.");
        }
    }

    private function _continueSession($destination_account, $currency_amount, $email)
    {
        $session_params = array(
            'api_key' => $this->_API_KEY,
            'command' => 'session_continue',
            'X_API_USERID' => $this->_X_API_USERID,
            'session' => $this->_SESSION,
            'currency_from' => 'SIB',
            'currency_from_value' => $currency_amount,
            'referral' => null,
            'currency_to' => 'QIWI_RUR_P2P',
            'destination_account' => $destination_account,
            'contact_email' => $email,
            'coupon' => '',
            'valid_till' => date("Y-m-d H:i:s", strtotime("+2 hours", time()))
        );

        $this->_requestApi($session_params);
    }

    private function _checkSession()
    {
        $transaction_params = array(
            'api_key' => $this->_API_KEY,
            'command' => 'session_check',
            'session' => $this->_SESSION
        );

        return $this->_requestApi($transaction_params);
    }

    public function getRate() {
        return $this->_CURRENT_RATE;
    }

    public function getPaymentAddress() {
        return $this->_SIBCOIN_ADDRESS;
    }

    public function getSession() {
        return $this->_SESSION;
    }

    public function setSession($session) {
        $this->_SESSION = $session;
    }

    public function getSessionURL() {
        return $this->_SESSION_URL . $this->_SESSION;
    }

    public function beginTransaction($destination_account, $currency_amount, $email)
    {
        $this->_startSession();
        $this->_continueSession($destination_account, $currency_amount, $email);

        $response = $this->_checkSession();

        $this->_SIBCOIN_ADDRESS = $response['session_data']['currency_from_details']['address'];
        $this->_CURRENT_RATE = $response['session_data']['rate'];
    }

    public function checkTransactionStatus($estimated_sum) {
        $reply = $this->_checkSession();
        $session_data = $reply['session_data'];

        $txs = $session_data['tx'];

        $txlen = count($txs);
        if ($txlen <= 0) {
            return 0;
        }

        $tx_sum = 0;
        $status = 0;

        foreach ($txs as $tx) {
            if(strtotime($tx['created_at']) > strtotime($session_data['valid_till'])) {
                throw new CoinexApiException("Ошибка обменника: cрок обмена истек, пожалуйста, начните новый обмен.");
            }

            switch ($tx["status"]) {
                case "withdrawal_ordered":
                    $tx_sum += (float)$tx['value_from'];
                    break;
                case "deposit_late":
                case "range_error":
                case "withdrawal_complete":
                default:
                    break;
            }
        }

        if($tx_sum >= $estimated_sum) {
            $status  = 1;
        }

        return $status;
    }
}