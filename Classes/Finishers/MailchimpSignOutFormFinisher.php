<?php

declare(strict_types=1);

namespace WapplerSystems\FormMailchimp\Finishers;

use MailchimpMarketing\ApiClient;
use MailchimpMarketing\ApiException;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use WapplerSystems\OauthService\Service\OAuthClientService;

class MailchimpSignOutFormFinisher extends AbstractFinisher
{
    public function __construct(
        private readonly OAuthClientService $oAuthClientService,
    ) {
        parent::__construct();
    }

    protected function executeInternal(): void
    {
        $formRuntime = $this->finisherContext->getFormRuntime();
        $email = $formRuntime['email'] ?? null;

        if (empty($email)) {
            return;
        }

        $listId = $this->parseOption('listId') ?: '';
        if ($listId === '') {
            return;
        }

        $apiClient = $this->buildApiClient();
        if ($apiClient === null) {
            return;
        }

        try {
            $subscriberHash = md5(strtolower($email));
            $apiClient->lists->deleteListMember($listId, $subscriberHash);
        } catch (ApiException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
    }

    private function buildApiClient(): ?ApiClient
    {
        $clientUid = (int)($this->parseOption('clientUid') ?? 0);

        $connection = $clientUid > 0
            ? $this->oAuthClientService->getActiveConnectionByClientUid($clientUid)
            : $this->oAuthClientService->getActiveConnectionByProvider('mailchimp');

        if ($connection !== null) {
            $apiClient = new ApiClient();
            $apiClient->setConfig([
                'accessToken' => $connection['access_token'],
                'server' => $this->parseOption('server') ?: 'us1',
            ]);
            return $apiClient;
        }

        // Fallback: API key
        $apiKey = $this->parseOption('apiKey') ?: '';
        if ($apiKey === '') {
            return null;
        }

        $parts = explode('-', $apiKey);
        $server = count($parts) > 1 ? end($parts) : 'us1';

        $apiClient = new ApiClient();
        $apiClient->setConfig([
            'apiKey' => $apiKey,
            'server' => $server,
        ]);
        return $apiClient;
    }
}