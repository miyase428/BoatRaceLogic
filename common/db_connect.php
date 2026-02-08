<?php
// db_connect.php

function getPDO()
{
    return new PDO(
        "pgsql:host=192.168.0.205;dbname=devdb",
        "miyase428",
        "herunia0113",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}