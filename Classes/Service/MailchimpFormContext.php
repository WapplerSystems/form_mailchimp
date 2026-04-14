<?php
declare(strict_types=1);

namespace WapplerSystems\FormMailchimp\Service;

/**
 * Request-scoped singleton that holds the Mailchimp settings read from a
 * form's finisher options during the EXT:form submission pipeline.
 *
 * Populated by MailchimpSignInFormFinisher/MailchimpSignOutFormFinisher::setOptions()
 * before validators run.
 * Read by OptinValidator and OptoutValidator via GeneralUtility::makeInstance().
 */
final class MailchimpFormContext
{
    private ?array $settings = null;

    public function setSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    public function isInitialized(): bool
    {
        return $this->settings !== null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}