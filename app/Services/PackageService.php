<?php


namespace App\Services;

use App\Model\Client\UserPackage;
use App\Model\Master\Client;
use App\Model\Master\ClientPackage;
use App\Model\Master\Package;
use App\Model\Master\Permission;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class PackageService
{

    public static function seedUserPackage(ClientPackage $clientPackage, array $assignToUsers = [])
    {

        $seededCount = UserPackage::on("mysql_{$clientPackage->client_id}")->where("client_package_id", $clientPackage->id)->count();
        Log::info("PackageService::seedUserPackage already seeded", [
            "ClientPackage" => $clientPackage->toArray(),
            "assignToUsers" => $assignToUsers,
            "seededCount" => $seededCount
        ]);

        if ($seededCount >= $clientPackage->quantity) {
            return true;
        }

        #if seeded count is > 0, check assigned user is already give license or not
        if ($seededCount > 0 && !empty($assignToUsers)) {
            foreach ($assignToUsers as $assignToUserId) {
                $objUserPackage = UserPackage::on("mysql_{$clientPackage->client_id}")->where("user_id", $assignToUserId)->first();
                if (!empty($objUserPackage)) {
                    if (($key = array_search($assignToUserId, $assignToUsers)) !== false) {
                        unset($assignToUsers[$key]);
                    }
                }
            }
        }

        #Entries into client_***.user_packages table.
        $objPackage = Package::findOrFail($clientPackage->package_key);
        $quantity = ($clientPackage->quantity - $seededCount);
        Log::info("PackageService::seedUserPackage seed quantity", [
            "packageKey" => $clientPackage->package_key,
            "assignToUsers" => $assignToUsers,
            "seededCount" => $seededCount,
            "quantityToSeed" => $quantity
        ]);

        for ($i = 0; $i < $quantity; $i++) {
            $objUserPackage = new UserPackage();
            $objUserPackage->setConnection("mysql_{$clientPackage->client_id}");
            $objUserPackage->client_package_id = $clientPackage->id;
            $objUserPackage->free_call_minutes = $objPackage->free_call_minute_monthly;
            $objUserPackage->free_sms = $objPackage->free_sms_monthly;
            $objUserPackage->free_fax = $objPackage->free_fax_monthly;
            $objUserPackage->free_emails = $objPackage->free_emails_monthly;
            $objUserPackage->free_reset_time = date('Y-m-d h:i:s', strtotime("+1 month", strtotime($clientPackage->start_time)));
            $objUserPackage->saveOrFail();

            if (!empty($assignToUsers)) {
                $userId = array_shift($assignToUsers);
                $objUserPackage->user_id = $userId;
                $objUserPackage->saveOrFail();
            }
        }

        return true;
    }


    public static function assignPackageToClientUsers(Client $client, Package $package, int $quantity, $expiryDate)
    {
        $clientPackages = ClientPackage::where("client_id", $client->id)->get()->all();
        if (!empty($clientPackages)) {
            echo "Client {$client->id}: {$client->company_name} already have package assigned\n";
        }

        $assignToUsers = [];

        #get all users for client from permissions table
        $clientPermissions = Permission::where("client_id", $client->id)->get()->all();
        foreach ($clientPermissions as $permission) {
            $user = User::where('id', $permission->user_id)
                ->where('parent_id', $client->id)
                ->first();
            if (empty($user)) {
                $permission->delete();
                echo "Deleted the permission for user id {$permission->user_id}\n";
            } elseif ($user->is_deleted == 0 && $user->user_level <= 7) {
                $assignToUsers[] = $user->id;
            }
        }

        $clientPackage = new ClientPackage();
        $clientPackage->client_id = $client->id;
        $clientPackage->package_key = $package->key;
        $clientPackage->quantity = $quantity;
        $clientPackage->start_time = Carbon::now();
        $clientPackage->end_time = $expiryDate;
        $clientPackage->expiry_time = $expiryDate;
        $clientPackage->billed = 4;
        $clientPackage->payment_cent_amount = 0;
        $clientPackage->payment_time = Carbon::now();
        $clientPackage->payment_method = "free";
        $clientPackage->psp_reference = time();
        $clientPackage->saveOrFail();

        #Entries into client_***.user_packages table.
        if (!empty($assignToUsers))
            PackageService::seedUserPackage($clientPackage, $assignToUsers);
    }
}
