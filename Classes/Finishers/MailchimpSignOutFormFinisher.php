<?php

declare(strict_types=1);

namespace WapplerSystems\FormMailchimp\Finishers;

use MailchimpMarketing\ApiClient;
use MailchimpMarketing\ApiException;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use TYPO3\CMS\Form\Exception;
use WapplerSystems\FormMailchimp\Service\MailchimpFormContext;
use WapplerSystems\OauthService\Service\OAuthClientService;

class MailchimpSignOutFormFinisher extends AbstractFinisher
{
    public function __construct(
        private readonly OAuthClientService $oAuthClientService,
        private readonly MailchimpFormContext $context,
    ) {
        parent::__construct();
    }

    /**
     * Called by EXT:form when the form definition is built — before validators run.
     * Populates MailchimpFormContext so AfterSubmitHook, OptinValidator and
     * OptoutValidator can access listId and clientUid.
     */
    public function setOptions(array $options): void
    {
        parent::setOptions($options);
        $this->context->setSettings($this->options);
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
            $server = $this->oAuthClientService->getConnectionMetadataValue($connection, 'dc')
                ?? $this->parseOption('server')
                ?: 'us1';

            $apiClient = new ApiClient();
            $apiClient->setConfig([
                'accessToken' => $connection['access_token'],
                'server' => $server,
            ]);
            return $apiClient;
        }

        throw new \RuntimeException('No active Mailchimp connection found.');
    }
}
