<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],

            // The name that goes on a cover, which is routinely not the name
            // on the account: a pen name, a name with credentials after it,
            // a married name the books were not published under.
            'author_name' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable', 'url', 'max:255'],

            'default_book_type' => [
                'nullable',
                'string',
                Rule::in(array_keys(config('booktypes'))),
            ],
            // A goal, not a quota. Capped at something a person could
            // plausibly write rather than left open to a typo.
            'daily_word_goal' => ['nullable', 'integer', 'min:50', 'max:20000'],
        ];
    }
}
