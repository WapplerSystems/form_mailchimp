<?php
declare(strict_types=1);

return [
    'ajax_form_mailchimp_form_editor_clients' => [
        'path' => '/ajax/form-mailchimp/form-editor/clients',
        'access' => 'public',
        'ajax' => true,
        'target' => \WapplerSystems\FormMailchimp\Controller\Backend\FormEditorAjaxController::class . '::getClientsAction',
    ],
    'ajax_form_mailchimp_form_editor_lists' => [
        'path' => '/ajax/form-mailchimp/form-editor/lists',
        'access' => 'public',
        'ajax' => true,
        'target' => \WapplerSystems\FormMailchimp\Controller\Backend\FormEditorAjaxController::class . '::getListsAction',
    ],
];