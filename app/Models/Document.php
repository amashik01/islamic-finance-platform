<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    protected $guarded = ['id', 'verification_status', 'verified_by', 'verified_at', 'disk', 'path'];

    protected function casts(): array
    {
        return ['category'=>\App\Enums\DocumentCategory::class,'verification_status'=>\App\Enums\DocumentVerificationStatus::class,'verified_at'=>'datetime'];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'previous_version_id');
    }
}
