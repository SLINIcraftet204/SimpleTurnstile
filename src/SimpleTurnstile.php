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

        $this->getCaptchaConfigurationInstaller()->install();
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $this->getCaptchaConfigurationInstaller()->install();
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
        parent::postUpdate($updateContext);

        $this->getCaptchaConfigurationInstaller()->install();
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);

        $this->getCaptchaConfigurationInstaller()->install();
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        parent::deactivate($deactivateContext);

        /*
         * Beim Deaktivieren NICHT entfernen.
         * Nur sicherstellen, dass der Captcha-Typ nicht aktiv verwendet wird.
         */
        $this->getCaptchaConfigurationInstaller()->markCaptchaInactiveOnly();
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        /*
         * Beim Deinstallieren wirklich aus activeCaptchasV2 entfernen.
         * Plugin-Konfigurationswerte werden nur bei "alle Daten löschen" entfernt.
         */
        $this->getCaptchaConfigurationInstaller()->uninstall(
            removeUserData: !$uninstallContext->keepUserData()
        );
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