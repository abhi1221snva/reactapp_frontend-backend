<?php

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class McaLeadStatusSeeder extends Seeder
{
    /**
     * MCA-specific lead statuses for Merchant Cash Advance industry
     * Run this seeder for each client database that has MCA enabled
     *
     * Usage: php artisan db:seed --class=McaLeadStatusSeeder --database=mysql_X
     */
    public function run()
    {
        $statuses = [
            // Pipeline Stages
            [
                'title' => 'New Lead',
                'lead_title_url' => 'new-lead',
                'status' => 'active',
                'color_code' => '#3498db',
                'display_order' => 1,
                'is_deleted' => 0
            ],
            [
                'title' => 'Contacted',
                'lead_title_url' => 'contacted',
                'status' => 'active',
                'color_code' => '#9b59b6',
                'display_order' => 2,
                'is_deleted' => 0
            ],
            [
                'title' => 'Docs Requested',
                'lead_title_url' => 'docs-requested',
                'status' => 'active',
                'color_code' => '#f39c12',
                'display_order' => 3,
                'is_deleted' => 0
            ],
            [
                'title' => 'Docs Received',
                'lead_title_url' => 'docs-received',
                'status' => 'active',
                'color_code' => '#e67e22',
                'display_order' => 4,
                'is_deleted' => 0
            ],
            [
                'title' => 'Submitted to Underwriting',
                'lead_title_url' => 'submitted-underwriting',
                'status' => 'active',
                'color_code' => '#1abc9c',
                'display_order' => 5,
                'is_deleted' => 0
            ],
            [
                'title' => 'In Underwriting',
                'lead_title_url' => 'in-underwriting',
                'status' => 'active',
                'color_code' => '#16a085',
                'display_order' => 6,
                'is_deleted' => 0
            ],
            [
                'title' => 'Approved',
                'lead_title_url' => 'approved',
                'status' => 'active',
                'color_code' => '#27ae60',
                'display_order' => 7,
                'is_deleted' => 0
            ],
            [
                'title' => 'Contract Sent',
                'lead_title_url' => 'contract-sent',
                'status' => 'active',
                'color_code' => '#2ecc71',
                'display_order' => 8,
                'is_deleted' => 0
            ],
            [
                'title' => 'Contract Signed',
                'lead_title_url' => 'contract-signed',
                'status' => 'active',
                'color_code' => '#00b894',
                'display_order' => 9,
                'is_deleted' => 0
            ],
            [
                'title' => 'Funded',
                'lead_title_url' => 'funded',
                'status' => 'active',
                'color_code' => '#00cec9',
                'display_order' => 10,
                'is_deleted' => 0
            ],
            // Negative Outcomes
            [
                'title' => 'Declined',
                'lead_title_url' => 'declined',
                'status' => 'active',
                'color_code' => '#e74c3c',
                'display_order' => 11,
                'is_deleted' => 0
            ],
            [
                'title' => 'Dead',
                'lead_title_url' => 'dead',
                'status' => 'active',
                'color_code' => '#7f8c8d',
                'display_order' => 12,
                'is_deleted' => 0
            ],
            [
                'title' => 'Not Interested',
                'lead_title_url' => 'not-interested',
                'status' => 'active',
                'color_code' => '#95a5a6',
                'display_order' => 13,
                'is_deleted' => 0
            ],
            // Follow-up Statuses
            [
                'title' => 'Callback Scheduled',
                'lead_title_url' => 'callback-scheduled',
                'status' => 'active',
                'color_code' => '#fd79a8',
                'display_order' => 14,
                'is_deleted' => 0
            ],
            [
                'title' => 'Needs More Docs',
                'lead_title_url' => 'needs-more-docs',
                'status' => 'active',
                'color_code' => '#fdcb6e',
                'display_order' => 15,
                'is_deleted' => 0
            ],
            // Renewal Pipeline
            [
                'title' => 'Renewal Eligible',
                'lead_title_url' => 'renewal-eligible',
                'status' => 'active',
                'color_code' => '#a29bfe',
                'display_order' => 16,
                'is_deleted' => 0
            ],
            [
                'title' => 'Renewal In Progress',
                'lead_title_url' => 'renewal-in-progress',
                'status' => 'active',
                'color_code' => '#6c5ce7',
                'display_order' => 17,
                'is_deleted' => 0
            ],
            // Special Statuses
            [
                'title' => 'Pre-Qualified',
                'lead_title_url' => 'pre-qualified',
                'status' => 'active',
                'color_code' => '#74b9ff',
                'display_order' => 18,
                'is_deleted' => 0
            ],
            [
                'title' => 'Conditionally Approved',
                'lead_title_url' => 'conditionally-approved',
                'status' => 'active',
                'color_code' => '#55efc4',
                'display_order' => 19,
                'is_deleted' => 0
            ],
            [
                'title' => 'In Default',
                'lead_title_url' => 'in-default',
                'status' => 'active',
                'color_code' => '#d63031',
                'display_order' => 20,
                'is_deleted' => 0
            ],
        ];

        foreach ($statuses as $status) {
            // Check if status already exists
            $exists = DB::table('crm_lead_status')
                ->where('lead_title_url', $status['lead_title_url'])
                ->exists();

            if (!$exists) {
                DB::table('crm_lead_status')->insert(array_merge($status, [
                    'created_at' => now(),
                    'updated_at' => now()
                ]));
            }
        }
    }
}
