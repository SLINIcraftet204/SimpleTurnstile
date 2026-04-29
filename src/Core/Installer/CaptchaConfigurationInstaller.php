<?php declare(strict_types=1);

namespace SLINIcraftet204\SimpleTurnstile\Core\Installer;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use SLINIcraftet204\SimpleTurnstile\Storefront\Framework\Captcha\SimpleTurnstileCaptcha;

class CaptchaConfigurationInstaller
{
    private const ACTIVE_CAPTCHAS_CONFIG_KEY = 'core.basicInformation.activeCaptchasV2';
    private const PLUGIN_CONFIG_PREFIX = 'SimpleTurnstile.config.';

    private const DEFAULT_PLUGIN_CONFIG = [
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
        $this->ensurePluginConfigDefaults();
        $this->addCaptchaToAllActiveCaptchaConfigs();
    }

    public function uninstall(bool $removeUserData): void
    {
        /*
         * Beim Deinstallieren muss der Captcha-Typ aus der Auswahl raus.
         */
        $this->removeCaptchaOnly();

        if ($removeUserData) {
            $this->removePluginConfiguration();
        }
    }

    public function markCaptchaInactiveOnly(): void
    {
        $rows = $this->fetchActiveCaptchaRows();

        foreach ($rows as $row) {
            $activeCaptchas = $this->decodeActiveCaptchasFromRow($row);

            if ($activeCaptchas === null) {
                continue;
            }

            $existingConfig = $activeCaptchas[SimpleTurnstileCaptcha::CAPTCHA_NAME] ?? [];

            if (!\is_array($existingConfig)) {
                $existingConfig = [];
            }

            /*
             * Beim Deaktivieren drin lassen, aber sicher deaktivieren.
             */
            $activeCaptchas[SimpleTurnstileCaptcha::CAPTCHA_NAME] = [
                'name' => SimpleTurnstileCaptcha::CAPTCHA_NAME,
                'isActive' => false,
                'config' => \is_array($existingConfig['config'] ?? null) ? $existingConfig['config'] : [],
            ];

            $this->writeActiveCaptchaRow($row, $activeCaptchas);
        }
    }

    public function removeCaptchaOnly(): void
    {
        $rows = $this->fetchActiveCaptchaRows();

        foreach ($rows as $row) {
            $activeCaptchas = $this->decodeActiveCaptchasFromRow($row);

            if ($activeCaptchas === null) {
                continue;
            }

            unset($activeCaptchas[SimpleTurnstileCaptcha::CAPTCHA_NAME]);

            $this->writeActiveCaptchaRow($row, $activeCaptchas);
        }
    }

    private function ensurePluginConfigDefaults(): void
    {
        foreach (self::DEFAULT_PLUGIN_CONFIG as $key => $defaultValue) {
            $configKey = self::PLUGIN_CONFIG_PREFIX . $key;

            if ($this->systemConfigService->get($configKey) !== null) {
                continue;
            }

            $this->systemConfigService->set($configKey, $defaultValue);
        }
    }

    private function addCaptchaToAllActiveCaptchaConfigs(): void
    {
        $rows = $this->fetchActiveCaptchaRows();

        /*
         * Falls durch die Migration/global config noch keine Zeile existiert,
         * legen wir global eine saubere Struktur an.
         */
        if ($rows === []) {
            $activeCaptchas = $this->addSimpleTurnstile(
                $this->getDefaultCaptchaStructure()
            );

            $this->systemConfigService->set(self::ACTIVE_CAPTCHAS_CONFIG_KEY, $activeCaptchas);

            return;
        }

        foreach ($rows as $row) {
            $activeCaptchas = $this->decodeActiveCaptchasFromRow($row) ?? $this->getDefaultCaptchaStructure();
            $activeCaptchas = $this->addSimpleTurnstile($activeCaptchas);

            $this->writeActiveCaptchaRow($row, $activeCaptchas);
        }
    }

    private function addSimpleTurnstile(array $activeCaptchas): array
    {
        $existingConfig = $activeCaptchas[SimpleTurnstileCaptcha::CAPTCHA_NAME] ?? [];

        if (!\is_array($existingConfig)) {
            $existingConfig = [];
        }

        /*
         * Beim Installieren/Aktivieren bestehenden Status erhalten.
         * Falls frisch angelegt: false.
         */
        $activeCaptchas[SimpleTurnstileCaptcha::CAPTCHA_NAME] = [
            'name' => SimpleTurnstileCaptcha::CAPTCHA_NAME,
            'isActive' => (bool) ($existingConfig['isActive'] ?? false),
            'config' => \is_array($existingConfig['config'] ?? null) ? $existingConfig['config'] : [],
        ];

        return $activeCaptchas;
    }

    private function fetchActiveCaptchaRows(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id_hex,
                    LOWER(HEX(sales_channel_id)) AS sales_channel_id_hex,
                    configuration_value
             FROM system_config
             WHERE configuration_key = :configurationKey',
            [
                'configurationKey' => self::ACTIVE_CAPTCHAS_CONFIG_KEY,
            ]
        );
    }

    private function writeActiveCaptchaRow(array $row, array $activeCaptchas): void
    {
        $salesChannelId = $this->getSalesChannelIdFromRow($row);

        /*
         * Erst über Shopwares SystemConfigService schreiben.
         * Dadurch werden Cache/Events sauber mitgenommen.
         */
        $this->systemConfigService->set(
            self::ACTIVE_CAPTCHAS_CONFIG_KEY,
            $activeCaptchas,
            $salesChannelId
        );

        /*
         * Danach zusätzlich exakt diese DB-Zeile aktualisieren.
         * Das macht den Vorgang robust für Admin-Lifecycle und sales-channel-spezifische Configs.
         */
        $idHex = $row['id_hex'] ?? null;

        if (!\is_string($idHex) || $idHex === '') {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE system_config
             SET configuration_value = :configurationValue,
                 updated_at = NOW(3)
             WHERE id = UNHEX(:id)',
            [
                'configurationValue' => $this->encodeActiveCaptchas($activeCaptchas),
                'id' => $idHex,
            ]
        );
    }

    private function getSalesChannelIdFromRow(array $row): ?string
    {
        $salesChannelId = $row['sales_channel_id_hex'] ?? null;

        if (!\is_string($salesChannelId) || $salesChannelId === '') {
            return null;
        }

        return $salesChannelId;
    }

    private function decodeActiveCaptchasFromRow(array $row): ?array
    {
        $configurationValue = $row['configuration_value'] ?? null;

        if (!\is_string($configurationValue) || trim($configurationValue) === '') {
            return null;
        }

        try {
            $decoded = json_decode($configurationValue, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        if (isset($decoded['_value']) && \is_array($decoded['_value'])) {
            return $decoded['_value'];
        }

        return $decoded;
    }

    private function encodeActiveCaptchas(array $activeCaptchas): string
    {
        return json_encode(
            [
                '_value' => $activeCaptchas,
            ],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        );
    }

    private function removePluginConfiguration(): void
    {
        foreach (array_keys(self::DEFAULT_PLUGIN_CONFIG) as $key) {
            $this->systemConfigService->delete(self::PLUGIN_CONFIG_PREFIX . $key);
        }

        $this->connection->executeStatement(
            'DELETE FROM system_config WHERE configuration_key LIKE :prefix',
            [
                'prefix' => self::PLUGIN_CONFIG_PREFIX . '%',
            ]
        );
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