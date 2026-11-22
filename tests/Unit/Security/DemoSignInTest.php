<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Infrastructure\Security\DemoSignIn;
use PHPUnit\Framework\TestCase;

final class DemoSignInTest extends TestCase
{
    public function testLocalSetupsAlwaysOfferTheDemoAccount(): void
    {
        $demo = new DemoSignIn('demo@symtony.local', 'symtony-demo', 'dev', false);

        self::assertSame(['email' => 'demo@symtony.local', 'password' => 'symtony-demo', 'public' => false], $demo->credentials());
    }

    public function testProductionHidesTheDemoAccountUnlessThePublicDemoIsEnabled(): void
    {
        self::assertNull((new DemoSignIn('demo@symtony.local', 'symtony-demo', 'prod', false))->credentials());

        $public = (new DemoSignIn('demo@symtony.local', 'symtony-demo', 'prod', true))->credentials();
        self::assertNotNull($public);
        self::assertTrue($public['public']);
    }

    public function testNothingIsOfferedWithoutConfiguredCredentials(): void
    {
        self::assertNull((new DemoSignIn('', 'symtony-demo', 'prod', true))->credentials());
        self::assertNull((new DemoSignIn('demo@symtony.local', '', 'dev', false))->credentials());
    }
}
