<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FeedRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.config('feed.max_page_size')],
        ];
    }

    public function cursor(): ?int
    {
        $cursor = $this->validated('cursor');

        return $cursor === null ? null : (int) $cursor;
    }

    public function limit(): int
    {
        return (int) $this->validated('limit', config('feed.page_size'));
    }
}
