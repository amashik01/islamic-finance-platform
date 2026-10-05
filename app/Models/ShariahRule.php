<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A Shariah rule the platform relies on, with its source and review state. Software never sets APPROVED. */
class ShariahRule extends Model
{
    protected $guarded = ['id', 'status', 'scholar_review_status', 'reviewed_by', 'reviewed_at'];
}
