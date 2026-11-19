<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InspectDownloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'url.required' => 'Вставьте ссылку на ролик.',
            'url.max' => 'Ссылка слишком длинная.',
        ];
    }
}
