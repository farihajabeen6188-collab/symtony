<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symtony\Tracer;

/**
 * Demo customer auth: "Authorization: Bearer <customer token>". Tokens are fixed demo values.
 */
final class CustomerAuthenticator
{
    private const CUSTOMERS = [
        'demo-customer-ada' => 'cus_ada',
        'demo-customer-linus' => 'cus_linus',
        'demo-customer-grace' => 'cus_grace',
    ];

    public function __construct(private readonly Tracer $tracer)
    {
    }

    public function authenticate(Request $request): string
    {
        return $this->tracer->span('Authenticate customer', function () use ($request): string {
            $header = (string) $request->headers->get('Authorization', '');
            $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
            $customer = self::CUSTOMERS[$token] ?? null;
            if (null === $customer) {
                throw new UnauthorizedHttpException('Bearer', 'Unknown customer token.');
            }

            return $customer;
        }, context: ['component' => 'auth']);
    }

    /** @return list<string> */
    public static function demoTokens(): array
    {
        return array_keys(self::CUSTOMERS);
    }
}
