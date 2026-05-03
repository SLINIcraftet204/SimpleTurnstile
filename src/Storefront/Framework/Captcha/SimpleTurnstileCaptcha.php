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

        if (!\is_string($token)) {
            $fallbackToken = $request->get(self::CAPTCHA_REQUEST_PARAMETER);
            $token = \is_string($fallbackToken) ? $fallbackToken : '';
        }

        $token = trim($token);

        if ($token === '') {
            $this->debug($request, 'Validation failed: missing Turnstile token.');

            return false;
        }

        if (\strlen($token) > 2048) {
            $this->debug($request, 'Validation failed: Turnstile token is too long.');

            return false;
        }

        $secretKey = $this->getStringConfigValue($request, 'secretKey');

        if ($secretKey === null || $secretKey === '') {
            $this->debug($request, 'Validation failed: missing secret key.');

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
                'http_errors' => false,
            ]);

            $rawResponse = $response->getBody()->getContents();

            try {
                $decodedResponse = json_decode($rawResponse, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                $this->debug($request, 'Validation failed: invalid JSON response from Cloudflare.', [
                    'exception' => $exception->getMessage(),
                    'statusCode' => $response->getStatusCode(),
                ]);

                return false;
            }

            if (!\is_array($decodedResponse)) {
                $this->debug($request, 'Validation failed: response from Cloudflare is not an array.', [
                    'statusCode' => $response->getStatusCode(),
                ]);

                return false;
            }

            $success = ($decodedResponse['success'] ?? false) === true;

            $this->debug($request, 'Validation response received from Cloudflare.', [
                'success' => $success,
                'statusCode' => $response->getStatusCode(),
                'hostname' => $decodedResponse['hostname'] ?? null,
                'action' => $decodedResponse['action'] ?? null,
                'errorCodes' => $decodedResponse['error-codes'] ?? [],
            ]);

            return $success;
        } catch (\Throwable $exception) {
            $this->debug($request, 'Validation failed: request to Cloudflare failed.', [
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

    private function getStringConfigValue(Request $request, string $key): ?string
    {
        $value = $this->getConfigValue($request, $key);

        if (!\is_string($value)) {
            return null;
        }

        return trim($value);
    }

    private function getBoolConfigValue(Request $request, string $key): bool
    {
        return (bool) $this->getConfigValue($request, $key);
    }

    private function getConfigValue(Request $request, string $key): mixed
    {
        $salesChannelId = $this->getSalesChannelId($request);

        $value = $this->systemConfigService->get(
            self::CONFIG_PREFIX . $key,
            $salesChannelId
        );

        if (($value === null || $value === '') && $salesChannelId !== null) {
            return $this->systemConfigService->get(self::CONFIG_PREFIX . $key);
        }

        return $value;
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