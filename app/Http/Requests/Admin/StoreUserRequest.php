<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:100', 'regex:/^[\p{L}\s\'\-\.]+$/u'],
            'username'      => ['required', 'string', 'min:3', 'max:30', 'unique:users,username', 'regex:/^[a-zA-Z0-9_]+$/'],
            'email'         => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'mobile'        => ['nullable', 'digits:10', 'regex:/^[6-9]\d{9}$/'],
            'password'      => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'post'          => ['nullable', 'string', 'max:100', 'regex:/^[\p{L}\s\'\-\.&\/\(\)]+$/u'],
            'role'          => ['required', 'in:admin,operator,viewer'],
            'privileges'    => ['nullable', 'array'],
            'privileges.*'  => ['string', 'regex:/^[a-z_]+(\.[a-z_]+)?$/'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'section_id'    => ['nullable', 'integer', 'exists:sections,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex'       => 'Name may only contain letters, spaces, hyphens, apostrophes, and dots.',
            'username.regex'   => 'Username may only contain letters, numbers, and underscores.',
            'mobile.digits'    => 'Mobile number must be exactly 10 digits.',
            'mobile.regex'     => 'Enter a valid Indian mobile number starting with 6–9.',
            'post.regex'       => 'Post/designation contains invalid characters.',
            'privileges.*.regex' => 'Invalid privilege format. Use dot-notation e.g. documents.delete',
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
