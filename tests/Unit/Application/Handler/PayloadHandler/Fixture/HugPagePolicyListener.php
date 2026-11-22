<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler\Fixture;

use Semitexa\Core\Pipeline\Exception\AuthenticationRequiredException;
use Semitexa\Core\Pipeline\PipelineListenerInterface;
use Semitexa\Core\Pipeline\RequestPipelineContext;

/**
 * The access policy of the probe pages, as an AuthCheck listener states one:
 * {@see HugProtectedPagePayload} opens to the request carrying the owner's
 * session cookie and to nobody else. It writes down every request it was asked
 * about.
 */
final class HugPagePolicyListener implements PipelineListenerInterface
{
    public const MESSAGE = 'Authentication required';

    /** @var list<array{payload: class-string, uri: string, reRun: bool}> */
    public static array $asked = [];

    public function handle(RequestPipelineContext $context): void
    {
        self::$asked[] = ['payload' => $context->requestDto::class, 'uri' => $context->request->uri, 'reRun' => $context->reRun];

        if ($context->requestDto instanceof HugProtectedPagePayload && ($context->request->cookies['session'] ?? '') !== 'owner') {
            throw new AuthenticationRequiredException(self::MESSAGE);
        }
    }
}
