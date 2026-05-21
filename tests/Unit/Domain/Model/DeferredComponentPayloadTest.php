<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Domain\Model\DeferredComponentPayload;

final class DeferredComponentPayloadTest extends TestCase
{
    public function testToArrayEmitsDeferredComponentEnvelope(): void
    {
        $payload = new DeferredComponentPayload(
            componentName: 'ui-playground.leads-grid',
            instanceId: 'cmp_abcd1234',
            html: '<table>...</table>',
            meta: ['priority' => 1],
        );

        self::assertSame([
            'type' => 'deferred_component',
            'component_name' => 'ui-playground.leads-grid',
            'instance_id' => 'cmp_abcd1234',
            'html' => '<table>...</table>',
            'meta' => ['priority' => 1],
        ], $payload->toArray());
    }

    public function testRejectsEmptyComponentName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeferredComponentPayload('', 'cmp_1', '<div/>');
    }

    public function testRejectsEmptyInstanceId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeferredComponentPayload('grid', '', '<div/>');
    }
}
