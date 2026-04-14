<?php
declare(strict_types=1);

namespace WapplerSystems\FormMailchimp\Form\Hook;

use MailchimpMarketing\ApiClient;
use TYPO3\CMS\Form\Domain\Runtime\FormRuntime;
use WapplerSystems\FormMailchimp\Finishers\MailchimpSignInFormFinisher;
use WapplerSystems\FormMailchimp\Finishers\MailchimpSignOutFormFinisher;
use WapplerSystems\FormMailchimp\Service\MailchimpFormContext;
use WapplerSystems\OauthService\Service\OAuthClientService;

/**
 * EXT:form afterSubmit hook.
 *
 * Fires for each form element during mapAndValidatePage(), before field
 * validators run. On the first invocation per request it reads the
 * clientUid from MailchimpFormContext (populated earlier by
 * MailchimpSignIn/OutFormFinisher::setOptions()) and authenticates the
 * shared ApiClient singleton via OAuthClientService so that
 * OptinValidator / OptoutValidator can call the Mailchimp API without
 * establishing their own connection.
 */
final class AfterSubmitHook
{
    private bool $connected = false;

    public function __construct(
        private readonly MailchimpFormContext $context,
        private readonly OAuthClientService $oAuthClientService,
        private readonly ApiClient $apiClient,
    ) {}

    public function afterSubmit(
        FormRuntime $formRuntime,
        mixed $renderable,
        mixed $value,
        array $requestArguments
    ): mixed {

        $finishers = $formRuntime->getFormDefinition()->getFinishers();
        $found = false;
        foreach ($finishers as $finisher) {
            if ($finisher instanceof MailchimpSignInFormFinisher || $finisher instanceof MailchimpSignOutFormFinisher) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return $value;
        }

        if (!$this->connected && $this->context->isInitialized()) {
            $clientUid = (int)$this->context->get('clientUid', 0);
            $connection = $clientUid > 0
                ? $this->oAuthClientService->getActiveConnectionByClientUid($clientUid)
                : $this->oAuthClientService->getActiveConnectionByProvider('mailchimp');

            if ($connection !== null && $connection['access_token'] !== '') {
                // Resolve server: metadata "dc" field > finisher option > default
                $server = $this->oAuthClientService->getConnectionMetadataValue($connection, 'dc')
                    ?? $this->context->get('server')
                    ?? 'us1';

                $this->apiClient->setConfig([
                    'accessToken' => $connection['access_token'],
                    'server' => $server,
                ]);
            }
            $this->connected = true;
        }

        return $value;
    }
}
