<?php

namespace App\Http\Controllers\Ringless;

use App\Http\Controllers\Controller;
use App\Model\Client\Ringless\RinglessWallet;
use App\Model\Client\Ringless\RinglessWalletTransaction;
use App\Model\Master\Ringless\RinglessOrder;
use App\Model\Master\Ringless\RinglessPaymentTransaction;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RinglessRechargeController extends Controller
{


    /**
     * @OA\Post(
     *     path="/ringless/stripe/recharge",
     *     summary="Recharge wallet using saved or new payment method",
     *     tags={"RinglessRecharge"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"request_type"},
     *             @OA\Property(property="request_type", type="string", example="recharge"),
     *             @OA\Property(property="amount", type="number", format="float", example=100),
     *             @OA\Property(property="payment_method", type="string", example="pm_1Jxxxxxxxxxxxxxx"),
     *             
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="line1", type="string", example="123 Main Street"),
     *             @OA\Property(property="city", type="string", example="New York"),
     *             @OA\Property(property="state", type="string", example="NY"),
     *             @OA\Property(property="country", type="string", example="US"),
     *             @OA\Property(property="postal_code", type="string", example="10001"),
     *             @OA\Property(property="number", type="string", example="4242424242424242"),
     *             @OA\Property(property="exp_month", type="string", example="12"),
     *             @OA\Property(property="exp_year", type="string", example="2025"),
     *             @OA\Property(property="cvc", type="string", example="123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Recharge successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Recharge successful"),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation or Processing Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to place order"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function recharge(Request $request)
    {
        $strPaymentMethodId = $strPaymentDescription = NULL;
        $boolPaymentFailed = FALSE;
        $intTotalAmount = 0;

        //Validations
        if ($request->get('payment_method') != '0') {
            $this->validate($request, [
                'payment_method' => 'required|string'
            ]);
        } else {
            $this->validate($request, [
                'name' => 'required|string',
                'line1' => 'required|string',
                'city' => 'required|string',
                'state' => 'required|string',
                'country' => 'required|string',
                'postal_code' => 'required',
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
            $objTrunkingMethodController = new RinglessPaymentMethodController($request);
            //Handler for recharge request
            if ($request->get('request_type') == 'recharge') {
                $intTotalAmount = $request->get('amount');
                $strPaymentDescription = "Recharge";
            }

            if ($request->get('payment_method') != '0') {
                $strPaymentMethodId = $request->payment_method;

                //order table entry
                $objOrder = new RinglessOrder();
                $objOrder->client_id = $request->auth->parent_id;
                $objOrder->net_amount = $intTotalAmount;
                $objOrder->gross_amount = $intTotalAmount;
                $objOrder->status = 'initiated';
                $objOrder->saveOrFail();
            } else {

                //order table entry
                $objOrder = new RinglessOrder();
                $objOrder->client_id = $request->auth->parent_id;
                $objOrder->net_amount = $intTotalAmount;
                $objOrder->gross_amount = $intTotalAmount;
                $objOrder->status = 'initiated';
                $objOrder->saveOrFail();

                //Create stripe customer ID if not present
                if ($request->auth->stripe_customer_id) {
                    $objTrunkingMethodController->getStripeCustomerId();
                }

                //Fetch customer associated Payment Methods
                $customerPaymentMethods = $objTrunkingMethodController->fetchStripeCustomerPaymentMethod();
                // Log::info('reached',['customerPaymentMethods'=>$customerPaymentMethods]);

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
                        'name' => $request->name,
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
                $objTrunkingMethodController->attachCustomerToPaymentMethod($paymentMethod->id);

                //Update default_payment_method for the first payment method for that customer
                if (empty($customerPaymentMethods->data)) {
                    $objTrunkingMethodController->setDefaultPaymentMethod($paymentMethod->id);
                }
            }

            //Payment processing
            $chargeResponse = $objTrunkingMethodController->chargeUsingStripe($intTotalAmount, $strPaymentMethodId, $strPaymentDescription);

            //payment_transactions table entry
            $objPaymentTransactions = new RinglessPaymentTransaction();
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



            //For recharge : wallet & wallet_transaction entry
            if ($request->get('request_type') == 'recharge') {

                //Add amount into client_xxx.wallet
                $res = RinglessWallet::creditCharge($intTotalAmount, $request->auth->parent_id, 'USD');

                //ledger entry into client_xxx.wallet_transactions
                $objWalletTransaction = new RinglessWalletTransaction();
                $objWalletTransaction->setConnection("mysql_" . $request->auth->parent_id);
                $objWalletTransaction->currency_code = 'USD';
                $objWalletTransaction->amount = $intTotalAmount;
                $objWalletTransaction->transaction_type = 'credit';
                $objWalletTransaction->transaction_reference = '';
                $objWalletTransaction->description = 'Recharge';
                $objWalletTransaction->saveOrFail();

                return $this->successResponse("Recharge successful", []);
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
