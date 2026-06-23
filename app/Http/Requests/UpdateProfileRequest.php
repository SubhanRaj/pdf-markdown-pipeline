<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'name'     => ['required', 'string', 'max:100', 'regex:/^[\p{L}\s\'\-\.]+$/u'],
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-zA-Z0-9_]+$/', "unique:users,username,{$userId}"],
            'email'    => ['required', 'email:rfc', 'max:255', "unique:users,email,{$userId}"],
            'mobile'   => ['nullable', 'digits:10'],
            'password' => ['nullable', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'post'     => ['nullable', 'string', 'max:100', 'regex:/^[\p{L}\s\'\-\.&\/\(\)]+$/u'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex'     => 'Name may only contain letters, spaces, hyphens, apostrophes, and dots.',
            'username.regex' => 'Username may only contain letters, numbers, and underscores.',
            'mobile.digits'  => 'Mobile number must be exactly 10 digits (country code stripped automatically).',
            'post.regex'     => 'Post/designation contains invalid characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name'     => strip_tags(trim($this->name ?? '')),
            'username' => strtolower(strip_tags(trim($this->username ?? ''))),
            'email'    => strtolower(strip_tags(trim($this->email ?? ''))),
            'mobile'   => \App\Http\Requests\Admin\StoreUserRequest::sanitizeMobile($this->mobile ?? ''),
            'post'     => strip_tags(trim($this->post ?? '')),
        ]);
    }
}
