<?php

use Illuminate\Database\Seeder;

class NotificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $notications = [
            [
                "id" => "list_add_delete",
                "name" => "Notification for list uploaded/deleted",
                "type" => "email",
                "type_sms" => "sms",
                "display_order" => 1
            ],
            [
                "id" => "extension_add_delete",
                "name" => "Notification for extension create/delete",
                "type" => "email",
                "type_sms" => "sms",

                "display_order" => 2
            ],
            [
                "id" => "campaign_low_lead",
                "name" => "Notification for campaign low leads",
                "type" => "email",
                "type_sms" => "sms",
                "display_order" => 3
            ],
            [
                "id" => "daily_call_report",
                "name" => "Daily call report",
                "type" => "email",
                "type_sms" => "sms",
                "display_order" => 4
            ],
            [
                "id" => "recycle_delete",
                "name" => "Notification for lead recycle",
                "type" => "email",
                "type_sms" => "sms",
                "display_order" => 5
            ],
            [
                "id" => "ip_whitelist",
                "name" => "IP Whitelisting notification",
                "type" => "email",
                "type_sms" => "sms",
                "display_order" => 6
            ],

            [
                "id" => "send_fax_email",
                "name" => "Fax Notification",
                "type" => "email",
                "type_sms" => "sms",
                "display_order" => 7
            ],

            [
                "id" => "send_callback",
                "name" => "Callback Notification",
                "type" => "email",
                "type_sms" => "sms",
                "display_order" => 8
            ],
        ];

        foreach ( $notications as $notication ) {
            $master = \App\Model\Master\SystemNotificationType::on("master")->find($notication["id"]);
            if (empty($master)) {
                echo "Adding {$notication["id"]} to master.system_notification_types\n";
                $master = new \App\Model\Master\SystemNotificationType([
                    "id" => $notication["id"],
                    "name" => $notication["name"],
                    "type" => $notication["type"],
                    "type_sms" => $notication["type_sms"],

                    "display_order" => $notication["display_order"]
                ]);
                $master->save();
            }
            else
            {
                $master->update($notication);
            }

            $clients = \App\Model\Master\Client::all();
            foreach ( $clients as $client ) {
                $subscription = \App\Model\Client\SystemNotification::on("mysql_".$client->id)->find($notication["id"]);
                if (empty($subscription)) {
                    echo "Adding {$notication["id"]} to client_{$client->id}.system_notifications\n";
                    $subscription = new \App\Model\Client\SystemNotification ([
                        "notification_id" => $notication["id"],
                        "active" => 0,
                        "active_sms" => 0,
                        "subscribers" => []
                    ]);
                    $subscription->setConnection("mysql_".$client->id);
                    $subscription->save();
                }
            }
        }
    }
}
