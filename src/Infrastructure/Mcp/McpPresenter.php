<?php

declare(strict_types=1);

namespace App\Infrastructure\Mcp;

use App\Application\Query\IncidentQueries;
use App\Domain\Application\Application;
use App\Domain\Event\Event;
use App\Domain\Incident\Incident;
use App\Domain\Investigation\Finding;
use App\Domain\Investigation\Investigation;
use App\Domain\TestLab\RegressionTest;
use App\Domain\Workspace\Workspace;
use App\Infrastructure\Ingestion\Redactor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Turns domain objects into the plain arrays MCP clients receive. Everything that leaves through MCP
 * passes the Redactor again, and every object carries a link back to the SYMTONY UI.
 */
final class McpPresenter
{
    public function __construct(
        private readonly Redactor $redactor,
        private readonly UrlGeneratorInterface $urls,
        private readonly IncidentQueries $incidents,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** "SYM-5", "5" or the incident's UUID, inside the workspace. */
    public function findIncident(Workspace $workspace, string $ref): Incident
    {
        $ref = trim($ref);
        if (1 === preg_match('/^(?:SYM-)?(\d+)$/i', $ref, $m)) {
            return $this->incidents->byNumber($workspace, (int) $m[1]);
        }
        $incident = Uuid::isValid($ref) ? $this->em->getRepository(Incident::class)->findOneBy(['id' => Uuid::fromString($ref), 'workspace' => $workspace]) : null;
        if (!$incident instanceof Incident) {
            throw new NotFoundHttpException(\sprintf('No incident "%s" in this workspace. Use an incident reference like SYM-5 (see list_incidents).', $ref));
        }

        return $incident;
    }

    public function findTest(Workspace $workspace, string $id): RegressionTest
    {
        $test = Uuid::isValid($id) ? $this->em->getRepository(RegressionTest::class)->findOneBy(['id' => Uuid::fromString($id), 'workspace' => $workspace]) : null;
        if (!$test instanceof RegressionTest) {
            throw new NotFoundHttpException(\sprintf('No regression test "%s" in this workspace.', $id));
        }

        return $test;
    }

    /** @return array<string, mixed> */
    public function application(Application $app): array
    {
        return [
            'id' => $app->getId()->toRfc4122(),
            'name' => $app->getName(),
            'slug' => $app->getSlug(),
            'environment' => $app->getEnvironment()->value,
            'framework' => $app->getFramework()->value,
            'status' => $app->getConnectionStatus()->value,
            'first_event_at' => $app->getFirstEventAt()?->format(\DATE_ATOM),
            'last_event_at' => $app->getLastEventAt()?->format(\DATE_ATOM),
            'url' => $this->url('app_applications_show', ['id' => $app->getId()->toRfc4122()]),
        ];
    }

    /** @return array<string, mixed> */
    public function incident(Incident $incident): array
    {
        return [
            'reference' => $incident->getReference(),
            'id' => $incident->getId()->toRfc4122(),
            'title' => $this->text($incident->getTitle()),
            'status' => $incident->getStatus()->value,
            'severity' => $incident->getSeverity()->value,
            'application' => $incident->getApplication()->getSlug(),
            'service' => $incident->getService(),
            'exception_class' => $incident->getExceptionClass(),
            'occurrences' => $incident->getOccurrences(),
            'first_seen_at' => $incident->getFirstSeenAt()->format(\DATE_ATOM),
            'last_seen_at' => $incident->getLastSeenAt()->format(\DATE_ATOM),
            'url' => $this->url('app_incident_show', ['number' => $incident->getNumber()]),
        ];
    }

    /** @return array<string, mixed> */
    public function eventSummary(Event $e): array
    {
        return [
            'id' => $e->getId()->toRfc4122(),
            'occurred_at' => $e->getOccurredAt()->format('Y-m-d\TH:i:s.uP'),
            'severity' => $e->getSeverity()->value,
            'type' => $e->getType()->value,
            'application' => $e->getApplication()->getSlug(),
            'service' => $e->getService(),
            'name' => null !== $e->getName() ? $this->text($e->getName()) : null,
            'message' => $this->text(mb_substr($e->getMessage(), 0, 300)),
            'exception_class' => $e->getExceptionClass(),
            'trace_id' => $e->getTraceId(),
            'request_id' => $e->getRequestId(),
            'duration_ms' => $e->getDurationMs(),
        ];
    }

    /** @return array<string, mixed> */
    public function event(Event $e): array
    {
        return $this->eventSummary($e) + [
            'environment' => $e->getEnvironment(),
            'release' => $e->getRelease(),
            'span_id' => $e->getSpanId(),
            'parent_span_id' => $e->getParentSpanId(),
            'exception_message' => null !== $e->getExceptionMessage() ? $this->text($e->getExceptionMessage()) : null,
            'stack_trace' => null !== $e->getStackTrace() ? $this->redactor->redactArray(\array_slice($e->getStackTrace(), 0, 40))['data'] : null,
            'context' => $this->redactor->redactArray($e->getContext())['data'],
            'url' => $this->url('app_events', array_filter(['event' => $e->getId()->toRfc4122(), 'trace_id' => $e->getTraceId()])),
        ];
    }

    /** @return array<string, mixed> */
    public function finding(Finding $f): array
    {
        return [
            'kind' => $f->getKind(),
            'statement' => $f->getStatement(),
            'rationale' => $f->getRationale(),
            'confidence' => $f->getConfidence(),
            'evidence' => $f->getCitations(),
            'source' => $f->getSource(),
            'grounding' => $f->getGrounding(),
            'grounding_reason' => $f->getGroundingReason(),
        ];
    }

    /** @return array<string, mixed> */
    public function investigation(Investigation $inv): array
    {
        return [
            'id' => $inv->getId()->toRfc4122(),
            'incident' => $inv->getIncident()->getReference(),
            'status' => $inv->getStatus()->value,
            'engine' => $inv->getEngine(),
            'created_at' => $inv->getCreatedAt()->format(\DATE_ATOM),
            'finished_at' => $inv->getFinishedAt()?->format(\DATE_ATOM),
            'url' => $this->url('app_investigation_show', ['id' => $inv->getId()->toRfc4122()]),
        ];
    }

    /**
     * @param array<string, mixed> $timeline Timeline::toArray()
     *
     * @return array<string, mixed>
     */
    public function timeline(array $timeline): array
    {
        $timeline['items'] = array_map(function (array $item): array {
            $item['name'] = $this->text((string) $item['name']);
            $item['message'] = $this->text((string) $item['message']);
            $item['context'] = $this->redactor->redactArray((array) $item['context'])['data'];
            unset($item['type_label'], $item['time']);

            return $item;
        }, (array) $timeline['items']);
        $timeline['legend'] = 'proven=true: same trace/request id. proven=false: inferred from timing only.';

        return $timeline;
    }

    public function text(string $value): string
    {
        return $this->redactor->redactString($value)['value'];
    }

    /** @param array<string, mixed> $params */
    public function url(string $route, array $params): string
    {
        return $this->urls->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
