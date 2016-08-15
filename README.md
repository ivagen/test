# Тестовое задание (РЕДАКТИРУЕМЫЙ СПИСОК) #

## Установка ##

Необходимо иметь уже установленный **docker** и **docker-compose**.

**Клонируем проект:**

``git clone https://github.com/ivagen/test.git .``

**Заходм в папку проекта:**

``cd path/to/project/``

**Поднимаем docker контейнер:**

``sh docker/run.sh``

**Заходим в контейнер:**

``sh docker/ssh.sh``

**В контейнере заходим в папку проекта:**

``cd /var/www/``

**Запускаем деплой:**

``sh setup.sh``

## Проект готов к использованию по ссылке http://0.0.0.0 . ## 

**Для более красивого имени сайта нужно добавить записть в файл /etc/hosts :**

``0.0.0.0 test.local``

**База данных доступна по адресу http://0.0.0.0:4001 .**

*Сервер:* ``postgres``

*Имя пользователя:* ``docker``

*Пароль:* ``docker``

*База данных:* ``docker``

Так как при деплое проекта запускается демон PHPDaemon, то перед выходом из контейнера **его нужно остановить**:

``phpd stop``

**Выход из контейнера:** ``exit``

**Удалить все контейнера:**

``cd path/to/project/``

``sh docker/rmi.sh``

## P.S. ##
*Если при сборке контейнера или деплое что-то пошло не так - попытайтесь перезустить процесс.*

*Если при сборке docker контейнера консоль ругается на занятые порты - попытайтесь их освободить.*

*Если ничего не помогает - действуйте по обстоятельствам.*