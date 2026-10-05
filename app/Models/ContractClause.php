<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractClause extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rule_codes' => 'array'];
    }
}
