<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Infrastructure\Ingestion\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    private Redactor $redactor;

    protected function setUp(): void
    {
        $this->redactor = new Redactor();
    }

    public function testRedactsSensitiveKeysAtAnyDepth(): void
    {
        $result = $this->redactor->redactArray([
            'user' => ['id' => 7, 'password' => 'hunter2', 'profile' => ['api_key' => 'k-123']],
            'headers' => ['Authorization' => 'Basic Zm9vOmJhcg==', 'X-Session-Id' => 'abc', 'Accept' => 'text/html'],
            'csrf_token' => 'tok',
            'sku' => 'SKU-1',
        ]);

        self::assertSame('[redacted]', $result['data']['user']['password']);
        self::assertSame('[redacted]', $result['data']['user']['profile']['api_key']);
        self::assertSame('[redacted]', $result['data']['headers']['Authorization']);
        self::assertSame('[redacted]', $result['data']['headers']['X-Session-Id']);
        self::assertSame('[redacted]', $result['data']['csrf_token']);
        self::assertSame('text/html', $result['data']['headers']['Accept']);
        self::assertSame('SKU-1', $result['data']['sku']);
        self::assertSame(7, $result['data']['user']['id']);
        self::assertSame(5, $result['count']);
    }

    public function testHarmlessCounterKeysAreKept(): void
    {
        $result = $this->redactor->redactArray(['token_count' => 512, 'total_tokens' => 900]);

        self::assertSame(['token_count' => 512, 'total_tokens' => 900], $result['data']);
        self::assertSame(0, $result['count']);
    }

    public function testWorkspaceExtraKeys(): void
    {
        $result = $this->redactor->redactArray(['customer_phone' => '+1 555 0100', 'note' => 'ok'], ['customer_phone']);

        self::assertSame('[redacted]', $result['data']['customer_phone']);
        self::assertSame('ok', $result['data']['note']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function secretStrings(): iterable
    {
        yield 'bearer' => ['auth failed: Bearer eyJabc.def.ghi', 'auth failed: Bearer [redacted]'];
        yield 'jwt' => ['jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N', 'jwt [redacted]'];
        yield 'symtony token' => ['used sym_prod_0123456789abcdefghijABCDEFGHIJKL ok', 'used [redacted] ok'];
        yield 'mcp token' => ['header smcp_0123456789abcdefghijABCDEFGHIJ0123456789 sent', 'header [redacted] sent'];
        yield 'hugging face' => ['key hf_abcdefghijklmnopqrstuvwxyz12', 'key [redacted]'];
        yield 'aws' => ['AKIAIOSFODNN7EXAMPLE leaked', '[redacted] leaked'];
        yield 'dsn password' => ['postgresql://app:s3cr3t@db:5432/shop', 'postgresql://app:[redacted]@db:5432/shop'];
        yield 'query param' => ['GET /cb?access_token=abc123&x=1', 'GET /cb?access_token=[redacted]&x=1'];
        yield 'card (luhn)' => ['card 4111 1111 1111 1111 declined', 'card [redacted] declined'];
        yield 'email' => ['sent to ann@example.com', 'sent to [email]'];
    }

    #[DataProvider('secretStrings')]
    public function testScrubsSecretShapedValues(string $input, string $expected): void
    {
        self::assertSame($expected, $this->redactor->redactString($input)['value']);
    }

    public function testDoesNotTouchNonLuhnNumbers(): void
    {
        $value = 'order 1234567890123 shipped';
        self::assertSame($value, $this->redactor->redactString($value)['value']);
    }

    public function testEmailRedactionCanBeDisabled(): void
    {
        self::assertSame('sent to ann@example.com', $this->redactor->redactString('sent to ann@example.com', emails: false)['value']);
    }
}
