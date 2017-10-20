<?php
require_once('easysibcoin.php');

use easysibcoin\EasySibcoin as EasySibcoin;

/**
 *
 * @author KM
 * @name sibcoin
 * @description Плагин оплаты с помощью Sibcoin.
 *
 * @property-read string $payout_address
 * @property-read int $confirmations
 * @property-read string $fee_level
 * @property-read bool $after
 * @property-read bool $before
 * @property-read bool $show_qr
 * @property-read bool $fee_type
 */
class sibcoinlocalPayment extends waPayment implements waIPayment
{
    /** @var  waOrder */
    private $_order;
    private $_address;
    private $_sib;
    private $_sibcoin;
    private $_currency_id = 'SIB';

    public function init()
    {
        $this->_sibcoin = new EasySibcoin('sibcoinrpc', 'password');

        return parent::init();
    }


    /**
     * Возвращает ISO3-коды валют, поддерживаемых платежной системой,
     * допустимые для выбранного в настройках протокола подключения и указанного номера кошелька продавца.
     *
     * @see waPayment::allowedCurrency()
     * @return mixed
     */
    public function allowedCurrency()
    {
        return array(
            'RUB',
        );
    }

    /**
     * Генерирует HTML-код формы оплаты.
     *
     * Платежная форма может отображаться во время оформления заказа или на странице просмотра ранее оформленного заказа.
     * Значение атрибута "action" формы может содержать URL сервера платежной системы либо URL текущей страницы (т. е. быть пустым).
     * Во втором случае отправленные пользователем платежные данные снова передаются в этот же метод для дальнейшей обработки, если это необходимо,
     * например, для проверки, сохранения в базу данных, перенаправления на сайт платежной системы и т. д.
     * @param array $payment_form_data Содержимое POST-запроса, полученное при отправке платежной формы
     *     (если в формы оплаты не указано значение атрибута "action")
     * @param waOrder $order_data Объект, содержащий всю доступную информацию о заказе
     * @param bool $auto_submit Флаг, обозначающий, должна ли платежная форма автоматически отправить данные без участия пользователя
     *     (удобно при оформлении заказа)
     * @return string HTML-код платежной формы
     * @throws waException
     */
    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        // заполняем обязательный элемент данных с описанием заказа
        if (empty($order_data['description'])) {
            $order_data['description'] = 'Заказ ' . $order_data['order_id'];
        }

        // вызываем класс-обертку, чтобы гарантировать использование данных в правильном формате
        $this->_order = waOrder::factory($order_data);

        try {
            $transaction = $this->getTransactionByOrderId($this->_order->id);
        } catch (waPaymentException $ex) {
            $transaction = false;
        }

        if (!$transaction) {
            try {
                $this->_sib = $this->getSIB($this->_order->total, $order_data['currency_id'], 'yobit');
                $this->_address = $this->createAddress(); // new unique Sibcoin address to receive payments

                $transaction_data = $this->formalizeData(array(
                    'currency_id' => $order_data['currency_id'],
                    'amount' => $this->_order->total,
                    'order_id' => $this->_order->id,
                    'type' => 'NEW',
                    'native_id' => $this->_address,
                ));

                $transaction = $this->saveTransaction($transaction_data, array(
                    'address' => $this->_address,
                    'sib' => $this->_sib,
                    'confirmations' => 0,
                ));
            } catch (waException $ex) {
                throw new waPaymentException('Ошибка получения данных: ' . $ex->getMessage());
            }
        } else {
            $this->_sib = $transaction['raw_data']['sib'];
            $this->_address = $transaction['raw_data']["address"]; // Sibcoin address to receive payments
        }

        $this->merchant_id = $transaction['merchant_id'];
        $this->app_id = $transaction['app_id'];

        $view = wa()->getView();

        // Оплата по URL или по QR-коду
        // пример: sibcoin:SQVjvk5DiToPR4ktZpHQBiWos718scGizB?amount=100.00000000&label=AlexxTrade&message=account_deposit
		$sibcoin_qr = urlencode('sibcoin:'.$this->_address.'?amount='.$this->_sib);

        $view->assign('show_qr', $this->show_qr);
        $view->assign('sibcoin_qr', $sibcoin_qr);

