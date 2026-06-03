<?php

use TYPO3\CMS\Core\Utility\GeneralUtility;
use WapplerSystems\OauthService\Provider\ProviderDefinition;
use WapplerSystems\OauthService\Provider\ProviderRegistryInterface;

(static function () {
    $registry = GeneralUtility::makeInstance(ProviderRegistryInterface::class);
    $registry->register(new ProviderDefinition(
        identifier: 'mailchimp',
        title: 'Mailchimp OAuth',
        type: 'generic_oauth2',
        authorizationUrl: 'https://login.mailchimp.com/oauth2/authorize',
        tokenUrl: 'https://login.mailchimp.com/oauth2/token',
        // Mailchimp publishes per-account info (DC, api_endpoint, login URL,
        // accountname) at this URL, authenticated via the bearer token from
        // the token exchange. OAuthFlowService stores the JSON response in
        // tx_oauthsvc_connection.metadata so MailchimpSignIn/SignOutFormFinisher
        // can read the DC from the DB instead of doing an extra HTTP roundtrip
        // on every form submission.
        metadataUrl: 'https://login.mailchimp.com/oauth2/metadata',
    ));
})();