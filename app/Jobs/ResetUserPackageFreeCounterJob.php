<?php

namespace App\Jobs;

use App\Http\Controllers\StripeController;
use App\Http\Controllers\UserPackagesController;
use App\Model\Client\UserPackage;
use App\Model\Client\wallet;
use App\Model\Client\walletTransaction;
use App\Model\Master\ClientPackage;
use App\Model\Master\Order;
use App\Model\Master\Package;
use App\Model\Master\PaymentTransaction;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResetUserPackageFreeCounterJob extends Job
{
    public $tries = 5;
    public $timeout = 300;
    private $clientId;

    public function __construct($clientId)
    {
        $this->clientId = $clientId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info("ResetUserPackageFreeCounterJob.handle", [
            "clientId" => $this->clientId,
            "attempts" => $this->attempts()
        ]);

        try {
            //fetch packages
            $packages = Package::all()->toArray();
            $packagesRekeyed = UserPackagesController::rekeyArray($packages, 'key');

            //fetch client_packages
            $clientPackages = ClientPackage::where('client_id', '=', $this->clientId)->where('is_expired', '=', 0)->get()->toArray();
            $arrClientPackagesRekeyed = UserPackagesController::rekeyArray($clientPackages, 'id');

            //fetch user_packages
            $arrUserPackages = UserPackage::on('mysql_' . $this->clientId)->get();
            $arrUserPackagesRekeyed = $this->rekeyArrayOfObjects($arrUserPackages, 'client_package_id');

            $currentDate = Carbon::now();

            foreach ($arrClientPackagesRekeyed as $arrClientPackage) {
                $arrPackage = $packagesRekeyed[$arrClientPackage['package_key']];

                //Renew the subscription
                if ($arrClientPackage['expiry_time'] <= $currentDate) {
                    $strPaymentProcessedUsing = 'wallet';
                    $boolPaymentProcessed = FALSE;

                    //If package is trial package then no renewal.
                    if ($arrPackage['name'] == "Trial" || $arrPackage['key'] == Package::TRIAL_PACKAGE_KEY) {
                        DB::table('client_packages')->where('id', $arrClientPackage['id'])->update(['is_expired' => 1]);
                        Log::info("ResetUserPackageFreeCounterJob.handle.TrialExpired", [
                            "clientId" => $this->clientId,
                            "clientPackageId" => $arrClientPackage['id'],
                            "expiryDate" => $arrClientPackage['expiry_time']
                        ]);
                        continue;
                    }

                    $intAssociatedPackageCharge = $arrPackage[ClientPackage::$billingMapping[$arrClientPackage['billed']]];
                    $wallet = wallet::on('mysql_' . $this->clientId)->first()->toArray();

                    if ($wallet['amount'] >= $intAssociatedPackageCharge) {
                        $boolPaymentProcessed = wallet::debitCharge($intAssociatedPackageCharge, $this->clientId, $arrPackage['currency_code']);

                        if ($boolPaymentProcessed) {
                            //ledger entry into client_xxx.wallet_transactions
                            $objWalletTransaction = new WalletTransaction();
                            $objWalletTransaction->setConnection("mysql_" . $this->clientId);
                            $objWalletTransaction->currency_code = $arrPackage['currency_code'];
                            $objWalletTransaction->amount = $intAssociatedPackageCharge;
                            $objWalletTransaction->transaction_type = 'debit';
                            $objWalletTransaction->transaction_reference = '';
                            $objWalletTransaction->description = 'Renew subscription';
                            $objWalletTransaction->saveOrFail();
                        }
                    } else {
                        $strPaymentProcessedUsing = 'card';

                        //order table entry
                        $objOrder = new Order();
                        $objOrder->client_id = $this->clientId;
                        $objOrder->net_amount = $intAssociatedPackageCharge;
                        $objOrder->gross_amount = $intAssociatedPackageCharge;
                        $objOrder->status = 'initiated';
                        $objOrder->saveOrFail();

                        //Insufficient Wallet Balance THEN deduct money from default payment method(card)
                        $user = User::where('parent_id', '=', 1)->where('user_level', '=', 7)->where('role', '=', 1)->where('stripe_customer_id', '!=', NULL)->orderBy('created_at', 'ASC')->first()->toArray();
                        if (empty($user)) {
                            //Didn't get valid Admin user
                            Log::info("ResetUserPackageFreeCounterJob.handle.AdminUserNotFound", [
                                "clientId" => $this->clientId,
                                "currentBalance" => $wallet['amount'],
                                "associatedPackageCharge" => $intAssociatedPackageCharge
                            ]);
                        } else {
                            $strDefaultPaymentMethod = StripeController::getDefaultPaymentMethod($user['stripe_customer_id']);

                            if ($strDefaultPaymentMethod == NULL) {
                                Log::info("ResetUserPackageFreeCounterJob.handle.PaymentMethodNotSet", [
                                    "clientId" => $this->clientId,
                                    "userId" => $user['id'],
                                    "currentBalance" => $wallet['amount'],
                                    "associatedPackageCharge" => $intAssociatedPackageCharge
                                ]);
                            } else {
                                //Payment processing
                                $chargeResponse = $this->chargeUsingStripe($intAssociatedPackageCharge, $strDefaultPaymentMethod, $user['stripe_customer_id'], 'package subscription upgraded using card');

                                //payment_transactions table entry
                                $objPaymentTransactions = new PaymentTransaction();
                                $objPaymentTransactions->order_id = $objOrder->id;
                                $objPaymentTransactions->payment_gateway_type = 'stripe';
                                $objPaymentTransactions->response = $chargeResponse;

                                //order table status update
                                if ($chargeResponse->status == 'succeeded') {
                                    $boolPaymentProcessed = TRUE;
                                    $objPaymentTransactions->status = $objOrder->status = 'success';
                                } elseif ($chargeResponse->status == 'requires_payment_method' || $chargeResponse->status == 'requires_action') {
                                    $objPaymentTransactions->status = $objOrder->status = 'failed';
                                } else {
                                    $objPaymentTransactions->status = $objOrder->status = 'failed';
                                }

                                $objPaymentTransactions->saveOrFail();
                                $objOrder->saveOrFail();
                            }
                        }
                    }

                    if ($boolPaymentProcessed) {
                        //End date for next billing cycle
                        $strEndDate = ClientPackage::getEndDateAsPerBillingCycle($arrClientPackage['billed']);

                        $objClientPackage = new ClientPackage();
                        $objClientPackage->client_id = $this->clientId;
                        $objClientPackage->package_key = $arrPackage['key'];
                        $objClientPackage->quantity = $arrClientPackage['quantity'];
                        $objClientPackage->start_time = Carbon::now();
                        $objClientPackage->end_time = $strEndDate;
                        $objClientPackage->expiry_time = $strEndDate;
                        $objClientPackage->billed = $arrClientPackage['billed'];
                        $objClientPackage->payment_cent_amount = $intAssociatedPackageCharge * 100;
                        $objClientPackage->payment_time = Carbon::now();
                        $objClientPackage->payment_method = $strPaymentProcessedUsing;
                        $objClientPackage->psp_reference = time();
                        $objClientPackage->saveOrFail();

                        //Entry into user_packages
                        if (is_array($arrUserPackagesRekeyed[$arrClientPackage['id']])) {
                            foreach ($arrUserPackagesRekeyed[$arrClientPackage['id']] as $objUserPackage) {
                                $objNewUserPackage = new UserPackage();
                                $objNewUserPackage->setConnection('mysql_' . $this->clientId);
                                $objNewUserPackage->user_id = $objUserPackage->user_id;
                                $objNewUserPackage->client_package_id = $objClientPackage->id;
                                $objNewUserPackage->free_call_minutes = $arrPackage['free_call_minute_monthly'];
                                $objNewUserPackage->free_sms = $arrPackage['free_sms_monthly'];
                                $objNewUserPackage->free_fax = $arrPackage['free_fax_monthly'];
                                $objNewUserPackage->free_emails = $arrPackage['free_emails_monthly'];
                                $objNewUserPackage->free_reset_time = Carbon::now()->addMonth();
                                $objNewUserPackage->saveOrFail();

                                // Unset user_id of old entry from User_Packages
                                $objUserPackage->user_id = NULL;
                                $objUserPackage->saveOrFail();
                            }
                        } else {
                            for ($i = 0; $i < $arrClientPackage['quantity']; $i++) {
                                $objNewUserPackage = new UserPackage();
                                $objNewUserPackage->setConnection('mysql_' . $this->clientId);
                                $objNewUserPackage->client_package_id = $objClientPackage->id;
                                $objNewUserPackage->free_call_minutes = $arrPackage['free_call_minute_monthly'];
                                $objNewUserPackage->free_sms = $arrPackage['free_sms_monthly'];
                                $objNewUserPackage->free_fax = $arrPackage['free_fax_monthly'];
                                $objNewUserPackage->free_emails = $arrPackage['free_emails_monthly'];
                                $objNewUserPackage->free_reset_time = Carbon::now()->addMonth();
                                $objNewUserPackage->saveOrFail();
                            }
                        }

                        // Mark old entry as expired
                        ClientPackage::where('id', $arrClientPackage['id'])->update(['is_expired' => ClientPackage::CLIENT_PACKAGE_EXPIRED]);
                    }
                } else {
                    //Update free counts & free_reset_time
                    foreach ($arrUserPackagesRekeyed[$arrClientPackage['id']] as $objUserPackage) {
                        $freeResetTime = Carbon::parse($objUserPackage->free_reset_time);

                        if ($freeResetTime <= $currentDate) {
                            $objUserPackage->free_call_minutes = $arrPackage['free_call_minute_monthly'];
                            $objUserPackage->free_sms = $arrPackage['free_sms_monthly'];
                            $objUserPackage->free_fax = $arrPackage['free_fax_monthly'];
                            $objUserPackage->free_emails = $arrPackage['free_emails_monthly'];
                            $objUserPackage->free_reset_time = Carbon::parse($objUserPackage->free_reset_time)->addMonth();
                            $objUserPackage->save();
                        }
                    }
                }
            }
        } catch (\Throwable $throwable) {
            $context = buildContext($throwable, [
                "clientId" => $this->clientId
            ]);
            Log::error("ResetUserPackageFreeCounterJob.handle.error", $context);
        }
    }


    /**
     * @param $arrDataToRekey
     * @param $key
     * @return array categorized with $key
     */

    public static function rekeyArrayOfObjects($arrDataToRekey, $key)
    {
        if (empty($arrDataToRekey)) return [];

        $arrDataToReturn = [];
        foreach ($arrDataToRekey as $objSingleData) {
            $arrDataToReturn[$objSingleData->$key][] = $objSingleData;
        }
        return $arrDataToReturn;
    }

    public function chargeUsingStripe($amount, $paymentMethod, $stripeCustomerId, $description = "charges")
    {
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
        $response = \Stripe\PaymentIntent::create([
            'amount' => $amount * 100,
            'currency' => 'usd',
            'customer' => $stripeCustomerId,
            'payment_method' => $paymentMethod,
            'off_session' => true,
            'confirm' => true,
            'description' => $description
        ]);
        return $response;
    }
}
