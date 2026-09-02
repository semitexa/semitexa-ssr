<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Isomorphic;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Proves the two renderers agree.
 *
 * A deferred slot is rendered TWICE: by PHP Twig when it ships in the initial HTML, and by
 * `semitexa-twig.js` when it arrives later over SSE. Nothing used to compare them. The one
 * test named for the client runtime asserted that certain STRINGS EXIST in the JS file,
 * which would pass unchanged if the renderer emitted garbage.
 *
 * MEASURED before this existed: every template diverged. PHP strips the single newline
 * after a block tag and the client did not, so a filter-free template gave
 * `"<li>a</li>\n<li>b</li>\n"` on the server against `"\n\n<li>a</li>\n\n<li>b</li>\n\n"`
 * on the client. Harmless in most markup and immediately visible inside `<pre>`.
 *
 * ⚠️ SCOPE. This asserts parity only for the subset the client engine implements: the tags
 * if/elseif/else/endif, for/endfor, set, and print expressions with an optional `|raw`.
 * Everything else - filters, functions, ternaries, `~` - renders as an EMPTY STRING on the
 * client with no error, and guarding against that is `lint:deferred-twig`'s job, not this
 * test's. The two are complements: the lint keeps templates inside the subset, this proves
 * the subset renders identically.
 *
 * It executes the SHIPPED semitexa-twig.js through node rather than a re-implementation,
 * because a parity test against a copy proves nothing about what the browser runs.
 */
final class RenderParityTest extends TestCase
{
    private const BRIDGE = __DIR__ . '/render-with-client-twig.js';

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function templates(): array
    {
        return [
            // The whitespace rules, measured against Twig itself. These are the cases that
            // were all failing before the client engine learned the newline rule.
            'block tag swallows one newline'    => ["A\n{% if x %}\nB\n{% endif %}\nC", ['x' => true]],
            'no newline, nothing swallowed'     => ['A{% if x %}B{% endif %}C', ['x' => true]],
            'only the first newline goes'       => ["A\n{% if x %}\n\nB\n{% endif %}\nC", ['x' => true]],
            'spaces before newline cancel it'   => ["A\n{% if x %}   \nB\n{% endif %}\nC", ['x' => true]],
            'print tags keep their newline'     => ["A\n{{ v }}\nB", ['v' => 'V']],

            'for loop'                          => ["{% for i in list %}\n[{{ i }}]\n{% endfor %}", ['list' => [1, 2, 3]]],
            'empty loop'                        => ["x{% for i in list %}[{{ i }}]{% endfor %}y", ['list' => []]],
            'else branch'                       => ['{% if x %}yes{% else %}no{% endif %}', ['x' => false]],
            'elseif chain'                      => ['{% if a %}A{% elseif b %}B{% else %}C{% endif %}', ['a' => false, 'b' => true]],
            'nested loop in condition'          => ["{% if show %}{% for i in list %}<li>{{ i.name }}</li>{% endfor %}{% endif %}", ['show' => true, 'list' => [['name' => 'a'], ['name' => 'b']]]],
            'dotted path'                       => ['{{ user.profile.name }}', ['user' => ['profile' => ['name' => 'Ada']]]],

            // Escaping is the one place a mismatch would be a security problem rather than
            // a cosmetic one: the server must not escape something the client leaves raw.
            'html is escaped by default'        => ['{{ v }}', ['v' => '<b>&"x</b>']],
            'raw filter is honoured'            => ['{{ v|raw }}', ['v' => '<b>bold</b>']],

            'missing key renders empty'         => ['[{{ nope }}]', []],
            // PHP prints true as "1" and false as the EMPTY STRING. JavaScript's String()
            // gives "true" and "false", so {{ isActive }} once showed nothing on the server
            // and the literal word "false" on the client. Found by this test on its first run.
            'numeric and boolean output'        => ['{{ n }}|{{ t }}', ['n' => 42, 't' => true]],
            'false prints nothing, not "false"' => ['[{{ f }}]', ['f' => false]],
            'zero still prints'                 => ['[{{ z }}]', ['z' => 0]],
            'float output'                      => ['{{ pi }}', ['pi' => 1.5]],

            // Twig normalises line endings before lexing - measured, CRLF and CR inputs
            // render identically to LF. The client used to preserve them, so a template
            // saved on Windows rendered differently in the browser than on the server.
            'CRLF source'                       => ["A\r\n{% if x %}\r\nB\r\n{% endif %}\r\nC", ['x' => true]],
            'CR source'                         => ["A\r{% if x %}\rB\r{% endif %}\rC", ['x' => true]],
        ];
    }

    #[Test]
    #[DataProvider('templates')]
    public function server_and_client_render_identically(string $template, array $data): void
    {
        $server = (new Environment(new ArrayLoader(['t' => $template])))->render('t', $data);
        $client = self::renderWithClientEngine($template, $data);

        self::assertSame(
            $server,
            $client,
            "Server and client renderers disagree.\n"
            . 'template: ' . json_encode($template, \JSON_UNESCAPED_SLASHES) . "\n"
            . 'server:   ' . json_encode($server, \JSON_UNESCAPED_SLASHES) . "\n"
            . 'client:   ' . json_encode($client, \JSON_UNESCAPED_SLASHES),
        );
    }

    /** @param array<string, mixed> $data */
    private static function renderWithClientEngine(string $template, array $data): string
    {
        if (self::node() === null) {
            self::markTestSkipped('node is required to execute the client renderer.');
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([self::node(), self::BRIDGE], $descriptors, $pipes);
        self::assertIsResource($process, 'could not start the client renderer');

        fwrite($pipes[0], (string) json_encode(['template' => $template, 'data' => $data]));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded, "client renderer returned no usable output.\nstdout: {$stdout}\nstderr: {$stderr}");
        self::assertTrue($decoded['ok'] ?? false, 'client renderer failed: ' . ($decoded['error'] ?? 'unknown'));

        return (string) $decoded['out'];
    }

    private static function node(): ?string
    {
        static $path = false;
        if ($path === false) {
            $found = trim((string) @shell_exec('command -v node 2>/dev/null'));
            $path = $found !== '' ? $found : null;
        }

        return $path;
    }
}
