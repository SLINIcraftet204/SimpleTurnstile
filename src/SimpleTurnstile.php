<?php declare(strict_types=1);

namespace SLINIcraftet204\SimpleTurnstile;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use SLINIcraftet204\SimpleTurnstile\Core\Installer\CaptchaConfigurationInstaller;

class SimpleTurnstile extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        $this->getCaptchaConfigurationInstaller()->install();
    }

    public function postInstall(InstallContext $installContext): void
    {
        parent::postInstall($installContext);

        $this->getCaptchaConfigurationInstaller()->postInstall();
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);

        $this->getCaptchaConfigurationInstaller()->activate();
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $installer = $this->getCaptchaConfigurationInstaller();

        $installer->beforeDeactivate();

        parent::deactivate($deactivateContext);

        $installer->afterDeactivate();
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        $removeUserData = !$uninstallContext->keepUserData();
        $installer = $this->getCaptchaConfigurationInstaller();

        $installer->beforeUninstall($removeUserData);

        parent::uninstall($uninstallContext);

        $installer->afterUninstall($removeUserData);
    }

    public function update(UpdateContext $updateContext): void
    {
        $installer = $this->getCaptchaConfigurationInstaller();

        $installer->beforeUpdate();

        parent::update($updateContext);

        $installer->afterUpdate();
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
        parent::postUpdate($updateContext);

        $this->getCaptchaConfigurationInstaller()->postUpdate();
    }

    private function getCaptchaConfigurationInstaller(): CaptchaConfigurationInstaller
    {
        /** @var SystemConfigService $systemConfigService */
        $systemConfigService = $this->container->get(SystemConfigService::class);

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);

        return new CaptchaConfigurationInstaller($systemConfigService, $connection);
    }
}