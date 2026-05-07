<?php declare(strict_types=1);

namespace SLINIcraftet204\SimpleTurnstile\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use SLINIcraftet204\SimpleTurnstile\Core\Installer\CaptchaConfigurationInstaller;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ConfigSnapshotSubscriber implements EventSubscriberInterface
{
    private const PLUGIN_CONFIG_PREFIX = 'SimpleTurnstile.config.';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly Connection $connection
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'onKernelTerminate',
        ];
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$this->requestMayHaveTouchedSimpleTurnstileConfig($event)) {
            return;
        }

        try {
            $installer = new CaptchaConfigurationInstaller($this->systemConfigService, $this->connection);
            $installer->synchronizeCurrentState('kernelTerminate.configWatcher', true);
        } catch (\Throwable) {
            // The watcher must never break storefront/admin responses.
        }
    }

    private function requestMayHaveTouchedSimpleTurnstileConfig(TerminateEvent $event): bool
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $method = $request->getMethod();

        if (!\in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        if (\str_contains($path, 'system-config') || \str_contains($path, 'system_config')) {
            return true;
        }

        if (\str_contains($path, 'plugin') || \str_contains($path, 'extension')) {
            return true;
        }

        $content = $request->getContent();

        if (\is_string($content) && \str_contains($content, self::PLUGIN_CONFIG_PREFIX)) {
            return true;
        }

        foreach ($request->request->all() as $key => $value) {
            if (\is_string($key) && \str_contains($key, self::PLUGIN_CONFIG_PREFIX)) {
                return true;
            }

            if (\is_string($value) && \str_contains($value, self::PLUGIN_CONFIG_PREFIX)) {
                return true;
            }
        }

        return false;
    }
}
