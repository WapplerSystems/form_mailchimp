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
    ) {}

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
        $clientUid = (int)($this->parseOption('oauthClient') ?? 0);
        if ($clientUid <= 0) {
            return null;
        }

        $connection = $this->oAuthClientService->getActiveConnectionByClientUid($clientUid);
        if ($connection === null || $connection['access_token'] === '') {
            return null;
        }

        $apiClient = new ApiClient();
        $apiClient->setConfig([
            'accessToken' => $connection['access_token'],
            'server' => $this->parseOption('server') ?: 'us1',
        ]);
        return $apiClient;
    }
}