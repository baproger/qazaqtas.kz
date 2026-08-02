<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomFieldValueController extends Controller
{
    public function sync(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'entity_type' => ['required', Rule::in(['deal', 'project', 'task'])],
            'entity_id' => ['required', 'integer'],
            'values' => ['array'],
        ]);

        $entity = match ($validated['entity_type']) {
            'deal' => \App\Models\Deal::find($validated['entity_id']),
            'project' => \App\Models\Project::find($validated['entity_id']),
            'task' => \App\Models\Task::find($validated['entity_id']),
            default => null,
        };
        abort_unless($entity && $request->user()->can('update', $entity), 403);

        DB::transaction(function () use ($validated) {
            $fields = CustomField::where('entity_type', $validated['entity_type'])->get()->keyBy('id');

            foreach (($validated['values'] ?? []) as $fieldId => $value) {
                if (! $fields->has((int) $fieldId)) {
                    continue;
                }
                CustomFieldValue::updateOrCreate(
                    [
                        'custom_field_id' => (int) $fieldId,
                        'entity_type' => $validated['entity_type'],
                        'entity_id' => $validated['entity_id'],
                    ],
                    ['value' => is_bool($value) ? ($value ? '1' : '0') : (is_array($value) ? json_encode($value) : $value)]
                );
            }
        });

        return back()->with('success', 'Дополнительные поля сохранены.');
    }
}
