<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Isomorphic;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredBindCookie;

final class DeferredBindCookieTest extends TestCase
{
    #[Test]
    public function each_request_reads_its_own_cookie(): void
    {
        $cookies = ['semitexa_ssr_bind_dr_a' => 'token-a', 'semitexa_ssr_bind_dr_b' => 'token-b'];

        self::assertSame('token-a', DeferredBindCookie::tokenIn($cookies, 'dr_a'));
        self::assertSame('token-b', DeferredBindCookie::tokenIn($cookies, 'dr_b'));
    }

    #[Test]
    public function its_own_cookie_wins_over_the_plain_one_another_tab_may_have_written(): void
    {
        self::assertSame('mine', DeferredBindCookie::tokenIn(['semitexa_ssr_bind' => 'theirs', 'semitexa_ssr_bind_dr_a' => 'mine'], 'dr_a'));
    }

    #[Test]
    public function a_client_from_before_still_gets_in(): void
    {
        self::assertSame('old', DeferredBindCookie::tokenIn(['semitexa_ssr_bind' => 'old'], 'dr_a'));
    }

    #[Test]
    public function no_cookie_is_no_token(): void
    {
        self::assertSame('', DeferredBindCookie::tokenIn([], 'dr_a'));
    }
}
