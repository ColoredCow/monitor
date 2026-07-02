<?php

namespace App\Http\Requests;

use App\Support\CurrentOrganization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MonitorRequest extends FormRequest
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
        $organizationId = app(CurrentOrganization::class)->id();

        return [
            'name' => 'required|string',
            'url' => [
                'required',
                'url',
                Rule::unique('monitors', 'url')
                    ->withoutTrashed()
                    ->ignore($this->route('monitor')?->id),
            ],
            'monitorUptime' => 'required',
            'monitorDomain' => 'required',
            'uptimeCheckInterval' => 'required',
            'monitorGroupId' => [
                'nullable',
                Rule::exists('groups', 'id')
                    ->where('organization_id', $organizationId),
            ],
        ];
    }
}
