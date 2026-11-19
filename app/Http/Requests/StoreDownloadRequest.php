<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDownloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Guests may create tasks too; limits are applied by DownloadService and the rate limiter.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Older clients did not send "type" (audio only).
        if (! $this->filled('type')) {
            $this->merge(['type' => 'audio']);
        }
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
            'type' => ['required', Rule::in(['audio', 'video'])],
            'format' => ['exclude_unless:type,audio', 'required', Rule::in(config('downloads.formats'))],
            'bitrate' => ['exclude_unless:type,audio', 'required', Rule::in(config('downloads.bitrates'))],
            'quality' => ['exclude_unless:type,video', 'required', Rule::in(config('downloads.qualities'))],
        ];
    }

    public function messages(): array
    {
        return [
            'url.required' => 'Вставьте ссылку на ролик.',
            'url.max' => 'Ссылка слишком длинная.',
            'type.in' => 'Выберите, что скачать: звук или видео.',
            'format.*' => 'Недопустимый формат.',
            'bitrate.*' => 'Недопустимый битрейт.',
            'quality.*' => 'Недопустимое качество видео.',
        ];
    }
}
