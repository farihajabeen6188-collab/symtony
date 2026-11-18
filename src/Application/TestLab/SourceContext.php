<?php

declare(strict_types=1);

namespace App\Application\TestLab;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read access to the allow-listed source files of the tested project — and nothing else.
 * Every file read is recorded so the UI can show exactly what was shared.
 */
final class SourceContext
{
    /** @var list<string> */
    private array $read = [];

    /** @param array{project_dir: string, test_namespace: string, base_class: string, reference_patch: string, source_paths: list<string>, jobs_dir: string, timeout_seconds: int} $config */
    public function __construct(#[Autowire('%symtony.test_lab%')] private readonly array $config)
    {
    }

    /** @return list<string> */
    public function allowed(): array
    {
        return $this->config['source_paths'];
    }

    public function read(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (!\in_array($path, $this->config['source_paths'], true)) {
            return null;
        }
        $file = $this->config['project_dir'].'/'.$path;
        $content = is_file($file) ? file_get_contents($file) : false;
        if (false === $content) {
            return null;
        }
        $this->read[] = $path;

        return $content;
    }

    /** @return list<string> */
    public function filesRead(): array
    {
        return array_values(array_unique($this->read));
    }

    public function reset(): void
    {
        $this->read = [];
    }

    public function config(string $key): mixed
    {
        return $this->config[$key];
    }
}
