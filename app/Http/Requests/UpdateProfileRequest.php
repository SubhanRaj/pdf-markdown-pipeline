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
            'mobile'   => ['nullable', 'digits:10', 'regex:/^[6-9]\d{9}$/'],
            'password' => ['nullable', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'post'     => ['nullable', 'string', 'max:100', 'regex:/^[\p{L}\s\'\-\.&\/\(\)]+$/u'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex'     => 'Name may only contain letters, spaces, hyphens, apostrophes, and dots.',
            'username.regex' => 'Username may only contain letters, numbers, and underscores.',
            'mobile.digits'  => 'Mobile number must be exactly 10 digits.',
            'mobile.regex'   => 'Enter a valid Indian mobile number starting with 6–9.',
            'post.regex'     => 'Post/designation contains invalid characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name'     => strip_tags(trim($this->name ?? '')),
            'username' => strtolower(strip_tags(trim($this->username ?? ''))),
            'email'    => strtolower(strip_tags(trim($this->email ?? ''))),
            'mobile'   => preg_replace('/\D/', '', $this->mobile ?? ''),
            'post'     => strip_tags(trim($this->post ?? '')),
        ]);
    }
}
