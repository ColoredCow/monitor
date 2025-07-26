<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'email' => 'required|email|unique:users,email' . ($this->user ? ',' . $this->user->id : ''),
        ];
        if ($this->isMethod('post')) {
            $rules['password'] = 'required|string|min:6';
        } else if ($this->isMethod('put') || $this->isMethod('patch')) {
            $rules['password'] = 'nullable|string|min:6';
        }
        return $rules;
    }
}
