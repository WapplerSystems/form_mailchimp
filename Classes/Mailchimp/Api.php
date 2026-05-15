<?php
declare(strict_types=1);

namespace WapplerSystems\FormMailchimp\Mailchimp;

use MailchimpMarketing\ApiClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Thin wrapper around MailchimpMarketing\ApiClient that lazily holds a
 * configured client per request and shields callers from deprecation
 * notices the SDK can emit on PHP 8.x.
 *
 * Used by the EXT:form Mailchimp finishers so multiple finishers in the
 * same submission share one connection without each rebuilding it.
 */
class Api implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Mailchimp documents this endpoint as the way to discover which data
     * center a given OAuth access token belongs to.
     * https://mailchimp.com/developer/marketing/guides/access-user-data-oauth-2/#use-an-access-token
     */
    private const METADATA_URL = 'https://login.mailchimp.com/oauth2/metadata';

    /**
     * Last-resort DC if metadata lookup fails and no DC was passed. Any
     * Mailchimp account that isn't actually on us1 will still get 401, but
     * at least the call goes out so the log line is meaningful.
     */
    private const DEFAULT_DC = 'us1';

    private ?ApiClient $client = null;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function isConnected(): bool
    {
        return $this->client !== null;
    }

    public function getClient(): ?ApiClient
    {
        return $this->client;
    }

    /**
     * Configures the ApiClient with the given access token.
     *
     * Pass an empty string for $server to auto-resolve the user's DC from
     * Mailchimp's metadata endpoint — that is the recommended approach
     * because OAuth tokens are region-bound and the DC isn't known until
     * after the authorization callback. Passing a non-empty $server skips
     * the HTTP call and uses the value as-is (useful for tests or if a DC
     * is explicitly configured on the finisher).
     */
    public function connect(string $accessToken, string $server = ''): void
    {
        if ($server === '') {
            $server = $this->resolveDc($accessToken);
        }

        $client = new ApiClient();
        $client->setConfig([
            'accessToken' => $accessToken,
            'server' => $server,
        ]);
        $this->client = $client;
    }

    /**
     * Runs the given callback with E_DEPRECATED/E_USER_DEPRECATED muted so
     * the Mailchimp SDK's internal deprecations don't leak into the
     * frontend response or pollute the log on every submission.
     */
    public function runWithoutDeprecationNotices(callable $callback): void
    {
        $previous = error_reporting();
        error_reporting($previous & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        try {
            $callback();
        } finally {
            error_reporting($previous);
        }
    }

    private function resolveDc(string $accessToken): string
    {
        try {
            $response = $this->requestFactory->request(
                self::METADATA_URL,
                'GET',
                [
                    'headers' => [
                        'Authorization' => 'OAuth ' . $accessToken,
                        'Accept' => 'application/json',
                    ],
                    'timeout' => 5,
                ],
            );
            $body = (string)$response->getBody();
            $data = json_decode($body, true);
            $dc = is_array($data) ? ($data['dc'] ?? null) : null;

            if (!is_string($dc) || $dc === '') {
                $this->logger?->warning('Mailchimp metadata endpoint did not return a "dc" field; falling back to "{default}"', [
                    'default' => self::DEFAULT_DC,
                    'body' => $body,
                ]);
                return self::DEFAULT_DC;
            }

            $this->logger?->info('Mailchimp DC auto-resolved via metadata endpoint: {dc}', [
                'dc' => $dc,
            ]);
            return $dc;
        } catch (\Throwable $e) {
            $this->logger?->warning('Mailchimp DC metadata lookup failed; falling back to "{default}"', [
                'default' => self::DEFAULT_DC,
                'error' => $e->getMessage(),
            ]);
            return self::DEFAULT_DC;
        }
    }
}
