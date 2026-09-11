<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Asset\StaticAssetHandler;

/**
 * Text assets go out compressed, and everything else goes out untouched.
 *
 * MEASURED before this existed: platform-ui shipped 315277 bytes of CSS and JS
 * and every byte went over the wire uncompressed — the same set gzips to 82720,
 * 74% of the transfer, for no change to a single character of delivered code.
 *
 * Nothing in the stack was doing it. Swoole's `http_compression` setting is
 * ACCEPTED and inert on this build (6.2.0, compiled without compression —
 * `php --ri swoole` lists openssl, dtls, http2, json and no zlib), and it would
 * not have covered this path anyway: Swoole compresses what a response `end()`s
 * and never what it `sendfile()`s. A deployment behind nginx had this all along,
 * which is why it went unnoticed; the dev server every developer uses, and any
 * single-container install, did not.
 */
final class AssetCompressionTest extends TestCase
{
    /** @var list<string> */
    private array $temporary = [];

    /**
     * A directory of this test's own.
     *
     * The production default is <project>/var/cache/assets, and a unit test
     * must not need the project tree writable to say whether compression
     * works. It also proved the point while being written: that directory was
     * root-owned in this workspace while the test runner is uid 1000, so the
     * first version of these cases failed on a permission problem rather than
     * on the subject — which is exactly the silent degradation the warning in
     * gzippedTwin() now reports.
     */
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/asset-twins-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        foreach (glob($this->cacheDir . '/*') ?: [] as $twin) {
            @unlink($twin);
        }
        @rmdir($this->cacheDir);
    }

    private function fixture(string $extension, int $bytes): string
    {
        $path = sys_get_temp_dir() . '/asset-compression-' . bin2hex(random_bytes(6)) . '.' . $extension;
        // Repetitive on purpose: real CSS and JS compress well, and a random
        // blob would not, which would test the opposite of the subject.
        file_put_contents($path, str_repeat("/* a comment that repeats */\n.rule { color: red; }\n", (int) ceil($bytes / 50)));
        $this->temporary[] = $path;

        return $path;
    }

    #[Test]
    public function a_text_asset_is_compressed_and_decodes_back_to_itself(): void
    {
        $source = $this->fixture('css', 4096);

        $twin = StaticAssetHandler::gzippedTwin($source, 'css', 'gzip, deflate, br', $this->cacheDir);

        self::assertIsString($twin, 'a compressible asset must get a twin');
        self::assertFileExists($twin);
        self::assertLessThan(filesize($source), filesize($twin), 'the twin must actually be smaller');
        self::assertSame(
            file_get_contents($source),
            gzdecode((string) file_get_contents($twin)),
            'and must decode back to the source byte for byte',
        );
    }

    /** Built once and kept: the cost is per deployment, not per request. */
    #[Test]
    public function the_twin_is_reused_rather_than_rebuilt(): void
    {
        $source = $this->fixture('js', 4096);

        $first = StaticAssetHandler::gzippedTwin($source, 'js', 'gzip', $this->cacheDir);
        self::assertIsString($first);

        $mtime = filemtime($first);
        $second = StaticAssetHandler::gzippedTwin($source, 'js', 'gzip', $this->cacheDir);

        self::assertSame($first, $second, 'the same source must resolve to the same twin');
        self::assertSame($mtime, filemtime($first), 'and it must not be written again');
    }

    /**
     * An image, a font and an audio file are already compressed; gzipping them
     * spends CPU to add bytes.
     */
    #[Test]
    public function a_binary_asset_is_left_alone(): void
    {
        $source = $this->fixture('png', 4096);

        self::assertNull(StaticAssetHandler::gzippedTwin($source, 'png', 'gzip', $this->cacheDir));
        self::assertNull(StaticAssetHandler::gzippedTwin($source, 'woff2', 'gzip', $this->cacheDir));
        self::assertNull(StaticAssetHandler::gzippedTwin($source, 'mp4', 'gzip', $this->cacheDir));
    }

    /** A client that did not ask for gzip must not be handed gzip. */
    #[Test]
    public function nothing_is_compressed_for_a_client_that_did_not_ask(): void
    {
        $source = $this->fixture('css', 4096);

        self::assertNull(StaticAssetHandler::gzippedTwin($source, 'css', 'identity', $this->cacheDir));
        self::assertNull(StaticAssetHandler::gzippedTwin($source, 'css', null, $this->cacheDir));
        self::assertNull(StaticAssetHandler::gzippedTwin($source, 'css', 'br', $this->cacheDir));
    }

    /** Below the floor the gzip header costs more than the saving. */
    #[Test]
    public function a_tiny_asset_is_not_worth_compressing(): void
    {
        self::assertNull(StaticAssetHandler::gzippedTwin($this->fixture('css', 200), 'css', 'gzip', $this->cacheDir));
    }

    /**
     * What `Vary: Accept-Encoding` claims is a property of the file, not of the
     * request that happened to arrive first.
     *
     * The header exists to tell a shared cache that this URL has more than one
     * representation. Deciding it from the current request's Accept-Encoding
     * would leave the identity answer unmarked, and a cache holding THAT copy
     * under the bare URL would serve it to every client behind it — one visitor
     * without gzip turning the compression off for everyone downstream. So the
     * answer must be the same for a request that asked for gzip and one that
     * did not.
     */
    #[Test]
    public function whether_a_url_varies_by_encoding_does_not_depend_on_the_request(): void
    {
        $text = $this->fixture('css', 4096);

        self::assertTrue(StaticAssetHandler::worthCompressing($text, 'css'));
        self::assertTrue(StaticAssetHandler::worthCompressing($this->fixture('js', 4096), 'js'));

        // An asset with a single representation must not be marked as varying:
        // saying so splits a cache key for nothing.
        self::assertFalse(StaticAssetHandler::worthCompressing($this->fixture('png', 4096), 'png'));
        self::assertFalse(StaticAssetHandler::worthCompressing($this->fixture('css', 200), 'css'));
        self::assertFalse(StaticAssetHandler::worthCompressing('/no/such/file.css', 'css'));
    }

    /**
     * An edited file must never be served from the old twin. The name carries
     * the source's size and mtime, so a change is a different twin rather than
     * a stale one.
     */
    #[Test]
    public function editing_the_source_produces_a_different_twin(): void
    {
        $source = $this->fixture('css', 4096);
        $before = StaticAssetHandler::gzippedTwin($source, 'css', 'gzip', $this->cacheDir);

        file_put_contents($source, str_repeat(".changed { color: blue; }\n", 200));
        touch($source, time() + 5);

        $after = StaticAssetHandler::gzippedTwin($source, 'css', 'gzip', $this->cacheDir);

        self::assertIsString($before);
        self::assertIsString($after);
        self::assertNotSame($before, $after, 'a changed source must not resolve to the old twin');
    }

    /** A missing file is a 404 upstream, not a crash here. */
    #[Test]
    public function a_file_that_is_not_there_yields_nothing(): void
    {
        self::assertNull(StaticAssetHandler::gzippedTwin('/no/such/file.css', 'css', 'gzip', $this->cacheDir));
    }
}
