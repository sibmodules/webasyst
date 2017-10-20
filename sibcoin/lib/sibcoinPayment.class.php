<?php
/**
 *
 * @author sibmodules
 * @name sibcoin
 * @description Плагин оплаты с помощью Sibcoin.
 *
 * @property-read int $_order
 * @property-read string $_address
 * @property-read float $_sib
 * @property-read object $_coinexapi
 * @property-read int $_confirmed
 * @property-read string $_currency_id
 * @property-read string $_error
 */
class sibcoinPayment extends waPayment implements waIPayment
{
    /** @var  waOrder */
    private $_order;
    private $_address;
    private $_sib;
    private $_coinexapi;
    private $_confirmed;
    private $_currency_id = 'SIB';
    private $_error;

    public function init()
    {
        waAutoload::getInstance()->add(array(
                'CoinexApi' => 'wa-plugins/payment/sibcoin/lib/coinexApi.class.php',
            )
        );

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

        // создаем экземпляр класса для общения с API обменника
        $this->_coinexapi = new CoinexApi($this->api_key, waRequest::getIp());

        try {
            $transaction = $this->getTransactionByOrderId($this->_order->id);
        } catch (waPaymentException $ex) {
            $transaction = false;
        }

        if (!$transaction) {
            try {
                $this->_coinexapi->beginTransaction($this->destination_address, $this->_order->total, $this->email);
            } catch (CoinexApiException $ex) {
                throw new waPaymentException($ex);
            }

            // Sibcoin allows maximum 8 digits precision
            $this->_sib = round((float)$this->_order->total / (float)$this->_coinexapi->getRate(), 8);
            $this->_address = $this->_coinexapi->getPaymentAddress(); // new unique Sibcoin address to receive payments
            $this->_confirmed = 0;

            $transaction_data = $this->formalizeData(array(
                'currency_id' => $order_data['currency_id'],
                'amount' => $this->_order->total,
                'order_id' => $this->_order->id,
                'type' => 'NEW',
                'native_id' => $this->_coinexapi->getSession(),
            ));

            try {
                // при первом вызове необходимо сохранить в базу сессию обменника и выданный адрес для платежа,
                // на случай, если пользователь перезагрузит страницу.
                $transaction = $this->saveTransaction($transaction_data, array(
                    'address' => $this->_address,
                    'sib' => $this->_sib,
                    'session' => $this->_coinexapi->getSession(),
                    'confirmed' => $this->_confirmed,
                    'paid' => $this->_coinexapi->checkTransactionStatus($this->_sib),
                ));
            } catch (CoinexApiException $ex) {
                $this->_error = $ex->getMessage();
            }
        } else {
            $this->_sib = $transaction['raw_data']['sib'];
            $this->_address = $transaction['raw_data']["address"]; // Sibcoin address to receive payments
            $this->_confirmed = (int)$transaction['raw_data']["confirmed"];
            $this->_coinexapi->setSession($transaction['raw_data']["session"]); // coinex session

            // Если ещё не оплачено, то обновляем статус сессии
            if (!$transaction['raw_data']['paid']) {
                try {
                    $transaction_data_model = new waTransactionDataModel();
                    $transaction_data_model->updateByField(
                        array(
                            'field_id' => 'paid',
                            'transaction_id' => $transaction['id'],
                        ),
                        array(
                            'value' => $this->_coinexapi->checkTransactionStatus($this->_sib),
                        )
                    );
                } catch (CoinexApiException $ex) {
                    $this->_error = $ex->getMessage();
                }
            }
        }

        $this->merchant_id = $transaction['merchant_id'];
        $this->app_id = $transaction['app_id'];

        $view = wa()->getView();

        // Оплата по URL или по QR-коду
        // пример: sibcoin:SQVjvk5DiToPR4ktZpHQBiWos718scGizB?amount=100.00000000&label=AlexxTrade&message=account_deposit
        $view->assign('show_qr', $this->show_qr);

        // если оплачено, то уже не надо показывать адрес и QR
        $view->assign('sibcoin_paid', isset($transaction['raw_data']['paid']) && $transaction['raw_data']['paid']);
        $view->assign('sibcoin_url', $this->getRelayUrl());
        $view->assign('sibcoin_before', $this->before);
        $view->assign('sibcoin_after', $this->after);
        $view->assign('sibcoin_order_id', $this->_order->id);
        $view->assign('sibcoin_address', $this->_address);
        $view->assign('sibcoin_amount', $this->_sib);
        $view->assign('sibcoin_currency_id', $this->_currency_id);
        $view->assign('sibcoin_merchant_id', $this->merchant_id);
        $view->assign('sibcoin_app_id', $this->app_id);
        $view->assign('sibcoin_error', $this->_error);
        $view->assign('sibcoin_confirmed', !!$this->_confirmed);
        $view->assign('sibcoin_session_url', $this->_coinexapi->getSessionURL());
        $view->assign('sibcoin_session', $this->_coinexapi->getSession());
        $view->assign('sibcoin_order_url', wa()->getRouteUrl('shop/my/order', array('id' => $this->_order->id)));;
        return $view->fetch($this->path . '/templates/payment.html');
    }

