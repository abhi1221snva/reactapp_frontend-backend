<?php

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds route_groups, role_route_permissions, and sidebar_menu_items.
 *
 * Run: php artisan db:seed --class=RbacSeeder
 */
class RbacSeeder extends Seeder
{
    // Role IDs from the `roles` table
    const AGENT      = 2; // level 1
    const ASSOCIATE   = 4; // level 3
    const MANAGER    = 3; // level 5
    const ADMIN      = 1; // level 7
    const SUPERADMIN = 5; // level 9
    // system_administrator (id=6, level=11) is NOT seeded — it bypasses all checks

    public function run()
    {
        $this->seedRouteGroups();
        $this->seedRolePermissions();
        $this->seedSidebarMenuItems();
    }

    // ── Route Groups ────────────────────────────────────────────────────

    private function seedRouteGroups()
    {
        $groups = [
            // ─── Shared ───
            ['key' => 'core.dashboard',   'name' => 'Dashboard',            'engine' => 'shared', 'url_patterns' => json_encode(['dashboard','count-dids','did-count','user-count','campaigns-count','lead-count','cdr-call-count','cdr-call-agent-count','cdr-count-range','disposition-wise-call','state-wise-call','cdr-dashboard-summary','voicemail-count','voicemail-unread','extension-summary','employee-directory','inbound-count-avg','dashboard-lead-status'])],
            ['key' => 'profile',          'name' => 'Profile',              'engine' => 'shared', 'url_patterns' => json_encode(['profile'])],
            ['key' => 'chat',             'name' => 'Team Chat',            'engine' => 'shared', 'url_patterns' => json_encode(['team-chat'])],
            ['key' => 'gmail',            'name' => 'Gmail',                'engine' => 'shared', 'url_patterns' => json_encode(['gmail'])],
            ['key' => 'calendar',         'name' => 'Google Calendar',      'engine' => 'shared', 'url_patterns' => json_encode(['calendar','integrations/google-calendar'])],
            ['key' => 'admin.clients',    'name' => 'Client Management',    'engine' => 'shared', 'url_patterns' => json_encode(['admin/clients'])],
            // Prefix match — covers admin/rvm/cutover, admin/rvm/dashboard, and any future admin/rvm/* route.
            ['key' => 'admin.rvm_cutover','name' => 'RVM Cutover',          'engine' => 'dialer', 'url_patterns' => json_encode(['admin/rvm'])],
            ['key' => 'admin.system',     'name' => 'System Admin',         'engine' => 'shared', 'url_patterns' => json_encode(['system/','docs','api/documentation'])],

            // ─── Dialer ───
            ['key' => 'core.dialer',      'name' => 'Dialer',              'engine' => 'dialer', 'url_patterns' => json_encode(['click2call','call-number','hang-up','send-dtmf','dtmf','agent-campaign','lead-temp','my-extension-status','extension-login','extension-logout','disposition-campaign','save-disposition','redial-call','voicemail-drop','webphone/'])],
            ['key' => 'campaigns',        'name' => 'Campaigns',            'engine' => 'dialer', 'url_patterns' => json_encode(['campaign','add-campaign','edit-campaign','copy-campaign','delete-campaign','status-update-campaign','campaign-by-id','campaign-list','campaign-type','campaigns','status-update-hopper','campaign/assign','campaign/detach'])],
            ['key' => 'dispositions',     'name' => 'Dispositions',         'engine' => 'dialer', 'url_patterns' => json_encode(['disposition','add-disposition','edit-disposition','status-update-disposition','campaign-disposition','edit-campaign-disposition'])],
            ['key' => 'agent_status',     'name' => 'Agent Status',         'engine' => 'dialer', 'url_patterns' => json_encode(['workforce/agent-status','workforce/agents-online'])],
            ['key' => 'users',            'name' => 'Users & Agents',       'engine' => 'dialer', 'url_patterns' => json_encode(['users','users-list-new','user/create','user/update','user/delete','user-detail','update-agent-password','agents','check-email','client_ip_list','get-client-extension'])],
            ['key' => 'ring_groups',      'name' => 'Ring Groups',          'engine' => 'dialer', 'url_patterns' => json_encode(['ring-group','delete-ring-group','add-ring-group','edit-ring-group','extension-ring-group'])],
            ['key' => 'extension_groups', 'name' => 'Extension Groups',     'engine' => 'dialer', 'url_patterns' => json_encode(['extension-group'])],
            ['key' => 'labels',           'name' => 'Labels',               'engine' => 'dialer', 'url_patterns' => json_encode(['label','edit-label','add-label','status-update-label','crm-labels','crm-add-label','crm-update-label','crm-delete-label','crm-change-label-status','crm-view-on-leads'])],
            ['key' => 'leads',            'name' => 'Leads',                'engine' => 'dialer', 'url_patterns' => json_encode(['leads','lead/','lead-new','sub-lead-new'])],
            ['key' => 'lists',            'name' => 'Lists',                'engine' => 'dialer', 'url_patterns' => json_encode(['list','raw-list','edit-list','add-list','parse-list-headers','import-list-with-mapping','get-list-mapping','update-list-mapping','search-leads','list-header','crm-list','crm-lists','count-lists'])],
            ['key' => 'recycle_rules',    'name' => 'Recycle Rules',        'engine' => 'dialer', 'url_patterns' => json_encode(['recycle-rule','edit-recycle-rule','add-recycle-rule','delete-leads-rule','search-recycle-rule'])],
            ['key' => 'lead_activity',    'name' => 'Lead Activity',        'engine' => 'dialer', 'url_patterns' => json_encode(['crm/lead/{id}/activity','crm/lead/{id}/status-history'])],
            ['key' => 'custom_fields',    'name' => 'Custom Field Labels',  'engine' => 'dialer', 'url_patterns' => json_encode(['custom-field-label','custom-field-value','custom-label-value','delete-custom-field'])],
            ['key' => 'lead_sources',     'name' => 'Lead Sources',         'engine' => 'crm', 'url_patterns' => json_encode(['lead-source','add-lead-source','update-lead-sources','lead-source-config','insert-lead-source','lead-data','header-by-listid'])],
            ['key' => 'reports',          'name' => 'Reports',              'engine' => 'dialer', 'url_patterns' => json_encode(['report','reports/','daily-call-report','agent-report','transfer-report','live-call','live-calls','export-report','call-matrix','login-history','cli-report','get-cdr'])],
            ['key' => 'dids',             'name' => 'DID Management',       'engine' => 'dialer', 'url_patterns' => json_encode(['did','edit-did','add-did','did_detail','save-edit-did','delete-did','get-did-by-id','upload-did','check-default-did','fax-did','set-app-extension','buy-save-selected-did'])],
            ['key' => 'ivr',              'name' => 'IVR Menus',            'engine' => 'dialer', 'url_patterns' => json_encode(['ivr','add-ivr','edit-ivr','delete-ivr','ivr-menu','add-ivr-menu','edit-ivr-menu','delete-ivr-menu'])],
            ['key' => 'voicemail',        'name' => 'Voicemail',            'engine' => 'dialer', 'url_patterns' => json_encode(['voicemail','mailbox','edit-mailbox','delete-mailbox','add-voice-mail-drop','view-voicemail','edit-voicemail','update-voiemail','update-voice-mail'])],
            ['key' => 'call_times',       'name' => 'Call Times',           'engine' => 'dialer', 'url_patterns' => json_encode(['get-call-timings','save-call-timings','get-department-call-timings','get-department-list'])],
            ['key' => 'call_timers',      'name' => 'Call Timers',          'engine' => 'dialer', 'url_patterns' => json_encode(['call-timers'])],
            ['key' => 'holidays',         'name' => 'Holidays',             'engine' => 'dialer', 'url_patterns' => json_encode(['get-all-holidays','get-holiday-datail','save-holiday-detail','delete-holiday'])],
            ['key' => 'ai',               'name' => 'AI & Tools',           'engine' => 'dialer', 'url_patterns' => json_encode(['ai-setting/','ai/'])],
            ['key' => 'ringless',         'name' => 'Ringless Voicemail',   'engine' => 'dialer', 'url_patterns' => json_encode(['ringless/'])],
            ['key' => 'smsai',            'name' => 'SMS AI',               'engine' => 'dialer', 'url_patterns' => json_encode(['smsai/','sms-ai-email-report'])],
            ['key' => 'sms',              'name' => 'SMS Center',           'engine' => 'dialer', 'url_patterns' => json_encode(['sms','send-sms','sms-by-did','sms-count','unread-sms-count'])],
            ['key' => 'telecom',          'name' => 'Telecom',              'engine' => 'dialer', 'url_patterns' => json_encode(['twilio/','plivo/','telecom/'])],
            ['key' => 'dnc',              'name' => 'DNC List',             'engine' => 'dialer', 'url_patterns' => json_encode(['dnc','edit-dnc','add-dnc','delete-dnc','upload-dnc','download-dnc'])],
            ['key' => 'exclude_list',     'name' => 'Exclude From List',    'engine' => 'dialer', 'url_patterns' => json_encode(['exclude-number'])],
            ['key' => 'fax',              'name' => 'Fax Settings',         'engine' => 'dialer', 'url_patterns' => json_encode(['fax','send-fax','delete-fax','receive-fax-list'])],
            ['key' => 'email_templates',  'name' => 'Email Templates',      'engine' => 'dialer', 'url_patterns' => json_encode(['email-template','email-templates','status-update-email-template'])],
            ['key' => 'sms_templates',   'name' => 'SMS Templates',        'engine' => 'dialer', 'url_patterns' => json_encode(['sms-templete','add-sms-templete','edit-sms-templete','delete-sms-templete','sms-template','update-sms-templete-status'])],
            ['key' => 'billing',          'name' => 'Billing',              'engine' => 'dialer', 'url_patterns' => json_encode(['billing','wallet/','cart','checkout','stripe/','orders','call-billing','billing-charge','active-client-plans','history-client-plans'])],

            // ─── CRM ───
            ['key' => 'crm.dashboard',    'name' => 'CRM Dashboard',        'engine' => 'crm', 'url_patterns' => json_encode(['mca/dashboard-metrics','crm/analytics','crm/pipeline'])],
            ['key' => 'crm.leads',        'name' => 'CRM Leads',            'engine' => 'crm', 'url_patterns' => json_encode(['crm/lead','crm/leads','fcs/','eligible-lender','lender-matrix'])],
            ['key' => 'crm.lead_fields',  'name' => 'Lead Fields',          'engine' => 'crm', 'url_patterns' => json_encode(['crm/lead-fields'])],
            ['key' => 'crm.lead_status',  'name' => 'Lead Status',          'engine' => 'crm', 'url_patterns' => json_encode(['crm/lead-status','leadStatus','add-lead-status','update-lead-status','change-lead-status','delete-lead-status','lead-status/updateDisplayOrder','change-view-on-dashboard-status'])],
            ['key' => 'crm.sms_inbox',    'name' => 'SMS Inbox',            'engine' => 'crm', 'url_patterns' => json_encode(['crm/sms/'])],
            ['key' => 'crm.email_templates', 'name' => 'Email Templates',   'engine' => 'crm', 'url_patterns' => json_encode(['crm-email-template','crm-add-email-template','crm-change-email-template-status','crm-delete-email-template'])],
            ['key' => 'crm.sms_templates','name' => 'SMS Templates',        'engine' => 'crm', 'url_patterns' => json_encode(['crm-sms-template','crm-add-sms-template','crm-change-sms-template-status','crm-delete-sms-template'])],
            ['key' => 'crm.pdf_templates','name' => 'PDF Templates',        'engine' => 'crm', 'url_patterns' => json_encode(['crm-custom-template','crm-add-custom-template','crm-change-custom-template-status','crm-delete-custom-template','crm/pdf'])],
            ['key' => 'crm.lenders',      'name' => 'Lenders',              'engine' => 'crm', 'url_patterns' => json_encode(['lenders','lender/','lender','delete-lender','change-lender-status','crm-lender-apis'])],
            ['key' => 'crm.lender_api',   'name' => 'Lender API Logs',      'engine' => 'crm', 'url_patterns' => json_encode(['crm/lender-api'])],
            ['key' => 'crm.email_settings','name' => 'Email Settings',      'engine' => 'crm', 'url_patterns' => json_encode(['crm/email-settings','crm-email-setting','update-crm-email-setting'])],
            ['key' => 'crm.document_types','name' => 'Document Types',      'engine' => 'crm', 'url_patterns' => json_encode(['document-types','document-type','document-value','update-document-type','delete-document-type','change-document-type-status'])],
            ['key' => 'crm.automations',  'name' => 'Automations',          'engine' => 'crm', 'url_patterns' => json_encode(['crm/automations'])],
            ['key' => 'crm.performance',  'name' => 'Agent Performance',    'engine' => 'crm', 'url_patterns' => json_encode(['crm/agent-performance','crm/commission','crm/commissions','crm/renewals','crm/agent-bonuses','crm/deal/'])],
            ['key' => 'crm.integrations', 'name' => 'API Integrations',     'engine' => 'crm', 'url_patterns' => json_encode(['crm/integration','integrations','connect-integration','disconnect-integration'])],
            ['key' => 'crm.company',      'name' => 'Company Settings',     'engine' => 'crm', 'url_patterns' => json_encode(['crm/company-settings','crm-system-setting','update-system-setting','company-columns'])],
            ['key' => 'crm.affiliate',    'name' => 'Affiliate Links',      'engine' => 'crm', 'url_patterns' => json_encode(['crm/affiliate'])],
            ['key' => 'email_parser',     'name' => 'Email Parser',         'engine' => 'crm', 'url_patterns' => json_encode(['email-parser/'])],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($groups as $i => &$g) {
            $g['display_order'] = ($i + 1) * 10;
            $g['created_at'] = $now;
            $g['updated_at'] = $now;
        }

        DB::connection('master')->table('route_groups')->insert($groups);
    }

    // ── Role → Route Group Permissions ──────────────────────────────────

    private function seedRolePermissions()
    {
        // Define which route groups each role can access
        // system_administrator (level 11) is NOT listed — bypasses all checks

        $level1 = [ // agent + associate
            'core.dashboard', 'core.dialer', 'profile', 'chat', 'users',
            'sms',
            'crm.dashboard', 'crm.leads', 'crm.sms_inbox', 'gmail', 'calendar',
        ];

        $level5 = array_merge($level1, [ // manager
            'leads', 'labels', 'lists', 'lead_activity', 'reports', 'voicemail',
            'crm.lead_fields', 'crm.lead_status',
            'crm.email_templates', 'crm.sms_templates', 'crm.pdf_templates',
            'crm.lenders', 'crm.lender_api',
            'crm.email_settings', 'crm.document_types',
            'crm.company', 'crm.affiliate',
            'email_parser',
        ]);

        $level7 = array_merge($level5, [ // admin
            'users', 'ring_groups', 'extension_groups', 'dispositions',
            'recycle_rules', 'custom_fields', 'lead_sources',
            'dids', 'ivr', 'call_times', 'call_timers', 'holidays',
            'ai', 'ringless', 'smsai',
            'dnc', 'exclude_list', 'fax', 'billing', 'email_templates', 'sms_templates',
            'crm.automations', 'crm.performance', 'crm.integrations',
        ]);

        $level9 = array_merge($level7, [ // super_admin
            'admin.clients',
            'admin.rvm_cutover',
        ]);

        $mapping = [
            self::AGENT     => $level1,
            self::ASSOCIATE => $level1,
            self::MANAGER   => $level5,
            self::ADMIN     => $level7,
            self::SUPERADMIN => $level9,
        ];

        $rows = [];
        $now = date('Y-m-d H:i:s');
        foreach ($mapping as $roleId => $groups) {
            foreach (array_unique($groups) as $groupKey) {
                $rows[] = [
                    'role_id' => $roleId,
                    'route_group_key' => $groupKey,
                    'created_at' => $now,
                ];
            }
        }

        // Insert in chunks to avoid max packet issues
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::connection('master')->table('role_route_permissions')->insert($chunk);
        }
    }

