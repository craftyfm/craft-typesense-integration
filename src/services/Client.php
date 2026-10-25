<?php

namespace craftyfm\typesenseintegration\services;

use craftyfm\typesenseintegration\helpers\Log;
use craftyfm\typesenseintegration\TypesenseIntegration;
use Craft;
use Monolog\Logger;
use Throwable;
use Typesense\Client as TypesenseClient;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Creates and holds the Typesense SDK client.
 */
class Client extends Component
{
    private ?TypesenseClient $client = null;

    /**
     * @throws InvalidConfigException if the host or API key is missing
     */
    public function getClient(): TypesenseClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $settings = TypesenseIntegration::getInstance()->getSettings();

        if (!$settings->isConfigured()) {
            throw new InvalidConfigException('Typesense is not configured. Set a host and an API key in the plugin settings.');
        }

        // The SDK does not apply its own timeout setting, so timeouts are enforced
        // on the HTTP client. Craft's Guzzle client also respects Craft's proxy config.
        $httpClient = Craft::createGuzzleClient([
            'timeout' => $settings->timeout,
            'connect_timeout' => $settings->timeout,
            'http_errors' => false,
        ]);

        $this->client = new TypesenseClient([
            'api_key' => $settings->getApiKey(),
            'nodes' => $settings->getNodes(),
            'num_retries' => $settings->numRetries,
            'retry_interval_seconds' => $settings->retryInterval,
            'connection_timeout_seconds' => $settings->timeout,
            // The SDK logs to stdout; silence it and log through the plugin instead.
            'log_level' => Logger::EMERGENCY,
            'client' => $httpClient,
        ]);

        return $this->client;
    }

    /**
     * Returns whether the Typesense server is reachable and healthy.
     */
    public function isHealthy(?string &$error = null): bool
    {
        try {
            $health = $this->getClient()->getHealth()->retrieve();
            $ok = (bool)($health['ok'] ?? false);

            if (!$ok) {
                $error = 'Typesense reported an unhealthy status.';
            }

            return $ok;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            Log::warning("Health check failed: {$error}");

            return false;
        }
    }

    /**
     * Forces the client to be rebuilt, e.g. after settings change.
     */
    public function reset(): void
    {
        $this->client = null;
    }
}
