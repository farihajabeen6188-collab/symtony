<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingestion;

use App\Domain\Event\EventType;
use App\Domain\Event\Severity;

/**
 * Validates one raw event payload (see docs/event-schema.json) and produces an EventDraft,
 * or a list of precise, per-field error messages. Never throws for bad input.
 */
final class PayloadParser
{
    private const MAX_MESSAGE = 8000;
    private const MAX_FRAMES = 100;
    private const MAX_CONTEXT_BYTES = 32768;
    /** Accept timestamps up to this far in the future (clock skew). */
    private const MAX_FUTURE_SECONDS = 300;

    private const STRUCTURED_KEYS = ['http', 'db', 'cache', 'queue', 'external', 'user', 'deploy'];

    /**
     * @return array{draft: ?EventDraft, errors: array<string, string>}
     */
    public function parse(mixed $raw, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $errors = [];

        if (!\is_array($raw) || array_is_list($raw)) {
            return ['draft' => null, 'errors' => ['$' => 'Each event must be a JSON object.']];
        }

        // timestamp (required)
        $occurredAt = $this->parseTimestamp($raw['timestamp'] ?? null);
        if (null === $occurredAt) {
            $errors['timestamp'] = 'Required. Use RFC 3339 (2026-11-17T14:02:31.482Z) or Unix seconds (1794938551.482).';
        } elseif ($occurredAt->getTimestamp() > $now->getTimestamp() + self::MAX_FUTURE_SECONDS) {
            $errors['timestamp'] = 'Timestamp is more than 5 minutes in the future. Check the sender clock.';
        }

        // type
        $type = EventType::Log;
        if (isset($raw['type'])) {
            $type = \is_string($raw['type']) ? EventType::tryFrom($raw['type']) : null;
            if (null === $type) {
                $errors['type'] = 'Unknown type. Allowed: '.implode(', ', array_map(static fn (EventType $t): string => $t->value, EventType::cases())).'.';
                $type = EventType::Log;
            }
        }

        // severity (alias: level)
        $severity = Severity::Info;
        $rawSeverity = $raw['severity'] ?? $raw['level'] ?? null;
        if (null !== $rawSeverity) {
            try {
                $severity = Severity::fromLoose(\is_int($rawSeverity) || \is_string($rawSeverity) ? $rawSeverity : '?');
            } catch (\InvalidArgumentException) {
                $errors['severity'] = 'Unknown severity. Use debug, info, notice, warning, error or critical.';
            }
        }

        $service = $this->string($raw, 'service', 64, $errors) ?? 'app';
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $service)) {
            $errors['service'] = 'Use letters, digits, ".", "_", "-" or "/".';
        }

        $exception = null;
        if (isset($raw['exception'])) {
            if (!\is_array($raw['exception']) || array_is_list($raw['exception'])) {
                $errors['exception'] = 'Must be an object with "class", "message" and optional "frames".';
            } else {
                $exception = $raw['exception'];
            }
        }
        $exceptionClass = null !== $exception ? $this->string($exception, 'class', 255, $errors, 'exception.class') : null;
        $exceptionMessage = null !== $exception ? $this->string($exception, 'message', self::MAX_MESSAGE, $errors, 'exception.message') : null;
        if (null !== $exception && null === $exceptionClass) {
            $errors['exception.class'] ??= 'Required when "exception" is present.';
        }
        $frames = null !== $exception ? $this->frames($exception['frames'] ?? null, $errors) : null;

        $message = $this->string($raw, 'message', self::MAX_MESSAGE, $errors);
        if (null === $message || '' === trim($message)) {
            $message = $exceptionMessage ?? $raw['name'] ?? null;
            if (!\is_string($message) || '' === trim($message)) {
                $errors['message'] = 'Required (or provide exception.message or name).';
                $message = '';
            }
        }
        if (null !== $exceptionClass && EventType::Log === $type && !isset($raw['type'])) {
            $type = EventType::Exception;
        }

        $environment = $this->string($raw, 'environment', 16, $errors);
        $release = $this->string($raw, 'release', 64, $errors);
        $name = $this->string($raw, 'name', 200, $errors);
        $traceId = $this->identifier($raw, 'trace_id', 64, $errors);
        $spanId = $this->identifier($raw, 'span_id', 32, $errors);
        $parentSpanId = $this->identifier($raw, 'parent_span_id', 32, $errors);
        $requestId = $this->identifier($raw, 'request_id', 64, $errors);
        $externalId = $this->identifier($raw, 'id', 64, $errors);

        $durationMs = null;
        if (isset($raw['duration_ms'])) {
            if (!\is_int($raw['duration_ms']) && !\is_float($raw['duration_ms']) || $raw['duration_ms'] < 0 || $raw['duration_ms'] > 86_400_000) {
                $errors['duration_ms'] = 'Must be a number of milliseconds between 0 and 86400000.';
            } else {
                $durationMs = (float) $raw['duration_ms'];
            }
        }

        $context = [];
        if (isset($raw['context'])) {
            if (!\is_array($raw['context']) || (array_is_list($raw['context']) && [] !== $raw['context'])) {
                $errors['context'] = 'Must be a JSON object.';
            } else {
                $context = $raw['context'];
            }
        }
        foreach (self::STRUCTURED_KEYS as $key) {
            if (isset($raw[$key])) {
                if (!\is_array($raw[$key])) {
                    $errors[$key] = 'Must be a JSON object.';
                } else {
                    $context[$key] = $raw[$key];
                }
            }
        }
        if (\strlen((string) json_encode($context)) > self::MAX_CONTEXT_BYTES) {
            $errors['context'] = 'Context is larger than 32 KB. Send less data per event.';
        }

        if ([] !== $errors || null === $occurredAt) {
            return ['draft' => null, 'errors' => $errors];
        }

        return ['draft' => new EventDraft(
            occurredAt: $occurredAt,
            severity: $severity,
            type: $type,
            service: $service,
            message: $message,
            environment: $environment,
            release: $release,
            name: $name,
            traceId: $traceId,
            spanId: $spanId,
            parentSpanId: $parentSpanId,
            requestId: $requestId,
            durationMs: $durationMs,
            exceptionClass: $exceptionClass,
            exceptionMessage: $exceptionMessage,
            frames: $frames,
            context: $context,
            externalId: $externalId,
        ), 'errors' => []];
    }

    private function parseTimestamp(mixed $value): ?\DateTimeImmutable
    {
        if (\is_int($value) || \is_float($value)) {
            if ($value <= 0) {
                return null;
            }
            // Accept seconds or milliseconds.
            $seconds = $value > 100_000_000_000 ? $value / 1000 : $value;
            $dt = \DateTimeImmutable::createFromFormat('U.u', number_format((float) $seconds, 6, '.', ''), new \DateTimeZone('UTC'));

            return false === $dt ? null : $dt;
        }
        if (\is_string($value) && '' !== $value && 1 === preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            // Trim sub-microsecond precision PHP cannot parse.
            $value = (string) preg_replace('/(\.\d{6})\d+/', '$1', $value);
            try {
                return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param array<mixed>          $data
     * @param array<string, string> $errors
     */
    private function string(array $data, string $key, int $max, array &$errors, ?string $path = null): ?string
    {
        if (!\array_key_exists($key, $data) || null === $data[$key]) {
            return null;
        }
        $path ??= $key;
        if (!\is_string($data[$key]) && !\is_int($data[$key]) && !\is_float($data[$key])) {
            $errors[$path] = 'Must be a string.';

            return null;
        }
        $value = (string) $data[$key];
        if (mb_strlen($value) > $max) {
            $errors[$path] = \sprintf('Longer than %d characters.', $max);

            return null;
        }

        return $value;
    }

    /**
     * @param array<mixed>          $data
     * @param array<string, string> $errors
     */
    private function identifier(array $data, string $key, int $max, array &$errors): ?string
    {
        $value = $this->string($data, $key, $max, $errors);
        if (null === $value || '' === $value) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9._:\/-]+$/', $value)) {
            $errors[$key] = 'Use letters, digits and . _ : / - only.';

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return list<array{class?: string, function?: string, file?: string, line?: int, in_app?: bool}>|null
     */
    private function frames(mixed $raw, array &$errors): ?array
    {
        if (null === $raw) {
            return null;
        }
        if (!\is_array($raw) || !array_is_list($raw)) {
            $errors['exception.frames'] = 'Must be an array of frame objects.';

            return null;
        }
        $frames = [];
        foreach (\array_slice($raw, 0, self::MAX_FRAMES) as $i => $frame) {
            if (!\is_array($frame)) {
                $errors["exception.frames[$i]"] = 'Each frame must be an object.';

                return null;
            }
            $clean = [];
            foreach (['class', 'function', 'file'] as $k) {
                if (isset($frame[$k]) && \is_string($frame[$k])) {
                    $clean[$k] = mb_substr($frame[$k], 0, 300);
                }
            }
            if (isset($frame['line']) && \is_int($frame['line'])) {
                $clean['line'] = $frame['line'];
            }
            $clean['in_app'] = (bool) ($frame['in_app'] ?? !str_contains($clean['file'] ?? '', '/vendor/'));
            $frames[] = $clean;
        }

        return $frames;
    }
}
