<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Announcement extends Model
{
    public const CATEGORIES = ['Weather Advisory', 'Evacuation', 'Traffic Advisory', 'Information', 'Relief Operations'];

    protected $fillable = ['title', 'category', 'description', 'image_path', 'posted_by'];

    public function author(): MorphTo { return $this->morphTo('author', 'posted_by_type', 'posted_by'); }
}
