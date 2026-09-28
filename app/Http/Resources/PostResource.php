<?php

namespace App\Http\Resources;

use App\Data\PostView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property PostView $resource
 */
class PostResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'content' => $this->resource->content,
            'created_at' => $this->resource->createdAt->toIso8601String(),
            'author' => [
                'id' => $this->resource->authorId,
                'name' => $this->resource->authorName,
            ],
            'likes_count' => $this->resource->likesCount,
            'liked_by_me' => $this->resource->likedByMe,
        ];
    }
}
