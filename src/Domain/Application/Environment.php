<?php

declare(strict_types=1);

namespace App\Domain\Application;

enum Environment: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Development = 'development';
    case Testing = 'testing';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Short form used in ingestion token prefixes: sym_prod_…, sym_stag_… */
    public function tokenTag(): string
    {
        return substr($this->value, 0, 4);
    }
}
