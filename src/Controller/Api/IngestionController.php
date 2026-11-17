<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Infrastructure\Ingestion\CredentialManager;
use App\Infrastructure\Ingestion\IngestionService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public ingestion endpoint. Accepts one event object or {"events": [...]}.
 * Auth: "Authorization: Bearer sym_…". Errors use RFC 9457 problem details.
 */
final class IngestionController
{
    private const SOURCES = ['sdk', 'monolog', 'http', 'demo'];

    public function __construct(
        private readonly CredentialManager $credentials,
        private readonly IngestionService $ingestion,
        #[Autowire(service: 'limiter.ingestion')]
        private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire(env: 'int:SYMTONY_INGEST_MAX_BYTES')]
        private readonly int $maxBytes,
    ) {
    }

    #[Route('/api/v1/events', name: 'api_ingest', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $token = $this->bearer($request);
        if (null === $token) {
            return $this->problem(401, 'missing_token', 'Send your ingestion token as "Authorization: Bearer sym_…".');
        }

        $credential = $this->credentials->findActiveByToken($token);
        if (null === $credential) {
            $revoked = $this->credentials->findAnyByToken($token);
            if (null !== $revoked) {
                $this->ingestion->recordRequestFailure($revoked, 'revoked_token', 'Request used a revoked token.');
            }

            return $this->problem(401, 'invalid_token', null !== $revoked ? 'This token was revoked. Create a new one in SYMTONY.' : 'Unknown ingestion token.');
        }

        $limit = $this->limiter->create($credential->getId()->toRfc4122())->consume();
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return $this->problem(429, 'rate_limited', \sprintf('Too many requests for this token. Retry in %d s.', $retry), ['Retry-After' => (string) $retry]);
        }

        $body = $request->getContent();
        if (\strlen($body) > $this->maxBytes) {
            $this->ingestion->recordRequestFailure($credential, 'payload_too_large', \sprintf('Body was %d bytes; the limit is %d.', \strlen($body), $this->maxBytes));

            return $this->problem(413, 'payload_too_large', \sprintf('Request body exceeds %d bytes. Send smaller batches.', $this->maxBytes));
        }

        try {
            $data = json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->ingestion->recordRequestFailure($credential, 'malformed_json', $e->getMessage(), $body);

            return $this->problem(400, 'malformed_json', 'Body is not valid JSON: '.$e->getMessage());
        }

        if (\is_array($data) && \array_key_exists('events', $data)) {
            $events = $data['events'];
            if (!\is_array($events) || !array_is_list($events)) {
                return $this->problem(422, 'invalid_batch', '"events" must be an array of event objects.');
            }
        } elseif (\is_array($data) && !array_is_list($data)) {
            $events = [$data];
        } else {
            return $this->problem(422, 'invalid_body', 'Send one event object or {"events": [...]}.');
        }

        if ([] === $events) {
            return $this->problem(422, 'empty_batch', 'The batch contains no events.');
        }
        if (\count($events) > IngestionService::MAX_BATCH) {
            return $this->problem(413, 'batch_too_large', \sprintf('At most %d events per request.', IngestionService::MAX_BATCH));
        }

        $source = (string) $request->headers->get('X-Symtony-Source', 'http');
        if (!\in_array($source, self::SOURCES, true)) {
            $source = 'http';
        }

        $result = $this->ingestion->ingest($credential, $events, $source);
        $status = [] === $result->acceptedIds && 0 === $result->duplicates ? 422 : 202;

        return new JsonResponse($result->toArray(), $status, [
            'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
        ]);
    }

    private function bearer(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (1 === preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $m[1];
        }

        return null;
    }

    /** @param array<string, string> $headers */
    private function problem(int $status, string $type, string $detail, array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'type' => 'https://symtony.dev/problems/'.$type,
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
        ], $status, ['Content-Type' => 'application/problem+json', ...$headers]);
    }
}
