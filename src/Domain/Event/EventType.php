<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * What an event describes. Drives timeline markers and correlation rules.
 */
enum EventType: string
{
    case HttpRequest = 'http_request';
    case HttpResponse = 'http_response';
    case Controller = 'controller';
    case ServiceCall = 'service_call';
    case DbQuery = 'db_query';
    case Transaction = 'transaction';
    case Cache = 'cache';
    case ExternalHttp = 'external_http';
    case Queue = 'queue';
    case Exception = 'exception';
    case Log = 'log';
    case Deploy = 'deploy';

    public function label(): string
    {
        return match ($this) {
            self::HttpRequest => 'HTTP request',
            self::HttpResponse => 'HTTP response',
            self::Controller => 'Controller',
            self::ServiceCall => 'Service call',
            self::DbQuery => 'Database query',
            self::Transaction => 'Transaction',
            self::Cache => 'Cache',
            self::ExternalHttp => 'External HTTP',
            self::Queue => 'Queue',
            self::Exception => 'Exception',
            self::Log => 'Log',
            self::Deploy => 'Deploy',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::HttpRequest => 'lucide:arrow-down-to-line',
            self::HttpResponse => 'lucide:arrow-up-from-line',
            self::Controller => 'lucide:route',
            self::ServiceCall => 'lucide:box',
            self::DbQuery => 'lucide:database',
            self::Transaction => 'lucide:layers',
            self::Cache => 'lucide:zap',
            self::ExternalHttp => 'lucide:globe',
            self::Queue => 'lucide:inbox',
            self::Exception => 'lucide:octagon-alert',
            self::Log => 'lucide:text',
            self::Deploy => 'lucide:rocket',
        };
    }
}
