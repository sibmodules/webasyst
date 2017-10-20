<?php
return array(
    'confirmations'    => array(
        'value'        => '3',
        'title'        => 'Количество подтверждений',
        'description'  => 'Число принятых подтверждений платежа в сети Sibcoin. <b>Не рекомендуется устанавливать ниже 3</b>',
        'control_type' => waHtmlControl::INPUT,
    ),
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
    'info'             => array(
        'value'        => '',
        'description'  => 'Используется API локального кошелька Sibcoin. Вам необходимо скачать и установить кошелек с http://sibcoin.org/download',
        'control_type' => waHtmlControl::HELP,
    ),
);
