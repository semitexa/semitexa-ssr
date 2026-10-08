<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Http\UploadedFile;

/** The default: nothing receives uploads until a package binds a receiver. */
#[SatisfiesServiceContract(of: HugUploadReceiverInterface::class)]
final class NotConfiguredHugUploadReceiver implements HugUploadReceiverInterface
{
    public function receive(array $claims, UploadedFile $file): array
    {
        return [422, ['status' => 'rejected', 'reason' => 'uploads_not_configured', 'message' => 'Uploads are not configured.']];
    }
}
