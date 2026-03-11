<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CRUD service for the crm_labels field-configuration table.
 */
class LeadFieldService
{
    /** Return all active field definitions ordered by display_order. */
    public function getActiveFields(string $clientId): array
    {
        return DB::connection("mysql_{$clientId}")
            ->table('crm_labels')
            ->where('status', true)
            ->orderBy('display_order')
            ->get()
            ->toArray();
    }

    /** Return all field definitions (including inactive), paginated if needed. */
    public function getAllFields(string $clientId, int $start = 0, int $limit = 0): array
    {
        $query = DB::connection("mysql_{$clientId}")
            ->table('crm_labels')
            ->orderBy('display_order');

        $total = (clone $query)->count();

        if ($limit > 0) {
            $query->offset($start)->limit($limit);
        }

        return [
            'total' => $total,
            'data'  => $query->get()->toArray(),
        ];
    }

    /** Create a new field definition in crm_labels. */
    public function create(string $clientId, array $data): object
    {
        $now  = Carbon::now();
        $id   = DB::connection("mysql_{$clientId}")
            ->table('crm_labels')
            ->insertGetId([
                'label_name'    => $data['label_name'],
                'field_key'     => $data['field_key'],
                'field_type'    => $data['field_type']    ?? 'text',
                'section'       => $data['section']       ?? 'general',
                'options'       => isset($data['options'])
                    ? (is_array($data['options']) ? json_encode($data['options']) : $data['options'])
                    : null,
                'placeholder'   => $data['placeholder']   ?? null,
                'conditions'    => isset($data['conditions'])
                    ? (is_array($data['conditions']) ? json_encode($data['conditions']) : $data['conditions'])
                    : null,
                'required'      => !empty($data['required']),
                'display_order' => $data['display_order'] ?? $this->nextOrder($clientId),
                'status'        => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);

        return DB::connection("mysql_{$clientId}")->table('crm_labels')->where('id', $id)->first();
    }

    /** Update an existing field definition. */
    public function update(string $clientId, int $id, array $data): object
    {
        $update = ['updated_at' => Carbon::now()];

        $scalars = ['label_name', 'field_type', 'section', 'placeholder', 'required', 'display_order', 'status'];
        foreach ($scalars as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        foreach (['options', 'conditions'] as $json) {
            if (array_key_exists($json, $data)) {
                $update[$json] = is_array($data[$json])
                    ? json_encode($data[$json])
                    : $data[$json];
            }
        }

        DB::connection("mysql_{$clientId}")->table('crm_labels')->where('id', $id)->update($update);
        return DB::connection("mysql_{$clientId}")->table('crm_labels')->where('id', $id)->first();
    }

    /** Hard-delete a field definition. Also removes all stored values for this field. */
    public function delete(string $clientId, int $id): void
    {
        $row = DB::connection("mysql_{$clientId}")->table('crm_labels')->where('id', $id)->first();
        if (!$row) {
            return;
        }

        // Remove EAV values for this field key
        DB::connection("mysql_{$clientId}")
            ->table('crm_lead_values')
            ->where('field_key', $row->field_key)
            ->delete();

        DB::connection("mysql_{$clientId}")->table('crm_labels')->where('id', $id)->delete();
    }

    /** Reorder fields by an ordered array of IDs. */
    public function reorder(string $clientId, array $orderedIds): void
    {
        $order = 1;
        foreach ($orderedIds as $id) {
            DB::connection("mysql_{$clientId}")
                ->table('crm_labels')
                ->where('id', (int)$id)
                ->update(['display_order' => $order++, 'updated_at' => Carbon::now()]);
        }
    }

    private function nextOrder(string $clientId): int
    {
        return (int)(DB::connection("mysql_{$clientId}")->table('crm_labels')->max('display_order') ?? 0) + 1;
    }
}
