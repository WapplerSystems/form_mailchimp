<?php

declare(strict_types=1);

namespace WapplerSystems\FormMailchimp\Finishers;

use MailchimpMarketing\ApiException;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use WapplerSystems\FormMailchimp\Mailchimp\Api;
use WapplerSystems\FormMailchimp\Service\MailchimpFormContext;
use WapplerSystems\OauthService\Service\OAuthClientService;

class MailchimpSignInFormFinisher extends AbstractFinisher
{
    public function __construct(
        private readonly OAuthClientService $oAuthClientService,
        private readonly MailchimpFormContext $context,
        private readonly Api $api,
    ) {}

    /**
     * Called by EXT:form when the form definition is built — before validators run.
     * Populates MailchimpFormContext so AfterSubmitHook (and any future validators)
     * can access listId, oauthClient and server.
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

        $apiClient = $this->ensureConnectedClient();
        if ($apiClient === null) {
            return;
        }

        $this->api->runWithoutDeprecationNotices(function () use ($apiClient, $listId, $email, $formRuntime): void {
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
        });
    }

    /**
     * Returns the shared, hook-authenticated ApiClient. As a fallback (e.g. when
     * the afterSubmit hook did not run) it connects on demand from the
     * finisher's own options so executeInternal() keeps working standalone.
     */
    private function ensureConnectedClient(): ?\MailchimpMarketing\ApiClient
    {
        if ($this->api->isConnected()) {
            return $this->api->getClient();
        }

        $clientUid = (int)($this->parseOption('oauthClient') ?? 0);
        if ($clientUid <= 0) {
            return null;
        }

        $connection = $this->oAuthClientService->getActiveConnectionByClientUid($clientUid);
        if ($connection === null || $connection['access_token'] === '') {
            return null;
        }

        $this->api->connect(
            $connection['access_token'],
            (string)($this->parseOption('server') ?: 'us1'),
        );
        return $this->api->getClient();
    }
}