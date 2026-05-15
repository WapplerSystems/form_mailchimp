<?php

declare(strict_types=1);

namespace WapplerSystems\FormMailchimp\Backend\FlexForm;

use MailchimpMarketing\ApiClient;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WapplerSystems\OauthService\Service\OAuthClientService;

/**
 * itemsProcFunc for the FlexForm-generated MailChimpSignIn/SignOut override sheet:
 * populates the `listId` select with the Mailchimp audiences the form's configured
 * OAuth client can see. Runs at BE form render time — no JavaScript involved.
 *
 * Resolution chain (all in BE context, no Extbase ConfigurationManager):
 *   1. Read settings.persistenceIdentifier from the tt_content row's pi_flexform.
 *   2. Parse the form YAML and locate the finisher block whose identifier matches
 *      the FlexForm field path (`settings.finishers.<id>.listId`).
 *   3. Look up the connected OAuth client via OAuthClientService.
 *   4. Call Mailchimp `lists.getAllLists` and return the audiences as items.
 *
 * If anything in the chain fails, the items list is left empty plus a placeholder
 * holding the currently stored listId so the editor does not silently lose the
 * value.
 */
final class ListItemsProvider
{
    public function __construct(
        private readonly OAuthClientService $oAuthClientService,
        private readonly RequestFactory $requestFactory,
    ) {}

    #[AsAllowedCallable]
    public function getItems(array &$params): void
    {
        $finisherIdentifier = $this->detectFinisherIdentifier((string)($params['field'] ?? ''));
        if ($finisherIdentifier === null) {
            return;
        }

        // In FlexForm itemsProcFunc context, $params['row'] is the flex-sheet's
        // local row (only sheet fields + uid). The full tt_content record with
        // the original pi_flexform XML lives in $params['flexParentDatabaseRow'].
        $persistenceIdentifier = $this->extractPersistenceIdentifier($params['flexParentDatabaseRow'] ?? []);
        if ($persistenceIdentifier === '') {
            $this->pushEmptyPlaceholder($params, 'no persistenceIdentifier set');
            return;
        }

        $finisherOptions = $this->resolveFinisherOptions($persistenceIdentifier, $finisherIdentifier);
        if ($finisherOptions === null) {
            $this->pushEmptyPlaceholder($params, 'finisher not found in form YAML');
            return;
        }

        $clientUid = (int)($finisherOptions['oauthClient'] ?? 0);
        if ($clientUid <= 0) {
            $this->pushEmptyPlaceholder($params, 'no oauthClient configured in form YAML');
            return;
        }

        $connection = $this->oAuthClientService->getActiveConnectionByClientUid($clientUid);
        if ($connection === null || ($connection['access_token'] ?? '') === '') {
            $this->pushEmptyPlaceholder($params, 'no active OAuth connection');
            return;
        }

        $server = $this->resolveServer($connection, (string)($finisherOptions['server'] ?? ''));
        $lists = $this->fetchLists((string)$connection['access_token'], $server);
        if ($lists === null) {
            $this->pushEmptyPlaceholder($params, 'Mailchimp API error');
            return;
        }

        $items = $params['items'] ?? [];
        // First item: empty / "use form default"
        $items[] = ['—', ''];
        foreach ($lists as $list) {
            $items[] = [
                $list['name'] . ' (' . $list['id'] . ')',
                $list['id'],
            ];
        }
        $params['items'] = $items;
    }

