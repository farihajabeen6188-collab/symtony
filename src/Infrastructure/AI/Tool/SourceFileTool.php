<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tool;

use App\Application\TestLab\SourceContext;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Lets the test writer read ONLY the allow-listed source files of the tested project.
 */
#[AsTool(name: 'source_file', description: 'Read one allow-listed source file of the tested project by its relative path (for example "src/Inventory/InventoryService.php"). Other paths are refused.')]
final class SourceFileTool
{
    public function __construct(private readonly SourceContext $source)
    {
    }

    /**
     * @param string $path Relative path from the allow-list
     */
    public function __invoke(string $path): string
    {
        return $this->source->read($path) ?? \sprintf('Refused: %s is not an allowed file. Allowed: %s', $path, implode(', ', $this->source->allowed()));
    }
}
