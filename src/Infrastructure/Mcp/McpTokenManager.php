<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Domain\Mcp\McpToken;
use App\Domain\Workspace\User;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Persistence\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues, lists, revokes and authenticates MCP tokens: "smcp_<40 base62 chars>" (~238 bits).
 * The plain token is returned once at creation; only its SHA-256 hash is stored.
 */
final class McpTokenManager
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param list<string> $scopes
     *
     * @return array{token: string, entity: McpToken}
     */
    public function issue(Workspace $workspace, string $label, array $scopes, ?User $user): array
    {
        $label = trim($label);
        if ('' === $label || mb_strlen($label) > 80) {
            throw new \DomainException('Give the token a name of 1 to 80 characters.');
        }
        $scopes = array_values(array_intersect([McpToken::READ, McpToken::WRITE], $scopes));
        if (!\in_array(McpToken::READ, $scopes, true)) {
            $scopes = [McpToken::READ, ...$scopes];
        }
        $random = '';
        for ($i = 0; $i < 40; ++$i) {
            $random .= self::ALPHABET[random_int(0, 61)];
        }
        $token = 'smcp_'.$random;
        $entity = new McpToken($workspace, $label, $scopes, hash('sha256', $token), substr($token, 0, 12), $user);
        $this->em->persist($entity);
        $this->audit->write($workspace, null !== $user ? 'user' : 'system', $user?->getEmail() ?? 'setup', 'mcp.token_created', $entity, ['label' => $label, 'scopes' => $scopes]);
        $this->em->flush();

        return ['token' => $token, 'entity' => $entity];
    }

    public function revoke(McpToken $token, User $user): void
    {
        if (!$token->isRevoked()) {
            $token->revoke();
            $this->audit->userAction($token->getWorkspace(), 'mcp.token_revoked', $token, ['label' => $token->getLabel()]);
            $this->em->flush();
        }
    }

    /** @return list<McpToken> */
    public function list(Workspace $workspace): array
    {
        return $this->em->getRepository(McpToken::class)->findBy(['workspace' => $workspace], ['createdAt' => 'DESC'], 100);
    }

    public function get(Workspace $workspace, string $id): ?McpToken
    {
        return $this->em->getRepository(McpToken::class)->findOneBy(['workspace' => $workspace, 'id' => $id]);
    }

    /** Returns the active token for a raw bearer value, or null. Never logs the value. */
    public function authenticate(string $raw): ?McpToken
    {
        $raw = trim($raw);
        if (1 !== preg_match('/^smcp_[0-9A-Za-z]{40}$/', $raw)) {
            return null;
        }
        $token = $this->em->getRepository(McpToken::class)->findOneBy(['tokenHash' => hash('sha256', $raw)]);
        if (!$token instanceof McpToken || $token->isRevoked()) {
            return null;
        }
        if ($token->touch(new \DateTimeImmutable())) {
            $this->em->flush();
        }

        return $token;
    }
}
