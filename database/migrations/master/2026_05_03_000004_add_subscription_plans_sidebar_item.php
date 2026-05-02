<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only insert if the sidebar_menu_items table exists (RBAC migration ran)
        if (!DB::connection('master')->getSchemaBuilder()->hasTable('sidebar_menu_items')) {
            return;
        }

        // Add route group for subscription plan management
        if (DB::connection('master')->getSchemaBuilder()->hasTable('route_groups')) {
            DB::connection('master')->table('route_groups')->insertOrIgnore([
                'key'          => 'admin.subscriptions',
                'name'         => 'Subscription Plans',
                'engine'       => 'shared',
                'url_patterns' => json_encode(['admin/subscription-plans', 'subscription/']),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        // Determine display_order — place after last SYSTEM ADMIN item in dialer engine
        $lastOrder = DB::connection('master')
            ->table('sidebar_menu_items')
            ->where('engine', 'dialer')
            ->where('section_label', 'SYSTEM ADMIN')
            ->max('display_order') ?? 900;

        // Insert for dialer engine
        DB::connection('master')->table('sidebar_menu_items')->insert([
            'engine'          => 'dialer',
            'section_label'   => 'SYSTEM ADMIN',
            'route_path'      => '/admin/subscription-plans',
            'label'           => 'Subscription Plans',
            'icon_name'       => 'Package',
            'route_group_key' => 'admin.subscriptions',
            'min_level'       => 9,
            'display_order'   => $lastOrder + 1,
            'is_active'       => true,
            'badge_source'    => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // Insert for CRM engine
        $lastOrderCrm = DB::connection('master')
            ->table('sidebar_menu_items')
            ->where('engine', 'crm')
            ->where('section_label', 'SYSTEM ADMIN')
            ->max('display_order') ?? 900;

        DB::connection('master')->table('sidebar_menu_items')->insert([
            'engine'          => 'crm',
            'section_label'   => 'SYSTEM ADMIN',
            'route_path'      => '/admin/subscription-plans',
            'label'           => 'Subscription Plans',
            'icon_name'       => 'Package',
            'route_group_key' => 'admin.subscriptions',
            'min_level'       => 9,
            'display_order'   => $lastOrderCrm + 1,
            'is_active'       => true,
            'badge_source'    => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('master')
            ->table('sidebar_menu_items')
            ->where('route_path', '/admin/subscription-plans')
            ->delete();

        if (DB::connection('master')->getSchemaBuilder()->hasTable('route_groups')) {
            DB::connection('master')
                ->table('route_groups')
                ->where('key', 'admin.subscriptions')
                ->delete();
        }
    }
};
