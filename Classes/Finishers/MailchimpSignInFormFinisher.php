<?php
namespace WapplerSystems\FormMailchimp\Finishers;

use DrewM\MailChimp\MailChimp;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;

class MailchimpSignInFormFinisher extends AbstractFinisher
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
            $result = $mailChimp->get("lists/$listId/members/$subscriberHash");
            if ($result['status'] === 404) {

                // TODO: use options to set keys
                $name = $formRuntime['name'] ?? '';
                if ($name === '') {
                    $name = ($formRuntime['firstName'] ? $formRuntime['firstName'].' ' : '') . ($formRuntime['lastName'] ?? '');
                }

                $result = $mailChimp->put("lists/$listId/members/$subscriberHash?skip_merge_validation=true", [
                    'email_address' => $email,
                    'status_if_new' => 'pending',
                    'email_type' => 'html',
                    'status' => 'pending',
                    #'language' => 'de',
                    'ip_signup' => $_SERVER['REMOTE_ADDR'],
                    'merge_fields' => [
                        'FNAME' => $name,
                    ],
                ]);

            } elseif ($result['status'] === 'pending') {
                // send mail again

            }


        } catch (\Exception $e) {

        }

    }


}
