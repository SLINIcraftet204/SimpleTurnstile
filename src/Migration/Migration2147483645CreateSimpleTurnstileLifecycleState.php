<?php declare(strict_types=1);

namespace SLINIcraftet204\SimpleTurnstile\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration2147483645CreateSimpleTurnstileLifecycleState extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 2147483645;
    }

    public function update(Connection $connection): void
    {
        $this->createTable($connection);
        $this->insertInitialStateIfMissing($connection, 'migration.createLifecycleStateTable');
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

    private function insertInitialStateIfMissing(Connection $connection, string $action): void
    {
        $state = json_encode(
            [
                'schemaVersion' => 1,
                'createdAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                'lastLifecycleAction' => $action,
                'lastLifecycleActionAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                'pluginConfigRows' => [],
                'captchaWasActive' => false,
                'captchaStateByScope' => [],
            ],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        );

        $connection->executeStatement(
            'INSERT IGNORE INTO simple_turnstile_lifecycle_state
                (state_key, state_value, created_at, updated_at)
             VALUES
                (:stateKey, :stateValue, NOW(3), NULL)',
            [
                'stateKey' => 'lifecycle',
                'stateValue' => $state,
            ]
        );
    }
}
