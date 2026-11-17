<?php

declare(strict_types=1);

namespace Symtony\Symfony;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symtony\Trace\Span;
use Symtony\Tracer;

/**
 * Request lifecycle → spans: http_request (root), controller (child), exception events, http_response.
 * Flushes the buffer on kernel.terminate (after the response is sent) and console.terminate.
 */
final class KernelSubscriber implements EventSubscriberInterface
{
    private ?Span $root = null;
    private ?Span $controller = null;
    private ?Span $command = null;

    public function __construct(private readonly Tracer $tracer)
    {
    }

    public static function getSubscribedEvents(): array
    {
        $events = [
            KernelEvents::REQUEST => ['onRequest', 4096],
            KernelEvents::CONTROLLER => ['onController', -4096],
            KernelEvents::EXCEPTION => ['onException', 4096],
            KernelEvents::RESPONSE => ['onResponse', -4096],
            KernelEvents::TERMINATE => ['onTerminate', -4096],
        ];
        if (class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::COMMAND] = ['onCommand', 4096];
            $events[ConsoleEvents::ERROR] = ['onConsoleError', 4096];
            $events[ConsoleEvents::TERMINATE] = ['onConsoleTerminate', -4096];
        }

        return $events;
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        // Events are delivered after the response; a client that disconnects early must not abort that work
        // (otherwise the request root span — closed on kernel.terminate — would be lost).
        ignore_user_abort(true);
        $this->tracer->context()->begin($request->headers->get('traceparent'), $request->headers->get('X-Request-Id'));
        $this->root = $this->tracer->start($request->getMethod().' '.$request->getPathInfo(), 'http_request', [
            'component' => 'http',
            // Path only: query strings often carry tokens.
            'http' => ['method' => $request->getMethod(), 'path' => $request->getPathInfo()],
        ]);
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest() || null === $this->root) {
            return;
        }
        $name = self::controllerName($event->getController());
        $attributes = $event->getRequest()->attributes;
        // Routes can opt out entirely: defaults: { _symtony_ignore: true }
        if (true === $attributes->get('_symtony_ignore')) {
            $this->tracer->discard($this->root);
            $this->tracer->context()->mute();
            $this->root = null;

            return;
        }
        $route = $attributes->get('_route');
        if (\is_string($route)) {
            $this->root->context['http']['route'] = $route;
        }
        // Routes can declare the logical service they belong to: defaults: { _symtony_service: payment-api }
        $service = $attributes->get('_symtony_service');
        if (\is_string($service)) {
            $this->tracer->context()->setService($service);
        }
        $this->controller = $this->tracer->start($name, 'controller', ['component' => 'controller', 'controller' => $name]);
    }

    public function onException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
        $this->tracer->exception($e, $status >= 500 ? 'error' : 'warning', ['component' => 'exception', 'http' => ['status' => $status]]);
        if (null !== $this->controller && $status >= 500) {
            $this->tracer->end($this->controller, error: true);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || null === $this->root) {
            return;
        }
        $response = $event->getResponse();
        $status = $response->getStatusCode();
        if (null !== $this->controller) {
            $this->tracer->end($this->controller, error: $status >= 500 ? true : null);
            $this->controller = null;
        }
        $this->root->context['http']['status'] = $status;
        if ($status >= 500) {
            $this->root->error = true;
            $this->root->severity = 'error';
        } elseif ($status >= 400) {
            $this->root->severity = 'warning';
        }
        $this->tracer->event('http_response', \sprintf('%d %s', $status, $this->root->name), $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info'), [
            'component' => 'http',
            'http' => ['status' => $status, 'method' => $event->getRequest()->getMethod(), 'path' => $event->getRequest()->getPathInfo()],
        ], 'HTTP '.$status);

        $response->headers->set('X-Request-Id', (string) $this->tracer->context()->requestId());
        $response->headers->set('traceresponse', $this->tracer->context()->traceparent($this->root));
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (null !== $this->root) {
            $this->tracer->end($this->root);
            $this->root = null;
        }
        // Deliver after the client has its response: the SDK must never add request latency.
        if (\function_exists('frankenphp_finish_request')) {
            frankenphp_finish_request();
        } elseif (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (\function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        $this->tracer->flush();
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $this->tracer->context()->begin();
        $name = $event->getCommand()?->getName() ?? 'console';
        $this->command = $this->tracer->start('console '.$name, 'service_call', ['component' => 'console', 'command' => $name]);
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->tracer->exception($event->getError(), 'error', ['component' => 'console']);
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if (null !== $this->command) {
            $this->tracer->end($this->command, ['exit_code' => $event->getExitCode()], 0 !== $event->getExitCode());
            $this->command = null;
        }
        $this->tracer->flush();
    }

    public static function controllerName(mixed $controller): string
    {
        return match (true) {
            \is_array($controller) && \is_object($controller[0]) => $controller[0]::class.'::'.$controller[1],
            \is_array($controller) => $controller[0].'::'.$controller[1],
            \is_object($controller) && !$controller instanceof \Closure => $controller::class.'::__invoke',
            \is_string($controller) => $controller,
            default => 'closure',
        };
    }
}
