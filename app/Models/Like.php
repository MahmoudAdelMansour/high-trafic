<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot-like row with a composite (user_id, post_id) primary key. Writes go
 * through LikeRepository with insertOrIgnore; the model exists for relations.
 */
class Like extends Model
{
    public $incrementing = false;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
