<?php

use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $packages = [
            [
                "name" => "Starter",
                "description" => "Phone",
                "is_active" => 1,
                "applicable_for" => 1,      #1 - b2b, 2 - b2c, 3 - both
                "show_on" => ["website", "portal"],   #website, portal
                "modules" => [
                    "phone","billing",
                ],
                "currency_code" => "USD",
                "base_rate_monthly_billed" => 999,
                "base_rate_quarterly_billed" => 3499,
                "base_rate_half_yearly_billed" => 4999,
                "base_rate_yearly_billed" => 9999,
                "call_rate_per_minute" => 0.015,
                "rate_per_sms" => 0.01,
                "rate_per_did" => 2,
                "rate_per_fax" => 0.02,
                "rate_per_email" => 0.0006,
                "free_call_minute_monthly" => 100,
                "free_sms_monthly" => 100,
                "free_fax_monthly" => 100,
                "free_emails_monthly" => 10000,
                "free_did_monthly" => 2
            ],
            [
                "name" => "Standard",
                "description" => "Phone, Dialer & Email",
                "is_active" => 1,
                "applicable_for" => 1,      #1 - b2b, 2 - b2c, 3 - both
                "show_on" => ["website", "portal"],   #website, portal
                "modules" => [
                    "phone", "dialer", "lead-management", "conferencing", "template-management", "email-integration","billing"
                ],
                "currency_code" => "USD",
                "base_rate_monthly_billed" => 2499,
                "base_rate_quarterly_billed" => 8999,
                "base_rate_half_yearly_billed" => 12999,
                "base_rate_yearly_billed" => 22999,
                "call_rate_per_minute" => 0.015,
                "rate_per_sms" => 0.01,
                "rate_per_did" => 2,
                "rate_per_fax" => 0.02,
                "rate_per_email" => 0.0006,
                "free_call_minute_monthly" => 100,
                "free_sms_monthly" => 100,
                "free_fax_monthly" => 100,
                "free_emails_monthly" => 10000,
                "free_did_monthly" => 2
            ],
            [
                "name" => "Premium",
                "description" => "Phone, Dialer, SMS/Email, Fax & Marketing",
                "is_active" => 1,
                "applicable_for" => 1,      #1 - b2b, 2 - b2c, 3 - both
                "show_on" => ["website", "portal"],   #website, portal
                "modules" => [
                    "phone",
                    "dialer",
                    "lead-management",
                    "conferencing",
                    "template-management",
                    "email-integration",
                    "sms-integration",
                    "fax-management",
                    "marketing-campaign",
                    "billing"
                ],
                "currency_code" => "USD",
                "base_rate_monthly_billed" => 3999,
                "base_rate_quarterly_billed" => 13999,
                "base_rate_half_yearly_billed" => 19999,
                "base_rate_yearly_billed" => 38999,
                "call_rate_per_minute" => 0.015,
                "rate_per_sms" => 0.01,
                "rate_per_did" => 2,
                "rate_per_fax" => 0.02,
                "rate_per_email" => 0.0006,
                "free_call_minute_monthly" => 100,
                "free_sms_monthly" => 100,
                "free_fax_monthly" => 100,
                "free_emails_monthly" => 10000,
                "free_did_monthly" => 2
            ],
            [
                "name" => "Trial",
                "description" => "Phone, Dialer, SMS/Email, Fax & Marketing",
                "is_active" => 1,
                "is_trial" => 1,
                "applicable_for" => 1,      #1 - b2b, 2 - b2c, 3 - both
                "show_on" => ["website"],   #website, portal
                "modules" => [
                    "phone",
                    "dialer",
                    "lead-management",
                    "conferencing",
                    "template-management",
                    "email-integration",
                    "sms-integration",
                    "fax-management",
                    "marketing-campaign",
                ],
                "currency_code" => "USD",
                "base_rate_monthly_billed" => 3999,
                "base_rate_quarterly_billed" => 13999,
                "base_rate_half_yearly_billed" => 19999,
                "base_rate_yearly_billed" => 38999,
                "call_rate_per_minute" => 0.015,
                "rate_per_sms" => 0.01,
                "rate_per_did" => 2,
                "rate_per_fax" => 0.02,
                "rate_per_email" => 0.0006,
                "free_call_minute_monthly" => 100,
                "free_sms_monthly" => 100,
                "free_fax_monthly" => 100,
                "free_emails_monthly" => 10000,
                "free_did_monthly" => 2
            ]
        ];

        foreach ($packages as $key => $package) {
            $packageFound = \App\Model\Master\Package::where("name", $package["name"])->first();
            if (!empty($packageFound)) {
                $packageFound->update($package);
            } else {
                $package["key"] = \Ramsey\Uuid\Uuid::uuid4()->toString();
                $package["display_order"] = $key + 1;
                $model = new \App\Model\Master\Package($package);
                $model->saveOrFail();
            }
        }
    }
}
