<?php

namespace App\Services;

use App\Models\CustomField;
use App\Models\CustomFieldValue;

class CustomFieldService
{
    /**
     * Return custom-field definitions for an entity type merged with the
     * current values for a specific record.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forEntity(string $entityType, int $entityId): array
    {
        $fields = CustomField::where('entity_type', $entityType)->orderBy('order')->get();

        $values = CustomFieldValue::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->pluck('value', 'custom_field_id');

        return $fields->map(fn (CustomField $f) => [
            'id' => $f->id,
            'name' => $f->name,
            'type' => $f->type,
            'required' => $f->required,
            'is_visible' => $f->is_visible,
            'options' => $f->options ?? [],
            'value' => $values[$f->id] ?? null,
        ])->all();
    }
}
