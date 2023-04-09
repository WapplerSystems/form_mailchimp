<?php
namespace WapplerSystems\FormMailchimp\Finishers;

use DrewM\MailChimp\MailChimp;
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


        $apiKey = $this->parseOption('apiKey');
        $listId = $this->parseOption('listId');

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
                ]);

            } elseif ($result['status'] === 'pending') {
                // send mail again

            }


        } catch (\Exception $e) {

        }

    }


}
