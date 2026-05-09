import DocumentService from '@typo3/core/document-service.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import { AjaxResponse } from '@typo3/core/ajax/ajax-response.js';
import '@typo3/backend/element/icon-element.js';

const SELECTORS = {
    form: '[data-cache-probe-form]',
    result: '[data-cache-probe-result]',
    site: '[data-cache-probe-site]',
    target: '[data-cache-probe-target]',
    submit: '[data-cache-probe-submit]',
    auditRoot: '[data-audit-log-root]',
    auditForm: '[data-audit-log-form]',
    auditLink: '[data-audit-log-link]',
    auditDatePreset: '[data-audit-date-preset]',
    auditCustomDateFrom: '[data-audit-custom-date-from]',
    auditCustomDateTo: '[data-audit-custom-date-to]',
    auditTagsToggle: '[data-audit-tags-toggle]',
};

function createIcon(identifier, size = 'small') {
    const icon = document.createElement('typo3-backend-icon');
    icon.setAttribute('identifier', identifier);
    icon.setAttribute('size', size);
    return icon;
}

function createAlert(payload) {
    const alert = document.createElement('div');
    alert.className = `alert alert-${payload.statusClass ?? 'secondary'} mb-0`;

    const wrapper = document.createElement('div');
    wrapper.className = 'd-flex align-items-start gap-3';
    wrapper.append(createIcon(payload.iconIdentifier ?? 'actions-info-circle'));

    const content = document.createElement('div');

    const title = document.createElement('div');
    title.className = 'fw-semibold';
    title.textContent = payload.title ?? '';
    content.append(title);

    if (payload.message) {
        const message = document.createElement('p');
        message.className = 'mb-0';
        message.textContent = payload.message;
        content.append(message);
    }

    if (payload.details) {
        const details = document.createElement('p');
        details.className = 'small text-body-secondary mb-0';
        details.textContent = payload.details;
        content.append(details);
    }

    if (payload.targetUrl) {
        const target = document.createElement('p');
        target.className = 'small text-body-secondary mb-0 mt-1';
        const code = document.createElement('code');
        code.textContent = payload.targetUrl;
        target.append(code);
        content.append(target);
    }

    wrapper.append(content);
    alert.append(wrapper);
    return alert;
}

function renderProbeResult(container, payload) {
    container.replaceChildren(createAlert(payload));
}

function renderProbeLoading(container, loadingLabel) {
    container.replaceChildren(createAlert({
        statusClass: 'info',
        iconIdentifier: 'actions-refresh',
        title: loadingLabel,
        message: loadingLabel,
    }));
}

function normalizePayload(payload, fallback) {
    if (payload && typeof payload === 'object') {
        return payload;
    }

    return fallback;
}

function initializeProbeForms(context = document) {
    context.querySelectorAll(SELECTORS.form).forEach((form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.boundProbe === '1') {
            return;
        }
        form.dataset.boundProbe = '1';

        const resultContainer = form.querySelector(SELECTORS.result);
        const submitButton = form.querySelector(SELECTORS.submit);
        const siteInput = form.querySelector(SELECTORS.site);
        const targetInput = form.querySelector(SELECTORS.target);

        if (!(resultContainer instanceof HTMLElement) || !(submitButton instanceof HTMLButtonElement) || !(targetInput instanceof HTMLInputElement)) {
            return;
        }

        const originalButtonLabel = submitButton.textContent ?? '';
        const loadingLabel = form.dataset.loadingLabel ?? originalButtonLabel;
        const httpErrorTitle = form.dataset.httpErrorTitle ?? 'HTTP-ERROR';
        const networkErrorLabel = form.dataset.networkErrorLabel ?? 'The probe request could not reach the target.';
        const unexpectedErrorLabel = form.dataset.unexpectedErrorLabel ?? 'The probe request failed unexpectedly.';

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const endpoint = form.getAttribute('action');
            if (!endpoint) {
                return;
            }

            submitButton.disabled = true;
            submitButton.textContent = loadingLabel;
            renderProbeLoading(resultContainer, loadingLabel);

            try {
                const response = await new AjaxRequest(endpoint).post({
                    site: siteInput instanceof HTMLInputElement ? siteInput.value : '',
                    target: targetInput.value,
                });
                const payload = normalizePayload(await response.resolve(), {});
                renderProbeResult(resultContainer, payload);
            } catch (error) {
                if (error instanceof AjaxResponse) {
                    try {
                        const payload = normalizePayload(await error.resolve(), null);
                        if (payload !== null) {
                            renderProbeResult(resultContainer, {
                                statusClass: payload.statusClass ?? 'danger',
                                iconIdentifier: payload.iconIdentifier ?? 'actions-close',
                                title: payload.title ?? httpErrorTitle,
                                message: payload.message ?? networkErrorLabel,
                                details: payload.details ?? '',
                                targetUrl: payload.targetUrl ?? '',
                            });
                            return;
                        }
                    } catch {
                        // Fall through to the generic error payload below.
                    }
                }

                renderProbeResult(resultContainer, {
                    statusClass: 'danger',
                    iconIdentifier: 'actions-close',
                    title: httpErrorTitle,
                    message: networkErrorLabel || unexpectedErrorLabel,
                });
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = originalButtonLabel;
            }
        });
    });
}