        // если оплачено, то уже не надо показывать адрес и QR
        $view->assign('sibcoin_paid', isset($transaction['raw_data']['paid']));
        $view->assign('sibcoin_url', $this->getRelayUrl());
        $view->assign('sibcoin_before', $this->before);
        $view->assign('sibcoin_after', $this->after);
        $view->assign('sibcoin_order_id', $this->_order->id);
        $view->assign('sibcoin_confirmations', $transaction['raw_data']['confirmations']);
        $view->assign('sibcoin_confirmation_needed', $this->confirmations);
        $view->assign('sibcoin_address', $this->_address);
        $view->assign('sibcoin_amount', $this->_sib);
        $view->assign('sibcoin_currency_id', $this->_currency_id);
        $view->assign('sibcoin_merchant_id', $this->merchant_id);
        $view->assign('sibcoin_app_id', $this->app_id);

        $view->assign('sibcoin_order_url', wa()->getRouteUrl('shop/my/order', array('id' => $this->_order->id)));
        return $view->fetch($this->path . '/templates/payment.html');
    }

    /**
     * Инициализация плагина для обработки вызовов от платежной системы.
     *
     * Для обработки вызовов по URL вида payments.php/sibcoinpay/?id=* необходимо определить
     * соответствующее приложение и идентификатор, чтобы правильно инициализировать настройки плагина.
     * @param array $request Данные запроса (массив $_REQUEST)
     * @return waPayment
     * @throws waPaymentException
     */
    protected function callbackInit($request)
    {
        if (waRequest::method() == 'get'
            && isset($request['order_id'])
            && isset($request['order_id'])
            && isset($request['hash'])
            && isset($request['merchant_id'])
            && isset($request['app_id'])
        ) {
            $transaction = $this->getTransactionByOrderId($request['order_id']);

            if ($transaction['raw_data']['address'] === $request['hash']) {
                $this->merchant_id = $request['merchant_id'];
                $this->app_id = $request['app_id'];

                $paid = isset($transaction['raw_data']['paid']);
                if (!$paid) {
                    $confirmations = $this->checkPayment($transaction['raw_data']['address'], $transaction['raw_data']['sib']);

                    $transaction_data_model = new waTransactionDataModel();
                    $transaction_data_model->updateByField(
                        array(
                            'field_id' => 'confirmations',
                            'transaction_id' => $transaction['id'],
                        ),
                        array(
                            'value' => $confirmations
                        )
                    );

                    if ($confirmations > $this->confirmations) {
                        $transaction_data_model = new waTransactionDataModel();
                        $transaction_data_model->insert(array(
                            'transaction_id' => $transaction['id'],
                            'field_id' => 'paid',
                            'value' => 1,
                        ), waModel::INSERT_ON_DUPLICATE_KEY_UPDATE
                        );
                    }
                }
            }
        }
        return parent::callbackInit($request);
    }

    private function getTransactionByOrderId($id)
    {
        $transactions = waPayment::getTransactionsByFields(array(
            'plugin' => $this->getId(),
            'order_id' => $id,
        ));
        if (!$transactions) {

            throw new waPaymentException('Транзакция не найдена');
        }
        return end($transactions);
    }

    /**
     * Обработка вызовов платежной системы.
     *
     * Проверяются параметры запроса, и при необходимости вызывается обработчик приложения.
     * Настройки плагина уже проинициализированы и доступны в коде метода.
     *
     *
     * @param array $request Данные запроса (массив $_REQUEST), полученного от платежной системы
     * @throws waPaymentException
     * @return array Ассоциативный массив необязательных параметров результата обработки вызова:
     *     'redirect' => URL для перенаправления пользователя
     *     'template' => путь к файлу шаблона, который необходимо использовать для формирования веб-страницы, отображающей результат обработки вызова платежной системы;
     *                   укажите false, чтобы использовать прямой вывод текста
     *                   если не указано, используется системный шаблон, отображающий строку 'OK'
     *     'header'   => ассоциативный массив HTTP-заголовков (в форме 'header name' => 'header value'),
     *                   которые необходимо отправить в браузер пользователя после завершения обработки вызова,
     *                   удобно для случаев, когда кодировка символов или тип содержимого отличны от UTF-8 и text/html
     *
     *     Если указан путь к шаблону, возвращаемый результат в исходном коде шаблона через переменную $result variable;
     *     параметры, переданные методу, доступны в массиве $params.
     */
    protected function callbackHandler($request)
    {
        $transaction_data = $this->getTransactionByOrderId($request['order_id']);
        $this->merchant_id = $request['merchant_id'];
        $this->app_id = $request['app_id'];

        $transaction_data['update_datetime'] = date('Y-m-d H:i:s');

        $transaction_model = new waTransactionModel();

        $confirmations = (int)$transaction_data['raw_data']['confirmations'];

        if ($confirmations < (int)$this->confirmations) { /* набираем подтверждения */
            $app_payment_method = self::CALLBACK_CONFIRMATION;
            $transaction_data['type'] = self::OPERATION_CHECK;
            $transaction_data['state'] = self::STATE_AUTH;
        }
        else { /* платеж подтвержден */
            $app_payment_method = self::CALLBACK_PAYMENT;
            $transaction_data['type'] = self::OPERATION_CAPTURE;
            $transaction_data['state'] = self::STATE_CAPTURED;
        }

        $transaction_model->updateById(
            $transaction_data['id'],
            $transaction_data
        );

        // вызываем соответствующий обработчик приложения для каждого из поддерживаемых типов транзакций
        $result = $this->execAppCallback($app_payment_method, $transaction_data);


        // в зависимости от успешности или неудачи обработки транзакции приложением отображаем сообщение либо отправляем соответствующий HTTP-заголовок
        // информацию о результате обработки дополнительно пишем в лог плагина
        if (!empty($result['result'])) {
            self::log($this->id, array('result' => 'success'));
            $response = array(
                "confirmations" => (int)$confirmations,
                "confirmations_needed" => (int)$this->confirmations,
                "paid" => !($confirmations == -1)
            );
            $message = json_encode($response);
        } else {
            $message = !empty($result['error']) ? $result['error'] : 'wa transaction error';

            header("HTTP/1.0 403 Forbidden");
        }
        echo $message;
        exit;
    }

    /**
     * Конвертирует исходные данные о транзакции, полученные от платежной системы, в формат, удобный для сохранения в базе данных.
     *
     * @param array $request Исходные данные
     * @return array $transaction_data Форматированные данные
     */
    protected function formalizeData($request)
    {
        // выполняем базовую обработку данных
        $transaction_data = parent::formalizeData($request);

        // тип транзакции
        $transaction_data['type'] = (isset($request['confirmations']) && $request['confirmations'] >= $this->confirmations) ? self::OPERATION_CAPTURE : self::OPERATION_CHECK;

        // сумма заказа
        $transaction_data['amount'] = $request['amount'];

        // код валюты
        $transaction_data['currency_id'] = $request['currency_id'];

        // номер заказа
        $transaction_data['order_id'] = $request['order_id'];

        // id сессии
        $transaction_data['native_id'] = $request['native_id'];

        return $transaction_data;
    }

    /**
     * Возвращает список операций с транзакциями, поддерживаемых плагином.
     *
     * @see waPayment::supportedOperations()
     * @return array
     */
    public function supportedOperations()
    {
        return array(
            self::OPERATION_CHECK,
            self::OPERATION_CAPTURE,
        );
    }

    /**
     * @param $address string with address that was paid
     * @param $amount int money that must be paid to address
     * @return int number of confirmations, -1 if transaction not found
     * @throws waException
     */
    public function checkPayment($address, $amount)
    {
        try {
            // достаточно смотреть последние 100 транзакций, т.к. в сибкоине они подтверждаются очень быстро
            $transactions = $this->_sibcoin->listtransactions("*", 100);
        }
        catch (EasySibcoinException $e) {
            return -1;
        }

        foreach ($transactions as $idx => $tx) {
            // транзакция должна быть на получение, с нужной суммой
            if ($tx["address"] != $address || $tx["category"] != "receive" || $tx["amount"] != $amount) {
                continue;
            }

            return $tx["confirmations"];
        }

        return -1;
    }

    /**
     * @return array|SimpleXMLElement|string
     * @throws waException
     */
    private function createAddress()
    {
        try {
            $address = $this->_sibcoin->getnewaddress(); // получить новый кошелек для пользователя
            return $address;
        }
        catch (\easysibcoin\EasySibcoinException $ex) {
            throw new waException("Failed to create new address for client: " . $ex->getMessage());
        }
    }

    /**
     * @param $amount
     * @param string $currency
     * @param string $market
     * @throws waPaymentException
     */
    private function getSIB($amount, $currency_code = 'RUB', $market = 'yobit')
    {
        if ($currency_code == 'RUB' and $market == 'yobit') {
            $ticker = json_decode(file_get_contents("https://yobit.net/api/3/ticker/sib_rur"), true);
            if (!isset($ticker["sib_rur"]) || !isset($ticker["sib_rur"]["avg"])) {
                throw new waPaymentException('Ошибка конвертации');
            }

            // coinmarketcap.com - курс продажи смотреть тут либо в обменнике
            $result = (float)$amount / (float)$ticker["sib_rur"]["avg"];
            $result = round($result, 6);
            return str_replace(',', '.', $result);
        }
        else throw new waException("Unexpected currency code $currency_code to convert into SIB");
    }
}