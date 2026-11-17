<?php

declare(strict_types=1);

namespace Symtony\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Symtony\Tracer;

/**
 * DBAL middleware: one db_query span per executed statement (SQL sanitized, parameters never captured)
 * and transaction events for BEGIN / COMMIT / ROLLBACK.
 */
final class TracingMiddleware implements Middleware
{
    public function __construct(private readonly Tracer $tracer)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $tracer = $this->tracer;

        return new class($driver, $tracer) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly Tracer $tracer)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new TracingConnection(parent::connect($params), $this->tracer, (string) ($params['driver'] ?? 'dbal'));
            }
        };
    }
}

/**
 * @internal
 */
final class TracingConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly Tracer $tracer, private readonly string $driver)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new TracingStatement(parent::prepare($sql), $this->tracer, $sql, $this->driver);
    }

    public function query(string $sql): Result
    {
        return Tracing::run($this->tracer, $sql, $this->driver, fn (): Result => parent::query($sql));
    }

    public function exec(string $sql): int|string
    {
        return Tracing::run($this->tracer, $sql, $this->driver, fn (): int|string => parent::exec($sql));
    }

    public function beginTransaction(): void
    {
        parent::beginTransaction();
        $this->tracer->event('transaction', 'BEGIN', 'info', ['component' => 'database', 'db' => ['operation' => 'BEGIN', 'system' => $this->driver]], 'BEGIN');
    }

    public function commit(): void
    {
        parent::commit();
        $this->tracer->event('transaction', 'COMMIT', 'info', ['component' => 'database', 'db' => ['operation' => 'COMMIT', 'system' => $this->driver]], 'COMMIT');
    }

    public function rollBack(): void
    {
        parent::rollBack();
        $this->tracer->event('transaction', 'ROLLBACK', 'warning', ['component' => 'database', 'db' => ['operation' => 'ROLLBACK', 'system' => $this->driver]], 'ROLLBACK');
    }
}

/**
 * @internal
 */
final class TracingStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $statement, private readonly Tracer $tracer, private readonly string $sql, private readonly string $driver)
    {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        return Tracing::run($this->tracer, $this->sql, $this->driver, fn (): Result => parent::execute());
    }
}

/**
 * @internal
 */
final class Tracing
{
    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public static function run(Tracer $tracer, string $sql, string $driver, \Closure $work): mixed
    {
        $clean = SqlSanitizer::sanitize($sql);
        $info = SqlSanitizer::describe($clean);
        $span = $tracer->start($info['operation'].(null !== $info['table'] ? ' '.$info['table'] : ''), 'db_query', [
            'component' => 'database',
            'db' => ['system' => $driver, 'operation' => $info['operation'], 'table' => $info['table'], 'statement' => $clean],
        ]);
        try {
            $result = $work();
            $rows = $result instanceof Result ? self::rowCount($result) : (\is_int($result) ? $result : null);
            $tracer->end($span, null !== $rows ? ['db' => ['rows' => $rows]] : []);

            return $result;
        } catch (\Throwable $e) {
            $tracer->end($span, ['db' => ['error' => mb_substr($e->getMessage(), 0, 500), 'error_class' => $e::class]], true);
            throw $e;
        }
    }

    private static function rowCount(Result $result): ?int
    {
        try {
            $n = $result->rowCount();

            return \is_int($n) ? $n : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
