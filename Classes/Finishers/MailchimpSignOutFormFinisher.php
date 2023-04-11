<?php
namespace WapplerSystems\FormMailchimp\Finishers;

use DrewM\MailChimp\MailChimp;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;

class MailchimpSignOutFormFinisher extends AbstractFinisher
{

    /**
     * Executes this finisher
     * @see AbstractFinisher::execute()
     *
     */
    protected function executeInternal()
    {

        $formRuntime = $this->finisherContext->getFormRuntime();
        $elements = $formRuntime->getFormDefinition()->getRenderablesRecursively();

        $email = $formRuntime['email'] ?? null;

        if (empty($email)) {
            return;
        }

        $configurationManager = GeneralUtility::makeInstance( ConfigurationManagerInterface::class);
        $settings = $configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT);

        $tsApiKey = $settings['plugin.']['tx_formmailchimp.']['settings.']['apiKey'] ?? '';
        $tsListId = $settings['plugin.']['tx_formmailchimp.']['settings.']['listId'] ?? '';

        $apiKey = $this->parseOption('apiKey');
        if ($apiKey === '') {
            $apiKey = $tsApiKey;
        }
        $listId = $this->parseOption('listId');
        if ($listId === '') {
            $listId = $tsListId;
        }

        if ($apiKey === null || $apiKey === '' || $listId === null || $listId === '') return;

        try {
            $mailChimp = new MailChimp($apiKey);

            $subscriberHash = MailChimp::subscriberHash($email);

            $result = $mailChimp->delete("lists/$listId/members/$subscriberHash");

        } catch (\Exception $e) {

        }

    }


}
