<?php

declare(strict_types=1);

namespace WapplerSystems\FormMailchimp\Finishers;

use MailchimpMarketing\ApiClient;
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
        $this->logger?->info('MailchimpSignIn: finisher invoked');

        $formRuntime = $this->finisherContext->getFormRuntime();
        $emailField = (string)($this->parseOption('emailField') ?: 'email');
        $email = $formRuntime[$emailField] ?? null;

        if (empty($email)) {
            $this->logger?->warning('MailchimpSignIn: aborting — no email value in form field "{field}"', [
                'field' => $emailField,
            ]);
            return;
        }

        $listId = $this->parseOption('listId') ?: '';
        if ($listId === '') {
            $this->logger?->warning('MailchimpSignIn: aborting — finisher option "listId" is empty');
            return;
        }

        $apiClient = $this->ensureConnectedClient();
        if ($apiClient === null) {
            $this->logger?->warning('MailchimpSignIn: aborting — no connected Mailchimp ApiClient');
            return;
        }

        $this->logger?->info('MailchimpSignIn: calling Mailchimp', [
            'email' => $email,
            'listId' => $listId,
        ]);

        $this->api->runWithoutDeprecationNotices(function () use ($apiClient, $listId, $email, $formRuntime): void {
            try {
                $subscriberHash = md5(strtolower($email));

                try {
                    $member = $apiClient->lists->getListMember($listId, $subscriberHash);
                    if (in_array($member->status, ['pending', 'subscribed'], true)) {
                        $this->logger?->info('MailchimpSignIn: member already {status} — skipping setListMember', [
                            'status' => $member->status,
                            'listId' => $listId,
                        ]);
                        return;
                    }
                } catch (ApiException $e) {
                    if ($e->getCode() !== 404) {
                        $this->logger?->error('MailchimpSignIn: getListMember failed', [
                            'code' => $e->getCode(),
                            'message' => $e->getMessage(),
                            'body' => $e->getResponseBody(),
                        ]);
                        return;
                    }
                    // 404 = not yet a member — fall through to setListMember.
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

                $this->logger?->info('MailchimpSignIn: setListMember accepted by Mailchimp', [
                    'listId' => $listId,
                ]);
            } catch (ApiException $e) {
                $this->logger?->error('MailchimpSignIn: Mailchimp API error', [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'body' => $e->getResponseBody(),
                ]);
            } catch (\Throwable $e) {
                $this->logger?->error('MailchimpSignIn: unexpected error', [
                    'class' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
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
            $this->logger?->warning('MailchimpSignIn: ensureConnectedClient — finisher option "oauthClient" is empty or 0');
            return null;
        }

        $connection = $this->oAuthClientService->getActiveConnectionByClientUid($clientUid);
        if ($connection === null) {
            $this->logger?->warning('MailchimpSignIn: ensureConnectedClient — no active OAuth connection for client uid {uid}', [
                'uid' => $clientUid,
            ]);
            return null;
        }
        if (($connection['access_token'] ?? '') === '') {
            $this->logger?->warning('MailchimpSignIn: ensureConnectedClient — OAuth connection has empty access_token', [
                'uid' => $clientUid,
            ]);
            return null;
        }

        // Pass an empty server through when no DC is configured: Api::connect()
        // then auto-resolves the DC from Mailchimp's metadata endpoint. Tokens
        // are region-bound, so hardcoding "us1" hits 401 for any other DC.
        $this->api->connect(
            $connection['access_token'],
            (string)$this->parseOption('server'),
        );
        return $this->api->getClient();
    }
}
