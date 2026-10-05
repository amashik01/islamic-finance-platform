<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractTemplate extends Model
{
    protected $guarded = ['id'];

    public function versions(): HasMany
    {
        return $this->hasMany(ContractTemplateVersion::class, 'template_id');
    }
}
