<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostMedia extends Model
{
    protected $table = 'post_medias';

    protected $fillable = ['post_id', 'url', 'type', 'ordre'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
