(function () {
    'use strict';

    // ── HTML Escaping ──────────────────────────────────────────────────
    var ESC_MAP = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    function htmlEscape(str) {
        if (str == null) return '';
        return String(str).replace(/[&<>"']/g, function (c) { return ESC_MAP[c]; });
    }

    // ── Deep Property Resolution ───────────────────────────────────────
    function resolve(path, ctx) {
        if (path == null || path === '') return '';
        var parts = String(path).split('.');
        var val = ctx;
        for (var i = 0; i < parts.length; i++) {
            if (val == null) return '';
            val = val[parts[i]];
        }
        return val == null ? '' : val;
    }

    // ── Tokenizer ──────────────────────────────────────────────────────
    var RE_TAG = /\{\{-?\s*(.*?)\s*-?\}\}|\{%-?\s*(.*?)\s*-?%\}/gs;

    function tokenize(src) {
        var tokens = [];
        var last = 0;
        var m;
        RE_TAG.lastIndex = 0;
        while ((m = RE_TAG.exec(src)) !== null) {
            if (m.index > last) {
                tokens.push({type: 'TEXT', value: src.slice(last, m.index)});
            }
            if (m[1] !== undefined) {
                // {{ expression }}
                var expr = m[1].trim();
                if (expr.endsWith('|raw')) {
                    tokens.push({type: 'OUTPUT_RAW', value: expr.slice(0, -4).trim()});
                } else {
                    tokens.push({type: 'OUTPUT', value: expr});
                }
            } else if (m[2] !== undefined) {
                var tag = m[2].trim();
                if (tag.startsWith('if ')) {
                    tokens.push({type: 'IF', value: tag.slice(3).trim()});
                } else if (tag.startsWith('elseif ')) {
                    tokens.push({type: 'ELSEIF', value: tag.slice(7).trim()});
                } else if (tag === 'else') {
                    tokens.push({type: 'ELSE', value: ''});
                } else if (tag === 'endif') {
                    tokens.push({type: 'ENDIF', value: ''});
                } else if (tag.startsWith('for ')) {
                    tokens.push({type: 'FOR', value: tag.slice(4).trim()});
                } else if (tag === 'endfor') {
                    tokens.push({type: 'ENDFOR', value: ''});
                } else if (tag.startsWith('set ')) {
                    tokens.push({type: 'SET', value: tag.slice(4).trim()});
                }
            }
            last = m.index + m[0].length;
        }
        if (last < src.length) {
            tokens.push({type: 'TEXT', value: src.slice(last)});
        }
        return tokens;
    }

    // ── Expression Parser (Recursive Descent) ──────────────────────────
    function parseExpression(expr) {
        var pos = 0;
        var len = expr.length;

        function skipWs() {
            while (pos < len && /\s/.test(expr[pos])) pos++;
        }

        function peek(s) {
            skipWs();
            return expr.substr(pos, s.length) === s;
        }

        function consume(s) {
            skipWs();
            if (expr.substr(pos, s.length) === s) {
                pos += s.length;
                return true;
            }
            return false;
        }

        function parseOr() {
            var left = parseAnd();
            while (true) {
                skipWs();
                if (pos + 2 <= len && expr.substr(pos, 2) === 'or' && (pos + 2 >= len || /\s/.test(expr[pos + 2]))) {
                    pos += 2;
                    var right = parseAnd();
                    left = {type: 'binary', op: 'or', left: left, right: right};
                } else break;
            }
            return left;
        }

        function parseAnd() {
            var left = parseNot();
            while (true) {
                skipWs();
                if (pos + 3 <= len && expr.substr(pos, 3) === 'and' && (pos + 3 >= len || /\s/.test(expr[pos + 3]))) {
                    pos += 3;
                    var right = parseNot();
                    left = {type: 'binary', op: 'and', left: left, right: right};
                } else break;
            }
            return left;
        }

        function parseNot() {
            skipWs();
            if (pos + 3 <= len && expr.substr(pos, 3) === 'not' && (pos + 3 >= len || /\s/.test(expr[pos + 3]))) {
                pos += 3;
                var operand = parseNot();
                return {type: 'unary', op: 'not', operand: operand};
            }
            return parseComparison();
        }

        function parseComparison() {
            var left = parsePrimary();
            skipWs();

            // "in" operator
            if (pos + 2 <= len && expr.substr(pos, 2) === 'in' && (pos + 2 >= len || /\s/.test(expr[pos + 2]))) {
                pos += 2;
                var right = parsePrimary();
                return {type: 'binary', op: 'in', left: left, right: right};
            }

            // "not in" operator
            if (pos + 6 <= len && expr.substr(pos, 6) === 'not in' && (pos + 6 >= len || /\s/.test(expr[pos + 6]))) {
                pos += 6;
                var rightNi = parsePrimary();
                return {type: 'unary', op: 'not', operand: {type: 'binary', op: 'in', left: left, right: rightNi}};
            }

            var ops = ['!=', '==', '>=', '<=', '>', '<'];
            for (var i = 0; i < ops.length; i++) {
                if (peek(ops[i])) {
                    consume(ops[i]);
                    var rightCmp = parsePrimary();
                    return {type: 'binary', op: ops[i], left: left, right: rightCmp};
                }
            }
            return left;
        }

        function parsePrimary() {
            skipWs();
            // Parenthesized expression
            if (peek('(')) {
                consume('(');
                var inner = parseOr();
                consume(')');
                return inner;
            }

            // String literal (single or double quotes)
            if (pos < len && (expr[pos] === "'" || expr[pos] === '"')) {
                var quote = expr[pos];
                pos++;
                var start = pos;
                while (pos < len && expr[pos] !== quote) {
                    if (expr[pos] === '\\') pos++;
                    pos++;
                }
                var strVal = expr.slice(start, pos);
                if (pos < len) pos++; // closing quote
                return {type: 'literal', value: strVal};
            }

            // Number
            var numMatch = expr.substr(pos).match(/^-?\d+(\.\d+)?/);
            if (numMatch) {
                pos += numMatch[0].length;
                return {type: 'literal', value: parseFloat(numMatch[0])};
            }

            // Boolean / null
            if (expr.substr(pos, 4) === 'true' && (pos + 4 >= len || /[\s)!=<>,]/.test(expr[pos + 4]))) {
                pos += 4; return {type: 'literal', value: true};
            }
            if (expr.substr(pos, 5) === 'false' && (pos + 5 >= len || /[\s)!=<>,]/.test(expr[pos + 5]))) {
                pos += 5; return {type: 'literal', value: false};
            }
            if (expr.substr(pos, 4) === 'null' && (pos + 4 >= len || /[\s)!=<>,]/.test(expr[pos + 4]))) {
                pos += 4; return {type: 'literal', value: null};
            }
            if (expr.substr(pos, 4) === 'none' && (pos + 4 >= len || /[\s)!=<>,]/.test(expr[pos + 4]))) {
                pos += 4; return {type: 'literal', value: null};
            }

            // Variable path
            var varMatch = expr.substr(pos).match(/^[a-zA-Z_][a-zA-Z0-9_.]*/);
            if (varMatch) {
                pos += varMatch[0].length;
                return {type: 'var', path: varMatch[0]};
            }

            // Array literal [a, b, c]
            if (peek('[')) {
                consume('[');
                var items = [];
                while (!peek(']') && pos < len) {
                    items.push(parseOr());
                    consume(',');
                }
                consume(']');
                return {type: 'array', items: items};
            }

            return {type: 'literal', value: null};
        }

        var result = parseOr();
        return result;
    }

    function evaluateExpr(node, ctx) {
        if (!node) return null;
        switch (node.type) {
            case 'literal': return node.value;
            case 'var': return resolve(node.path, ctx);
            case 'array':
                return node.items.map(function (item) { return evaluateExpr(item, ctx); });
            case 'unary':
                if (node.op === 'not') return !evaluateExpr(node.operand, ctx);
                return null;
            case 'binary': {
                var l = evaluateExpr(node.left, ctx);
                var r = evaluateExpr(node.right, ctx);
                switch (node.op) {
                    case 'and': return l && r;
                    case 'or':  return l || r;
                    case '==':  return l == r;
                    case '!=':  return l != r;
                    case '>':   return l > r;
                    case '<':   return l < r;
                    case '>=':  return l >= r;
                    case '<=':  return l <= r;
                    case 'in':
                        if (Array.isArray(r)) return r.indexOf(l) !== -1;
                        if (typeof r === 'string') return r.indexOf(String(l)) !== -1;
                        return false;
                }
                return null;
            }
        }
        return null;
    }

    // ── AST Parser ─────────────────────────────────────────────────────
    function parse(tokens) {
        var pos = 0;

        function parseBody(until) {
            var nodes = [];
            while (pos < tokens.length) {
                var t = tokens[pos];
                if (until && until.indexOf(t.type) !== -1) break;

                pos++;
                switch (t.type) {
                    case 'TEXT':
                        nodes.push({type: 'text', value: t.value});
                        break;
                    case 'OUTPUT':
                        nodes.push({type: 'output', expr: t.value, raw: false});
                        break;
                    case 'OUTPUT_RAW':
                        nodes.push({type: 'output', expr: t.value, raw: true});
                        break;
                    case 'IF':
                        nodes.push(parseIf(t.value));
                        break;
                    case 'FOR':
                        nodes.push(parseFor(t.value));
                        break;
                    case 'SET':
                        nodes.push(parseSet(t.value));
                        break;
                }
            }
            return nodes;
        }

        function parseIf(condExpr) {
            var node = {type: 'if', branches: [], elseBranch: null};
            node.branches.push({
                condition: parseExpression(condExpr),
                body: parseBody(['ELSEIF', 'ELSE', 'ENDIF'])
            });

            while (pos < tokens.length) {
                var t = tokens[pos];
                if (t.type === 'ELSEIF') {
                    pos++;
                    node.branches.push({
                        condition: parseExpression(t.value),
                        body: parseBody(['ELSEIF', 'ELSE', 'ENDIF'])
                    });
                } else if (t.type === 'ELSE') {
                    pos++;
                    node.elseBranch = parseBody(['ENDIF']);
                    if (pos < tokens.length && tokens[pos].type === 'ENDIF') pos++;
                    break;
                } else if (t.type === 'ENDIF') {
                    pos++;
                    break;
                } else break;
            }
            return node;
        }

        function parseFor(expr) {
            // "item in items" or "key, value in items"
            var m = expr.match(/^(\w+)(?:\s*,\s*(\w+))?\s+in\s+(.+)$/);
            var node = {type: 'for', varName: '', keyName: null, iterExpr: '', body: []};
            if (m) {
                if (m[2]) {
                    node.keyName = m[1];
                    node.varName = m[2];
                    node.iterExpr = m[3].trim();
                } else {
                    node.varName = m[1];
                    node.iterExpr = m[3].trim();
                }
            }
            node.body = parseBody(['ENDFOR']);
            if (pos < tokens.length && tokens[pos].type === 'ENDFOR') pos++;
            return node;
        }

        function parseSet(expr) {
            var eq = expr.indexOf('=');
            if (eq === -1) return {type: 'set', name: expr.trim(), expr: ''};
            return {type: 'set', name: expr.slice(0, eq).trim(), expr: expr.slice(eq + 1).trim()};
        }

        return parseBody(null);
    }

    // ── Renderer ───────────────────────────────────────────────────────
    function render(ast, data) {
        var out = '';
        var ctx = Object.create(null);
        for (var k in data) {
            if (data.hasOwnProperty(k)) ctx[k] = data[k];
        }

        function exec(nodes, localCtx) {
            for (var i = 0; i < nodes.length; i++) {
                var node = nodes[i];
                switch (node.type) {
                    case 'text':
                        out += node.value;
                        break;
                    case 'output': {
                        var val = resolve(node.expr, localCtx);
                        out += node.raw ? String(val == null ? '' : val) : htmlEscape(val);
                        break;
                    }
                    case 'if': {
                        var matched = false;
                        for (var b = 0; b < node.branches.length; b++) {
                            if (evaluateExpr(node.branches[b].condition, localCtx)) {
                                exec(node.branches[b].body, localCtx);
                                matched = true;
                                break;
                            }
                        }
                        if (!matched && node.elseBranch) {
                            exec(node.elseBranch, localCtx);
                        }
                        break;
                    }
                    case 'for': {
                        var items = resolve(node.iterExpr, localCtx);
                        if (!items) break;
                        var isArray = Array.isArray(items);
                        var keys = isArray ? items.map(function (_, idx) { return idx; }) : Object.keys(items);
                        var loopLen = keys.length;
                        for (var fi = 0; fi < loopLen; fi++) {
                            var childCtx = Object.create(localCtx);
                            var key = keys[fi];
                            var value = items[key];
                            childCtx[node.varName] = value;
                            if (node.keyName) childCtx[node.keyName] = key;
                            childCtx.loop = {
                                index: fi + 1,
                                index0: fi,
                                first: fi === 0,
                                last: fi === loopLen - 1,
                                length: loopLen
                            };
                            exec(node.body, childCtx);
                        }
                        break;
                    }
                    case 'set': {
                        if (node.expr !== '') {
                            var setExprNode = parseExpression(node.expr);
                            localCtx[node.name] = evaluateExpr(setExprNode, localCtx);
                        }
                        break;
                    }
                }
            }
        }

        exec(ast, ctx);
        return out;
    }

    // ── SSR Runtime ────────────────────────────────────────────────────
    var SemitexaSSR = {
        _templates: new Map(),
        _connected: false,
        _eventSource: null,
        _manifest: null,

        parse: function (templateString) {
            return parse(tokenize(templateString));
        },

        render: function (ast, data) {
            return render(ast, data);
        },

        _connect: function (manifest) {
            if (!manifest || !manifest.requestId) return;
            var self = this;
            self._manifest = manifest;
            self._setBindCookie(manifest);
            var sseUrl = '/__semitexa_kiss?session_id=' + encodeURIComponent(manifest.sessionId)
                + '&deferred_request_id=' + encodeURIComponent(manifest.requestId);

            if (typeof EventSource === 'undefined') {
                self._fallback(manifest);
                return;
            }

            // Track which slots still need to be rendered so we can fall back if the
            // connection closes before all blocks arrive (e.g. premature 'done').
            var pendingSlots = new Set();
            if (Array.isArray(manifest.slots)) {
                manifest.slots.forEach(function (s) { pendingSlots.add(s.id); });
            }

            var es = new EventSource(sseUrl);
            self._eventSource = es;
            self._connected = true;

            es.onmessage = function (event) {
                try {
                    var payload = JSON.parse(event.data);
                    if (payload.type === 'done') {
                        // Fallback for any blocks that never arrived
                        if (pendingSlots.size > 0) {
                            var missed = [];
                            pendingSlots.forEach(function (id) { missed.push(id); });
                            var fallbackManifest = {
                                requestId: manifest.requestId,
                                sessionId: manifest.sessionId,
                                slots: missed.map(function (id) { return {id: id}; })
                            };
                            self._fallback(fallbackManifest);
                        }
                        // Keep connection open if server will push live updates
                        if (!payload.live) {
                            es.close();
                            self._connected = false;
                        }
                        return;
                    }
                    if (payload.connected) return;
                    if (payload.type === 'deferred_block') {
                        pendingSlots.delete(payload.slot_id);
                        self._handleMessage(payload);
                    }
                } catch (e) {
                    // ignore parse errors
                }
            };

            es.onerror = function () {
                es.close();
                self._connected = false;
                self._fallback(manifest);
            };
        },

        _setBindCookie: function (manifest) {
            if (!manifest || !manifest.bindToken) return;
            document.cookie = 'semitexa_ssr_bind=' + encodeURIComponent(manifest.bindToken) + '; Path=/; SameSite=Lax';
        },

        _handleMessage: function (payload) {
            var start = performance.now();
            var el = document.querySelector('[data-ssr-deferred="' + payload.slot_id + '"]');
            if (!el) return;

            var self = this;

            if (payload.mode === 'html') {
                el.innerHTML = payload.html;
                self._fireEvent('semitexa:block:rendered', {
                    slotId: payload.slot_id,
                    mode: 'html',
                    renderTimeMs: Math.round(performance.now() - start)
                });
            } else if (payload.mode === 'template') {
                self._fetchTemplate(payload.template).then(function (ast) {
                    el.innerHTML = render(ast, payload.data);
                    self._fireEvent('semitexa:block:rendered', {
                        slotId: payload.slot_id,
                        mode: 'template',
                        renderTimeMs: Math.round(performance.now() - start)
                    });
                }).catch(function (err) {
                    self._fireEvent('semitexa:block:error', {
                        slotId: payload.slot_id,
                        error: err.message || String(err)
                    });
                    // Fallback: fetch rendered HTML for this slot
                    self._fallbackSlot(payload.slot_id);
                });
            }
        },

        _fetchTemplate: function (url) {
            var self = this;
            if (self._templates.has(url)) {
                return Promise.resolve(self._templates.get(url));
            }
            return fetch(url).then(function (resp) {
                if (!resp.ok) throw new Error('Template fetch failed: ' + resp.status);
                return resp.text();
            }).then(function (text) {
                var ast = parse(tokenize(text));
                self._templates.set(url, ast);
                return ast;
            });
        },

        _fallback: function (manifest) {
            if (!manifest || !manifest.requestId) return;
            var self = this;
            var slots = Array.isArray(manifest.slots) ? manifest.slots : [];
            var slotIds = slots.map(function (s) { return s.id; });

            // We need the page handle, extract from manifest or use a data attribute
            var handleEl = document.querySelector('[data-ssr-handle]');
            var pageHandle = handleEl ? handleEl.getAttribute('data-ssr-handle') : '';

            var fallbackUrl = '/__semitexa_hug?handle=' + encodeURIComponent(pageHandle)
                + '&slots=' + encodeURIComponent(slotIds.join(','))
                + '&deferred_request_id=' + encodeURIComponent(manifest.requestId);

            fetch(fallbackUrl).then(function (resp) {
                if (!resp.ok) return;
                return resp.json();
            }).then(function (data) {
                if (!data) return;
                for (var slotId in data) {
                    if (data.hasOwnProperty(slotId)) {
                        var el = document.querySelector('[data-ssr-deferred="' + slotId + '"]');
                        if (el) {
                            el.innerHTML = data[slotId];
                            self._fireEvent('semitexa:block:rendered', {
                                slotId: slotId,
                                mode: 'fallback',
                                renderTimeMs: 0
                            });
                        }
                    }
                }
            }).catch(function () {
                // Silent failure — deferred blocks remain as skeletons
            });
        },

        _fallbackSlot: function (slotId) {
            var el = document.querySelector('[data-ssr-deferred="' + slotId + '"]');
            if (!el) return;
            var handleEl = document.querySelector('[data-ssr-handle]');
            var pageHandle = handleEl ? handleEl.getAttribute('data-ssr-handle') : '';
            var requestId = this._manifest && this._manifest.requestId ? this._manifest.requestId : '';

            fetch('/__semitexa_hug?handle=' + encodeURIComponent(pageHandle)
                + '&slots=' + encodeURIComponent(slotId)
                + (requestId ? '&deferred_request_id=' + encodeURIComponent(requestId) : ''))
                .then(function (resp) { return resp.ok ? resp.json() : null; })
                .then(function (data) {
                    if (data && data[slotId]) el.innerHTML = data[slotId];
                })
                .catch(function () {});
        },

        _fireEvent: function (name, detail) {
            try {
                document.dispatchEvent(new CustomEvent(name, {detail: detail}));
            } catch (e) {
                // IE fallback — not critical
            }
        },

        setLocale: function (locale) {
            if (!locale) return;
            var manifest = this._manifest || window.__SSR_DEFERRED;
            if (!manifest || !manifest.requestId || !manifest.sessionId) return;

            if (!this._connected) {
                this._connect(manifest);
            }

            var url = '/__semitexa_locale?session_id=' + encodeURIComponent(manifest.sessionId)
                + '&deferred_request_id=' + encodeURIComponent(manifest.requestId)
                + '&locale=' + encodeURIComponent(locale);

            fetch(url, {method: 'GET', credentials: 'same-origin'})
                .then(function (resp) {
                    if (resp && resp.ok && document.documentElement) {
                        document.documentElement.setAttribute('lang', locale);
                    }
                })
                .catch(function () {});
        }
    };

    window.SemitexaSSR = SemitexaSSR;

    // Auto-initialize when manifest is available
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            if (window.__SSR_DEFERRED) {
                SemitexaSSR._connect(window.__SSR_DEFERRED);
            }
        });
    } else {
        if (window.__SSR_DEFERRED) {
            SemitexaSSR._connect(window.__SSR_DEFERRED);
        }
    }
})();
