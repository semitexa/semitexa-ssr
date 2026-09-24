<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\SiteHead;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;
use Semitexa\Ssr\Domain\Model\SiteHead;

/**
 * The current site's head values — the one place they are read from.
 *
 * The page render reads them through here, and so does anything that has to
 * account for them, such as a move's reconciliation: the incident behind this
 * was an analytics id that did not come across and that nothing reported.
 * SettingsStore is tenant-scoped, so "current site" needs no tenant handling.
 */
#[AsService]
final class SiteHeadReader
{
    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

    public function current(): SiteHead
    {
        return SiteHead::fromSettings($this->settings->getAll(SiteHead::SETTINGS_MODULE));
    }
}
