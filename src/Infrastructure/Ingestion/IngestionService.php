<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingestion;

use App\Domain\Application\IngestionCredential;
use App\Domain\Event\Event;
use App\Domain\Event\IngestionFailure;
use App\Infrastructure\Ingestion\Message\EventsIngested;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Validate → redact → normalize → persist (one transaction per batch) → dispatch EventsIngested.
 */
final class IngestionService
{
    public const MAX_BATCH = 500;
    private const MAX_FAILURE_ROWS_PER_REQUEST = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PayloadParser $parser,
        private readonly Redactor $redactor,
        private readonly MessageNormalizer $normalizer,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * @param list<mixed> $payloads
     */
    public function ingest(IngestionCredential $credential, array $payloads, string $source): IngestionResult
    {
        $application = $credential->getApplication();
        $workspace = $application->getWorkspace();
        $now = new \DateTimeImmutable();

        $drafts = [];
        $rejected = [];
        foreach ($payloads as $index => $raw) {
            $parsed = $this->parser->parse($raw, $now);
            if (null === $parsed['draft']) {
                $rejected[] = ['index' => $index, 'errors' => $parsed['errors']];
                continue;
            }
            $drafts[$index] = $parsed['draft'];
        }

        // Idempotency: skip events whose client id we already stored (or that repeat inside this batch).
        $existing = $this->existingExternalIds($credential, $drafts);
        $seen = [];
        $duplicates = 0;
        $events = [];
        $redactions = 0;

        foreach ($drafts as $draft) {
            if (null !== $draft->externalId) {
                if (isset($existing[$draft->externalId]) || isset($seen[$draft->externalId])) {
                    ++$duplicates;
                    continue;
                }
                $seen[$draft->externalId] = true;
            }

            $context = $this->redactor->redactArray($draft->context, $workspace->getRedactKeys());
            $message = $this->redactor->redactString($draft->message);
            $exMessage = null !== $draft->exceptionMessage ? $this->redactor->redactString($draft->exceptionMessage) : null;
            $redactions += $context['count'] + $message['count'] + ($exMessage['count'] ?? 0);

            $events[] = new Event(
                application: $application,
                occurredAt: $draft->occurredAt,
                severity: $draft->severity,
                type: $draft->type,
                service: $draft->service,
                message: $message['value'],
                normalizedMessage: $this->normalizer->normalize($exMessage['value'] ?? $message['value']),
                source: $source,
                environment: $draft->environment,
                release: $draft->release,
                name: $draft->name,
                traceId: $draft->traceId,
                spanId: $draft->spanId,
                parentSpanId: $draft->parentSpanId,
                requestId: $draft->requestId,
                durationMs: $draft->durationMs,
                exceptionClass: $draft->exceptionClass,
                exceptionMessage: $exMessage['value'] ?? null,
                stackTrace: $draft->frames,
                context: $context['data'],
                externalId: $draft->externalId,
                redactions: $context['count'] + $message['count'] + ($exMessage['count'] ?? 0),
                receivedAt: $now,
            );
        }

        $this->em->wrapInTransaction(function () use ($events, $rejected, $credential, $application, $payloads, $now): void {
            foreach ($events as $event) {
                $this->em->persist($event);
            }
            foreach (\array_slice($rejected, 0, self::MAX_FAILURE_ROWS_PER_REQUEST) as $r) {
                $this->em->persist(new IngestionFailure(
                    $application,
                    'invalid_event',
                    $this->describeErrors($r['errors']),
                    $credential->getPrefix(),
                    $this->excerpt($payloads[$r['index']] ?? null),
                ));
            }
            if ([] !== $events) {
                $application->recordEventsReceived($now);
            }
            $credential->markUsed($now);
        });

        $ids = array_map(static fn (Event $e): string => $e->getId()->toRfc4122(), $events);
        if ([] !== $ids) {
            $this->bus->dispatch(new EventsIngested($application->getId()->toRfc4122(), $ids));
        }

        return new IngestionResult($ids, $rejected, $duplicates, $redactions);
    }

    /**
     * Records a request-level rejection (bad auth, malformed JSON, oversize, rate limit).
     */
    public function recordRequestFailure(?IngestionCredential $credential, string $reason, string $detail, ?string $body = null): void
    {
        $this->em->persist(new IngestionFailure(
            $credential?->getApplication(),
            $reason,
            $detail,
            $credential?->getPrefix(),
            null !== $body ? $this->excerpt($body) : null,
        ));
        $this->em->flush();
    }

    /**
     * @param array<int, EventDraft> $drafts
     *
     * @return array<string, true>
     */
    private function existingExternalIds(IngestionCredential $credential, array $drafts): array
    {
        $ids = array_values(array_filter(array_map(static fn (EventDraft $d): ?string => $d->externalId, $drafts)));
        if ([] === $ids) {
            return [];
        }
        $rows = $this->em->createQuery('SELECT e.externalId FROM '.Event::class.' e WHERE e.application = :app AND e.externalId IN (:ids)')
            ->setParameter('app', $credential->getApplication())
            ->setParameter('ids', $ids)
            ->getSingleColumnResult();

        return array_fill_keys(array_map('strval', $rows), true);
    }

    /** @param array<string, string> $errors */
    private function describeErrors(array $errors): string
    {
        $parts = [];
        foreach ($errors as $field => $message) {
            $parts[] = $field.': '.$message;
        }

        return implode("\n", $parts);
    }

    private function excerpt(mixed $payload): string
    {
        $json = \is_string($payload) ? $payload : (string) json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $json = mb_substr($json, 0, 2000);

        return $this->redactor->redactString($json)['value'];
    }
}
