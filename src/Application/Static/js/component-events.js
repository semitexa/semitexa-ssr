(function () {
    'use strict';

    var boundCustomTriggers = new Set();

    function init() {
        bindNativeTriggers();
        bindDeclaredCustomTriggers();
    }

    function bindNativeTriggers() {
        document.addEventListener('click', function (event) {
            dispatchFromEvent(event, 'click');
        });

        document.addEventListener('change', function (event) {
            dispatchFromEvent(event, 'change');
        });

        document.addEventListener('input', function (event) {
            dispatchFromEvent(event, 'input');
        });

        document.addEventListener('submit', function (event) {
            dispatchFromEvent(event, 'submit');
        });

        document.addEventListener('mouseover', function (event) {
            var target = findTriggerTarget(event, 'hover');
            if (!target) return;

            var related = event.relatedTarget;
            if (related instanceof Node && target.contains(related)) {
                return;
            }

            dispatch(target, 'hover', event);
        });
    }

    function bindDeclaredCustomTriggers() {
        document.querySelectorAll('[data-semitexa-component-event]').forEach(function (node) {
            var trigger = String(node.getAttribute('data-semitexa-component-event') || '').trim();
            if (trigger.indexOf(':') === -1 || boundCustomTriggers.has(trigger)) {
                return;
            }

            boundCustomTriggers.add(trigger);
            document.addEventListener(trigger, function (event) {
                dispatchFromEvent(event, trigger);
            });
        });
    }

    function dispatchFromEvent(event, trigger) {
        var target = findTriggerTarget(event, trigger);
        if (!target) {
            return;
        }

        dispatch(target, trigger, event);
    }

    function findTriggerTarget(event, trigger) {
        if (!(event.target instanceof Element)) {
            return null;
        }

        var candidate = event.target.closest('[data-semitexa-component-event]');
        if (!candidate) {
            return null;
        }

        if ((candidate.getAttribute('data-semitexa-component-event') || '').trim() !== trigger) {
            return null;
        }

        return candidate;
    }

    function dispatch(target, trigger, event) {
        var componentRoot = target.closest('[data-semitexa-component-id]');
        if (!componentRoot) {
            return;
        }

        var allowedTriggers = parseJson(componentRoot.getAttribute('data-semitexa-component-triggers'), []);
        if (allowedTriggers.indexOf(trigger) === -1) {
            emitFailure(componentRoot, {
                status: 'rejected',
                reason: 'Trigger is not declared by this component.',
                frontend_event: trigger
            });
            return;
        }

        var payload = {
            component_id: componentRoot.getAttribute('data-semitexa-component-id') || '',
            component_name: componentRoot.getAttribute('data-semitexa-component') || '',
            event_class: componentRoot.getAttribute('data-semitexa-component-event-class') || '',
            frontend_event: trigger,
            signature: componentRoot.getAttribute('data-semitexa-component-signature') || '',
            page_path: componentRoot.getAttribute('data-semitexa-component-page') || window.location.pathname,
            session_binding: componentRoot.getAttribute('data-semitexa-component-session-binding') || '',
            issued_at: parseInt(componentRoot.getAttribute('data-semitexa-component-issued-at') || '0', 10) || 0,
            declared_payload: parseJson(target.getAttribute('data-semitexa-component-payload'), {}),
            interaction: buildInteraction(target, event, trigger)
        };

        if (trigger === 'submit' && event && typeof event.preventDefault === 'function') {
            event.preventDefault();
        }

        fetch(componentRoot.getAttribute('data-semitexa-component-event-endpoint') || '/__semitexa_component_event', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        })
        .then(function (response) {
            return response.json().catch(function () {
                return {
                    status: 'error',
                    reason: 'Component event endpoint returned invalid JSON.'
                };
            }).then(function (data) {
                data.http_status = response.status;
                if (!response.ok) {
                    throw data;
                }
                return data;
            });
        })
        .then(function (data) {
            emitAccepted(componentRoot, data);
        })
        .catch(function (error) {
            emitFailure(componentRoot, error && typeof error === 'object' ? error : {
                status: 'error',
                reason: String(error || 'Unknown component bridge error.')
            });
        });
    }

    function buildInteraction(target, event, trigger) {
        var interaction = {
            elementTag: target.tagName ? target.tagName.toLowerCase() : '',
            value: target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement
                ? target.value
                : null,
            checked: target instanceof HTMLInputElement ? target.checked : null
        };

        if (trigger === 'submit') {
            var form = target instanceof HTMLFormElement ? target : target.closest('form');
            interaction.form = form ? serializeForm(form) : null;
        }

        if (event && event.detail !== undefined) {
            interaction.detail = event.detail;
        }

        return interaction;
    }

    function serializeForm(form) {
        var data = {};
        new FormData(form).forEach(function (value, key) {
            if (Object.prototype.hasOwnProperty.call(data, key)) {
                if (!Array.isArray(data[key])) {
                    data[key] = [data[key]];
                }
                data[key].push(value);
                return;
            }

            data[key] = value;
        });

        return data;
    }

    function emitAccepted(componentRoot, detail) {
        componentRoot.dispatchEvent(new CustomEvent('semitexa:component-event:accepted', {
            bubbles: true,
            detail: detail
        }));
    }

    function emitFailure(componentRoot, detail) {
        componentRoot.dispatchEvent(new CustomEvent('semitexa:component-event:failed', {
            bubbles: true,
            detail: detail
        }));
    }

    function parseJson(raw, fallback) {
        if (!raw) {
            return fallback;
        }

        try {
            return JSON.parse(raw);
        } catch (error) {
            return fallback;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
