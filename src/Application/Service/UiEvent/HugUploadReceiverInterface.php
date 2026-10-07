<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

use Semitexa\Core\Http\UploadedFile;

/**
 * What HUG does with a file a signed upload context let through.
 *
 * HUG has already verified the context (signature, expiry, session and tenant
 * binding) and that it is an upload context (`k: upload`); the receiver
 * enforces the policy signed into it — size, type — stores the file and
 * answers. Platform UI binds the real one; without it uploads are refused.
 */
interface HugUploadReceiverInterface
{
    /**
     * @param array<string, mixed> $claims the verified upload context
     * @return array{0: int, 1: array<string, mixed>} HTTP status and JSON body
     */
    public function receive(array $claims, UploadedFile $file): array;
}
