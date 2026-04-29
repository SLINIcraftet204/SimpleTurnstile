<?php declare(strict_types=1);

namespace SLINIcraftet204\SimpleTurnstile\Storefront\Framework\Captcha;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Framework\Captcha\AbstractCaptcha;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Contracts\Translation\TranslatorInterface;

class SimpleTurnstileCaptcha extends AbstractCaptcha
{
    public const CAPTCHA_NAME = 'simpleTurnstile';
    public const CAPTCHA_REQUEST_PARAMETER = 'cf-turnstile-response';

    private const SITEVERIFY_ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const CONFIG_PREFIX = 'SimpleTurnstile.config.';
    private const INVALID_CAPTCHA_CODE = 'SIMPLE_TURNSTILE_INVALID';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator
    ) {
    }

    public function isValid(Request $request, array $captchaConfig): bool
    {
        $token = $request->request->get(self::CAPTCHA_REQUEST_PARAMETER);

        if (!\is_string($token) || trim($token) === '') {
            $this->debug($request, 'Turnstile validation failed: missing token.');

            return false;
        }

        $secretKey = $this->getConfigValue($request, 'secretKey');

        if (!\is_string($secretKey) || trim($secretKey) === '') {
            $this->debug($request, 'Turnstile validation failed: missing secret key.');

            return false;
        }

        $formParams = [
            'secret' => $secretKey,
            'response' => $token,
        ];

        if ($this->getBoolConfigValue($request, 'sendRemoteIp')) {
            $clientIp = $request->getClientIp();

            if (\is_string($clientIp) && $clientIp !== '') {
                $formParams['remoteip'] = $clientIp;
            }
        }

        try {
            $response = $this->client->request('POST', self::SITEVERIFY_ENDPOINT, [
                'form_params' => $formParams,
                'timeout' => 8.0,
                'connect_timeout' => 4.0,
            ]);

            $rawResponse = $response->getBody()->getContents();

            try {
                $decodedResponse = json_decode($rawResponse, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                $this->debug($request, 'Turnstile validation failed: invalid JSON response.', [
                    'exception' => $exception->getMessage(),
                ]);

                return false;
            }

            if (!\is_array($decodedResponse)) {
                $this->debug($request, 'Turnstile validation failed: response is not an array.');

                return false;
            }

            $success = ($decodedResponse['success'] ?? false) === true;

            $this->debug($request, 'Turnstile validation response received.', [
                'success' => $success,
                'hostname' => $decodedResponse['hostname'] ?? null,
                'errorCodes' => $decodedResponse['error-codes'] ?? [],
            ]);

            return $success;
        } catch (\Throwable $exception) {
            $this->debug($request, 'Turnstile validation failed: request exception.', [
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function getName(): string
    {
        return self::CAPTCHA_NAME;
    }

    public function shouldBreak(): bool
    {
        return false;
    }

    public function getViolations(): ConstraintViolationList
    {
    $message = $this->translator->trans('simple-turnstile.captcha.error');

    $violations = new ConstraintViolationList();
    $violations->add(new ConstraintViolation(
        $message,
        $message,
        [],
        '',
        '/' . self::CAPTCHA_REQUEST_PARAMETER,
        '',
        null,
        self::INVALID_CAPTCHA_CODE
    ));

    return $violations;
    }

    private function getConfigValue(Request $request, string $key): mixed
    {
        return $this->systemConfigService->get(
            self::CONFIG_PREFIX . $key,
            $this->getSalesChannelId($request)
        );
    }

    private function getBoolConfigValue(Request $request, string $key): bool
    {
        return (bool) $this->getConfigValue($request, $key);
    }

    private function getSalesChannelId(Request $request): ?string
    {
        $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);

        if (\is_string($salesChannelId) && $salesChannelId !== '') {
            return $salesChannelId;
        }

        $salesChannelContext = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);

        if ($salesChannelContext instanceof SalesChannelContext) {
            return $salesChannelContext->getSalesChannelId();
        }

        return null;
    }

    private function debug(Request $request, string $message, array $context = []): void
    {
        if (!$this->getBoolConfigValue($request, 'debugLogging')) {
            return;
        }

        $this->logger->debug('[Simple Turnstile] ' . $message, $context);
    }
}