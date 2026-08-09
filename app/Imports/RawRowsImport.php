<?php

declare(strict_types=1);

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Reads a spreadsheet as raw rows with positional column access - no
 * heading row assumed, no column mapping. Used by SyncSourcesAction,
 * whose Excel column layout (date/nrft/ppm/defects) is fixed and
 * positional, not named.
 */
final class RawRowsImport implements ToCollection
{
    /**
     * @var Collection<int, Collection<int, mixed>>|null
     */
    private ?Collection $rows = null;

    /**
     * @param  Collection<int, Collection<int, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        $this->rows = $rows;
    }

    /**
     * @return Collection<int, mixed>
     */
    public function rows(): Collection
    {
        return $this->rows ?? collect();
    }
}
