<?php

return [
    'class'     => 'yii\db\Connection',
    'dsn'       => 'pgsql:host=postgres;dbname=docker',
    'username'  => 'docker',
    'password'  => 'docker',
    'charset'   => 'utf8',
    'schemaMap' => [
        'pgsql' => [
            'class'         => 'yii\db\pgsql\Schema',
            'defaultSchema' => 'public',
        ],
    ],
];