<?php

use Illuminate\Database\Seeder;
use App\Model\Master\ModuleComponent;
use Illuminate\Database\Eloquent\ModelNotFoundException;


class ModuleComponentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return voi
     */
    public function run()
    {
        $component = [
            ['key' => 'dashboard', 'name' => 'Dashboard', 'is_active' => 1, 'url' => 'dashboard', 'logo' => 'fa fa-home', 'new_logo' => 'icon-Home', 'min_level' => 1, 'display_order' => 100, 'parent_key' => '', 'arrow' => 0],
            ['key' => 'start-dialing', 'name' => 'Start Dialing', 'is_active' => 1, 'url' => 'start-dialing', 'logo' => 'fa fa-phone', 'new_logo' => 'icon-Phone', 'min_level' => 1, 'display_order' => 200, 'parent_key' => '', 'arrow' => 0],
            ['key' => 'manage-extensions', 'name' => 'User Management', 'is_active' => 1, 'url' => 'manage-extensions', 'logo' => 'fa fa-users','new_logo' => 'icon-Add-user', 'min_level' => 1, 'display_order' => 300, 'parent_key' => '', 'arrow' => 1],
            ['key' => 'manage-campaigns', 'name' => 'Campaign', 'is_active' => 1, 'url' => 'manage-campaigns', 'logo' => 'fa fa-bullhorn', 'new_logo' => 'icon-Cardboard-vr', 'min_level' => 5, 'display_order' => 400, 'parent_key' => '', 'arrow' => 1],
            ['key' => 'lead-management', 'name' => 'Lead Management', 'is_active' => 1, 'url' => 'main-menu-lead-management', 'logo' => 'fa fa-file-excel-o', 'new_logo' => 'icon-file-excel', 'min_level' => 1, 'display_order' => 500, 'parent_key' => '', 'arrow' => 1],
            ['key' => 'inbound-configuration', 'name' => 'Inbound Setting', 'is_active' => 1, 'url' => 'inbound-configuration', 'logo' => 'fa fa-th', 'new_logo' => 'icon-Layout-4-blocks', 'min_level' => 5, 'display_order' => 600, 'parent_key' => '', 'arrow' => 1],
            ['key' => 'configuration', 'name' => 'Configuration', 'is_active' => 1, 'url' => 'main-menu-configuration', 'logo' => 'fa fa-gears', 'new_logo' =>'icon-Settings-2', 'min_level' => 1, 'display_order' => 700, 'parent_key' => '', 'arrow' => 1],
            ['key' => 'call-data-reports', 'name' => 'Reports', 'is_active' => 1, 'url' => 'main-menu-call-data-reports', 'logo' => 'fa fa-table', 'new_logo' => 'icon-Layout-grid', 'min_level' => 1, 'display_order' => 800, 'parent_key' => '', 'arrow' => 1],
            ['key' => 'system-configuration', 'name' => 'System Setting', 'is_active' => 1, 'url' => 'main-menu-system-configuration', 'logo' => 'fa fa-gears', 'new_logo' =>'icon-Settings-2', 'min_level' => 9, 'display_order' => 900, 'parent_key' => '', 'arrow' => 1],
            ['key' => 'do-not-call', 'name' => 'Do Not Call', 'is_active' => 1, 'url' => 'do-not-call', 'logo' => 'fa fa-table', 'new_logo' => 'icon-Layout-grid', 'min_level' => 5, 'display_order' => 1000, 'parent_key' => '', 'arrow' => 1],
            ['key' => 'mailbox', 'name' => 'Mailbox', 'is_active' => 1, 'url' => 'mailbox', 'logo' => 'fa fa-envelope', 'new_logo' =>'icon-Incoming-mail', 'min_level' => 1, 'display_order' => 1100, 'parent_key' => '', 'arrow' => 0],
            ['key' => 'sms', 'name' => 'SMS', 'is_active' => 1, 'url' => 'sms', 'logo' => 'fa fa-commenting-o', 'new_logo' => 'icon-Chat', 'min_level' => 1, 'display_order' => 1200, 'parent_key' => '', 'arrow' => 1],
            ['key' => 'schedule', 'name' => 'Schedule', 'is_active' => 1, 'url' => 'schedule', 'logo' => 'fa fa-calander', 'new_logo' => 'icon-Credit-card', 'min_level' => 1, 'display_order' => 1299, 'parent_key' => '', 'arrow' => 0],
            ['key' => 'receive-fax', 'name' => 'Fax', 'is_active' => 1, 'url' => 'receive-fax', 'logo' => 'fa fa-fax', 'new_logo' => 'icon-iPhone-X', 'min_level' => 1, 'display_order' => 1300, 'parent_key' => '', 'arrow' => 0],
            ['key' => 'marketing-campaigns', 'name' => 'Marketing Campaigns', 'is_active' => 1, 'url' => 'marketing-campaigns', 'logo' => 'fa fa-users', 'new_logo' => 'icon-User-folder', 'min_level' => 5, 'display_order' => 1600, 'parent_key' => '', 'arrow' => 0],
            ['key' => 'Conferences', 'name' => 'Conferencing', 'is_active' => 1, 'url' => 'main-menu-Conferences', 'logo' => 'fa fa-users', 'new_logo' => 'icon-User', 'min_level' => 5, 'display_order' => 1700, 'parent_key' => '', 'arrow' => 1],
            ['key' => 'billing', 'name' => 'Billing', 'is_active' => 1, 'url' => 'main-menu-billing', 'logo' => 'fa fa-table', 'new_logo' => 'icon-Credit-card', 'min_level' => 7, 'display_order' => 1800, 'parent_key' => '', 'arrow' => 1],
            ['key' => 'subscription', 'name' => 'Subscriptions', 'is_active' => 1, 'url' => 'subscription', 'logo' => 'fa fa-paper-plane', 'new_logo' => 'icon-Airplay' ,'min_level' => 5, 'display_order' => 1900, 'parent_key' => '', 'arrow' => 1],
            ['key' => 'extension', 'name' => 'Extension', 'is_active' => 1, 'url' => 'extension', 'logo' => 'fa fa-quora', 'new_logo' => 'icon-Commit', 'min_level' => 1, 'display_order' => 301, 'parent_key' => 'manage-extensions', 'arrow' => 0],
            ['key' => 'extension-group', 'name' => 'Group', 'is_active' => 1, 'url' => 'extension-group', 'logo' => 'fa fa-italic', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 302, 'parent_key' => 'manage-extensions', 'arrow' => 0],
            ['key' => 'ring-group', 'name' => 'Ring Groups', 'is_active' => 1, 'url' => 'ring-group', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 303, 'parent_key' => 'manage-extensions', 'arrow' => 0],
            ['key' => 'extension_live', 'name' => 'Agent Status', 'is_active' => 1, 'url' => 'extension_live', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 401, 'parent_key' => 'manage-campaigns', 'arrow' => 0],
            ['key' => 'campaign', 'name' => 'Campaign', 'is_active' => 1, 'url' => 'campaign', 'logo' => 'fa fa-phone', 'new_logo' => 'icon-Commit' ,'min_level' => 5, 'display_order' => 402, 'parent_key' => 'manage-campaigns', 'arrow' => 0],
            ['key' => 'disposition', 'name' => 'Disposition', 'is_active' => 1, 'url' => 'disposition', 'logo' => 'fa fa-envelope', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 403, 'parent_key' => 'manage-campaigns', 'arrow' => 0],
            ['key' => 'label', 'name' => 'Label', 'is_active' => 1, 'url' => 'label', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 501, 'parent_key' => 'lead-management', 'arrow' => 0],
            ['key' => 'lead', 'name' => 'Lead', 'is_active' => 1, 'url' => 'lead', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 502, 'parent_key' => 'lead-management', 'arrow' => 0],
            ['key' => 'list', 'name' => 'List', 'is_active' => 1, 'url' => 'list', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 503, 'parent_key' => 'lead-management', 'arrow' => 0],
            ['key' => 'recycle-rule', 'name' => 'Recycle Rule', 'is_active' => 1, 'url' => 'recycle-rule', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 504, 'parent_key' => 'lead-management', 'arrow' => 0],
            ['key' => 'lead-activity', 'name' => 'Lead Activity', 'is_active' => 1, 'url' => 'lead-activity', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 1, 'display_order' => 505, 'parent_key' => 'lead-management', 'arrow' => 0],
            ['key' => 'custom-field-labels', 'name' => 'Custom Field Label', 'is_active' => 1, 'url' => 'custom-field-labels', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 506, 'parent_key' => 'lead-management', 'arrow' => 0],
            ['key' => 'lead-source-configs', 'name' => 'Lead Source', 'is_active' => 1, 'url' => 'lead-source-configs', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 506, 'parent_key' => 'lead-management', 'arrow' => 0],
            ['key' => 'transfer-report', 'name' => 'Call Transfer', 'is_active' => 1, 'url' => 'transfer-report', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 801, 'parent_key' => 'call-data-reports', 'arrow' => 0],
            ['key' => 'live-call', 'name' => 'Live Call', 'is_active' => 1, 'url' => 'live-call', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 802, 'parent_key' => 'call-data-reports', 'arrow' => 0],

            ['key' => 'press1-campaign', 'name' => 'Ivr Logs', 'is_active' => 1, 'url' => 'press1-campaign', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 803, 'parent_key' => 'call-data-reports', 'arrow' => 0],


            ['key' => 'login-history', 'name' => 'Login History', 'is_active' => 1, 'url' => 'login-history', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 806, 'parent_key' => 'call-data-reports', 'arrow' => 0],
            ['key' => 'report', 'name' => 'Call Data Reports', 'is_active' => 1, 'url' => 'report', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 1, 'display_order' => 803, 'parent_key' => 'call-data-reports', 'arrow' => 0],
            ['key' => 'callback', 'name' => 'Callback', 'is_active' => 1, 'url' => 'callback', 'logo' => 'fa fa-phone', 'new_logo' => 'icon-Commit', 'min_level' => 1, 'display_order' => 804, 'parent_key' => 'call-data-reports', 'arrow' => 0],
            ['key' => 'cli-report', 'name' => 'CLI CNAM Reports', 'is_active' => 1, 'url' => 'cli-report', 'logo' => 'fa fa-phone', 'new_logo' => 'icon-Commit', 'min_level' => 1, 'display_order' => 805, 'parent_key' => 'call-data-reports', 'arrow' => 0],
            ['key' => 'count-list', 'name' => 'Call Count Report', 'is_active' => 1, 'url' => 'count-list', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 806, 'parent_key' => 'call-data-reports', 'arrow' => 0],
            ['key' => 'api-data', 'name' => 'API', 'is_active' => 1, 'url' => 'api-data', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 701, 'parent_key' => 'configuration', 'arrow' => 0],
            ['key' => 'voip-configurations', 'name' => 'VoIP Configurations', 'is_active' => 1, 'url' => 'voip-configurations', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 7, 'display_order' => 701, 'parent_key' => 'configuration', 'arrow' => 0],
            ['key' => 'email-templates', 'name' => 'Email Templates', 'is_active' => 1, 'url' => 'email-templates', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 702, 'parent_key' => 'configuration', 'arrow' => 0],
            ['key' => 'voice-templete', 'name' => 'Voice Templates', 'is_active' => 1, 'url' => 'voice-templete', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 702, 'parent_key' => 'configuration', 'arrow' => 0],
            ['key' => 'ivr', 'name' => 'IVR', 'is_active' => 1, 'url' => 'ivr', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 605, 'parent_key' => 'inbound-configuration', 'arrow' => 0],
            ['key' => 'ivr-menu', 'name' => 'IVR Menu', 'is_active' => 1, 'url' => 'ivr-menu', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 606, 'parent_key' => 'inbound-configuration', 'arrow' => 0],
            ['key' => 'audio-message', 'name' => 'Audio Message', 'is_active' => 1, 'url' => 'audio-message', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 607, 'parent_key' => 'inbound-configuration', 'arrow' => 0],
            ['key' => 'ip-setting', 'name' => 'IP Setting', 'is_active' => 1, 'url' => 'ip-setting', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 7, 'display_order' => 706, 'parent_key' => 'configuration', 'arrow' => 0],
            ['key' => 'logo-setting', 'name' => 'Notification Setting', 'is_active' => 1, 'url' => 'logo-setting', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 7, 'display_order' => 707, 'parent_key' => 'configuration', 'arrow' => 0],
            ['key' => 'smtps', 'name' => 'SMTP Setting', 'is_active' => 1, 'url' => 'smtps', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 1, 'display_order' => 708, 'parent_key' => 'configuration', 'arrow' => 0],
            ['key' => 'custom-fields-values', 'name' => 'Custom Fields Values', 'is_active' => 1, 'url' => 'custom-fields-values', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 1, 'display_order' => 709, 'parent_key' => 'configuration', 'arrow' => 0],
            ['key' => 'listdid', 'name' => 'List DIDs', 'is_active' => 0, 'url' => 'listdid', 'logo' => 'fa fa-users', 'min_level' => 5, 'display_order' => 601, 'parent_key' => 'inbound-configuration', 'arrow' => 0],
            ['key' => 'did', 'name' => 'DID Configuration', 'is_active' => 1, 'url' => 'did', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 7, 'display_order' => 602, 'parent_key' => 'inbound-configuration', 'arrow' => 0],
            ['key' => 'did_call-timings-listing', 'name' => 'Call Times', 'is_active' => 1, 'url' => 'call-timings-listing', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 603, 'parent_key' => 'inbound-configuration', 'arrow' => 0],
            ['key' => 'did_holidays', 'name' => 'Holidays', 'is_active' => 1, 'url' => 'holidays', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 604, 'parent_key' => 'inbound-configuration', 'arrow' => 0],
            ['key' => 'dnc', 'name' => 'DNC', 'is_active' => 1, 'url' => 'dnc', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 1001, 'parent_key' => 'do-not-call', 'arrow' => 0],
            ['key' => 'exclude-from-list', 'name' => 'Exclude From List', 'is_active' => 1, 'url' => 'exclude-from-list', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 1002, 'parent_key' => 'do-not-call', 'arrow' => 0],
            ['key' => 'invoice', 'name' => 'Invoice', 'is_active' => 1, 'url' => 'invoice', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 7, 'display_order' => 1801, 'parent_key' => 'billing', 'arrow' => 0],
            ['key' => 'recharge', 'name' => 'Recharge', 'is_active' => 1, 'url' => 'recharge', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 7, 'display_order' => 1802, 'parent_key' => 'billing', 'arrow' => 0],
            ['key' => 'inbox', 'name' => 'Inbox', 'is_active' => 1, 'url' => 'inbox', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 1, 'display_order' => 1201, 'parent_key' => 'sms', 'arrow' => 0],
            ['key' => 'sms-templete', 'name' => 'Text Template', 'is_active' => 1, 'url' => 'sms-templete', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 1202, 'parent_key' => 'sms', 'arrow' => 0],

            

            ['key' => 'conferencing', 'name' => 'Conferencing', 'is_active' => 1, 'url' => 'conferencing', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 1701, 'parent_key' => 'Conferences', 'arrow' => 0],
            ['key' => 'live-conference', 'name' => 'Live Conferencing', 'is_active' => 1, 'url' => 'live-conference', 'logo' => 'fa fa-users',  'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 1702, 'parent_key' => 'Conferences', 'arrow' => 0],
            ['key' => 'recording-conference', 'name' => 'Recording Conferencing', 'is_active' => 1, 'url' => 'recording-conference', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 1703, 'parent_key' => 'Conferences', 'arrow' => 0],
            ['key' => 'clients', 'name' => 'Clients', 'is_active' => 1, 'url' => 'clients', 'logo' => 'fa fa-building-o', 'new_logo' => 'icon-Commit', 'min_level' => 9, 'display_order' => 901, 'parent_key' => 'system-configuration', 'arrow' => 0],
            ['key' => 'super_components', 'name' => 'Components', 'is_active' => 1, 'url' => 'super/components', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 11, 'display_order' => 902, 'parent_key' => 'system-configuration', 'arrow' => 0],
            ['key' => 'super_modules', 'name' => 'Modules', 'is_active' => 1, 'url' => 'super/modules', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 9, 'display_order' => 903, 'parent_key' => 'system-configuration', 'arrow' => 0],
            ['key' => 'super_packages', 'name' => 'Packages', 'is_active' => 1, 'url' => 'super/packages', 'logo' => 'fa fa-users',  'new_logo' => 'icon-Commit', 'min_level' => 9, 'display_order' => 904, 'parent_key' => 'system-configuration', 'arrow' => 0],
            ['key' => 'active-plans', 'name' => 'Active plans', 'is_active' => 1, 'url' => 'active-plans', 'logo' => 'fa fa-users',  'new_logo' => 'icon-Commit','min_level' => 7, 'display_order' => 1901, 'parent_key' => 'subscription', 'arrow' => 0],
            ['key' => 'packages', 'name' => 'Manage Packages', 'is_active' => 1, 'url' => 'packages', 'logo' => 'fa fa-users',  'new_logo' => 'icon-Commit','min_level' => 7, 'display_order' => 1902, 'parent_key' => 'subscription', 'arrow' => 0],
            ['key' => 'plan-history', 'name' => 'Plan History', 'is_active' => 1, 'url' => 'plan-history', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 7, 'display_order' => 1903, 'parent_key' => 'subscription', 'arrow' => 0],
            ['key' => 'user-packages', 'name' => 'User Packages', 'is_active' => 1, 'url' => 'user-packages', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 7, 'display_order' => 1904, 'parent_key' => 'subscription', 'arrow' => 0],
            ['key' => 'wallet-transactions', 'name' => 'Wallet Transactions', 'is_active' => 1, 'url' => 'wallet/transactions', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 7, 'display_order' => 1804, 'parent_key' => 'billing', 'arrow' => 0],
            /*['key' => 'sms-configuration', 'name' => 'SMS Configuration', 'is_active' => 1, 'url' => 'sms-configuration', 'logo' => 'fa fa-envelope', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 404, 'parent_key' => 'manage-campaigns', 'arrow' => 0],*/
            ['key' => 'flash-panel', 'name' => 'Flash Panel', 'is_active' => 1, 'url' => 'flash-panel', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 709, 'parent_key' => 'configuration', 'arrow' => 0],
            ['key' => 'allowed-ips', 'name' => 'Allowed IPs', 'is_active' => 1, 'url' => 'allowed-ips', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit', 'min_level' => 5, 'display_order' => 710, 'parent_key' => 'configuration', 'arrow' => 0],
            ['key' => 'payment-method-list', 'name' => 'Payment Methods', 'is_active' => 1, 'url' => 'payment-method-list', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 1805, 'parent_key' => 'billing', 'arrow' => 0],
            ['key' => 'super-admins', 'name' => 'Super Admins', 'is_active' => 1, 'url' => 'super-admins', 'logo' => 'fa fa-italic', 'new_logo' => 'icon-Commit', 'min_level' => 10, 'display_order' => 1806, 'parent_key' => 'manage-extensions', 'arrow' => 0],

            ['key' => 'contact-crm', 'name' => 'CRM', 'is_active' => 1, 'url' => 'crm', 'logo' => 'fa fa-table', 'new_logo' => 'icon-Credit-card', 'min_level' => 1, 'display_order' => 850, 'parent_key' => '', 'arrow' => 1],

            ['key' => 'leads', 'name' => 'Leads', 'is_active' => 1, 'url' => 'leads', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 1, 'display_order' => 851, 'parent_key' => 'contact-crm', 'arrow' => 0],

            ['key' => 'sub-leads', 'name' => 'Sub Leads', 'is_active' => 1, 'url' => 'sub-leads', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 1, 'display_order' => 851, 'parent_key' => 'contact-crm', 'arrow' => 0],

            ['key' => 'lenders', 'name' => 'Lenders', 'is_active' => 1, 'url' => 'lenders', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 852, 'parent_key' => 'contact-crm', 'arrow' => 0],

            ['key' => 'lead-status', 'name' => 'Lead Status', 'is_active' => 1, 'url' => 'lead-status', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 853, 'parent_key' => 'contact-crm', 'arrow' => 0],


            ['key' => 'crm-labels', 'name' => 'Labels', 'is_active' => 1, 'url' => 'crm-labels', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 854, 'parent_key' => 'contact-crm', 'arrow' => 0],

            ['key' => 'document-types', 'name' => 'Document Types', 'is_active' => 1, 'url' => 'document-types', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 855, 'parent_key' => 'contact-crm', 'arrow' => 0],

            ['key' => 'lead-source', 'name' => 'Lead Source', 'is_active' => 1, 'url' => 'lead-source', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 856, 'parent_key' => 'contact-crm', 'arrow' => 0],

            ['key' => 'crm-email-templates', 'name' => 'Email Templates', 'is_active' => 1, 'url' => 'crm-email-templates', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 857, 'parent_key' => 'contact-crm', 'arrow' => 0],

            ['key' => 'crm-sms-templates', 'name' => 'SMS Templates', 'is_active' => 1, 'url' => 'crm-sms-templates', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 857, 'parent_key' => 'contact-crm', 'arrow' => 0],

            ['key' => 'crm-custom-templates', 'name' => 'Custom Templates', 'is_active' => 1, 'url' => 'crm-custom-templates', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 858, 'parent_key' => 'contact-crm', 'arrow' => 0],

            ['key' => 'crm-system-setting', 'name' => 'Company Details', 'is_active' => 1, 'url' => 'crm-system-setting', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 859, 'parent_key' => 'contact-crm', 'arrow' => 0],

            ['key' => 'crm-email-setting', 'name' => 'Email Settings', 'is_active' => 1, 'url' => 'crm-email-setting', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 860, 'parent_key' => 'contact-crm', 'arrow' => 0],

            ['key' => 'ringless-voicemail', 'name' => 'Ringless Voicemail', 'is_active' => 1, 'url' => 'ringless-voicemail', 'logo' => 'fa fa-table', 'new_logo' => 'icon-Credit-card', 'min_level' => 7, 'display_order' => 950, 'parent_key' => '', 'arrow' => 1],

            ['key' => 'ringless-voicemail-campaign', 'name' => 'Campaign', 'is_active' => 1, 'url' => 'ringless-campaigns', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 951, 'parent_key' => 'ringless-voicemail', 'arrow' => 0],

            ['key' => 'ringless-list', 'name' => 'Lists', 'is_active' => 1, 'url' => 'ringless-lists', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 952, 'parent_key' => 'ringless-voicemail', 'arrow' => 0],

            ['key' => 'ringless-report', 'name' => 'Report', 'is_active' => 1, 'url' => 'ringless-report', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 952, 'parent_key' => 'ringless-voicemail', 'arrow' => 0],

            ['key' => 'ringless-recharge', 'name' => 'Recharge', 'is_active' => 1, 'url' => 'ringless-recharge', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 952, 'parent_key' => 'ringless-voicemail', 'arrow' => 0],

            ['key' => 'ringless-wallet-amount', 'name' => 'Balance', 'is_active' => 1, 'url' => 'ringless-wallet-amount', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 952, 'parent_key' => 'ringless-voicemail', 'arrow' => 0],

            ['key' => 'ringless-payment-method', 'name' => 'Payment', 'is_active' => 1, 'url' => 'ringless-payment-method', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 952, 'parent_key' => 'ringless-voicemail', 'arrow' => 0],

            ['key' => 'ringless-wallet-transactions', 'name' => 'Wallet Transactions', 'is_active' => 1, 'url' => 'ringless-wallet-transactions', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 952, 'parent_key' => 'ringless-voicemail', 'arrow' => 0],

            ['key' => 'ringless-voice', 'name' => 'Voice Template', 'is_active' => 1, 'url' => 'ringless-voice', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 953, 'parent_key' => 'ringless-voicemail', 'arrow' => 0],

            ['key' => 'sip-gateways', 'name' => 'SIP Gateways', 'is_active' => 1, 'url' => 'sip-gateways', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 954, 'parent_key' => 'ringless-voicemail', 'arrow' => 0],

            ['key' => 'sms-ai', 'name' => 'SMS AI', 'is_active' => 1, 'url' => 'sms-ai', 'logo' => 'fa fa-table', 'new_logo' => 'icon-Credit-card', 'min_level' => 1, 'display_order' => 450, 'parent_key' => '', 'arrow' => 1],

            ['key' => 'open_ai_setting', 'name' => 'AI Demo', 'is_active' => 1, 'url' => 'sms-ai-setting', 'logo' => 'fa fa-users',  'new_logo' => 'icon-Commit', 'min_level' => 7, 'display_order' => 905, 'parent_key' => 'sms-ai', 'arrow' => 0],

            ['key' => 'sms-ai-campaign', 'name' => 'Campaign', 'is_active' => 1, 'url' => 'sms-ai-campaign', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 1051, 'parent_key' => 'sms-ai', 'arrow' => 0],

            ['key' => 'sms-ai-list', 'name' => 'Lists', 'is_active' => 1, 'url' => 'sms-ai-lists', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 1052, 'parent_key' => 'sms-ai', 'arrow' => 0],

            ['key' => 'sms-ai-report', 'name' => 'Reports', 'is_active' => 1, 'url' => 'sms-ai-report', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 1053, 'parent_key' => 'sms-ai', 'arrow' => 0],

            ['key' => 'sms-ai-templates', 'name' => 'SMS AI Templates', 'is_active' => 1, 'url' => 'sms-ai-templates', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 7, 'display_order' => 1054, 'parent_key' => 'sms-ai', 'arrow' => 0],

            ['key' => 'sms-ai-recharge', 'name' => 'Recharge', 'is_active' => 1, 'url' => 'sms-ai-recharge', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 9, 'display_order' => 1055, 'parent_key' => 'sms-ai', 'arrow' => 0],

            ['key' => 'sms-ai-payment-method', 'name' => 'Payment', 'is_active' => 1, 'url' => 'sms-ai-payment-method', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 9, 'display_order' => 1056, 'parent_key' => 'sms-ai', 'arrow' => 0],

            ['key' => 'sms-ai-wallet-transactions', 'name' => 'Wallet Transactions', 'is_active' => 1, 'url' => 'sms-ai-wallet-transactions', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 9, 'display_order' => 1057, 'parent_key' => 'sms-ai', 'arrow' => 0],



            ['key' => 'chat-ai', 'name' => 'Chat AI', 'is_active' => 1, 'url' => 'chat-ai', 'logo' => 'fa fa-table', 'new_logo' => 'icon-Incoming-mail', 'min_level' => 1, 'display_order' => 1070, 'parent_key' => '', 'arrow' => 1],

            ['key' => 'chat-ai-setting', 'name' => 'Chat AI Demo', 'is_active' => 1, 'url' => 'chat-ai-setting', 'logo' => 'fa fa-users',  'new_logo' => 'icon-Commit', 'min_level' => 7, 'display_order' => 1071, 'parent_key' => 'chat-ai', 'arrow' => 0],

            ['key' => 'sip-trunking', 'name' => 'Sip Trunk', 'is_active' => 1, 'url' => 'sip-trunking', 'logo' => 'fa fa-table', 'new_logo' => 'icon-Credit-card', 'min_level' => 9, 'display_order' => 1061, 'parent_key' => '', 'arrow' => 1],

            ['key' => 'trunking-call-report', 'name' => 'Report', 'is_active' => 1, 'url' => 'trunking-call-report', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 9, 'display_order' => 1062, 'parent_key' => 'sip-trunking', 'arrow' => 0],
            ['key' => 'trunking-recharge', 'name' => 'Recharge', 'is_active' => 1, 'url' => 'trunking-recharge', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 9, 'display_order' => 1063, 'parent_key' => 'sip-trunking', 'arrow' => 0],

            ['key' => 'trunking-payment-method', 'name' => 'Payment', 'is_active' => 1, 'url' => 'trunking-payment-method', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 9, 'display_order' => 1064, 'parent_key' => 'sip-trunking', 'arrow' => 0],

            ['key' => 'trunking-balance', 'name' => 'Balance', 'is_active' => 1, 'url' => 'trunking-balance', 'logo' => 'fa fa-users', 'new_logo' => 'icon-Commit',  'min_level' => 9, 'display_order' => 1065, 'parent_key' => 'sip-trunking', 'arrow' => 0],

        ];
        foreach ($component as $key => $module) {
            $module_component = ModuleComponent::find($module['key']);
            if (!empty($module_component)) {
                $module_component->update($module);
            } else {
                $model = new ModuleComponent();
                $model->key = $module["key"];
                $model->name = $module["name"];
                $model->is_active = $module["is_active"];
                $model->url = (!empty($module["url"]) ? $module["url"] : null);
                $model->logo = $module["logo"];
                $model->new_logo = $module["new_logo"];
                $model->min_level = $module["min_level"];
                $model->display_order = $module["display_order"];
                $model->parent_key = $module['parent_key'];
                $model->arrow = $module['arrow'];
                $model->saveOrFail();
            }
        }
    }
}
