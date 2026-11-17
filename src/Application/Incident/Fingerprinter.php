<?php

declare(strict_types=1);

namespace App\Application\Incident;

use App\Domain\Event\Event;
use App\Domain\Event\EventType;
use App\Domain\Event\Severity;

/**
 * Deterministic grouping key. Same failure → same fingerprint, even when ids, numbers or line numbers change.
 *
 *   sha256( application | service | exception class (or "log") | normalized message | top N in-app frames as Class::function )
 */
final class Fingerprinter
{
    public const FRAMES = 3;

    /** Only these events open or grow incidents. Error spans and 5xx responses are evidence, not separate failures. */
    public function isCandidate(Event $event): bool
    {
        return match ($event->getType()) {
            EventType::Exception => $event->getSeverity()->rank() >= Severity::Warning->rank(),
            EventType::Log => $event->getSeverity()->rank() >= Severity::Warning->rank(),
            default => false,
        };
    }

    public function fingerprint(Event $event): string
    {
        return hash('sha256', implode('|', [
            $event->getApplication()->getId()->toRfc4122(),
            $event->getService(),
            $event->getExceptionClass() ?? 'log:'.$event->getSeverity()->value,
            $event->getNormalizedMessage(),
            implode(',', $this->topFrames($event)),
        ]));
    }

    /** @return list<string> */
    public function topFrames(Event $event): array
    {
        $out = [];
        foreach ($event->getStackTrace() ?? [] as $frame) {
            $class = $frame['class'] ?? '';
            // Instrumentation frames are never part of the failure's identity, whatever the SDK reported.
            if (!($frame['in_app'] ?? false) || str_starts_with($class, 'Symtony\\')) {
                continue;
            }
            // PHP 8.4 closure names embed line numbers ("{closure:Foo::bar():38}"); strip them.
            $function = (string) preg_replace('/(\{closure:.*?)(?::\d+)+\}$/', '$1}', $frame['function'] ?? '');
            $out[] = $class.'::'.$function;
            if (\count($out) >= self::FRAMES) {
                break;
            }
        }

        return $out;
    }

    public function title(Event $event): string
    {
        $class = $event->getExceptionClass();
        $message = trim($event->getExceptionMessage() ?? $event->getMessage());
        // Drop generic wrapper prefixes so the title shows the actual failure.
        $message = (string) preg_replace('/^An exception occurred (?:while executing a query|in the driver): /i', '', $message);
        if (null === $class) {
            return mb_substr($message, 0, 200);
        }
        $short = str_contains($class, '\\') ? substr((string) strrchr($class, '\\'), 1) : $class;

        return mb_substr($short.': '.$message, 0, 200);
    }
}
