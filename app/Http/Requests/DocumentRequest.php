<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'documentable_type' => ['required', Rule::in(['deal', 'project'])],
            'documentable_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:20480', // 20MB
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,gif,zip,rar,txt,csv'],
        ];
    }
}
