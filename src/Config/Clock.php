<?php

declare(strict_types=1);

namespace GsppManager\Config;

class Clock
{
    private static ?int $now = null;

    public static function now(): int
    {
        return self::$now ?? time();
    }

    public static function today(): string
    {
        return date('Y-m-d', self::now());
    }

    public static function setNow(int $timestamp): void
    {
        self::$now = $timestamp;
    }

    public static function reset(): void
    {
        self::$now = null;
    }
}
