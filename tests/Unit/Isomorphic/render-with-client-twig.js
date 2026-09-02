// Renders a template with the REAL client-side engine, for the server/client parity test.
//
// Loaded by RenderParityTest, which passes {template, data} as JSON on stdin and reads the
// rendered string back as JSON on stdout. It deliberately requires the shipped
// semitexa-twig.js rather than a copy: a parity test against a duplicate of the renderer
// would prove nothing about what the browser actually runs.
//
// The file is an IIFE that expects a browser, so window and document are stubbed to the
// minimum it touches at load time.
global.window = {};
global.document = { readyState: 'complete', addEventListener: function () {} };

require(__dirname + '/../../../src/Application/Static/js/semitexa-twig.js');

let raw = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => { raw += chunk; });
process.stdin.on('end', () => {
    try {
        const input = JSON.parse(raw);
        const engine = global.window.SemitexaSSR;
        const out = engine.render(engine.parse(input.template), input.data);
        process.stdout.write(JSON.stringify({ ok: true, out: out }));
    } catch (e) {
        process.stdout.write(JSON.stringify({ ok: false, error: e.constructor.name + ': ' + e.message }));
    }
});
