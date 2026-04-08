<?php

namespace App\Http\Controllers;

use App\Models\Client\CrmVisibilitySetting;
use App\Services\CacheService;
use Illuminate\Http\Request;

class CrmVisibilitySettingsController extends Controller
{
    /**
     * GET /crm/visibility-settings
     * Returns the current visibility settings for the client.
     */
    public function show(Request $request)
    {
        $clientId = (int) $request->auth->parent_id;
        $conn     = "mysql_{$clientId}";

        $settings = CrmVisibilitySetting::on($conn)->first();

        if (!$settings) {
            return $this->successResponse('Visibility settings (defaults).', [
                'enable_team_visibility'           => false,
                'enable_hierarchy_visibility'      => false,
                'enable_creator_visibility'        => true,
                'enable_multi_assignee_visibility' => true,
                'non_admin_min_level'              => 7,
            ]);
        }

        return $this->successResponse('Visibility settings.', $settings->toArray());
    }

    /**
     * PUT /crm/visibility-settings
     * Update the client's visibility settings.
     */
    public function update(Request $request)
    {
        $this->validate($request, [
            'enable_team_visibility'           => 'boolean',
            'enable_hierarchy_visibility'      => 'boolean',
            'enable_creator_visibility'        => 'boolean',
            'enable_multi_assignee_visibility' => 'boolean',
            'non_admin_min_level'              => 'integer|min:1|max:11',
        ]);

        $clientId = (int) $request->auth->parent_id;
        $conn     = "mysql_{$clientId}";

        $settings = CrmVisibilitySetting::on($conn)->first();

        if (!$settings) {
            $settings = new CrmVisibilitySetting();
            $settings->setConnection($conn);
        }

        $fillable = ['enable_team_visibility', 'enable_hierarchy_visibility', 'enable_creator_visibility', 'enable_multi_assignee_visibility', 'non_admin_min_level'];

        foreach ($fillable as $field) {
            if ($request->has($field)) {
                $settings->$field = $request->input($field);
            }
        }

        $settings->updated_by = (int) $request->auth->id;
        $settings->save();

        // Clear cached settings
        CacheService::tenantForget($clientId, 'visibility_settings');

        return $this->successResponse('Visibility settings updated.', $settings->toArray());
    }
}
