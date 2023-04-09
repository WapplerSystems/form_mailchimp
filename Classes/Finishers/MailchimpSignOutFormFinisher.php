<?php
namespace WapplerSystems\FormMailchimp\Finishers;

use DrewM\MailChimp\MailChimp;
use TYPO3\CMS\Core\Utility\DebugUtility;
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

        $apiKey = $this->parseOption('apiKey');
        $listId = $this->parseOption('listId');

        if ($apiKey === null || $apiKey === '' || $listId === null || $listId === '') return;

        try {
            $mailChimp = new MailChimp($apiKey);

            $subscriberHash = MailChimp::subscriberHash($email);

            $result = $mailChimp->delete("lists/$listId/members/$subscriberHash");

        } catch (\Exception $e) {

        }

    }


}
