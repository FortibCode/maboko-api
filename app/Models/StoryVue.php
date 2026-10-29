<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une personne a vu une story, une seule fois.
 *
 * @property int $id
 * @property int $story_id
 * @property int $user_id
 * @property-read User $utilisateur
 */
class StoryVue extends Model
{
    protected $table = 'story_vues';

    protected $fillable = ['story_id', 'user_id'];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /** @return BelongsTo<User, $this> */
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
