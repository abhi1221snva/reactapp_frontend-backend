<?php

namespace App\Http\Controllers;

use App\Cart;
use App\Mail\GenericMail;
use App\Model\Client\SmtpSetting;
use App\Model\Client\UserPackage;
use App\Model\Client\wallet;
use App\Model\Client\walletTransaction;
use App\Model\Master\ClientPackage;
use App\Model\Master\Order;
use App\Model\Master\OrdersItem;
use App\Model\Master\Package;
use App\Model\Master\PaymentTransaction;
use App\Services\MailService;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\StripeController;

class CheckoutController extends Controller
{
    public function processCheckout(Request $request)
    {

        $arrDataForClientPackages = [];
        $strPaymentMethodId = $strPaymentDescription = NULL;
        $boolPaymentFailed = FALSE;
        $intTotalAmount = 0;

        //Validations
        if ($request->get('payment_method') != '0') {
            $this->validate($request, [
                'payment_method' => 'required|string'
            ]);
        } else{
            $this->validate($request, [
                'full_name' => 'required|string',
                'line1' => 'required|string',
                'city' => 'required|string',
                'state' => 'required|string',
                'country' => 'required|string',
                'postal_code' => 'required|numeric',
                'number' => 'required',
                'exp_month' => 'required|numeric|digits:2',
                'exp_year' => 'required|numeric|digits:4',
                'cvc' => 'required|numeric|digits_between:3,4'
            ]);
        }

        if ($request->get('request_type') == 'recharge') {
            $this->validate($request, [
                'amount' => 'required|numeric'
            ]);
        }

        try {
            $objStripeController = new StripeController($request);
            $objCartController = new CartController();
            Log::info('reached',['objStripeController'=>$objStripeController]);
            //Handler for recharge request
            if ($request->get('request_type') == 'recharge') {
                $intTotalAmount = $request->get('amount');
                $strPaymentDescription = "Recharge";
            } else {
                $intTotalAmount = $objCartController->calculateCartTotalAmount($request->auth->parent_id);
                $strPaymentDescription = "Package purchase";
            }
            
            if ($request->get('payment_method') != '0') {
                $strPaymentMethodId = $request->payment_method;
                
                //order table entry
                $objOrder = new Order();
                $objOrder->client_id = $request->auth->parent_id;
                $objOrder->net_amount = $intTotalAmount;
                $objOrder->gross_amount = $intTotalAmount;
                $objOrder->status = 'initiated';
                $objOrder->saveOrFail();
                
            } else {
                
                //order table entry
                $objOrder = new Order();
                $objOrder->client_id = $request->auth->parent_id;
                $objOrder->net_amount = $intTotalAmount;
                $objOrder->gross_amount = $intTotalAmount;
                $objOrder->status = 'initiated';
                $objOrder->saveOrFail();
                
                //Create stripe customer ID if not present
                if ($request->auth->stripe_customer_id) {
                    $objStripeController->getStripeCustomerId();
                }
                
                //Fetch customer associated Payment Methods
                $customerPaymentMethods = $objStripeController->fetchStripeCustomerPaymentMethod();

                //Create payment Method
                $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));
                $paymentMethod = $stripe->paymentMethods->create([
                    'type' => 'card',
                    'card' => [
                        'number' => $request->number,
                        'exp_month' => $request->exp_month,
                        'exp_year' => $request->exp_year,
                        'cvc' => $request->cvc,
                    ],
                    'billing_details' => [
                        'name' => $request->full_name,
                        'address' => [
                            'city' => $request->city,
                            'country' => $request->country,
                            'line1' => $request->line1,
                            'postal_code' => $request->postal_code,
                            'state' => $request->state,
                        ]
                    ]
                ]);

                $strPaymentMethodId = $paymentMethod->id;

                //Attach Customer And Payment Method
                $objStripeController->attachCustomerToPaymentMethod($paymentMethod->id);

                //Update default_payment_method for the first payment method for that customer
                if (empty($customerPaymentMethods->data)) {
                    $objStripeController->setDefaultPaymentMethod($paymentMethod->id);
                }
            }

            //Payment processing
            $chargeResponse = $objStripeController->chargeUsingStripe($intTotalAmount, $strPaymentMethodId, $strPaymentDescription);

