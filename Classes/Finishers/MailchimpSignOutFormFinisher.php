<?php

declare(strict_types=1);

namespace WapplerSystems\FormMailchimp\Finishers;

use GuzzleHttp\Exception\ClientException;
use MailchimpMarketing\ApiClient;
use MailchimpMarketing\ApiException;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use WapplerSystems\FormMailchimp\Mailchimp\Api;
use WapplerSystems\FormMailchimp\Service\MailchimpFormContext;
use WapplerSystems\OauthService\Service\OAuthClientService;

class MailchimpSignOutFormFinisher extends AbstractFinisher
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
        $this->logger?->info('MailchimpSignOut: finisher invoked');

        $formRuntime = $this->finisherContext->getFormRuntime();
        $emailField = (string)($this->parseOption('emailField') ?: 'email');
        $email = $formRuntime[$emailField] ?? null;

        if (empty($email)) {
            $this->logger?->warning('MailchimpSignOut: aborting — no email value in form field "{field}"', [
                'field' => $emailField,
            ]);
            return;
        }

        $listId = $this->parseOption('listId') ?: '';
        if ($listId === '') {
            $this->logger?->warning('MailchimpSignOut: aborting — finisher option "listId" is empty');
            return;
        }

        $apiClient = $this->ensureConnectedClient();
        if ($apiClient === null) {
            $this->logger?->warning('MailchimpSignOut: aborting — no connected Mailchimp ApiClient');
            return;
        }

        $this->logger?->info('MailchimpSignOut: calling Mailchimp', [
            'email' => $email,
            'listId' => $listId,
        ]);

        $this->api->runWithoutDeprecationNotices(function () use ($apiClient, $listId, $email): void {
            try {
                $subscriberHash = md5(strtolower($email));
                $apiClient->lists->deleteListMember($listId, $subscriberHash);

                $this->logger?->info('MailchimpSignOut: deleteListMember accepted by Mailchimp', [
                    'listId' => $listId,
                ]);
            } catch (ApiException | ClientException $e) {
                // Mailchimp SDK leaks Guzzle's ClientException on 4xx for some
                // endpoints instead of wrapping it as ApiException. Normalize.
                $status = $e instanceof ClientException && $e->getResponse() !== null
                    ? $e->getResponse()->getStatusCode()
                    : $e->getCode();
                if ($status === 404) {
                    $this->logger?->info('MailchimpSignOut: member already absent (404) — nothing to do', [
                        'listId' => $listId,
                    ]);
                    return;
                }
                $this->logger?->error('MailchimpSignOut: Mailchimp API error', [
                    'status' => $status,
                    'message' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    /**
     * Returns the shared, hook-authenticated ApiClient. As a fallback (e.g. when
     * the afterSubmit hook did not run) it connects on demand from the
     * finisher's own options so executeInternal() keeps working standalone.
     */
    private function ensureConnectedClient(): ?ApiClient
    {
        if ($this->api->isConnected()) {
            return $this->api->getClient();
        }

        $clientUid = (int)($this->parseOption('oauthClient') ?? 0);
        if ($clientUid <= 0) {
            $this->logger?->warning('MailchimpSignOut: ensureConnectedClient — finisher option "oauthClient" is empty or 0');
            return null;
        }

        $connection = $this->oAuthClientService->getActiveConnectionByClientUid($clientUid);
        if ($connection === null) {
            $this->logger?->warning('MailchimpSignOut: ensureConnectedClient — no active OAuth connection for client uid {uid}', [
                'uid' => $clientUid,
            ]);
            return null;
        }
        if (($connection['access_token'] ?? '') === '') {
            $this->logger?->warning('MailchimpSignOut: ensureConnectedClient — OAuth connection has empty access_token', [
                'uid' => $clientUid,
            ]);
            return null;
        }

        // DC resolution priority:
        //   1. explicit finisher option "server" (test/manual override)
        //   2. connection.metadata.dc — persisted by OAuthFlowService after the
        //      OAuth callback hit Mailchimp's /oauth2/metadata endpoint
        //   3. Api::connect('') falls back to a live HTTP lookup if empty
        $server = (string)$this->parseOption('server');
        if ($server === '') {
            $server = $this->extractDcFromConnectionMetadata($connection);
            if ($server !== '') {
                $this->logger?->debug('MailchimpSignOut: using DC "{dc}" from tx_oauthsvc_connection.metadata', [
                    'dc' => $server,
                ]);
            }
        }

        $this->api->connect($connection['access_token'], $server);
        return $this->api->getClient();
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function extractDcFromConnectionMetadata(array $connection): string
    {
        $raw = (string)($connection['metadata'] ?? '');
        if ($raw === '') {
            return '';
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '';
        }
        $dc = $decoded['dc'] ?? null;
        return is_string($dc) ? $dc : '';
    }
}
