<?php
declare(strict_types=1);

namespace WapplerSystems\FormMailchimp\Form\Validator;

use MailchimpMarketing\ApiClient;
use MailchimpMarketing\ApiException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Validation\Validator\AbstractValidator;
use WapplerSystems\FormMailchimp\Service\MailchimpFormContext;

class OptoutValidator extends AbstractValidator
{
    public function isValid(mixed $value): void
    {
        $context = GeneralUtility::makeInstance(MailchimpFormContext::class);

        $listId = (string)$context->get('listId');
        if ($listId === '') {
            $this->addError('List ID not set.', 1712000011);
            return;
        }

        $apiClient = GeneralUtility::makeInstance(ApiClient::class);

        try {
            $subscriberHash = md5(strtolower((string)$value));
            $member = $apiClient->lists->getListMember($listId, $subscriberHash);
            if (!in_array($member->status, ['subscribed'], true)) {
                $this->addError(
                    $this->translateErrorMessage('validator.notInList', 'form_mailchimp') ?? 'Not subscribed.',
                    1712000012
                );
            }
        } catch (ApiException $e) {
            if ($e->getCode() === 404) {
                $this->addError(
                    $this->translateErrorMessage('validator.notInList', 'form_mailchimp') ?? 'Not subscribed.',
                    1712000013
                );
            } else {
                $this->addError('Could not validate subscription status.', 1712000014);
            }
        }
    }
}