            //payment_transactions table entry
            $objPaymentTransactions = new PaymentTransaction();
            $objPaymentTransactions->order_id = $objOrder->id;
            $objPaymentTransactions->payment_gateway_type = 'stripe';
            $objPaymentTransactions->response = $chargeResponse;

            //order table status update
            if ($chargeResponse->status == 'succeeded') {
                $objPaymentTransactions->status = 'success';
                $objOrder->status = 'success';
            } elseif ($chargeResponse->status == 'requires_payment_method') {
                $objPaymentTransactions->status = 'failed';
                $objOrder->status = 'failed';
                $boolPaymentFailed = TRUE;
            } elseif ($chargeResponse->status == 'requires_action') {
                $objPaymentTransactions->status = 'failed';
                $objOrder->status = 'failed';
                $boolPaymentFailed = TRUE;
            }

            $objPaymentTransactions->saveOrFail();
            $objOrder->saveOrFail();

            if ($boolPaymentFailed) {
                Log::debug("CheckoutController.processCheckout", [
                    "clientId" => $request->auth->parent_id,
                    "user" => $request->auth,
                    "orderId" => $objOrder->id,
                    "message" => 'Payment processing failed'
                ]);

                //Send Email in case of failed transaction
                $context["clientId"] = $request->auth->parent_id;
                $context["userId"] = $request->auth->id;
                $context["orderId"] = $objOrder->id;
                $context["paymentTransactions"] = $objPaymentTransactions->id;

                $emailBody = view('emails.errorNotification', compact('context'))->render();
                $genericMail = new GenericMail(
                    "Payment processing failed",
                    [
                        "address" => "rohit@cafmotel.com",
                        "name" => "Cafmotel Order Process"
                    ],
                    $emailBody
                );

                $smtpSetting = SmtpSetting::getBySenderType('mysql_' . $request->auth->parent_id, "system");
                $mailService = new MailService($request->auth->parent_id, $genericMail, $smtpSetting);
                $mailService->sendEmail([env('ADMIN_EMAIL')]);

                return $this->failResponse("Payment processing failed!", []);
            }

