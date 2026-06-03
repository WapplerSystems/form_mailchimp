<?php

declare(strict_types=1);

namespace WapplerSystems\FormMailchimp\Finishers;

use GuzzleHttp\Exception\ClientException;
use MailchimpMarketing\ApiClient;
use MailchimpMarketing\ApiException;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
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
     * Populates MailchimpFormContext so future validators can access
     * listId, oauthClient and server.
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
                } catch (ApiException | ClientException $e) {
                    // Mailchimp SDK leaks Guzzle's ClientException on 4xx for
                    // some endpoints (incl. getListMember) instead of wrapping
                    // it as ApiException. Both expose the HTTP status — but
                    // ClientException reaches it via getResponse().
                    $status = $e instanceof ClientException && $e->getResponse() !== null
                        ? $e->getResponse()->getStatusCode()
                        : $e->getCode();
                    if ($status !== 404) {
                        $this->logger?->error('MailchimpSignIn: getListMember failed', [
                            'status' => $status,
                            'message' => $e->getMessage(),
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

                $payload = [
                    'email_address' => $email,
                    'status_if_new' => 'pending',
                    'status' => 'pending',
                    'email_type' => 'html',
                    'ip_signup' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'merge_fields' => [
                        'FNAME' => $name,
                    ],
                ];

                // Mailchimp uses ISO-639-1 codes ("de", "tr", ...) for per-subscriber
                // language. We derive it from the active TYPO3 SiteLanguage so the same
                // form yaml works across language roots without duplication.
                $language = $this->resolveLanguageCode();
                if ($language !== '') {
                    $payload['language'] = $language;
                }

                $apiClient->lists->setListMember($listId, $subscriberHash, $payload);

                $this->logger?->info('MailchimpSignIn: setListMember accepted by Mailchimp', [
                    'listId' => $listId,
                    'language' => $language ?: '(unset)',
                ]);
            } catch (ApiException | ClientException $e) {
                $response = $e instanceof ClientException ? $e->getResponse() : null;
                $status = $response !== null ? $response->getStatusCode() : $e->getCode();
                $body = $response !== null ? (string)$response->getBody() : '';

                // Mailchimp 400 "Invalid Resource" is user-input rejection:
                // the anti-fake-email/disposable filter blocks the address even
                // though it passes RFC-5322 validation. Surface a localized
                // field-level error so the user stops retrying the same address.
                if ($status === 400 && str_contains($body, 'Invalid Resource')) {
                    $this->logger?->warning('MailchimpSignIn: Mailchimp rejected subscription (400 Invalid Resource)', [
                        'email' => $email,
                        'response' => $body,
                    ]);
                    $this->replaceConfirmationMessageWithError();
                    return;
                }

                $this->logger?->error('MailchimpSignIn: Mailchimp API error', [
                    'status' => $status,
                    'message' => $e->getMessage(),
                    'response' => $body,
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

        // DC resolution priority:
        //   1. explicit finisher option "server" (test/manual override)
        //   2. connection.metadata.dc — persisted by OAuthFlowService after the
        //      OAuth callback hit Mailchimp's /oauth2/metadata endpoint
        //   3. Api::connect('') falls back to a live HTTP lookup if empty
        $server = (string)$this->parseOption('server');
        if ($server === '') {
            $server = $this->extractDcFromConnectionMetadata($connection);
            if ($server !== '') {
                $this->logger?->debug('MailchimpSignIn: using DC "{dc}" from tx_oauthsvc_connection.metadata', [
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

    /**
     * Resolves the ISO-639-1 language code to push to Mailchimp's "language" field.
     * Priority: finisher option "language" (explicit override) > current TYPO3
     * SiteLanguage (derived from the request). Returns '' when neither is set,
     * in which case the language key is omitted from the Mailchimp payload.
     */
    private function resolveLanguageCode(): string
    {
        $optionLang = trim((string)$this->parseOption('language'));
        if ($optionLang !== '') {
            return strtolower($optionLang);
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return '';
        }
        $siteLanguage = $request->getAttribute('language');
        if (!$siteLanguage instanceof SiteLanguage) {
            return '';
        }
        return strtolower($siteLanguage->getLocale()->getLanguageCode());
    }

    /**
     * Replaces the next Confirmation finisher's `message` option with a localized
     * "this address could not be subscribed" error. The user otherwise sees the
     * default "thanks, please check your email" confirmation and keeps retrying
     * the same address — see logs where the same hash appears 6–8× in a minute.
     */
    private function replaceConfirmationMessageWithError(): void
    {
        $message = $this->translateRejectMessage();
        $seen = [];
        foreach ($this->finisherContext->getFormRuntime()->getFormDefinition()->getFinishers() as $finisher) {
            $id = $finisher->getFinisherIdentifier();
            $seen[] = $id;
            if ($id === 'Confirmation') {
                $finisher->setOption('message', $message);
                $this->logger?->warning('MailchimpSignIn: replaced Confirmation message', [
                    'newMessage' => $message,
                    'finisherClass' => $finisher::class,
                ]);
                return;
            }
        }
        $this->logger?->warning('MailchimpSignIn: Confirmation finisher not found in chain', [
            'seenIdentifiers' => $seen,
            'newMessage' => $message,
        ]);
    }

    private function translateRejectMessage(): string
    {
        $fallback = 'This e-mail address could not be subscribed. Please use a different e-mail address.';
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $siteLanguage = $request instanceof ServerRequestInterface
            ? $request->getAttribute('language')
            : null;
        $languageService = $siteLanguage instanceof SiteLanguage
            ? GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromSiteLanguage($siteLanguage)
            : GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
        $translated = $languageService->sL(
            'LLL:EXT:form_mailchimp/Resources/Private/Language/locallang.xlf:finisher.signIn.emailRejected'
        );
        return $translated !== '' ? $translated : $fallback;
    }
}
