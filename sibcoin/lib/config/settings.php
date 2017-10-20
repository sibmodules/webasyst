<?php
return array(
    'show_qr'          => array(
        'value'        => true,
        'title'        => 'Показывать QR код',
        'control_type' => waHtmlControl::CHECKBOX,
    ),
    'before' => array(
        'value'        => 'Оплата Sibcoin',
        'title'        => 'Текст перед данными для оплаты',
        'control_type' => waHtmlControl::INPUT,
        'description'  => 'Текст ДО данных платежа.',
    ),
    'after'  => array(
        'value'        => 'Сумма в Sibcoin, сконвертирована по курсу на текущий момент.',
        'title'        => 'Текст после данных для оплаты',
        'control_type' => waHtmlControl::INPUT,
        'description'  => 'Текст ПОСЛЕ данных платежа.',
    ),
    'api_key' => array(
        'value'        => 'YOUR_KEY',
        'title'        => 'API key',
        'control_type' => waHtmlControl::INPUT,
        'description'  => 'Ваш ключ API для https://coinex.im',
    ),
    'destination_address' => array(
        'value'        => '+79161234567',
        'title'        => 'Счет QIWI',
        'control_type' => waHtmlControl::INPUT,
        'description'  => 'Номер мобильного, к которому привязан счет QIWI (для вывода в рубли)',
    ),
    'email' => array(
        'value'        => 'your@email.ru',
        'title'        => 'e-mail',
        'control_type' => waHtmlControl::INPUT,
        'description'  => 'Ваш адрес электронной почты для получения информации по платежам',
    ),
);
