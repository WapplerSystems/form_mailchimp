<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3_MODE') or die();

ExtensionManagementUtility::addStaticFile('form_mailchimp', 'Configuration/TypoScript', 'Form Mailchimp');

