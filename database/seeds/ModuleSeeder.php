<?php

use Illuminate\Database\Seeder;
use App\Model\Master\Module;

class ModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $modules = [
            [
                "key" => "super-admin",
                "name" => "Super Admin",
                "is_active" => 0,
                "components" => [
                    "system-configuration", "clients", "super_components", "super_modules", "super_packages"
                ],
                "attributes" => [
                    "Manage clients",
                    "Manage Components",
                    "Manage Modules",
                    "Manage Packages",
                ]
            ],
            [
                "key" => "client-admin",
                "name" => "General Admin",
                "is_active" => 0,
                "components" => [
                    "manage-extensions", "extension", "subscription", "packages", "active-plans", "plan-history", "user-packages","configuration","flash-panel","super-admins"
                ],
                "attributes" => [
                    "Subscriptions",
                    "Manage Packages",
                    "Active Plans",
                    "Plan History",
                ]
            ],
            [
                "key" => "phone",
                "name" => "Phone",
                "is_active" => 1,
                "components" => [
                    'dashboard',
                    'schedule',
                    'manage-extensions',
                    'extension',
                    'extension-group',
                    'ring-group',
                    'inbound-configuration',
                    'listdid',
                    'did',
                    'configuration',
                    'ivr',
                    'ivr-menu',
                    'audio-message',
                    'ip-setting',
                    'logo-setting',
                    'call-data-reports',
                    'press1-campaign',
                    'transfer-report',
                    'live-call',
                    'report',
                    'do-not-call',
                    'dnc',
                    'exclude-from-list',
                    'mailbox',
                    'cli-report',
                    'login-history',
                    'allowed-ips',
                    'voice-templete',
                    'count-list',




                ],
                "attributes" => [
                    "Cloud Phone System",
                    "Unlimited Inbound Calls",
                    "Unlimited Outbound Calls",
                    "Extensions",
                    "Extension Groups",
                    "IP Whitelisting",
                    "DNC Registry",
                    "Voicemail",
                    "Call-back Management",
                    "Mobile Application",
                    "API"
                ]
            ],
            [
                "key" => "dialer",
                "name" => "Dialer",
                "is_active" => 1,
                "components" => [
                    'dashboard',
                    'schedule',
                    'start-dialing',
                    'manage-campaigns',
                    'extension_live',
                    'campaign',
                    'disposition',
                    'inbound-configuration',
                    'listdid',
                    'did',
                    'did_call-timings-listing',
                    'did_holidays',
                    'configuration',
                    'api-data',
                    'callback',
                    /*'sms-configuration',*/
                    'voip-configurations',
                ],
                "attributes" => [
                    "Desktop Phone",
                    "Progressive Dialer",
                    "Click-to-Call Dialing",
                    "DID Management",
                    "Local Caller ID**"
                ]
            ],
            [
                "key" => "lead-management",
                "name" => "Lead management",
                "is_active" => 1,
                "components" => [
                    'lead-management',
                    'label',
                    'lead',
                    'list',
                    'recycle-rule',
                    'lead-activity',
                    'lead-source-configs',
                    'custom-field-labels',
                ],
                "attributes" => [
                    "Lead management"
                ]
            ],
            [
                "key" => "template-management",
                "name" => "Template Management",
                "is_active" => 1,
                "components" => [
                    'email-templates',
                    'sms',
                    'sms-templete',
                ],
                "attributes" => [
                    "Manage email templates",
                    "Manage text templates"
                ]
            ],
            [
                "key" => "email-integration",
                "name" => "Email Integration",
                "is_active" => 1,
                "components" => [
                    'email-templates',
                    'smtps',
                    'custom-fields-values',
                    'mailbox',
                ],
                "attributes" => [
                    "Email Notifications"
                ]
            ],
            [
                "key" => "sms-integration",
                "name" => "Text Integration",
                "is_active" => 1,
                "components" => [
                    'inbound-configuration',
                    'listdid',
                    'did',
                    'sms',
                    'inbox',
                ],
                "attributes" => [
                    "SMS Portal**"
                ]
            ],
            [
                "key" => "fax-management",
                "name" => "Fax",
                "is_active" => 1,
                "components" => [
                    'inbound-configuration',
                    'listdid',
                    'did',
                    'receive-fax',
                ],
                "attributes" => [
                    "Manage Fax Did's",
                    "Fax"
                ]
            ],
            [
                "key" => "conferencing",
                "name" => "Conferencing",
                "is_active" => 1,
                "components" => [
                    'Conferences',
                    'conferencing',
                    'live-conference',
                    'recording-conference',
                ],
                "attributes" => [
                    "Conferencing"
                ]
            ],
            [
                "key" => "marketing-campaign",
                "name" => "Marketing Automation",
                "is_active" => 1,
                "components" => [
                    'lead-management',
                    'label',
                    'lead',
                    'list',
                    'inbound-configuration',
                    'listdid',
                    'did',
                    'configuration',
                    'email-templates',
                    'smtps',
                    'sms-templete',
                    'marketing-campaigns',
                ],
                "attributes" => [
                    "Marketing Automation"
                ]
            ],
            [
                "key" => "billing",
                "name" => "Email Integration",
                "is_active" => 1,
                "components" => [
                    'billing',
                    'invoice',
                    'recharge',
                    'wallet-transactions',
                    'payment-method-list',
                ],
                "attributes" => [
                    "Billing and invoices"
                ]
            ],

            [
                "key" => "contact-crm",
                "name" => "CRM",
                "is_active" => 1,
                "components" => [
                    'contact-crm',
                    'lenders',
                    'lead-status',
                    'leads',
                    'sub-leads',
                    'crm-labels',
                    'document-types',
                    'lead-source',
                    'crm-email-templates',
                    'crm-custom-templates',
                    'crm-system-setting',
                    'crm-email-setting',
                    'crm-sms-templates',

                ],
                "attributes" => [
                    "Leads And Lenders"
                ]
            ],

            [
                "key" => "ringless-voicemail",
                "name" => "Ringless Voicemail",
                "is_active" => 1,
                "components" => [
                    'ringless-voicemail',
                    'ringless-voicemail-campaign',
                    'ringless-list',
                    'ringless-report',
                    'ringless-recharge',
                    'ringless-payment-method',
                    'ringless-wallet-transactions',
                    'ringless-wallet-amount',
                    'ringless-voice',
                    'sip-gateways'

                ],
                "attributes" => [
                    "Ringless CRM"
                ]
            ],

            [
                "key" => "sms-ai",
                "name" => "SMS AI",
                "is_active" => 1,
                "components" => [
                    'open_ai_setting',
                    'sms-ai',
                    'sms-ai-campaign',
                    'sms-ai-list',
                    'sms-ai-report',
                    'sms-ai-templates',
                    'sms-ai-recharge',
                    'sms-ai-payment-method',
                    'sms-ai-wallet-transactions',



                    

                ],
                "attributes" => [
                    "SMS AI"
                ]
            ],

            [
                "key" => "chat-ai",
                "name" => "Chat AI",
                "is_active" => 1,
                "components" => [
                    'chat-ai-setting',
                    'chat-ai',



                    

                ],
                "attributes" => [
                    "Chat AI"
                ]
            ],

            [
                "key" => "sip-trunking",
                "name" => "Sip Trunk",
                "is_active" => 1,
                "components" => [
                    'sip-trunking',
                    'trunking-call-report',
                    'trunking-recharge',
                    'trunking-payment-method',
                    'trunking-balance'
                    

                ],
                "attributes" => [
                    "Sip Trunk"
                ]
            ],
        ];

        foreach ($modules as $key => $module) {
            $moduleFound = Module::find($module["key"]);
            if (!empty($moduleFound)) {
                $moduleFound->update($module);
            } else {
                $model = new Module();
                $model->key = $module["key"];
                $model->name = $module["name"];
                $model->is_active = $module["is_active"];
                $model->display_order = $key + 1;
                $model->components = $module["components"];
                $model->attributes = $module["attributes"];
                $model->saveOrFail();
            }
        }
    }
}