    /**
     * The FlexForm field path looks like `settings.finishers.MailChimpSignIn.listId`.
     * Extracts the finisher identifier from that path.
     */
    private function detectFinisherIdentifier(string $fieldPath): ?string
    {
        if (preg_match('/settings\.finishers\.([A-Za-z0-9_]+)\.listId$/', $fieldPath, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function extractPersistenceIdentifier(array $row): string
    {
        $flex = $row['pi_flexform'] ?? '';
        if (is_string($flex) && $flex !== '') {
            $parsed = GeneralUtility::xml2array($flex);
            if (!is_array($parsed)) {
                return '';
            }
            $flex = $parsed;
        }
        if (!is_array($flex)) {
            return '';
        }
        // sDEF / lDEF / settings.persistenceIdentifier / vDEF
        // vDEF is a string in raw XML form but gets wrapped in a single-element
        // array by FormData providers — accept both shapes.
        $value = $flex['data']['sDEF']['lDEF']['settings.persistenceIdentifier']['vDEF'] ?? null;
        if (is_array($value)) {
            $value = reset($value);
        }
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Loads the form YAML referenced by `EXT:foo/...` or a fileadmin path and
     * returns the matching finisher's options array.
     *
     * @return array<string,mixed>|null
     */
    private function resolveFinisherOptions(string $persistenceIdentifier, string $finisherIdentifier): ?array
    {
        $absPath = GeneralUtility::getFileAbsFileName($persistenceIdentifier);
        if ($absPath === '' || !is_readable($absPath)) {
            return null;
        }

        try {
            $form = Yaml::parseFile($absPath);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($form) || !isset($form['finishers']) || !is_array($form['finishers'])) {
            return null;
        }

        foreach ($form['finishers'] as $entry) {
            if (is_array($entry)
                && ($entry['identifier'] ?? null) === $finisherIdentifier
                && is_array($entry['options'] ?? null)
            ) {
                return $entry['options'];
            }
        }
        return null;
    }

    /**
     * Prefer the DC stored on the OAuth connection (from the metadata roundtrip
     * during the OAuth callback). Fall back to the finisher's "server" option,
     * then "us1" as a last resort.
     *
     * @param array<string,mixed> $connection
     */
    private function resolveServer(array $connection, string $finisherServer): string
    {
        $metaRaw = (string)($connection['metadata'] ?? '');
        if ($metaRaw !== '') {
            $decoded = json_decode($metaRaw, true);
            if (is_array($decoded) && is_string($decoded['dc'] ?? null) && $decoded['dc'] !== '') {
                return $decoded['dc'];
            }
        }
        if ($finisherServer !== '') {
            return $finisherServer;
        }
        // Last resort: live lookup via the OAuth metadata endpoint.
        return $this->resolveMailchimpDatacenter((string)$connection['access_token']) ?? 'us1';
    }

    /**
     * @return list<array{id:string,name:string}>|null
     */
    private function fetchLists(string $accessToken, string $server): ?array
    {
        // The Mailchimp Marketing SDK leaks E_DEPRECATED on PHP 8.4+ which
        // TYPO3's ErrorHandler escalates to exceptions. Same workaround as in
        // MailchimpSignInFormFinisher.
        set_error_handler(static fn () => true, E_DEPRECATED | E_USER_DEPRECATED);
        try {
            $apiClient = new ApiClient();
            $apiClient->setConfig([
                'accessToken' => $accessToken,
                'server' => $server,
            ]);
            $response = $apiClient->lists->getAllLists(null, null, 1000);
        } catch (\Throwable) {
            restore_error_handler();
            return null;
        }
        restore_error_handler();

        $out = [];
        foreach (($response->lists ?? []) as $list) {
            $out[] = [
                'id' => (string)$list->id,
                'name' => (string)($list->name ?? $list->id),
            ];
        }
        return $out;
    }

    private function resolveMailchimpDatacenter(string $accessToken): ?string
    {
        if ($accessToken === '') {
            return null;
        }
        try {
            $response = $this->requestFactory->request(
                'https://login.mailchimp.com/oauth2/metadata',
                'GET',
                [
                    'headers' => ['Authorization' => 'OAuth ' . $accessToken],
                    'timeout' => 5,
                    'http_errors' => false,
                ]
            );
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $data = json_decode((string)$response->getBody(), true);
            $dc = is_array($data) ? ($data['dc'] ?? null) : null;
            return (is_string($dc) && $dc !== '') ? $dc : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Empty list dropdown if we cannot reach Mailchimp — but preserve the
     * stored value so it does not silently disappear.
     */
    private function pushEmptyPlaceholder(array &$params, string $reason): void
    {
        $stored = (string)($params['config']['default'] ?? '');
        $items = $params['items'] ?? [];
        if ($stored !== '') {
            $items[] = [$stored . ' (' . $reason . ')', $stored];
        } else {
            $items[] = ['— ' . $reason . ' —', ''];
        }
        $params['items'] = $items;
    }
}
