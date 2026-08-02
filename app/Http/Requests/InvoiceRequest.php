<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'invoiceable_type' => ['nullable', Rule::in(['deal', 'project'])],
            'invoiceable_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'status' => ['nullable', Rule::in(['draft', 'sent', 'partially_paid', 'paid', 'overdue', 'cancelled'])],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
        ];
    }
}
