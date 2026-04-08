<?php

namespace App\Http\Controllers;

use App\Models\UserHierarchy;
use App\Services\CacheService;
use Illuminate\Http\Request;

class UserHierarchyController extends Controller
{
    /**
     * GET /admin/user-hierarchy
     * List all hierarchy relationships for the client.
     */
    public function index(Request $request)
    {
        $clientId = (int) $request->auth->parent_id;

        $relationships = UserHierarchy::where('client_id', $clientId)
            ->orderBy('manager_id')
            ->orderBy('user_id')
            ->get();

        return $this->successResponse('Hierarchy retrieved.', $relationships->toArray());
    }

    /**
     * POST /admin/user-hierarchy
     * Create a manager → agent relationship.
     */
    public function store(Request $request)
    {
        $this->validate($request, [
            'user_id'    => 'required|integer',
            'manager_id' => 'required|integer',
        ]);

        $clientId = (int) $request->auth->parent_id;

        // Prevent self-assignment
        if ((int) $request->input('user_id') === (int) $request->input('manager_id')) {
            return $this->failResponse('A user cannot be their own manager.', [], null, 422);
        }

        $relationship = UserHierarchy::updateOrCreate(
            [
                'user_id'    => (int) $request->input('user_id'),
                'manager_id' => (int) $request->input('manager_id'),
                'client_id'  => $clientId,
            ]
        );

        // Clear cached subordinate data
        CacheService::tenantForget($clientId, "subordinates_{$request->input('manager_id')}");

        return $this->successResponse('Hierarchy relationship created.', ['relationship' => $relationship->toArray()]);
    }

    /**
     * DELETE /admin/user-hierarchy/{id}
     * Remove a hierarchy relationship.
     */
    public function destroy(Request $request, $id)
    {
        $clientId = (int) $request->auth->parent_id;

        $relationship = UserHierarchy::where('id', $id)
            ->where('client_id', $clientId)
            ->first();

        if (!$relationship) {
            return $this->failResponse('Relationship not found.', [], null, 404);
        }

        $managerId = $relationship->manager_id;
        $relationship->delete();

        // Clear cached subordinate data
        CacheService::tenantForget($clientId, "subordinates_{$managerId}");

        return $this->successResponse('Hierarchy relationship removed.');
    }
}
