<?php

namespace Longman\TelegramBot;

use Longman\TelegramBot\Exception\TelegramException;

class DBFactory
{
    protected static $instance;

    /**
     * @return DBBase
     * @throws TelegramException
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            throw new TelegramException('DB is not instantiated!');
        }

        return self::$instance;
    }

    public static function initMysql(array $credential, Telegram $telegram, $table_prefix = null, $encoding = 'utf8mb4')
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $db = new DB();
        $db->initialize($credential, $telegram, $table_prefix, $encoding);
        self::$instance = $db;

        return self::$instance;
    }

    public static function initMysqlExternal($external_pdo_connection, Telegram $telegram, $table_prefix)
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $db = new DB();
        $db->externalInitialize($external_pdo_connection, $telegram, $table_prefix);
        self::$instance = $db;

        return self::$instance;
    }

    public static function initMongoDb(array $credentials, Telegram $telegram, $table_prefix = null)
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $db = new DBMongo();
        $db->initialize($credentials, $telegram, $table_prefix);
        self::$instance = $db;

        return self::$instance;
    }
}