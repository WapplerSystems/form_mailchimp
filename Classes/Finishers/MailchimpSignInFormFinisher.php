<?php

declare(strict_types=1);

namespace WapplerSystems\FormMailchimp\Finishers;

use MailchimpMarketing\ApiClient;
use MailchimpMarketing\ApiException;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use TYPO3\CMS\Form\Exception;
use WapplerSystems\FormMailchimp\Service\MailchimpFormContext;
use WapplerSystems\OauthService\Service\OAuthClientService;

class MailchimpSignInFormFinisher extends AbstractFinisher
{
    public function __construct(
        private readonly OAuthClientService $oAuthClientService,
        private readonly MailchimpFormContext $context,
    ) {
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
