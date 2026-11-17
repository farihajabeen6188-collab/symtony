<?php

declare(strict_types=1);

namespace App\Application\Timeline;

use App\Domain\Event\Event;

final readonly class TimelineItem
{
    /**
     * @param 'trace'|'request'|'context' $membership how the item got into this timeline
     */
    public function __construct(
        public string $id,
        public Event $event,
        public string $lane,
        public float $offsetMs,
        public ?float $durationMs,
        public bool $error,
        public string $membership,
        public ?string $reason = null,
    ) {
    }

    public function isProven(): bool
    {
        return 'context' !== $this->membership;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $e = $this->event;

        return [
            'id' => $this->id,
            'lane' => $this->lane,
            'type' => $e->getType()->value,
            'type_label' => $e->getType()->label(),
            'name' => $e->getName() ?? $e->getMessage(),
            'message' => mb_substr($e->getExceptionMessage() ?? $e->getMessage(), 0, 300),
            'exception' => $e->getExceptionClass(),
            'service' => $e->getService(),
            'severity' => $e->getSeverity()->value,
            'error' => $this->error,
            'offset_ms' => round($this->offsetMs, 3),
            'duration_ms' => null !== $this->durationMs ? round($this->durationMs, 3) : null,
            'time' => $e->getOccurredAt()->format('H:i:s.u'),
            'span_id' => $e->getSpanId(),
            'parent_span_id' => $e->getParentSpanId(),
            'request_id' => $e->getRequestId(),
            'trace_id' => $e->getTraceId(),
            'membership' => $this->membership,
            'proven' => $this->isProven(),
            'reason' => $this->reason,
            'context' => self::summary($e),
        ];
    }

    /** @return array<string, scalar|null> a few human-useful context fields for tooltips */
    private static function summary(Event $e): array
    {
        $c = $e->getContext();
        $out = [];
        foreach ([
            'status' => $c['http']['status'] ?? $c['external']['status'] ?? null,
            'statement' => $c['db']['statement'] ?? null,
            'db error' => $c['db']['error'] ?? null,
            'rows' => $c['db']['rows'] ?? null,
            'cache' => isset($c['cache']['hit']) ? ($c['cache']['hit'] ? 'hit' : 'miss') : null,
            'cache age' => isset($c['cache']['age_seconds']) ? $c['cache']['age_seconds'].' s' : null,
            'release' => $e->getRelease(),
        ] as $k => $v) {
            if (null !== $v && (\is_scalar($v))) {
                $out[$k] = \is_string($v) ? mb_substr($v, 0, 200) : $v;
            }
        }

        return $out;
    }
}
