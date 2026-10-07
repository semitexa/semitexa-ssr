/*
 * SemitexaComponent — client mounts for `#[AsComponent(script: …)]`.
 *
 *   SemitexaComponent.register(name, mount)
 *     mount(node, { componentId, componentName, script, signal }) is called for
 *     every instance root `[data-ui-component="<name>"]` now and later; whatever it
 *     returns may carry destroy(), called when the node leaves the page.
 *
 * The global exists as soon as this script runs, so a component script that
 * loads after it can register straight away (it used to appear only at
 * DOMContentLoaded, and early callers were skipped silently).
 *
 * When Platform UI's client core is on the page, mounts go through its one
 * element lifecycle (core.mount: one MutationObserver, teardown on removal).
 * Without it — ssr does not depend on platform-ui — a plain scan on load and
 * on deferred blocks does the connecting, with no teardown.
 */
(function () {
    'use strict';

    if (window.SemitexaComponent) {
        return;
    }

    var registry = new Map();
    var mountedRoots = new WeakMap();
    var ready = false;

    function info(node, name, signal) {
        return {
            componentId: node.getAttribute('data-ui-component-instance-id') || '',
            componentName: name,
            script: node.getAttribute('data-ui-component-script') || '',
            signal: signal
        };
    }

    function core() {
        var ui = window.SemitexaUi;
        return ui && ui.core && typeof ui.core.mount === 'function' ? ui.core : null;
    }

    function bind(name, mount) {
        var c = core();
        if (c) {
            // The instance root, not its event-manifest <script> (which names the
            // component too).
            c.mount('[data-ui-component="' + name.replace(/"/g, '\\"') + '"][data-ui-component-instance-id]', {
                connect: function (node, ctx) {
                    return mount(node, info(node, name, ctx.signal));
                }
            });
            return;
        }
        scan(document);
    }

    function register(name, mount) {
        if (typeof name !== 'string' || name.trim() === '' || typeof mount !== 'function' || registry.has(name)) {
            return;
        }
        registry.set(name, mount);
        // Module scripts (the client core) run before DOMContentLoaded, so the
        // choice between core.mount and the fallback is made once ready.
        if (ready) {
            bind(name, mount);
        }
    }

    function scan(root) {
        if (core()) {
            return; // core.mount owns the lifecycle
        }
        var scope = root instanceof Element || root instanceof Document ? root : document;
        var candidates = [];
        if (scope instanceof Element && scope.matches('[data-ui-component][data-ui-component-instance-id]')) {
            candidates.push(scope);
        }
        scope.querySelectorAll('[data-ui-component][data-ui-component-instance-id]').forEach(function (node) {
            candidates.push(node);
        });
        candidates.forEach(function (node) {
            var name = node.getAttribute('data-ui-component');
            if (!name || !registry.has(name)) {
                return;
            }
            var mounted = mountedRoots.get(node) || new Set();
            mountedRoots.set(node, mounted);
            if (mounted.has(name)) {
                return;
            }
            mounted.add(name);
            registry.get(name)(node, info(node, name, undefined));
        });
    }

    window.SemitexaComponent = { register: register, scan: scan };

    function init() {
        ready = true;
        registry.forEach(function (mount, name) { bind(name, mount); });
        if (!core()) {
            document.addEventListener('semitexa:block:rendered', function (event) {
                scan(event && event.detail && event.detail.block instanceof Element ? event.detail.block : document);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
