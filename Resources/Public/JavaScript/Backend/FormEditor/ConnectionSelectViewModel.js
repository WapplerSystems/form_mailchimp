/**
 * Module: @wapplersystems/form-mailchimp/Backend/FormEditor/ConnectionSelectViewModel.js
 *
 * Replaces the Inspector-TextEditor inputs of the Mailchimp finishers with
 * <select>s populated from the backend:
 *
 *  - oauthClient: list of active Mailchimp OAuth clients
 *  - listId:      Mailchimp audiences accessible through the chosen OAuth
 *                 client; reloads when oauthClient or server changes.
 *
 * Using Inspector-TextEditor (instead of Inspector-SingleSelectEditor) avoids
 * TYPO3's HMAC "limitedAllowedValues" validation, which rejects dynamic values
 * not listed in the static YAML selectOptions.
 */

import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

const FINISHER_IDENTIFIERS = ['MailChimpSignIn', 'MailChimpSignOut'];

let _formEditorApp = null;

/**
 * Per-inspector-render state. Reset whenever the oauthClient editor is
 * (re-)rendered, since that runs before listId/server in the YAML order.
 */
let _state = null;

function freshState() {
    return {
        collectionElementId: null,
        clientUid: '',
        server: 'us1',
        listSelect: null,
        listInput: null,
        listValue: '',
    };
}

function getPublisherSubscriber() {
    return _formEditorApp.getPublisherSubscriber();
}

/**
 * Resolves the inspector editor's root DOM element. TYPO3 v14 passes a vanilla
 * Node here; older releases passed a jQuery wrapper, so we accept both.
 */
function resolveEditorRoot(editorHtml) {
    if (!editorHtml) {
        return null;
    }
    if (editorHtml instanceof Element) {
        return editorHtml;
    }
    if (typeof editorHtml.get === 'function') {
        return editorHtml.get(0) ?? null;
    }
    if (typeof editorHtml[0] !== 'undefined') {
        return editorHtml[0];
    }
    return null;
}

function readModelProperty(rawPath, collectionElementIdentifier) {
    try {
        const propertyPath = _formEditorApp.buildPropertyPath(
            rawPath,
            collectionElementIdentifier,
            'finishers'
        );
        return _formEditorApp.getCurrentlySelectedFormElement().get(propertyPath);
    } catch (e) {
        return undefined;
    }
}

function syncInputFromSelect(input, select) {
    input.value = select.value;
    input.dispatchEvent(new Event('keyup', { bubbles: true }));
}

function buildSelect(currentValue, options) {
    const select = document.createElement('select');
    select.className = 'form-select form-control';
    options.forEach(function (opt) {
        const option = document.createElement('option');
        option.value = opt.value;
        option.textContent = opt.label;
        if (opt.value === currentValue) {
            option.selected = true;
        }
        select.appendChild(option);
    });
    return select;
}

function replaceInputWithSelect(input, select) {
    input.style.display = 'none';
    input.parentNode.insertBefore(select, input);
}

async function handleOauthClientEditor(editorHtml, currentValue, collectionElementIdentifier) {
    const root = resolveEditorRoot(editorHtml);
    if (!root) {
        return;
    }
    const input = root.querySelector('[data-template-property="propertyPath"]');
    if (!input) {
        return;
    }

    _state = freshState();
    _state.collectionElementId = collectionElementIdentifier;
    _state.clientUid = String(currentValue ?? '');
    _state.server = String(readModelProperty('options.server', collectionElementIdentifier) ?? '') || 'us1';
    _state.listValue = String(readModelProperty('options.listId', collectionElementIdentifier) ?? '');

    let clients;
    try {
        const response = await new AjaxRequest(TYPO3.settings.ajaxUrls['form_mailchimp_form_editor_clients']).get();
        clients = await response.resolve();
    } catch (e) {
        console.error('Mailchimp: Could not load OAuth clients', e);
        return;
    }

    const select = buildSelect(_state.clientUid, clients);
    select.addEventListener('change', function () {
        syncInputFromSelect(input, select);
        _state.clientUid = select.value;
        refreshListSelect();
    });
    replaceInputWithSelect(input, select);
}

