<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A saved answer the admin can drop into a support reply. */
class SupportQuickReply extends Model
{
    protected $fillable = ['title', 'body'];
}
