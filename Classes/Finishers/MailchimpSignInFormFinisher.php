<?php

declare(strict_types=1);

namespace WapplerSystems\FormMailchimp\Finishers;

use MailchimpMarketing\ApiClient;
use MailchimpMarketing\ApiException;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use WapplerSystems\OauthService\Service\OAuthClientService;

class MailchimpSignInFormFinisher extends AbstractFinisher
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

            try {
                $member = $apiClient->lists->getListMember($listId, $subscriberHash);
                if (in_array($member->status, ['pending', 'subscribed'], true)) {
                    return;
                }
            } catch (ApiException $e) {
                if ($e->getCode() !== 404) {
                    return;
                }
            }

            $name = $formRuntime['name'] ?? '';
            if ($name === '') {
                $firstName = $formRuntime['firstName'] ?? '';
                $lastName = $formRuntime['lastName'] ?? '';
                $name = trim($firstName . ' ' . $lastName);
            }

            $apiClient->lists->setListMember($listId, $subscriberHash, [
                'email_address' => $email,
                'status_if_new' => 'pending',
                'status' => 'pending',
                'email_type' => 'html',
                'ip_signup' => $_SERVER['REMOTE_ADDR'] ?? '',
                'merge_fields' => [
                    'FNAME' => $name,
                ],
            ]);
        } catch (\Exception) {
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