    // ── Sidebar Menu Items ──────────────────────────────────────────────

    private function seedSidebarMenuItems()
    {
        $items = [];
        $order = 0;

        // ─── DIALER ENGINE ───

        // CORE
        $items[] = $this->item('dialer', 'CORE', '/dashboard', 'Dashboard', 'LayoutDashboard', 'core.dashboard', 1, ++$order);
        $items[] = $this->item('dialer', 'CORE', '/dialer',    'Dialer',    'Phone',           'core.dialer',    1, ++$order);

        // USER MANAGEMENT
        $items[] = $this->item('dialer', 'USER MANAGEMENT', '/users',            'Users & Agents',  'UserCog', 'users',            1, ++$order);
        $items[] = $this->item('dialer', 'USER MANAGEMENT', '/ring-groups',      'Ring Groups',      'Users',   'ring_groups',      7, ++$order);
        $items[] = $this->item('dialer', 'USER MANAGEMENT', '/extension-groups', 'Extension Groups', 'Layers',  'extension_groups', 7, ++$order);

        // CAMPAIGN MANAGEMENT
        $items[] = $this->item('dialer', 'CAMPAIGN MANAGEMENT', '/campaigns',             'Campaigns',    'Radio',      'campaigns',    1, ++$order);
        $items[] = $this->item('dialer', 'CAMPAIGN MANAGEMENT', '/settings/dispositions', 'Dispositions', 'ListChecks', 'dispositions', 7, ++$order);
        // Agent Status removed from sidebar

        // LEAD MANAGEMENT
        $items[] = $this->item('dialer', 'LEAD MANAGEMENT', '/settings/labels',              'Labels',              'Tag',       'labels',        5, ++$order);
        $items[] = $this->item('dialer', 'LEAD MANAGEMENT', '/leads',                        'Leads',               'Target',    'leads',         5, ++$order);
        $items[] = $this->item('dialer', 'LEAD MANAGEMENT', '/lists',                        'Lists',               'List',      'lists',         5, ++$order);
        $items[] = $this->item('dialer', 'LEAD MANAGEMENT', '/settings/recycle-rules',       'Recycle Rules',       'RefreshCw', 'recycle_rules', 7, ++$order);
        $items[] = $this->item('dialer', 'LEAD MANAGEMENT', '/settings/lead-activity',       'Lead Activity',       'Activity',  'lead_activity', 5, ++$order);
        $items[] = $this->item('dialer', 'LEAD MANAGEMENT', '/settings/custom-field-labels', 'Custom Field Labels', 'Settings2', 'custom_fields', 7, ++$order);
        // Lead Sources moved to CRM engine (MERCHANT MANAGEMENT section)

        // REPORTS (only CDR Report + Live Calls active)
        $items[] = $this->item('dialer', 'REPORTS', '/reports',                      'CDR Report',           'BarChart3',  'reports', 5, ++$order);
        // $items[] = $this->item('dialer', 'REPORTS', '/reports/daily',                'Daily Report',         'Calendar',   'reports', 5, ++$order);
        // $items[] = $this->item('dialer', 'REPORTS', '/reports/agent-summary',        'Agent Summary',        'Users',      'reports', 5, ++$order);
        // $items[] = $this->item('dialer', 'REPORTS', '/reports/disposition',          'Disposition Report',   'ListChecks', 'reports', 5, ++$order);
        // $items[] = $this->item('dialer', 'REPORTS', '/reports/campaign-performance', 'Campaign Performance', 'PieChart',   'reports', 5, ++$order);
        $items[] = $this->item('dialer', 'REPORTS', '/reports/live',                 'Live Calls',           'Radio',      'reports', 5, ++$order);
        // $items[] = $this->item('dialer', 'REPORTS', '/reports/recordings',           'Recording Report',     'Mic',        'reports', 5, ++$order);

        // VOICE
        $items[] = $this->item('dialer', 'VOICE', '/dids',              'DID Management',  'Hash',         'dids',        7, ++$order);
        $items[] = $this->item('dialer', 'VOICE', '/ivr',               'IVR Menus',       'PhoneCall',    'ivr',         7, ++$order);
        $items[] = $this->item('dialer', 'VOICE', '/voicemail',         'Voicemail Drops', 'Voicemail',    'voicemail',   7, ++$order);
        $items[] = $this->item('dialer', 'VOICE', '/voicemail/mailbox', 'Mailbox',         'Inbox',        'voicemail',   5, ++$order);
        $items[] = $this->item('dialer', 'VOICE', '/call-times',        'Call Times',      'Clock',        'call_times',  7, ++$order);
        $items[] = $this->item('dialer', 'VOICE', '/call-timers',       'Call Timers',     'Clock',        'call_timers', 7, ++$order);
        $items[] = $this->item('dialer', 'VOICE', '/holidays',          'Holidays',        'CalendarDays', 'holidays',    7, ++$order);

        // AI & TOOLS
        $items[] = $this->item('dialer', 'AI & TOOLS', '/ai/settings', 'AI Settings',        'Bot',       'ai',       7, ++$order);
        $items[] = $this->item('dialer', 'AI & TOOLS', '/ai/coach',    'AI Coach',           'Headphones','ai',       7, ++$order);
        $items[] = $this->item('dialer', 'AI & TOOLS', '/ringless',    'Ringless Voicemail', 'Voicemail', 'ringless', 7, ++$order);

        // SMS AI
        $items[] = $this->item('dialer', 'SMS AI', '/smsai/demo',      'AI Demo',         'BrainCircuit', 'smsai', 7, ++$order);
        $items[] = $this->item('dialer', 'SMS AI', '/smsai/campaigns', 'Campaigns',       'Radio',        'smsai', 7, ++$order);
        $items[] = $this->item('dialer', 'SMS AI', '/smsai/lists',     'Lists',           'List',         'smsai', 7, ++$order);
        $items[] = $this->item('dialer', 'SMS AI', '/smsai/reports',   'Reports',         'BarChart3',    'smsai', 7, ++$order);
        $items[] = $this->item('dialer', 'SMS AI', '/smsai/templates', 'SMS AI Templates','FileText',     'smsai', 7, ++$order);

        // COMMUNICATIONS
        $items[] = $this->item('dialer', 'COMMUNICATIONS', '/chat', 'Team Chat', 'MessagesSquare', 'chat', 1, ++$order);

        // TELECOM
        $items[] = $this->item('dialer', 'TELECOM', '/telecom',                    'Telecom Hub',     'Radio',         'telecom', 11, ++$order);
        $items[] = $this->item('dialer', 'TELECOM', '/telecom?p=twilio&t=numbers', 'Phone Numbers',   'Hash',          'telecom', 11, ++$order);
        $items[] = $this->item('dialer', 'TELECOM', '/telecom?p=twilio&t=trunks',  'SIP Trunks',      'Wifi',          'telecom', 11, ++$order);
        $items[] = $this->item('dialer', 'TELECOM', '/telecom?p=twilio&t=calls',   'Call Logs',       'PhoneCall',     'telecom', 11, ++$order);
        $items[] = $this->item('dialer', 'TELECOM', '/telecom?p=twilio&t=sms',     'SMS Logs',        'MessageSquare', 'telecom', 11, ++$order);
        $items[] = $this->item('dialer', 'TELECOM', '/telecom?p=twilio&t=usage',   'Usage & Billing', 'DollarSign',    'telecom', 11, ++$order);

        // SETTINGS
        $items[] = $this->item('dialer', 'SETTINGS', '/settings/dnc',     'DNC List',          'ShieldCheck', 'dnc',          7, ++$order);
        $items[] = $this->item('dialer', 'SETTINGS', '/settings/exclude', 'Exclude From List', 'MinusCircle', 'exclude_list', 7, ++$order);
        $items[] = $this->item('dialer', 'SETTINGS', '/settings/email-templates', 'Email Templates', 'Mail', 'email_templates', 7, ++$order);
        $items[] = $this->item('dialer', 'SETTINGS', '/settings/sms-templates', 'SMS Templates', 'MessageSquare', 'sms_templates', 7, ++$order);
        $items[] = $this->item('dialer', 'SETTINGS', '/settings/fax',     'Fax Settings',      'FileText',    'fax',          7, ++$order);
        $items[] = $this->item('dialer', 'SETTINGS', '/billing',          'Billing',           'CreditCard',  'billing',      7, ++$order);

        // SYSTEM ADMIN (dialer)
        $items[] = $this->item('dialer', 'SYSTEM ADMIN', '/admin/clients',        'Client Management', 'Building2',  'admin.clients',     9,  ++$order);
        $items[] = $this->item('dialer', 'SYSTEM ADMIN', '/admin/rvm/dashboard',  'RVM Dashboard',     'BarChart3',  'admin.rvm_cutover', 9,  ++$order);
        $items[] = $this->item('dialer', 'SYSTEM ADMIN', '/admin/rvm/cutover',    'RVM Cutover',       'Radio',      'admin.rvm_cutover', 9,  ++$order);
        $items[] = $this->item('dialer', 'SYSTEM ADMIN', '/admin/system-monitor', 'System Monitor',    'Activity',   'admin.system',      11, ++$order);
        $items[] = $this->item('dialer', 'SYSTEM ADMIN', '/system/swagger',       'Swagger API Docs',  'BookMarked', 'admin.system',      11, ++$order);


        // ─── CRM ENGINE ───

        // OVERVIEW
        $items[] = $this->item('crm', 'OVERVIEW', '/crm/dashboard', 'Dashboard', 'PieChart', 'crm.dashboard', 1, ++$order);

        // MERCHANT MANAGEMENT
        $items[] = $this->item('crm', 'MERCHANT MANAGEMENT', '/crm/leads',       'Leads',       'Target',    'crm.leads',       1, ++$order);
        $items[] = $this->item('crm', 'MERCHANT MANAGEMENT', '/crm/lead-fields', 'Labels',      'Settings2', 'crm.lead_fields', 5, ++$order);
        $items[] = $this->item('crm', 'MERCHANT MANAGEMENT', '/crm/lead-status', 'Lead Status', 'Tag',       'crm.lead_status', 5, ++$order);
        $items[] = $this->item('crm', 'MERCHANT MANAGEMENT', '/settings/lead-sources', 'Lead Sources', 'Globe', 'lead_sources', 7, ++$order);

        // INBOX
        $items[] = $this->item('crm', 'INBOX', '/chat',          'Chat',         'MessagesSquare', 'chat',          1, ++$order);
        $items[] = $this->item('crm', 'INBOX', '/crm/sms-inbox', 'SMS',          'MessageSquare',  'crm.sms_inbox', 1, ++$order);
        $items[] = $this->item('crm', 'INBOX', '/gmail-mailbox', 'Gmail Inbox',  'Inbox',          'gmail',         1, ++$order);
        $items[] = $this->item('crm', 'INBOX', '/email-parser',  'Email Parser', 'FileSearch',     'email_parser',  5, ++$order);

        // TEMPLATE MANAGEMENT
        $items[] = $this->item('crm', 'TEMPLATE MANAGEMENT', '/crm/email-templates', 'Email Templates', 'Mail',          'crm.email_templates', 5, ++$order);
        $items[] = $this->item('crm', 'TEMPLATE MANAGEMENT', '/crm/sms-templates',   'SMS Templates',   'MessageSquare', 'crm.sms_templates',   5, ++$order);
        $items[] = $this->item('crm', 'TEMPLATE MANAGEMENT', '/crm/pdf-templates',   'PDF Templates',   'FileText',      'crm.pdf_templates',   5, ++$order);

        // SETTINGS (CRM)
        $items[] = $this->item('crm', 'SETTINGS', '/crm/email-settings',  'Email Settings',  'Mail', 'crm.email_settings',  5, ++$order);
        $items[] = $this->item('crm', 'SETTINGS', '/crm/document-types',  'Document Types',  'Tag',  'crm.document_types',  5, ++$order);

        // INTEGRATIONS
        $items[] = $this->item('crm', 'INTEGRATIONS', '/google-calendar',  'Google Calendar',  'Calendar', 'calendar',         1, ++$order);
        $items[] = $this->item('crm', 'INTEGRATIONS', '/crm/integrations', 'API Integrations', 'Plug2',    'crm.integrations', 7, ++$order);

        // PERFORMANCE
        $items[] = $this->item('crm', 'PERFORMANCE', '/crm/agent-performance', 'Agent Performance', 'BarChart3',  'crm.performance', 7, ++$order);
        $items[] = $this->item('crm', 'PERFORMANCE', '/crm/commissions',       'Commissions',       'DollarSign', 'crm.performance', 7, ++$order);
        $items[] = $this->item('crm', 'PERFORMANCE', '/crm/renewals',          'Renewal Pipeline',  'RefreshCw',  'crm.performance', 7, ++$order);

        // PARTNERS
        $items[] = $this->item('crm', 'PARTNERS', '/crm/lenders',         'Lenders',       'Building2', 'crm.lenders',    5, ++$order);
        $items[] = $this->item('crm', 'PARTNERS', '/crm/lender-api-logs', 'API Call Logs', 'Activity',  'crm.lender_api', 5, ++$order);

        // SYSTEM ADMIN (CRM)
        $items[] = $this->item('crm', 'SYSTEM ADMIN', '/admin/clients',        'Client Management', 'Building2',  'admin.clients', 9,  ++$order);
        $items[] = $this->item('crm', 'SYSTEM ADMIN', '/admin/system-monitor', 'System Monitor',    'Activity',   'admin.system',  11, ++$order);
        $items[] = $this->item('crm', 'SYSTEM ADMIN', '/system/swagger',       'Swagger API Docs',  'BookMarked', 'admin.system',  11, ++$order);

        DB::connection('master')->table('sidebar_menu_items')->insert($items);
    }

    private function item(
        string $engine, string $section, string $path, string $label,
        string $icon, ?string $groupKey, int $minLevel, int $order,
        ?string $badge = null
    ): array {
        return [
            'engine'          => $engine,
            'section_label'   => $section,
            'route_path'      => $path,
            'label'           => $label,
            'icon_name'       => $icon,
            'route_group_key' => $groupKey,
            'min_level'       => $minLevel,
            'display_order'   => $order,
            'is_active'       => 1,
            'badge_source'    => $badge,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];
    }
}