function buildUrlFromForm(form) {
    const action = form.getAttribute('action') || window.location.pathname;
    const parameters = new URLSearchParams(new FormData(form));
    const query = parameters.toString();

    return query !== '' ? `${action}?${query}` : action;
}

function updateCustomDateFields(root) {
    const presetSelect = root.querySelector(SELECTORS.auditDatePreset);
    const fromWrapper = root.querySelector(SELECTORS.auditCustomDateFrom);
    const toWrapper = root.querySelector(SELECTORS.auditCustomDateTo);

    if (!(presetSelect instanceof HTMLSelectElement) || !(fromWrapper instanceof HTMLElement) || !(toWrapper instanceof HTMLElement)) {
        return;
    }

    const isCustom = presetSelect.value === 'custom';
    fromWrapper.hidden = !isCustom;
    toWrapper.hidden = !isCustom;
}

function bindTagToggles(root) {
    root.querySelectorAll(SELECTORS.auditTagsToggle).forEach((toggleButton) => {
        if (!(toggleButton instanceof HTMLButtonElement) || toggleButton.dataset.boundTagToggle === '1') {
            return;
        }
        toggleButton.dataset.boundTagToggle = '1';

        toggleButton.addEventListener('click', () => {
            const targetId = toggleButton.dataset.auditTagsToggle;
            if (!targetId) {
                return;
            }

            const target = root.querySelector(`#${CSS.escape(targetId)}`);
            if (!(target instanceof HTMLElement)) {
                return;
            }

            target.hidden = !target.hidden;
        });
    });
}

async function loadAuditLogUrl(url, pushState = true) {
    const currentRoot = document.querySelector(SELECTORS.auditRoot);
    if (!(currentRoot instanceof HTMLElement)) {
        return;
    }

    const response = await fetch(url, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        window.location.href = url;
        return;
    }

    const html = await response.text();
    const parser = new DOMParser();
    const parsedDocument = parser.parseFromString(html, 'text/html');
    const nextRoot = parsedDocument.querySelector(SELECTORS.auditRoot);

    if (!(nextRoot instanceof HTMLElement)) {
        window.location.href = url;
        return;
    }

    currentRoot.replaceWith(nextRoot);

    if (pushState) {
        window.history.pushState({}, '', url);
    }

    initializeAuditLog(document);
}

function bindAuditForms(root) {
    root.querySelectorAll(SELECTORS.auditForm).forEach((form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.boundAuditForm === '1') {
            return;
        }
        form.dataset.boundAuditForm = '1';

        form.addEventListener('submit', async (event) => {
            if (form.method.toLowerCase() !== 'get') {
                return;
            }

            event.preventDefault();
            await loadAuditLogUrl(buildUrlFromForm(form));
        });
    });
}

function bindAuditLinks(root) {
    root.querySelectorAll(SELECTORS.auditLink).forEach((link) => {
        if (!(link instanceof HTMLAnchorElement) || link.dataset.boundAuditLink === '1') {
            return;
        }
        link.dataset.boundAuditLink = '1';

        link.addEventListener('click', async (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            const href = link.getAttribute('href');
            if (!href) {
                return;
            }

            event.preventDefault();
            await loadAuditLogUrl(href);
        });
    });
}

function initializeAuditLog(context = document) {
    const root = context.querySelector(SELECTORS.auditRoot);
    if (!(root instanceof HTMLElement)) {
        return;
    }

    bindAuditForms(root);
    bindAuditLinks(root);
    bindTagToggles(root);
    updateCustomDateFields(root);

    const presetSelect = root.querySelector(SELECTORS.auditDatePreset);
    if (presetSelect instanceof HTMLSelectElement && presetSelect.dataset.boundPresetToggle !== '1') {
        presetSelect.dataset.boundPresetToggle = '1';
        presetSelect.addEventListener('change', () => updateCustomDateFields(root));
    }
}

DocumentService.ready().then(() => {
    initializeProbeForms(document);
    initializeAuditLog(document);

    if (!window.__lscacheAuditLogPopStateBound) {
        window.__lscacheAuditLogPopStateBound = true;
        window.addEventListener('popstate', async () => {
            const root = document.querySelector(SELECTORS.auditRoot);
            if (!(root instanceof HTMLElement)) {
                return;
            }

            await loadAuditLogUrl(window.location.href, false);
        });
    }
});
