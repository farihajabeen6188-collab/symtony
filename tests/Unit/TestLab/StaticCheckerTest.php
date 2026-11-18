<?php

declare(strict_types=1);

namespace App\Tests\Unit\TestLab;

use App\Application\TestLab\StaticChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StaticCheckerTest extends TestCase
{
    private const NS = 'App\Tests\Symtony';
    private const BASE = 'App\Tests\ShopTestCase';

    public function testAWellFormedTestPasses(): void
    {
        self::assertSame([], $this->check($this->code('$this->assertSame(409, $this->checkout("t", "SKU-1", 1)["status"]);')));
    }

    public function testMethodsNamedLikeForbiddenFunctionsAreFine(): void
    {
        self::assertSame([], $this->check($this->code('$this->cache->unlink("x"); $repo->copy(); Foo::exec();')));
    }

    /** @return iterable<string, array{string, string}> */
    public static function forbidden(): iterable
    {
        yield 'shell' => ['shell_exec("id");', 'shell_exec'];
        yield 'fully qualified' => ['\exec("id");', 'exec'];
        yield 'backtick' => ['$x = `id`;', 'Backtick'];
        yield 'eval' => ['eval("1;");', 'eval'];
        yield 'include' => ['require "/etc/passwd";', 'require'];
        yield 'network' => ['fsockopen("example.com", 80);', 'fsockopen'];
        yield 'file write' => ['file_put_contents("/tmp/x", "y");', 'file_put_contents'];
        yield 'dynamic call' => ['call_user_func("system", "id");', 'call_user_func'];
    }

    #[DataProvider('forbidden')]
    public function testForbiddenConstructsAreRejected(string $body, string $expected): void
    {
        $issues = $this->check($this->code($body));

        self::assertNotSame([], $issues);
        self::assertStringContainsString($expected, implode(' ', $issues));
    }

    public function testStructureIsEnforced(): void
    {
        $code = str_replace(['namespace App\Tests\Symtony;', 'extends ShopTestCase'], ['namespace Evil;', 'extends \PHPUnit\Framework\TestCase'], $this->code(''));
        $issues = implode(' ', $this->check($code));

        self::assertStringContainsString('namespace must be App\Tests\Symtony', $issues);
        self::assertStringContainsString('must extend App\Tests\ShopTestCase', $issues);
    }

    public function testWrongClassNameAndMissingTestMethod(): void
    {
        $code = str_replace(['ProvenTest', 'public function testIt'], ['OtherTest', 'public function helper'], $this->code(''));
        $issues = implode(' ', $this->check($code));

        self::assertStringContainsString('exactly one class named ProvenTest', $issues);
        self::assertStringContainsString('no public test method', $issues);
    }

    public function testSyntaxErrorsAreReportedWithoutExecutingAnything(): void
    {
        $issues = $this->check($this->code('$x = ;'));

        self::assertCount(1, $issues);
        self::assertStringStartsWith('Syntax error on line', $issues[0]);
    }

    /** @return list<string> */
    private function check(string $code): array
    {
        return (new StaticChecker())->check($code, 'ProvenTest', self::NS, self::BASE);
    }

    private function code(string $body): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Tests\Symtony;

            use App\Tests\ShopTestCase;

            final class ProvenTest extends ShopTestCase
            {
                public function testIt(): void
                {
                    {$body}
                }
            }

            PHP;
    }
}
