<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => 'required|string',
            'role' => ['required', Rule::in([
                Organization::ROLE_ADMIN,
                Organization::ROLE_MEMBER,
            ])],
        ];

        if ($this->isMethod('post')) {
            $rules['email'] = 'required|email';
            $rules['password'] = 'required|string|min:6';
        } else {
            $rules['email'] = 'required|email|unique:users,email,'.$this->user->id;
            $rules['password'] = 'nullable|string|min:6';
        }

        return $rules;
    }
}