    /**
     * Инициализация плагина для обработки вызовов от платежной системы.
     *
     * Для обработки вызовов по URL вида payments.php/sibcoin/?id=* необходимо определить
     * соответствующее приложение и идентификатор, чтобы правильно инициализировать настройки плагина.
     * @param array $request Данные запроса (массив $_REQUEST)
     * @return waPayment
     * @throws waPaymentException
     */
    protected function callbackInit($request)
    {
        if (waRequest::method() == 'get'
                && isset($request['order_id'])
                && isset($request['hash'])
                && isset($request['merchant_id'])
                && isset($request['app_id'])
                && isset($request['confirmed'])) {
            $transaction = $this->getTransactionByOrderId($request['order_id']);

            if ($transaction['raw_data']['session'] === $request['hash']) {
                $this->merchant_id = $request['merchant_id'];
                $this->app_id = $request['app_id'];
                $this->_confirmed = (int)$transaction['raw_data']['confirmed'] ? 1 : (int)$request['confirmed'];

                $transaction_data_model = new waTransactionDataModel();
                $transaction_data_model->updateByField(
                    array(
                        'field_id' => 'confirmed',
                        'transaction_id' => $transaction['id'],
                    ),
                    array(
                        'value' => $this->_confirmed,
                    )
                );


                $paid = $transaction['raw_data']['paid'];
                if (!$paid) {
                    $this->_coinexapi = new CoinexApi($this->api_key, waRequest::getIp());
                    $this->_coinexapi->setSession($transaction['raw_data']['session']);
                    try {
                        $this->_sib = $transaction['raw_data']['sib'];
                        $paid = $this->_coinexapi->checkTransactionStatus($this->_sib);

                        if ($paid) {
                            $transaction_data_model = new waTransactionDataModel();
                            $transaction_data_model->updateByField(
                                array(
                                    'field_id' => 'paid',
                                    'transaction_id' => $transaction['id'],
                                ),
                                array(
                                    'value' => $paid,
                                )
                            );
                        }
                    } catch (CoinexApiException $ex) {
                        $this->_error = $ex->getMessage();
                    }
                }
            }
        }
        return parent::callbackInit($request);
    }

    /**
     * Получение транзакции из БД по номеру заказа.
     *
     * @param int $id Внутренний id заказа Webasyst
     * @throws waPaymentException
     * @return array Ассоциативный массив необязательных параметров результата обработки вызова
     */
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
     * @param array $request Данные запроса (массив $_REQUEST), полученного от платежной системы
     * @throws waPaymentException
     * @return array Ассоциативный массив необязательных параметров результата обработки вызова
     */
    protected function callbackHandler($request)
    {
        $transaction = $this->getTransactionByOrderId($request['order_id']);
        $this->merchant_id = $request['merchant_id'];
        $this->app_id = $request['app_id'];
        $transaction['update_datetime'] = date('Y-m-d H:i:s');

        $transaction_model = new waTransactionModel();
        $paid = $transaction['raw_data']['paid'];

        if (!$paid) {
            $app_payment_method = self::CALLBACK_CONFIRMATION;
            $transaction['type'] = self::OPERATION_CHECK;
            $transaction['state'] = self::STATE_CAPTURED;
        }
        else {
            $app_payment_method = self::CALLBACK_PAYMENT;
            $transaction['type'] = self::OPERATION_CAPTURE;
            $transaction['state'] = self::STATE_CAPTURED;
        }

        $transaction_model->updateById(
            $transaction['id'],
            $transaction
        );

        // вызываем соответствующий обработчик приложения для каждого из поддерживаемых типов транзакций
        $result = $this->execAppCallback($app_payment_method, $transaction);

        // в зависимости от успешности или неудачи обработки транзакции приложением отображаем сообщение либо отправляем соответствующий HTTP-заголовок
        // информацию о результате обработки дополнительно пишем в лог плагина
        if (!empty($result['result'])) {
            $response = array(
                "err" => $this->_error,
                "paid" => (int)$paid
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
}