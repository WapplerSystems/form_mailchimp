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
            $result = $mailChimp->get("lists/$listId/members/$subscriberHash");
            if ($result['status'] === 404) {


                $result = $mailChimp->delete("lists/$listId/members/$subscriberHash");

            } elseif ($result['status'] === 'pending') {
                // send mail again

            }


        } catch (\Exception $e) {

        }

    }


}
