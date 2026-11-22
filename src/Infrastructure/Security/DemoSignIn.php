<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decides whether the sign-in page offers the shared demo account.
 *
 * Outside production it always does (local Docker setup). In production it only does when the
 * deployment opts in with SYMTONY_PUBLIC_DEMO=1, e.g. the public hackathon demo, so a real
 * production install never advertises credentials by accident.
 */
final readonly class DemoSignIn
{
    public function __construct(
        #[Autowire(env: 'SYMTONY_DEMO_EMAIL')] private string $email,
        #[Autowire(env: 'SYMTONY_DEMO_PASSWORD')] private string $password,
        #[Autowire('%kernel.environment%')] private string $environment,
        #[Autowire(env: 'bool:SYMTONY_PUBLIC_DEMO')] private bool $publicDemo,
    ) {
    }

    /**
     * @return array{email: string, password: string, public: bool}|null
     */
    public function credentials(): ?array
    {
        if ('' === $this->email || '' === $this->password) {
            return null;
        }
        $public = 'prod' === $this->environment;
        if ($public && !$this->publicDemo) {
            return null;
        }

        return ['email' => $this->email, 'password' => $this->password, 'public' => $public];
    }
}
