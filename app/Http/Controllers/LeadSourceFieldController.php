<?php

namespace App\Http\Controllers;

use App\Model\Client\LeadSourceField;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadSourceFieldController extends Controller
{
    /** GET /lead-source/{sourceId}/fields */
    public function list(Request $request, int $sourceId)
    {
        try {
            $clientId = $request->auth->parent_id;
            $fields = LeadSourceField::on("mysql_$clientId")
                ->where('lead_source_id', $sourceId)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get()
                ->toArray();
            return $this->successResponse('Lead source fields', $fields);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to list fields', [$e->getMessage()], $e, 500);
        }
    }

    /** PUT /lead-source/{sourceId}/fields */
    public function create(Request $request, int $sourceId)
    {
        $clientId = $request->auth->parent_id;

        $this->validate($request, [
            'field_name'       => [
                'required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/i',
                Rule::unique("mysql_$clientId.crm_lead_source_fields")
                    ->where('lead_source_id', $sourceId),
            ],
            'field_label'      => 'required|string|max:255',
            'field_type'       => 'required|in:text,email,list',
            'mapped_field_key' => 'nullable|string|max:100',
            'is_required'      => 'boolean',
            'description'      => 'nullable|string',
            'allowed_values'   => 'nullable|array',
            'allowed_values.*' => 'string',
            'display_order'    => 'integer',
        ]);

        try {
            $field = new LeadSourceField();
            $field->setConnection("mysql_$clientId");
            $field->fill([
                'lead_source_id'   => $sourceId,
                'field_name'       => $request->input('field_name'),
                'mapped_field_key' => $request->input('mapped_field_key') ?: null,
                'field_label'      => $request->input('field_label'),
                'field_type'       => $request->input('field_type', 'text'),
                'is_required'      => $request->input('is_required', false),
                'description'      => $request->input('description'),
                'allowed_values'   => $request->input('allowed_values'),
                'display_order'    => $request->input('display_order', 0),
            ]);
            $field->saveOrFail();
            return $this->successResponse('Field created', $field->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to create field', [$e->getMessage()], $e, 500);
        }
    }

    /** POST /lead-source/{sourceId}/fields/{fieldId} */
    public function update(Request $request, int $sourceId, int $fieldId)
    {
        $clientId = $request->auth->parent_id;

        $this->validate($request, [
            'field_name'       => [
                'sometimes', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/i',
                Rule::unique("mysql_$clientId.crm_lead_source_fields")
                    ->where('lead_source_id', $sourceId)
                    ->ignore($fieldId),
            ],
            'field_label'      => 'sometimes|string|max:255',
            'field_type'       => 'sometimes|in:text,email,list',
            'mapped_field_key' => 'nullable|string|max:100',
            'is_required'      => 'boolean',
            'description'      => 'nullable|string',
            'allowed_values'   => 'nullable|array',
            'allowed_values.*' => 'string',
            'display_order'    => 'integer',
        ]);

        try {
            $field = LeadSourceField::on("mysql_$clientId")
                ->where('lead_source_id', $sourceId)
                ->findOrFail($fieldId);

            $data = $request->only([
                'field_name', 'field_label', 'field_type',
                'mapped_field_key', 'is_required', 'description',
                'allowed_values', 'display_order',
            ]);
            // Allow clearing the mapping by sending null explicitly
            if ($request->has('mapped_field_key')) {
                $data['mapped_field_key'] = $request->input('mapped_field_key') ?: null;
            }
            $field->fill($data);
            $field->saveOrFail();
            return $this->successResponse('Field updated', $field->toArray());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->failResponse('Field not found', [], $e, 404);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to update field', [$e->getMessage()], $e, 500);
        }
    }

    /** DELETE /lead-source/{sourceId}/fields/{fieldId} */
    public function delete(Request $request, int $sourceId, int $fieldId)
    {
        $clientId = $request->auth->parent_id;
        try {
            $field = LeadSourceField::on("mysql_$clientId")
                ->where('lead_source_id', $sourceId)
                ->findOrFail($fieldId);
            $field->delete();
            return $this->successResponse('Field deleted', []);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->failResponse('Field not found', [], $e, 404);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to delete field', [$e->getMessage()], $e, 500);
        }
    }

    /** POST /lead-source/{sourceId}/fields/reorder */
    public function reorder(Request $request, int $sourceId)
    {
        $clientId = $request->auth->parent_id;
        $this->validate($request, [
            'order'   => 'required|array',
            'order.*' => 'integer',
        ]);

        try {
            foreach ($request->input('order') as $position => $fieldId) {
                LeadSourceField::on("mysql_$clientId")
                    ->where('lead_source_id', $sourceId)
                    ->where('id', $fieldId)
                    ->update(['display_order' => $position]);
            }
            return $this->successResponse('Order saved', []);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to reorder fields', [$e->getMessage()], $e, 500);
        }
    }
}