async function handleListIdEditor(editorHtml, currentValue, collectionElementIdentifier) {
    const root = resolveEditorRoot(editorHtml);
    if (!root) {
        return;
    }
    const input = root.querySelector('[data-template-property="propertyPath"]');
    if (!input) {
        return;
    }

    if (!_state || _state.collectionElementId !== collectionElementIdentifier) {
        _state = freshState();
        _state.collectionElementId = collectionElementIdentifier;
        _state.clientUid = String(readModelProperty('options.oauthClient', collectionElementIdentifier) ?? '');
        _state.server = String(readModelProperty('options.server', collectionElementIdentifier) ?? '') || 'us1';
    }
    _state.listValue = String(currentValue ?? '');

    const select = document.createElement('select');
    select.className = 'form-select form-control';
    select.addEventListener('change', function () {
        syncInputFromSelect(input, select);
        _state.listValue = select.value;
    });
    replaceInputWithSelect(input, select);

    _state.listSelect = select;
    _state.listInput = input;

    await refreshListSelect();
}

function handleServerEditor(editorHtml, currentValue, collectionElementIdentifier) {
    const root = resolveEditorRoot(editorHtml);
    if (!root) {
        return;
    }
    const input = root.querySelector('[data-template-property="propertyPath"]');
    if (!input) {
        return;
    }
    if (!_state || _state.collectionElementId !== collectionElementIdentifier) {
        return;
    }
    _state.server = String(currentValue ?? '') || 'us1';
    // Refresh list dropdown when the user changes datacenter.
    input.addEventListener('keyup', function () {
        const next = String(input.value ?? '') || 'us1';
        if (next === _state.server) {
            return;
        }
        _state.server = next;
        refreshListSelect();
    });
}

async function refreshListSelect() {
    if (!_state || !_state.listSelect || !_state.listInput) {
        return;
    }
    const select = _state.listSelect;
    const input = _state.listInput;

    select.innerHTML = '';
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = _state.clientUid ? '…' : '— OAuth Client wählen —';
    select.appendChild(placeholder);

    if (!_state.clientUid) {
        syncInputFromSelect(input, select);
        return;
    }

    let lists;
    try {
        const url = TYPO3.settings.ajaxUrls['form_mailchimp_form_editor_lists']
            + '?clientUid=' + encodeURIComponent(_state.clientUid)
            + '&server=' + encodeURIComponent(_state.server || 'us1');
        const response = await new AjaxRequest(url).get();
        lists = await response.resolve();
    } catch (e) {
        console.error('Mailchimp: Could not load audiences', e);
        placeholder.textContent = '(Fehler beim Laden)';
        return;
    }

    // Replace placeholder by the actual options.
    select.innerHTML = '';
    let matched = false;
    lists.forEach(function (item) {
        const option = document.createElement('option');
        option.value = item.value;
        option.textContent = item.label;
        if (item.value === _state.listValue) {
            option.selected = true;
            matched = true;
        }
        select.appendChild(option);
    });

    // If the previously stored listId is not in the audiences list, prepend it
    // as a separate option so the user does not silently lose the value.
    if (!matched && _state.listValue !== '') {
        const lost = document.createElement('option');
        lost.value = _state.listValue;
        lost.textContent = _state.listValue + ' (nicht in Liste)';
        lost.selected = true;
        select.insertBefore(lost, select.firstChild);
    }

    syncInputFromSelect(input, select);
}

function _subscribeEvents() {
    getPublisherSubscriber().subscribe(
        'view/inspector/editor/insert/perform',
        function (topic, args) {
            const editorConfiguration         = args[0];
            const editorHtml                  = args[1];
            const collectionElementIdentifier = args[2];
            const collectionName              = args[3];

            if (
                collectionName !== 'finishers' ||
                !FINISHER_IDENTIFIERS.includes(collectionElementIdentifier)
            ) {
                return;
            }

            const editorIdentifier = editorConfiguration['identifier'];
            if (!['oauthClient', 'listId', 'server'].includes(editorIdentifier)) {
                return;
            }

            const propertyPath = _formEditorApp.buildPropertyPath(
                editorConfiguration['propertyPath'],
                collectionElementIdentifier,
                collectionName
            );
            const currentValue = String(
                _formEditorApp.getCurrentlySelectedFormElement().get(propertyPath) ?? ''
            );

            switch (editorIdentifier) {
                case 'oauthClient':
                    handleOauthClientEditor(editorHtml, currentValue, collectionElementIdentifier);
                    break;
                case 'listId':
                    handleListIdEditor(editorHtml, currentValue, collectionElementIdentifier);
                    break;
                case 'server':
                    handleServerEditor(editorHtml, currentValue, collectionElementIdentifier);
                    break;
            }
        }
    );
}

export function bootstrap(formEditorApp) {
    _formEditorApp = formEditorApp;
    _subscribeEvents();
}