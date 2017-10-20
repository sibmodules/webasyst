# webasyst
https://github.com/sibmodules/webasyst/releases/
1. На линуксе сделать каталог ~/.sibcoin, а в нем файл sibcoin.conf. На Windows каталог будет %appdata%\Sibcoin В файле sibcoin.conf написать: rpcuser=sibcoinrpc rpcpassword=password rpcallow=127.0.0.1 

2. Скачать кошелек для своей платформы с http://sibcoin.org/download . Для Windows нужно будет установить кошелек, для Linux - просто распаковать архив. 

3. Запустить sibcoind, подождать синхронизацию блокчейна. Убедиться, что синхронизация закончилась, можно командой sibcoin-cli mnsync status - когда пройдет полная синхронизация - одна из строк станет "IsSynced": true 

4. Распаковать плагин для оплаты в webasyst: wa/wa-plugins/payment/ 5. Включить оплату: перейти Магазин - Настройки - Оплата и добавить способ оплаты "Sibcoin Pay" 

PS. Кошелек желательно зашифровать, особенно если сайт на внешнем хостинге, для linux и windows без GUI приведены команды консоли: sibcoin-cli encryptwallet "пароль" просмотреть текущий баланс: sibcoin-cli getbalance для перевода на другой кошелек/биржу  
1. разблокировать кошелек на 100 секунд sibcoin-cli walletpassphrase "пароль" 100 
2. перевести на другой аккаунт sendtoaddress "sibcoinaddress" amount полный перечень команд можно посмотреть так: sibcoin-cli help
