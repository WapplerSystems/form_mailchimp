<?php
declare(strict_types=1);

namespace WapplerSystems\FormMailchimp\Controller\Backend;

use MailchimpMarketing\ApiClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WapplerSystems\OauthService\Service\OAuthClientService;

final class FormEditorAjaxController
{
    public function __construct(
        private readonly OAuthClientService $oAuthClientService,
    ) {}

    public function getClientsAction(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse($this->oAuthClientService->getActiveClientsAsOptions('mailchimp'));
    }

    /**
     * Returns the audiences (lists) the given OAuth client has access to.
     */
    public function getListsAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $clientUid = (int)($query['clientUid'] ?? 0);
        $server = trim((string)($query['server'] ?? '')) ?: 'us1';

        $empty = [['value' => '', 'label' => '---']];

        if ($clientUid <= 0) {
            return new JsonResponse($empty);
        }

        $connection = $this->oAuthClientService->getActiveConnectionByClientUid($clientUid);
        if ($connection === null || ($connection['access_token'] ?? '') === '') {
            return new JsonResponse($empty);
        }

        $accessToken = (string)$connection['access_token'];
        $resolvedDc = $this->resolveMailchimpDatacenter($accessToken);
        if ($resolvedDc !== null) {
            $server = $resolvedDc;
        }

        // The Mailchimp Marketing SDK still relies on implicit-nullable
        // parameter types, which trigger E_DEPRECATED on PHP 8.4+. TYPO3's
        // ErrorHandler escalates those to exceptions, so we silence
        // deprecation notices only during the SDK call.
        set_error_handler(static fn () => true, E_DEPRECATED | E_USER_DEPRECATED);

        try {
            $apiClient = new ApiClient();
            $apiClient->setConfig([
                'accessToken' => $accessToken,
                'server' => $server,
            ]);

            $response = $apiClient->lists->getAllLists(null, null, 1000);
        } catch (\Throwable $e) {
            restore_error_handler();
            return new JsonResponse([
                ['value' => '', 'label' => '(Mailchimp API: ' . $e->getMessage() . ')'],
            ]);
        }
        restore_error_handler();

        $options = $empty;
        foreach (($response->lists ?? []) as $list) {
            $options[] = [
                'value' => (string)$list->id,
                'label' => (string)($list->name ?? $list->id),
            ];
        }
        return new JsonResponse($options);
    }

    /**
     * Resolves the Mailchimp datacenter (e.g. "us18") for the given access
     * token via the OAuth metadata endpoint. Returns null if discovery fails;
     * callers should then fall back to the user-provided server value.
     */
    private function resolveMailchimpDatacenter(string $accessToken): ?string
    {
        try {
            $response = GeneralUtility::makeInstance(RequestFactory::class)->request(
                'https://login.mailchimp.com/oauth2/metadata',
                'GET',
                [
                    'headers' => [
                        'Authorization' => 'OAuth ' . $accessToken,
                    ],
                    'timeout' => 5,
                    'http_errors' => false,
                ]
            );
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $data = json_decode((string)$response->getBody(), true);
            $dc = is_array($data) ? ($data['dc'] ?? null) : null;
            return (is_string($dc) && $dc !== '') ? $dc : null;
        } catch (\Throwable) {
            return null;
        }
    }
}