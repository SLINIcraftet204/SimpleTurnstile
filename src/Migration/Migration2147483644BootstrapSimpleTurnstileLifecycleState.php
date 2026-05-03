<?php declare(strict_types=1);

namespace SLINIcraftet204\SimpleTurnstile\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration2147483644BootstrapSimpleTurnstileLifecycleState extends MigrationStep
{
    private const ACTIVE_CAPTCHAS_CONFIG_KEY = 'core.basicInformation.activeCaptchasV2';
    private const PLUGIN_CONFIG_PREFIX = 'SimpleTurnstile.config.';
    private const CAPTCHA_NAME = 'simpleTurnstile';

    private const CONFIG_KEYS = [
        'siteKey',
        'secretKey',
        'theme',
        'size',
        'language',
        'sendRemoteIp',
        'debugLogging',
    ];

    public function getCreationTimestamp(): int
    {
        return 2147483644;
    }

    public function update(Connection $connection): void
    {
        $this->createTable($connection);

        $existingState = $this->readState($connection);
        $state = $existingState ?: [
            'schemaVersion' => 1,
            'createdAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'pluginConfigRows' => [],
            'captchaWasActive' => false,
            'captchaStateByScope' => [],
        ];

        $configRows = $this->readCurrentPluginConfigRows($connection);
        if ($configRows !== []) {
            $state['pluginConfigRows'] = $this->mergeConfigRows(
                \is_array($state['pluginConfigRows'] ?? null) ? $state['pluginConfigRows'] : [],
                $configRows
            );
            $state['pluginConfigSnapshotCreatedAt'] = (new \DateTimeImmutable())->format(\DATE_ATOM);
        }

        $captchaSnapshot = $this->readCaptchaState($connection);
        if ($captchaSnapshot !== null) {
            $state['captchaWasActive'] = $captchaSnapshot['captchaWasActive'];
            $state['captchaStateByScope'] = $captchaSnapshot['captchaStateByScope'];
            $state['captchaStateUpdatedAt'] = (new \DateTimeImmutable())->format(\DATE_ATOM);
        }

        $state['schemaVersion'] = 1;
        $state['lastLifecycleAction'] = 'migration.bootstrapLifecycleState';
        $state['lastLifecycleActionAt'] = (new \DateTimeImmutable())->format(\DATE_ATOM);

        $this->writeState($connection, $state);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function createTable(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS `simple_turnstile_lifecycle_state` (
                `state_key` VARCHAR(64) NOT NULL,
                `state_value` LONGTEXT NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`state_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function readState(Connection $connection): array
    {
        $rawValue = $connection->fetchOne(
            'SELECT state_value FROM simple_turnstile_lifecycle_state WHERE state_key = :stateKey LIMIT 1',
            ['stateKey' => 'lifecycle']
        );

        if (!\is_string($rawValue) || trim($rawValue) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawValue, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    private function writeState(Connection $connection, array $state): void
    {
        $encodedState = json_encode(
            $state,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        );

        $connection->executeStatement(
            'INSERT INTO simple_turnstile_lifecycle_state
                (state_key, state_value, created_at, updated_at)
             VALUES
                (:stateKey, :stateValue, NOW(3), NULL)
             ON DUPLICATE KEY UPDATE
                state_value = VALUES(state_value),
                updated_at = NOW(3)',
            [
                'stateKey' => 'lifecycle',
                'stateValue' => $encodedState,
            ]
        );
    }

    private function readCurrentPluginConfigRows(Connection $connection): array
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT configuration_key,
                    configuration_value,
                    LOWER(HEX(sales_channel_id)) AS sales_channel_id_hex
             FROM system_config
             WHERE configuration_key LIKE :prefix
             ORDER BY configuration_key ASC, sales_channel_id ASC',
            ['prefix' => self::PLUGIN_CONFIG_PREFIX . '%']
        );

        $result = [];

        foreach ($rows as $row) {
            $configurationKey = $row['configuration_key'] ?? null;
            $configurationValue = $row['configuration_value'] ?? null;
            $salesChannelIdHex = $this->normalizeSalesChannelIdHex($row['sales_channel_id_hex'] ?? null);

            if (!$this->isManagedPluginConfigKey($configurationKey)) {
                continue;
            }

            if (!\is_string($configurationValue) || trim($configurationValue) === '') {
                continue;
            }

            $result[] = [
                'configurationKey' => $configurationKey,
                'configurationValue' => $configurationValue,
                'salesChannelIdHex' => $salesChannelIdHex,
            ];
        }

        return $result;
    }

    private function mergeConfigRows(array $existingRows, array $currentRows): array
    {
        $merged = [];

        foreach ([$existingRows, $currentRows] as $rows) {
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }

                $configurationKey = $row['configurationKey'] ?? null;
                $configurationValue = $row['configurationValue'] ?? null;
                $salesChannelIdHex = $this->normalizeSalesChannelIdHex($row['salesChannelIdHex'] ?? null);

                if (!$this->isManagedPluginConfigKey($configurationKey)) {
                    continue;
                }

                if (!\is_string($configurationValue) || trim($configurationValue) === '') {
                    continue;
                }

                $merged[($salesChannelIdHex ?? 'global') . '::' . $configurationKey] = [
                    'configurationKey' => $configurationKey,
                    'configurationValue' => $configurationValue,
                    'salesChannelIdHex' => $salesChannelIdHex,
                ];
            }
        }

        return array_values($merged);
    }

    private function readCaptchaState(Connection $connection): ?array
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT LOWER(HEX(sales_channel_id)) AS sales_channel_id_hex,
                    configuration_value
             FROM system_config
             WHERE configuration_key = :configurationKey',
            ['configurationKey' => self::ACTIVE_CAPTCHAS_CONFIG_KEY]
        );

        $captchaStateByScope = [];
        $wasActiveSomewhere = false;
        $found = false;

        foreach ($rows as $row) {
            $configurationValue = $row['configuration_value'] ?? null;

            if (!\is_string($configurationValue) || trim($configurationValue) === '') {
                continue;
            }

            $activeCaptchas = $this->decodeSystemConfigValue($configurationValue);

            if (!\is_array($activeCaptchas) || !array_key_exists(self::CAPTCHA_NAME, $activeCaptchas)) {
                continue;
            }

            $found = true;
            $scopeKey = $this->normalizeSalesChannelIdHex($row['sales_channel_id_hex'] ?? null) ?? 'global';
            $captchaConfig = $activeCaptchas[self::CAPTCHA_NAME];
            $wasActive = \is_array($captchaConfig) && ($captchaConfig['isActive'] ?? false) === true;

            $captchaStateByScope[$scopeKey] = [
                'wasActive' => $wasActive,
                'capturedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            ];

            if ($wasActive) {
                $wasActiveSomewhere = true;
            }
        }

        if (!$found) {
            return null;
        }

        return [
            'captchaWasActive' => $wasActiveSomewhere,
            'captchaStateByScope' => $captchaStateByScope,
        ];
    }

    private function decodeSystemConfigValue(string $configurationValue): mixed
    {
        try {
            $decoded = json_decode($configurationValue, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (\is_array($decoded) && array_key_exists('_value', $decoded)) {
            return $decoded['_value'];
        }

        return $decoded;
    }

    private function isManagedPluginConfigKey(mixed $configurationKey): bool
    {
        if (!\is_string($configurationKey) || !str_starts_with($configurationKey, self::PLUGIN_CONFIG_PREFIX)) {
            return false;
        }

        $shortKey = substr($configurationKey, \strlen(self::PLUGIN_CONFIG_PREFIX));

        return \in_array($shortKey, self::CONFIG_KEYS, true);
    }

    private function normalizeSalesChannelIdHex(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return preg_match('/^[0-9a-f]{32}$/', $value) === 1 ? $value : null;
    }
}
