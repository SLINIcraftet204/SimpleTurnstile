<?php declare(strict_types=1);

namespace SLINIcraftet204\SimpleTurnstile\Core\Installer;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use SLINIcraftet204\SimpleTurnstile\Storefront\Framework\Captcha\SimpleTurnstileCaptcha;

class CaptchaConfigurationInstaller
{
    private const ACTIVE_CAPTCHAS_CONFIG_KEY = 'core.basicInformation.activeCaptchasV2';
    private const PLUGIN_CONFIG_PREFIX = 'SimpleTurnstile.config.';

    private const STATE_TABLE = 'simple_turnstile_lifecycle_state';
    private const STATE_KEY = 'lifecycle';
    private const STATE_VERSION = 2;

    private const GLOBAL_SCOPE_KEY = 'global';

    private const CONFIG_KEYS = [
        'siteKey',
        'secretKey',
        'theme',
        'size',
        'language',
        'sendRemoteIp',
        'debugLogging',
    ];

    private const CONFIG_DEFAULTS = [
        'siteKey' => '',
        'secretKey' => '',
        'theme' => 'auto',
        'size' => 'normal',
        'language' => 'auto',
        'sendRemoteIp' => false,
        'debugLogging' => false,
    ];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly Connection $connection
    ) {
    }

    public function install(): void
    {
        $this->recordLifecycleAction('install.start');
        $this->restoreUserConfigSnapshot();
        $this->ensureCaptchaRegistered(false);
        $this->synchronizeCurrentState('install.end', false);
        $this->registerRestoreFinalizer(false, 'install.shutdown');
    }

    public function postInstall(): void
    {
        $this->recordLifecycleAction('postInstall.start');
        $this->restoreUserConfigSnapshot();
        $this->ensureCaptchaRegistered(false);
        $this->synchronizeCurrentState('postInstall.end', false);
        $this->registerRestoreFinalizer(false, 'postInstall.shutdown');
    }

    public function activate(): void
    {
        $this->recordLifecycleAction('activate.start');
        $this->restoreUserConfigSnapshot();
        $this->ensureCaptchaRegistered(true);
        $this->synchronizeCurrentState('activate.end', true);
        $this->registerRestoreFinalizer(true, 'activate.shutdown');
    }

    public function beforeDeactivate(): void
    {
        $this->recordLifecycleAction('beforeDeactivate.start');
        $this->snapshotUserConfig('beforeDeactivate.configSnapshot', true);
        $this->snapshotCaptchaState('beforeDeactivate.captchaSnapshot', true);
        $this->removeCaptchaFromAllActiveCaptchaConfigs();
        $this->recordLifecycleAction('beforeDeactivate.end');
        $this->registerRestoreConfigAndRemoveCaptchaFinalizer('deactivate.shutdown');
    }

    public function afterDeactivate(): void
    {
        $this->recordLifecycleAction('afterDeactivate.start');
        $this->restoreUserConfigSnapshot();
        $this->removeCaptchaFromAllActiveCaptchaConfigs();
        $this->recordLifecycleAction('afterDeactivate.end');
        $this->registerRestoreConfigAndRemoveCaptchaFinalizer('afterDeactivate.shutdown');
    }

    public function beforeUninstall(bool $removeUserData): void
    {
        $this->recordLifecycleAction($removeUserData ? 'beforeUninstallRemoveUserData.start' : 'beforeUninstallKeepUserData.start');

        if (!$removeUserData) {
            $this->snapshotUserConfig('beforeUninstall.keepUserData.configSnapshot', true);
            $this->snapshotCaptchaState('beforeUninstall.keepUserData.captchaSnapshot', true);
        }

        $this->removeCaptchaFromAllActiveCaptchaConfigs();
        $this->recordLifecycleAction($removeUserData ? 'beforeUninstallRemoveUserData.end' : 'beforeUninstallKeepUserData.end');

        if (!$removeUserData) {
            $this->registerRestoreConfigAndRemoveCaptchaFinalizer('uninstallKeepUserData.shutdown');
        }
    }

    public function afterUninstall(bool $removeUserData): void
    {
        if ($removeUserData) {
            $this->recordLifecycleAction('afterUninstallRemoveUserData.start');
            $this->removeCaptchaFromAllActiveCaptchaConfigs();
            $this->removePluginConfiguration();
            $this->dropLifecycleStateTable();

            return;
        }

        $this->recordLifecycleAction('afterUninstallKeepUserData.start');
        $this->restoreUserConfigSnapshot();
        $this->removeCaptchaFromAllActiveCaptchaConfigs();
        $this->synchronizeCurrentState('afterUninstallKeepUserData.end', false);
        $this->registerRestoreConfigAndRemoveCaptchaFinalizer('afterUninstallKeepUserData.shutdown');
    }

    public function beforeUpdate(): void
    {
        $this->recordLifecycleAction('beforeUpdate.start');
        $this->snapshotUserConfig('beforeUpdate.configSnapshot', true);
        $this->snapshotCaptchaState('beforeUpdate.captchaSnapshot', true);
        $this->recordLifecycleAction('beforeUpdate.end');
    }

    public function afterUpdate(): void
    {
        $this->recordLifecycleAction('afterUpdate.start');
        $this->restoreUserConfigSnapshot();
        $this->ensureCaptchaRegistered(true);
        $this->synchronizeCurrentState('afterUpdate.end', true);
        $this->registerRestoreFinalizer(true, 'afterUpdate.shutdown');
    }

    public function postUpdate(): void
    {
        $this->recordLifecycleAction('postUpdate.start');
        $this->restoreUserConfigSnapshot();
        $this->ensureCaptchaRegistered(true);
        $this->synchronizeCurrentState('postUpdate.end', true);
        $this->registerRestoreFinalizer(true, 'postUpdate.shutdown');
    }

    /**
     * Used by the storefront/admin request subscriber. This keeps the backup table fresh after the merchant saves plugin config,
     * not only when a plugin lifecycle action is executed.
     */
    public function synchronizeCurrentState(string $source, bool $captureCaptchaState = true): void
    {
        $this->recordLifecycleAction($source . '.sync.start');
        $this->snapshotUserConfig($source . '.configSnapshot', false);

        if ($captureCaptchaState) {
            $this->snapshotCaptchaState($source . '.captchaSnapshot', false);
        }

        $this->recordLifecycleAction($source . '.sync.end');
    }

    private function recordLifecycleAction(string $lifecycleAction): void
    {
        $state = $this->readLifecycleState();
        $now = $this->now();

        $state['schemaVersion'] = self::STATE_VERSION;
        $state['createdAt'] ??= $now;
        $state['lastLifecycleAction'] = $lifecycleAction;
        $state['lastLifecycleActionAt'] = $now;
        $state['pluginConfigRows'] = \is_array($state['pluginConfigRows'] ?? null) ? $state['pluginConfigRows'] : [];
        $state['captchaStateByScope'] = \is_array($state['captchaStateByScope'] ?? null) ? $state['captchaStateByScope'] : [];
        $state['captchaWasActive'] = (bool) ($state['captchaWasActive'] ?? false);

        $this->appendStateLog($state, $lifecycleAction, $now);
        $this->writeLifecycleState($state);
    }

    private function appendStateLog(array &$state, string $action, string $at): void
    {
        $log = \is_array($state['lifecycleLog'] ?? null) ? $state['lifecycleLog'] : [];
        $log[] = [
            'action' => $action,
            'at' => $at,
        ];

        if (\count($log) > 30) {
            $log = \array_slice($log, -30);
        }

        $state['lifecycleLog'] = $log;
    }

    private function snapshotUserConfig(string $source, bool $force): void
    {
        $state = $this->readLifecycleState();
        $existingRows = \is_array($state['pluginConfigRows'] ?? null) ? $state['pluginConfigRows'] : [];
        $currentRows = $this->readCurrentPluginConfigRows();
        $now = $this->now();

        if ($currentRows === []) {
            $state['lastPluginConfigSnapshotSkippedAt'] = $now;
            $state['lastPluginConfigSnapshotSkippedReason'] = 'no-current-system-config-rows';
            $state['lastPluginConfigSnapshotSource'] = $source;
            $this->writeLifecycleState($state);

            return;
        }

        $mergedRows = $this->mergePluginConfigRows($existingRows, $currentRows, $force);

        $state['pluginConfigRows'] = $mergedRows;
        $state['pluginConfigRowsHash'] = $this->hashRows($mergedRows);
        $state['pluginConfigSnapshotCreatedAt'] = $now;
        $state['lastPluginConfigSnapshotSource'] = $source;
        unset($state['lastPluginConfigSnapshotSkippedReason']);

        $this->writeLifecycleState($state);
    }

    private function restoreUserConfigSnapshot(): void
    {
        $state = $this->readLifecycleState();
        $rows = $state['pluginConfigRows'] ?? null;

        if (!\is_array($rows) || $rows === []) {
            return;
        }

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

            $decodedValue = $this->decodeSystemConfigValue($configurationValue);
            $this->writeSystemConfigValue($configurationKey, $decodedValue, $salesChannelIdHex);
        }

        $state['lastPluginConfigRestoreAt'] = $this->now();
        $state['lastPluginConfigRestoreRows'] = \count($rows);
        $this->writeLifecycleState($state);
    }

    private function readCurrentPluginConfigRows(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT configuration_key,
                    configuration_value,
                    LOWER(HEX(sales_channel_id)) AS sales_channel_id_hex
             FROM system_config
             WHERE configuration_key LIKE :prefix
             ORDER BY configuration_key ASC, sales_channel_id ASC',
            [
                'prefix' => self::PLUGIN_CONFIG_PREFIX . '%',
            ]
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

    private function mergePluginConfigRows(array $existingRows, array $currentRows, bool $force): array
    {
        $merged = [];

        foreach ($existingRows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $mapKey = $this->buildConfigRowMapKey($row['configurationKey'] ?? null, $row['salesChannelIdHex'] ?? null);
            $configurationValue = $row['configurationValue'] ?? null;

            if ($mapKey === null || !\is_string($configurationValue) || trim($configurationValue) === '') {
                continue;
            }

            $merged[$mapKey] = [
                'configurationKey' => $row['configurationKey'],
                'configurationValue' => $configurationValue,
                'salesChannelIdHex' => $this->normalizeSalesChannelIdHex($row['salesChannelIdHex'] ?? null),
            ];
        }

        foreach ($currentRows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $mapKey = $this->buildConfigRowMapKey($row['configurationKey'] ?? null, $row['salesChannelIdHex'] ?? null);
            $configurationValue = $row['configurationValue'] ?? null;

            if ($mapKey === null || !\is_string($configurationValue) || trim($configurationValue) === '') {
                continue;
            }

            $candidate = [
                'configurationKey' => $row['configurationKey'],
                'configurationValue' => $configurationValue,
                'salesChannelIdHex' => $this->normalizeSalesChannelIdHex($row['salesChannelIdHex'] ?? null),
            ];

            $existing = $merged[$mapKey] ?? null;

            if (!$force && \is_array($existing) && $this->looksLikeDefaultRegression($existing, $candidate)) {
                continue;
            }

            $merged[$mapKey] = $candidate;
        }

        return \array_values($merged);
    }

    private function looksLikeDefaultRegression(array $existingRow, array $candidateRow): bool
    {
        $configurationKey = $candidateRow['configurationKey'] ?? null;

        if (!$this->isManagedPluginConfigKey($configurationKey)) {
            return false;
        }

        $shortKey = \substr($configurationKey, \strlen(self::PLUGIN_CONFIG_PREFIX));

        if (!\array_key_exists($shortKey, self::CONFIG_DEFAULTS)) {
            return false;
        }

        $existingRaw = $existingRow['configurationValue'] ?? null;
        $candidateRaw = $candidateRow['configurationValue'] ?? null;

        if (!\is_string($existingRaw) || !\is_string($candidateRaw)) {
            return false;
        }

        $existingValue = $this->decodeSystemConfigValue($existingRaw);
        $candidateValue = $this->decodeSystemConfigValue($candidateRaw);
        $defaultValue = self::CONFIG_DEFAULTS[$shortKey];

        return $candidateValue === $defaultValue && $existingValue !== $defaultValue;
    }

    private function snapshotCaptchaState(string $source, bool $force): void
    {
        $state = $this->readLifecycleState();
        $captchaStateByScope = \is_array($state['captchaStateByScope'] ?? null) ? $state['captchaStateByScope'] : [];
        $foundSimpleTurnstile = false;
        $wasActiveSomewhere = false;
        $now = $this->now();

        foreach ($this->fetchActiveCaptchaRows() as $row) {
            $scopeKey = $this->getScopeKeyFromRow($row);
            $activeCaptchas = $this->decodeActiveCaptchasFromRow($row);

            if (!\is_array($activeCaptchas) || !\array_key_exists(SimpleTurnstileCaptcha::CAPTCHA_NAME, $activeCaptchas)) {
                continue;
            }

            $foundSimpleTurnstile = true;
            $captchaConfig = $activeCaptchas[SimpleTurnstileCaptcha::CAPTCHA_NAME];
            $wasActive = \is_array($captchaConfig) && ($captchaConfig['isActive'] ?? false) === true;

            $captchaStateByScope[$scopeKey] = [
                'wasActive' => $wasActive,
                'capturedAt' => $now,
                'source' => $source,
            ];

            if ($wasActive) {
                $wasActiveSomewhere = true;
            }
        }

        if (!$foundSimpleTurnstile && !$force && $captchaStateByScope !== []) {
            $state['lastCaptchaSnapshotSkippedAt'] = $now;
            $state['lastCaptchaSnapshotSkippedReason'] = 'simple-turnstile-not-present';
            $state['lastCaptchaSnapshotSource'] = $source;
            $this->writeLifecycleState($state);

            return;
        }

        if (!$foundSimpleTurnstile && $force && $captchaStateByScope !== []) {
            $state['lastCaptchaSnapshotSkippedAt'] = $now;
            $state['lastCaptchaSnapshotSkippedReason'] = 'simple-turnstile-not-present-preserved-existing-state';
            $state['lastCaptchaSnapshotSource'] = $source;
            $this->writeLifecycleState($state);

            return;
        }

        $state['captchaWasActive'] = $wasActiveSomewhere;
        $state['captchaStateByScope'] = $captchaStateByScope;
        $state['captchaStateUpdatedAt'] = $now;
        $state['lastCaptchaSnapshotSource'] = $source;
        unset($state['lastCaptchaSnapshotSkippedReason']);

        $this->writeLifecycleState($state);
    }

    private function ensureCaptchaRegistered(bool $restoreActive): void
    {
        $rows = $this->fetchActiveCaptchaRows();

        if ($rows === []) {
            $activeCaptchas = $this->addOrUpdateSimpleTurnstile(
                $this->getDefaultCaptchaStructure(),
                self::GLOBAL_SCOPE_KEY,
                $restoreActive
            );

            $this->writeSystemConfigValue(self::ACTIVE_CAPTCHAS_CONFIG_KEY, $activeCaptchas, null);

            return;
        }

        $hasGlobalRow = false;

        foreach ($rows as $row) {
            $scopeKey = $this->getScopeKeyFromRow($row);
            $salesChannelIdHex = $this->getSalesChannelIdFromRow($row);

            if ($scopeKey === self::GLOBAL_SCOPE_KEY) {
                $hasGlobalRow = true;
            }

            $activeCaptchas = $this->decodeActiveCaptchasFromRow($row) ?? $this->getDefaultCaptchaStructure();
            $activeCaptchas = $this->addOrUpdateSimpleTurnstile($activeCaptchas, $scopeKey, $restoreActive);

            $this->writeSystemConfigValue(self::ACTIVE_CAPTCHAS_CONFIG_KEY, $activeCaptchas, $salesChannelIdHex);
        }

        if (!$hasGlobalRow) {
            $activeCaptchas = $this->addOrUpdateSimpleTurnstile(
                $this->getDefaultCaptchaStructure(),
                self::GLOBAL_SCOPE_KEY,
                $restoreActive
            );

            $this->writeSystemConfigValue(self::ACTIVE_CAPTCHAS_CONFIG_KEY, $activeCaptchas, null);
        }
    }

    private function addOrUpdateSimpleTurnstile(array $activeCaptchas, string $scopeKey, bool $restoreActive): array
    {
        $existingConfig = $activeCaptchas[SimpleTurnstileCaptcha::CAPTCHA_NAME] ?? [];

        if (!\is_array($existingConfig)) {
            $existingConfig = [];
        }

        $existingIsActive = ($existingConfig['isActive'] ?? false) === true;
        $rememberedIsActive = $this->wasCaptchaActiveBeforeRemoval($scopeKey);
        $otherCaptchaIsActive = $this->hasOtherActiveCaptcha($activeCaptchas);

        $activeCaptchas[SimpleTurnstileCaptcha::CAPTCHA_NAME] = [
            'name' => SimpleTurnstileCaptcha::CAPTCHA_NAME,
            'isActive' => $restoreActive && !$otherCaptchaIsActive && ($existingIsActive || $rememberedIsActive),
            'config' => \is_array($existingConfig['config'] ?? null) ? $existingConfig['config'] : [],
        ];

        return $activeCaptchas;
    }

    private function wasCaptchaActiveBeforeRemoval(string $scopeKey): bool
    {
        $state = $this->readLifecycleState();
        $captchaStateByScope = $state['captchaStateByScope'] ?? null;

        if (\is_array($captchaStateByScope)) {
            $scopeState = $captchaStateByScope[$scopeKey] ?? null;

            if (\is_array($scopeState) && \array_key_exists('wasActive', $scopeState)) {
                return $scopeState['wasActive'] === true;
            }

            $globalState = $captchaStateByScope[self::GLOBAL_SCOPE_KEY] ?? null;

            if (\is_array($globalState) && \array_key_exists('wasActive', $globalState)) {
                return $globalState['wasActive'] === true;
            }
        }

        return ($state['captchaWasActive'] ?? false) === true;
    }

    private function hasOtherActiveCaptcha(array $activeCaptchas): bool
    {
        foreach ($activeCaptchas as $name => $captchaConfig) {
            if ($name === SimpleTurnstileCaptcha::CAPTCHA_NAME || !\is_array($captchaConfig)) {
                continue;
            }

            if (($captchaConfig['isActive'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function removeCaptchaFromAllActiveCaptchaConfigs(): void
    {
        foreach ($this->fetchActiveCaptchaRows() as $row) {
            $activeCaptchas = $this->decodeActiveCaptchasFromRow($row);

            if (!\is_array($activeCaptchas) || !\array_key_exists(SimpleTurnstileCaptcha::CAPTCHA_NAME, $activeCaptchas)) {
                continue;
            }

            unset($activeCaptchas[SimpleTurnstileCaptcha::CAPTCHA_NAME]);

            $this->writeSystemConfigValue(
                self::ACTIVE_CAPTCHAS_CONFIG_KEY,
                $activeCaptchas,
                $this->getSalesChannelIdFromRow($row)
            );
        }
    }

    private function fetchActiveCaptchaRows(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(sales_channel_id)) AS sales_channel_id_hex,
                    configuration_value
             FROM system_config
             WHERE configuration_key = :configurationKey
             ORDER BY sales_channel_id ASC',
            [
                'configurationKey' => self::ACTIVE_CAPTCHAS_CONFIG_KEY,
            ]
        );
    }

    private function decodeActiveCaptchasFromRow(array $row): ?array
    {
        $configurationValue = $row['configuration_value'] ?? null;

        if (!\is_string($configurationValue) || trim($configurationValue) === '') {
            return null;
        }

        $decoded = $this->decodeSystemConfigValue($configurationValue);

        return \is_array($decoded) ? $decoded : null;
    }

    private function writeSystemConfigValue(string $configurationKey, mixed $value, ?string $salesChannelIdHex): void
    {
        $this->systemConfigService->set($configurationKey, $value, $salesChannelIdHex);
        $this->upsertRawSystemConfigValue($configurationKey, $this->encodeSystemConfigValue($value), $salesChannelIdHex);
    }

    private function upsertRawSystemConfigValue(string $configurationKey, string $configurationValue, ?string $salesChannelIdHex): void
    {
        if ($salesChannelIdHex === null) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*)
                 FROM system_config
                 WHERE configuration_key = :configurationKey
                   AND sales_channel_id IS NULL',
                [
                    'configurationKey' => $configurationKey,
                ]
            ) > 0;

            if ($exists) {
                $this->connection->executeStatement(
                    'UPDATE system_config
                     SET configuration_value = :configurationValue,
                         updated_at = NOW(3)
                     WHERE configuration_key = :configurationKey
                       AND sales_channel_id IS NULL',
                    [
                        'configurationKey' => $configurationKey,
                        'configurationValue' => $configurationValue,
                    ]
                );

                return;
            }

            $this->connection->executeStatement(
                'INSERT INTO system_config
                    (id, configuration_key, configuration_value, sales_channel_id, created_at)
                 VALUES
                    (UNHEX(REPLACE(UUID(), "-", "")), :configurationKey, :configurationValue, NULL, NOW(3))',
                [
                    'configurationKey' => $configurationKey,
                    'configurationValue' => $configurationValue,
                ]
            );

            return;
        }

        $exists = (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM system_config
             WHERE configuration_key = :configurationKey
               AND sales_channel_id = UNHEX(:salesChannelIdHex)',
            [
                'configurationKey' => $configurationKey,
                'salesChannelIdHex' => $salesChannelIdHex,
            ]
        ) > 0;

        if ($exists) {
            $this->connection->executeStatement(
                'UPDATE system_config
                 SET configuration_value = :configurationValue,
                     updated_at = NOW(3)
                 WHERE configuration_key = :configurationKey
                   AND sales_channel_id = UNHEX(:salesChannelIdHex)',
                [
                    'configurationKey' => $configurationKey,
                    'configurationValue' => $configurationValue,
                    'salesChannelIdHex' => $salesChannelIdHex,
                ]
            );

            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO system_config
                (id, configuration_key, configuration_value, sales_channel_id, created_at)
             VALUES
                (UNHEX(REPLACE(UUID(), "-", "")), :configurationKey, :configurationValue, UNHEX(:salesChannelIdHex), NOW(3))',
            [
                'configurationKey' => $configurationKey,
                'configurationValue' => $configurationValue,
                'salesChannelIdHex' => $salesChannelIdHex,
            ]
        );
    }

    private function readLifecycleState(): array
    {
        $this->ensureLifecycleStateTableExists();

        $rawValue = $this->connection->fetchOne(
            'SELECT state_value
             FROM simple_turnstile_lifecycle_state
             WHERE state_key = :stateKey
             LIMIT 1',
            [
                'stateKey' => self::STATE_KEY,
            ]
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

    private function writeLifecycleState(array $state): void
    {
        $this->ensureLifecycleStateTableExists();

        $state['schemaVersion'] = self::STATE_VERSION;

        $encodedState = json_encode(
            $state,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        );

        $this->connection->executeStatement(
            'INSERT INTO simple_turnstile_lifecycle_state
                (state_key, state_value, created_at, updated_at)
             VALUES
                (:stateKey, :stateValue, NOW(3), NULL)
             ON DUPLICATE KEY UPDATE
                state_value = VALUES(state_value),
                updated_at = NOW(3)',
            [
                'stateKey' => self::STATE_KEY,
                'stateValue' => $encodedState,
            ]
        );
    }

    private function ensureLifecycleStateTableExists(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS `simple_turnstile_lifecycle_state` (
                `state_key` VARCHAR(64) NOT NULL,
                `state_value` LONGTEXT NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`state_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function dropLifecycleStateTable(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS `simple_turnstile_lifecycle_state`');
    }

    private function removePluginConfiguration(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM system_config
             WHERE configuration_key LIKE :prefix',
            [
                'prefix' => self::PLUGIN_CONFIG_PREFIX . '%',
            ]
        );
    }

    private function registerRestoreFinalizer(bool $restoreActive, string $source): void
    {
        $connection = $this->connection;
        $systemConfigService = $this->systemConfigService;

        register_shutdown_function(static function () use ($connection, $systemConfigService, $restoreActive, $source): void {
            try {
                $installer = new self($systemConfigService, $connection);
                $installer->recordLifecycleAction($source . '.start');
                $installer->restoreUserConfigSnapshot();
                $installer->ensureCaptchaRegistered($restoreActive);
                $installer->synchronizeCurrentState($source . '.end', $restoreActive);
            } catch (\Throwable) {
            }
        });
    }

    private function registerRestoreConfigAndRemoveCaptchaFinalizer(string $source): void
    {
        $connection = $this->connection;
        $systemConfigService = $this->systemConfigService;

        register_shutdown_function(static function () use ($connection, $systemConfigService, $source): void {
            try {
                $installer = new self($systemConfigService, $connection);
                $installer->recordLifecycleAction($source . '.start');
                $installer->restoreUserConfigSnapshot();
                $installer->removeCaptchaFromAllActiveCaptchaConfigs();
                $installer->recordLifecycleAction($source . '.end');
            } catch (\Throwable) {
            }
        });
    }

    private function decodeSystemConfigValue(string $configurationValue): mixed
    {
        try {
            $decoded = json_decode($configurationValue, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (\is_array($decoded) && \array_key_exists('_value', $decoded)) {
            return $decoded['_value'];
        }

        return $decoded;
    }

    private function encodeSystemConfigValue(mixed $value): string
    {
        return json_encode(
            [
                '_value' => $value,
            ],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        );
    }

    private function isManagedPluginConfigKey(mixed $configurationKey): bool
    {
        if (!\is_string($configurationKey) || !\str_starts_with($configurationKey, self::PLUGIN_CONFIG_PREFIX)) {
            return false;
        }

        $shortKey = \substr($configurationKey, \strlen(self::PLUGIN_CONFIG_PREFIX));

        return \in_array($shortKey, self::CONFIG_KEYS, true);
    }

    private function buildConfigRowMapKey(mixed $configurationKey, mixed $salesChannelIdHex): ?string
    {
        if (!$this->isManagedPluginConfigKey($configurationKey)) {
            return null;
        }

        $salesChannelIdHex = $this->normalizeSalesChannelIdHex($salesChannelIdHex);

        return ($salesChannelIdHex ?? self::GLOBAL_SCOPE_KEY) . '::' . $configurationKey;
    }

    private function getScopeKeyFromRow(array $row): string
    {
        return $this->normalizeSalesChannelIdHex($row['sales_channel_id_hex'] ?? null) ?? self::GLOBAL_SCOPE_KEY;
    }

    private function getSalesChannelIdFromRow(array $row): ?string
    {
        return $this->normalizeSalesChannelIdHex($row['sales_channel_id_hex'] ?? null);
    }

    private function normalizeSalesChannelIdHex(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = \strtolower(\trim($value));

        if ($value === '' || !$this->isUuidHex($value)) {
            return null;
        }

        return $value;
    }

    private function isUuidHex(string $value): bool
    {
        return \preg_match('/^[0-9a-f]{32}$/', \strtolower($value)) === 1;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(\DATE_ATOM);
    }

    private function hashRows(array $rows): string
    {
        return \hash('sha256', json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    private function getDefaultCaptchaStructure(): array
    {
        return [
            'honeypot' => [
                'name' => 'honeypot',
                'isActive' => false,
            ],
            'basicCaptcha' => [
                'name' => 'basicCaptcha',
                'isActive' => false,
            ],
            'friendlyCaptcha' => [
                'name' => 'friendlyCaptcha',
                'config' => [
                    'siteKey' => '',
                    'secretKey' => '',
                ],
                'isActive' => false,
            ],
            'googleReCaptchaV2' => [
                'name' => 'googleReCaptchaV2',
                'config' => [
                    'siteKey' => '',
                    'invisible' => false,
                    'secretKey' => '',
                ],
                'isActive' => false,
            ],
            'googleReCaptchaV3' => [
                'name' => 'googleReCaptchaV3',
                'config' => [
                    'siteKey' => '',
                    'secretKey' => '',
                    'thresholdScore' => 0.3,
                ],
                'isActive' => false,
            ],
        ];
    }
}
