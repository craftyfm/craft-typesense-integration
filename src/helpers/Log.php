<?php

namespace craftyfm\typesenseintegration\helpers;

use craftyfm\typesenseintegration\TypesenseIntegration;
use Craft;
use Psr\Log\LogLevel;
use yii\log\Logger;

/**
 * Level-aware logger. Messages below the configured `logLevel` are dropped,
 * everything else goes to `storage/logs/typesense-integration-*.log`.
 */
class Log
{
    public const CATEGORY = 'typesense-integration';

    public const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
        'none' => 4,
    ];

    public static function debug(string $message): void
    {
        self::log('debug', $message);
    }

    public static function info(string $message): void
    {
        self::log('info', $message);
    }

    public static function warning(string $message): void
    {
        self::log('warning', $message);
    }

    public static function error(string $message): void
    {
        self::log('error', $message);
    }

    /**
     * Maps a plugin log level to a PSR-3 level, or null when logging is disabled.
     */
    public static function psrLevel(string $level): ?string
    {
        return match ($level) {
            'debug' => LogLevel::DEBUG,
            'info' => LogLevel::INFO,
            'warning' => LogLevel::WARNING,
            'error' => LogLevel::ERROR,
            default => null,
        };
    }

    private static function log(string $level, string $message): void
    {
        $configured = TypesenseIntegration::getInstance()?->getSettings()->logLevel ?? 'warning';
        $threshold = self::LEVELS[$configured] ?? self::LEVELS['warning'];

        if (self::LEVELS[$level] < $threshold) {
            return;
        }

        $yiiLevel = match ($level) {
            'debug' => Logger::LEVEL_TRACE,
            'info' => Logger::LEVEL_INFO,
            'warning' => Logger::LEVEL_WARNING,
            default => Logger::LEVEL_ERROR,
        };

        Craft::getLogger()->log($message, $yiiLevel, self::CATEGORY);
    }
}
