<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WapplerSystems\OauthService\Provider\ProviderDefinition;
use WapplerSystems\OauthService\Provider\ProviderRegistryInterface;

(static function () {
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/form']['afterSubmit'][] =
        \WapplerSystems\FormMailchimp\Form\Hook\AfterSubmitHook::class;

    $registry = GeneralUtility::makeInstance(ProviderRegistryInterface::class);
    $registry->register(new ProviderDefinition(
        identifier: 'mailchimp',
        title: 'Mailchimp OAuth',
        type: 'generic_oauth2',
        authorizationUrl: 'https://login.mailchimp.com/oauth2/authorize',
        tokenUrl: 'https://login.mailchimp.com/oauth2/token',
        metadataUrl: 'https://login.mailchimp.com/oauth2/metadata'
    ));

    ExtensionManagementUtility::addTypoScriptSetup(
        'module.tx_form {
    settings {
        yamlConfigurations {
            541 = EXT:form_mailchimp/Configuration/Yaml/FormSetup.yaml
            542 = EXT:form_mailchimp/Configuration/Yaml/FormEditorSetup.yaml
        }
    }
}'
    );
})();
