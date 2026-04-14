<?php
declare(strict_types=1);

namespace WapplerSystems\FormMailchimp\Form\Validator;

use MailchimpMarketing\ApiClient;
use MailchimpMarketing\ApiException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Validation\Validator\AbstractValidator;
use WapplerSystems\FormMailchimp\Service\MailchimpFormContext;

class OptinValidator extends AbstractValidator
{
    public function isValid(mixed $value): void
    {
        $context = GeneralUtility::makeInstance(MailchimpFormContext::class);

        $listId = (string)$context->get('listId');
        if ($listId === '') {
            $this->addError('List ID not set.', 1712000001);
            return;
        }

        $apiClient = GeneralUtility::makeInstance(ApiClient::class);

        try {
            $subscriberHash = md5(strtolower((string)$value));
            $member = $apiClient->lists->getListMember($listId, $subscriberHash);
            if (in_array($member->status, ['pending', 'subscribed'], true)) {
                $this->addError(
                    $this->translateErrorMessage('validator.alreadyInList', 'form_mailchimp') ?? 'Already subscribed.',
                    1712000002
                );
            }
        } catch (ApiException $e) {
            if ($e->getCode() !== 404) {
                $this->addError('Could not validate subscription status.', 1712000003);
            }
            // 404 = not a member, which is fine for opt-in
        }
    }
}