            //For recharge : wallet & wallet_transaction entry
            if ($request->get('request_type') == 'recharge') {

                //Add amount into client_xxx.wallet
                $res = wallet::creditCharge($intTotalAmount, $request->auth->parent_id, 'USD');

                //ledger entry into client_xxx.wallet_transactions
                $objWalletTransaction = new WalletTransaction();
                $objWalletTransaction->setConnection("mysql_" . $request->auth->parent_id);
                $objWalletTransaction->currency_code = 'USD';
                $objWalletTransaction->amount = $intTotalAmount;
                $objWalletTransaction->transaction_type = 'credit';
                $objWalletTransaction->transaction_reference = '';
                $objWalletTransaction->description = 'Recharge';
                $objWalletTransaction->saveOrFail();

                return $this->successResponse("Recharge successful", []);
            } else {
                //fetch packages & cart items
                $packages = Package::all()->toArray();
                $packagesRekeyed = UserPackagesController::rekeyArray($packages, 'key');
                $cartItems = Cart::on("mysql_" . $request->auth->parent_id)->get()->toArray();

                foreach ($cartItems as $cartItem) {
                    //Entry into order_items
                    $objOrdersItem = new OrdersItem();
                    $objOrdersItem->order_id = $objOrder->id;
                    $objOrdersItem->description = 'package purchase';
                    $objOrdersItem->package_key = $cartItem['package_key'];
                    $objOrdersItem->quantity = $cartItem['quantity'];
                    $objOrdersItem->billed = $cartItem['billed'];
                    $objOrdersItem->amount = $cartItem['quantity'] * $packagesRekeyed[$cartItem['package_key']][ClientPackage::$billingMapping[$cartItem['billed']]];
                    $objOrdersItem->saveOrFail();

                    if (array_key_exists($cartItem['package_key'] . "_" . $cartItem['billed'], $arrDataForClientPackages)) {
                        $arrDataForClientPackages[$cartItem['package_key'] . "_" . $cartItem['billed']]['quantity'] += $cartItem['quantity'];
                    } else {
                        $arrDataForClientPackages[$cartItem['package_key'] . "_" . $cartItem['billed']] = $cartItem;
                    }
                }

                //Terminate current trial package if active
                $trialPackageAssigned = ClientPackage::where('client_id', '=', $request->auth->parent_id)->where('package_key', '=', Package::TRIAL_PACKAGE_KEY)->where('end_time', '>=', date('Y-m-d h:i:s'))->get();
                if (!$trialPackageAssigned->isEmpty()) {
                    $trialPackageAssigned[0]->end_time = Carbon::now();
                    $trialPackageAssigned[0]->expiry_time = Carbon::now();
                    $trialPackageAssigned[0]->saveOrFail();
                }

                //If current user NOT associated with any package THEN assign new one
                $clientPackages = ClientPackage::where('client_id', '=', $request->auth->parent_id)->where('end_time', '>=', date('Y-m-d h:i:s'))->get()->toArray();
                $clientPackagesRekeyed = UserPackagesController::rekeyArray($clientPackages, 'id');

                $userPackages = DB::connection('mysql_' . $request->auth->parent_id)->table('user_packages')->get()->toArray();
                $intClientPackageId = UserPackagesController::getPackageAssignedToUser($userPackages, $request->auth->id);

                foreach ($arrDataForClientPackages as $cartItemPackageWise) {
                    $paymentAmount = $cartItemPackageWise['quantity'] * $packagesRekeyed[$cartItemPackageWise['package_key']][ClientPackage::$billingMapping[$cartItemPackageWise['billed']]];

                    //End date for next billing cycle
                    $strEndDate = ClientPackage::getEndDateAsPerBillingCycle($cartItemPackageWise['billed']);

                    //entry into client_packages
                    $objClientPackage = new ClientPackage();
                    $objClientPackage->client_id = $request->auth->parent_id;
                    $objClientPackage->package_key = $cartItemPackageWise['package_key'];
                    $objClientPackage->quantity = $cartItemPackageWise['quantity'];
                    $objClientPackage->start_time = Carbon::now();
                    $objClientPackage->end_time = $strEndDate;
                    $objClientPackage->expiry_time = $strEndDate;
                    $objClientPackage->billed = $cartItemPackageWise['billed'];
                    $objClientPackage->payment_cent_amount = $paymentAmount * 100;
                    $objClientPackage->payment_time = Carbon::now();
                    $objClientPackage->payment_method = "stripe";
                    $objClientPackage->psp_reference = time();
                    $objClientPackage->saveOrFail();

                    //entries into user_packages
                    for ($i = 0; $i < $cartItemPackageWise['quantity']; $i++) {
                        $objUserPackage = new UserPackage();
                        $objUserPackage->setConnection('mysql_' . $request->auth->parent_id);
                        $objUserPackage->client_package_id = $objClientPackage->id;
                        $objUserPackage->free_call_minutes = $packagesRekeyed[$cartItemPackageWise['package_key']]['free_call_minute_monthly'];
                        $objUserPackage->free_sms = $packagesRekeyed[$cartItemPackageWise['package_key']]['free_sms_monthly'];
                        $objUserPackage->free_fax = $packagesRekeyed[$cartItemPackageWise['package_key']]['free_fax_monthly'];
                        $objUserPackage->free_emails = $packagesRekeyed[$cartItemPackageWise['package_key']]['free_emails_monthly'];
                        $objUserPackage->free_reset_time = Carbon::now()->addMonth();

                        if ($i == 0 && !$intClientPackageId) {
                            $objUserPackage->user_id = $request->auth->id;
                        } elseif (!array_key_exists($intClientPackageId, $clientPackagesRekeyed)) {
                            $objUserPackage->user_id = $request->auth->id;
                        }

                        $objUserPackage->saveOrFail();
                    }
                }

                //remove all entries from cart table
                Cart::on("mysql_" . $request->auth->parent_id)->delete();
                return $this->successResponse("Order completed successfully", []);
            }

        } catch (\Throwable $exception) {
            Log::debug("CheckoutController.processCheckout", [
                "clientId" => $request->auth->parent_id,
                "user" => $request->auth,
                "message" => 'Failed to place order'
            ]);
            return $this->failResponse("Failed to place order", [$exception->getMessage()], $exception);
        }
    }
}
