<?php
// db_connect.php

require_once __DIR__ . '/db_env.php';

function getPDO()
{
    $db = loadDbEnv();

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $db['host'],
        $db['port'],
        $db['dbname']
    );

    return new PDO(
        $dsn,
        $db['user'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}
