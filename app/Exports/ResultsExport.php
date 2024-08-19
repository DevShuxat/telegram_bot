<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;

class ResultsExport implements FromArray
{
    protected $data;

    // Constructor orqali natijalarni qabul qilamiz
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    // Ma'lumotlar array sifatida qaytariladi
    public function array(): array
    {
        return $this->data;
    }
}
