<?php

namespace App\Jobs;

use App\Model\Client\ExtensionGroup;
use App\Model\Extension;
use App\Model\Master\AsteriskServer;
use App\Model\Master\Client;
use App\Model\Master\ClientPackage;
use App\Model\Master\Prospect;
use App\Model\Master\ProspectPackage;
use App\Model\User;
use App\Services\PackageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ConvertProspectToClient extends Job
{
    private $prospectId;
    private $packageKey;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $prospectId, string $packageKey)
    {
        $this->prospectId = $prospectId;
        $this->packageKey = $packageKey;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info("ConvertProspectToClient.handle", [
            "prospectId" => $this->prospectId,
            "packageKey" => $this->packageKey
        ]);
        try {
            $prospect = Prospect::find($this->prospectId);
            $prospectPackage = ProspectPackage::where([['prospect_id', "=", $this->prospectId], ["package_key", "=", $this->packageKey]])->get()->first();
            if (empty($prospectPackage)) {
                Log::warning("Terminating ConvertProspectToClient.handle", [
                    "message" => "Subscribed package by prospect no found",
                    "prospect_id" => $this->prospectId,
                    "package_key" => $this->packageKey
                ]);
                echo "Subscribed package({$this->packageKey}) by prospect({$this->prospectId}) not found\n";
                return;
            }
            Log::info("ConvertProspectToClient.prospect", $prospect->toArray());

            #check if client entry created for the signup
            if (empty($prospect->client_id_assigned)) {
                try {
                    #if create client if not created
                    $attributes = [
                        'company_name' => $prospect->company_name,
                        'trunk' => env('NEW_CLIENT_TRUNK'),
                        'address_1' => $prospect->address_1,
                        'address_2' => $prospect->address_2,
                        'logo' => $prospect->logo,
                        'stage' => Client::RECORD_SAVED
                    ];
                    $client = Client::create($attributes);

                    #update the Subscription Signup with client id
                    $prospect->client_id_assigned = $client->id;
                    $prospect->status = Prospect::CLIENT_CREATED;
                    $prospect->saveOrFail();
                } catch (\Throwable $throwable) {
                    Log::error("ConvertProspectToClient.handle.2", buildContext($throwable));
                }
            } else {
                $client = Client::findOrFail($prospect->client_id_assigned);
            }

            #randomly select one asterisk server
            $randomServers = AsteriskServer::inRandomOrder()->limit(1)->get('id');
            $asteriskServers = [$randomServers[0]->id];

            #use CreateClientJob in sync mode
            $createClient = new CreateClientJob($client, $asteriskServers);
            $createClient->handle();

            #check is user already created.
            $user = User::where('email', $prospect->email)->first();
            if (empty($user)) {
                #First create extension group to which first user needs to be added
                $extensionGroup = new ExtensionGroup();
                $extensionGroup->setConnection("mysql_{$client->id}");
                $extensionGroup->title = "default";
                $extensionGroup->saveOrFail();

                #If not create new entry in users and extensions table
                $request = new \Illuminate\Http\Request();
                $request->replace([
                    "auth" => (object)[
                        "parent_id" => $client->id
                    ],
                    "group_id" => [$extensionGroup->id],
                    "first_name" => $prospect->first_name,
                    "last_name" => $prospect->last_name,
                    "email" => $prospect->email,
                    "password" => Str::random(8),
                    "extension" => 1001,
                    "alt_extension" => 1001,
                    "asterisk_server_id" => $randomServers[0]->id,
                    "mobile" => $prospect->mobile
                ]);
                Log::debug("ConvertProspectToClient create extension request", $request->all());
                $extensionModel = new Extension();
                $response = $extensionModel->newExtensionSave($request);

                $prospect->status = Prospect::USER_CREATED;
                $prospect->saveOrFail();

                $userData = $response['data'];
                $user = User::findOrFail($userData["id"]);
                $user->password = $prospect->password;
                $user->saveOrFail();
                Log::debug("ConvertProspectToClient password copied", [
                    "user" => $user->password,
                    "prospect" => $prospect->password
                ]);
            }

            #add admin role to first for the client
            $user->addPermission($client->id, 1);
            $user = $user->switchClient($client->id);

            #Entry from prospect_packages will be copied to master.client_packages
            $clientPackage = ClientPackage::where([['client_id', "=", $prospect->client_id_assigned], ["package_key", "=", $this->packageKey]])->get()->first();
            if (empty($clientPackage)) {
                $clientPackage = new ClientPackage();
                $clientPackage->client_id = $prospect->client_id_assigned;
                $clientPackage->package_key = $prospectPackage->package_key;
                $clientPackage->quantity = $prospectPackage->quantity;
                $clientPackage->start_time = $prospectPackage->start_time;
                $clientPackage->end_time = $prospectPackage->end_time;
                $clientPackage->expiry_time = $prospectPackage->expiry_time;
                $clientPackage->billed = $prospectPackage->billed;
                $clientPackage->payment_cent_amount = $prospectPackage->payment_cent_amount;
                $clientPackage->payment_time = $prospectPackage->payment_time;
                $clientPackage->payment_method = $prospectPackage->payment_method;
                $clientPackage->psp_reference = $prospectPackage->psp_reference;
                $clientPackage->saveOrFail();

                #Entries into client_***.user_packages table.
                PackageService::seedUserPackage($clientPackage, [$user->id]);

                $prospect->status = Prospect::PACKED_SUBSCRIBED;
                $prospect->saveOrFail();
            } else {
                PackageService::seedUserPackage($clientPackage, [$user->id]);
            }

            #Update the Prospect with status Onboarded
            $prospect->status = Prospect::ONBOARDED;
            $prospect->saveOrFail();
        } catch (\Throwable $baseThrowable) {
            Log::error("ConvertProspectToClient.handle.error", buildContext($baseThrowable));
            throw $baseThrowable;
        }
    }
}
