<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class DynamicExport implements FromCollection, WithHeadings
{
    protected $results;

    public function __construct($results)
    {
        $this->results = $results;
    }

    public function collection()
    {
        return collect($this->results);
    }

    public function headings(): array
    {
        return array_keys((array)$this->results[0]);
    }
}
