<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingestion;

use App\Domain\Application\Application;
use App\Domain\Application\IngestionCredential;
use App\Infrastructure\Persistence\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues, rotates and revokes ingestion tokens. The plain token is returned once and never stored.
 */
final class CredentialManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TokenGenerator $generator,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array{credential: IngestionCredential, token: string} */
    public function issue(Application $application, string $label = 'Default'): array
    {
        $generated = $this->generator->generate($application->getEnvironment());
        $credential = new IngestionCredential($application, $label, $generated['prefix'], $generated['hash']);
        $this->em->persist($credential);
        $this->audit->userAction($application->getWorkspace(), 'credential.created', $credential, ['prefix' => $generated['prefix'], 'application' => $application->getName()]);
        $this->em->flush();

        return ['credential' => $credential, 'token' => $generated['token']];
    }

    /**
     * Rotation = issue a new token and revoke the old one in the same transaction.
     *
     * @return array{credential: IngestionCredential, token: string}
     */
    public function rotate(IngestionCredential $old): array
    {
        return $this->em->wrapInTransaction(function () use ($old): array {
            $old->revoke();
            $this->audit->userAction($old->getWorkspace(), 'credential.rotated', $old, ['prefix' => $old->getPrefix()]);

            return $this->issue($old->getApplication(), $old->getLabel());
        });
    }

    public function revoke(IngestionCredential $credential): void
    {
        $credential->revoke();
        $this->audit->userAction($credential->getWorkspace(), 'credential.revoked', $credential, ['prefix' => $credential->getPrefix()]);
        $this->em->flush();
    }

    public function findActiveByToken(string $token): ?IngestionCredential
    {
        if (!TokenGenerator::looksValid($token)) {
            return null;
        }
        $credential = $this->em->getRepository(IngestionCredential::class)->findOneBy(['tokenHash' => TokenGenerator::hash($token)]);

        return $credential instanceof IngestionCredential && !$credential->isRevoked() ? $credential : null;
    }

    public function findAnyByToken(string $token): ?IngestionCredential
    {
        if (!TokenGenerator::looksValid($token)) {
            return null;
        }
        $credential = $this->em->getRepository(IngestionCredential::class)->findOneBy(['tokenHash' => TokenGenerator::hash($token)]);

        return $credential instanceof IngestionCredential ? $credential : null;
    }
}
