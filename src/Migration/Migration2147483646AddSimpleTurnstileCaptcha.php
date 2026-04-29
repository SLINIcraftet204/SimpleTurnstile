<?php declare(strict_types=1);

namespace SLINIcraftet204\SimpleTurnstile\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration2147483646AddSimpleTurnstileCaptcha extends MigrationStep
{
    private const ACTIVE_CAPTCHAS_CONFIG_KEY = 'core.basicInformation.activeCaptchasV2';
    private const CAPTCHA_NAME = 'simpleTurnstile';

    public function getCreationTimestamp(): int
    {
        return 2147483646;
    }

    public function update(Connection $connection): void
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id_hex,
                    LOWER(HEX(sales_channel_id)) AS sales_channel_id_hex,
                    configuration_value
             FROM system_config
             WHERE configuration_key = :configurationKey',
            [
                'configurationKey' => self::ACTIVE_CAPTCHAS_CONFIG_KEY,
            ]
        );

        $hasGlobalRow = false;

        foreach ($rows as $row) {
            $idHex = $row['id_hex'] ?? null;
            $salesChannelIdHex = $row['sales_channel_id_hex'] ?? null;
            $configurationValue = $row['configuration_value'] ?? null;

            if (!\is_string($idHex) || !\is_string($configurationValue)) {
                continue;
            }

            if ($salesChannelIdHex === null || $salesChannelIdHex === '') {
                $hasGlobalRow = true;
            }

            $activeCaptchas = $this->decodeActiveCaptchas($configurationValue) ?? $this->getDefaultCaptchaStructure();
            $activeCaptchas = $this->addSimpleTurnstile($activeCaptchas);

            $connection->executeStatement(
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

        if (!$hasGlobalRow) {
            $activeCaptchas = $this->addSimpleTurnstile($this->getDefaultCaptchaStructure());

            $connection->executeStatement(
                'INSERT INTO system_config
                    (id, configuration_key, configuration_value, sales_channel_id, created_at)
                 VALUES
                    (UNHEX(:id), :configurationKey, :configurationValue, NULL, NOW(3))',
                [
                    'id' => Uuid::randomHex(),
                    'configurationKey' => self::ACTIVE_CAPTCHAS_CONFIG_KEY,
                    'configurationValue' => $this->encodeActiveCaptchas($activeCaptchas),
                ]
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function addSimpleTurnstile(array $activeCaptchas): array
    {
        $existingConfig = $activeCaptchas[self::CAPTCHA_NAME] ?? [];

        if (!\is_array($existingConfig)) {
            $existingConfig = [];
        }

        $activeCaptchas[self::CAPTCHA_NAME] = [
            'name' => self::CAPTCHA_NAME,
            'isActive' => (bool) ($existingConfig['isActive'] ?? false),
            'config' => \is_array($existingConfig['config'] ?? null) ? $existingConfig['config'] : [],
        ];

        return $activeCaptchas;
    }

    private function decodeActiveCaptchas(string $configurationValue): ?array
    {
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
            ['_value' => $activeCaptchas],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
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