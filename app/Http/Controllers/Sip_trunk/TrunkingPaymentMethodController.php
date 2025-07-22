<?php

namespace App\Http\Controllers\Sip_trunk;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Model\User;
use DB;


class TrunkingPaymentMethodController extends Controller
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }
    /**
     * @OA\Post(
     *     path="/trunking/stripe/save-card",
     *     summary="Create Stripe customer and attach card payment method",
     *     tags={"TrunkingPayment"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "line1", "city", "state", "country", "postal_code", "number", "exp_month", "exp_year", "cvc"},
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="line1", type="string", example="123 Main St"),
     *             @OA\Property(property="city", type="string", example="New York"),
     *             @OA\Property(property="state", type="string", example="NY"),
     *             @OA\Property(property="country", type="string", example="US"),
     *             @OA\Property(property="postal_code", type="string", example="10001"),
     *             @OA\Property(property="number", type="string", example="4242424242424242"),
     *             @OA\Property(property="exp_month", type="string", example="12"),
     *             @OA\Property(property="exp_year", type="string", example="2026"),
     *             @OA\Property(property="cvc", type="string", example="123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Stripe customer and payment method created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="string", example="pm_1NvnM7IY6p0nDtxxxx"),
     *             @OA\Property(property="object", type="string", example="payment_method"),
     *             @OA\Property(property="type", type="string", example="card"),
     *             @OA\Property(property="billing_details", type="object",
     *                 @OA\Property(property="name", type="string", example="John Doe"),
     *                 @OA\Property(property="address", type="object",
     *                     @OA\Property(property="city", type="string", example="New York"),
     *                     @OA\Property(property="country", type="string", example="US"),
     *                     @OA\Property(property="line1", type="string", example="123 Main St"),
     *                     @OA\Property(property="postal_code", type="string", example="10001"),
     *                     @OA\Property(property="state", type="string", example="NY")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request - validation error or Stripe API failure"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error - unable to create Stripe customer or payment method"
     *     )
     * )
     */
    public function saveCard()
    {
        $paymentMethod = $this->createStripeCustomerPaymentMethod();
        //Attach Customer And Payment Method
        $this->attachCustomerToPaymentMethod($paymentMethod->id);
        return response()->json([
            'success' => 'true',
            'message' => 'Payment Method Added',
            'data' => $paymentMethod
        ]);
    }
    public function createStripeCustomerPaymentMethod()
    {

        $this->validate($this->request, [
            'name' => 'required',
            'line1' => 'required',
            'city' => 'required',
            'state' => 'required',
            'country' => 'required',
            'postal_code' => 'required',
            'number' => 'required',
            'exp_month' => 'required',
            'exp_year' => 'required',
            'cvc' => 'required'
        ]);
        $response = [];
        if ($this->request->auth->stripe_customer_id) {
            $stripeCustomerId = $this->request->auth->stripe_customer_id;
        } else {
            \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
            $customer = \Stripe\Customer::create([
                'name' => $this->request->auth->first_name . " " . $this->request->auth->last_name,
                'email' => $this->request->auth->email
            ]);

            $data['id'] = $this->request->auth->id;
            $query = "UPDATE users set stripe_customer_id = '" . $customer->id . "' WHERE id = :id";
            DB::connection('master')->update($query, $data);

            $stripeCustomerId = $customer->id;
        }
        Log::info('reached', ['stripe_id' => $stripeCustomerId]);

        if ($stripeCustomerId) {
            $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));
            $response = $stripe->paymentMethods->create([
                'type' => 'card',
                'card' => [
                    'number' => $this->request->number,
                    'exp_month' => $this->request->exp_month,
                    'exp_year' => $this->request->exp_year,
                    'cvc' => $this->request->cvc,
                ],
                'billing_details' => [
                    'name' => $this->request->name,
                    'address' => [
                        'city' => $this->request->city,
                        'country' => $this->request->country,
                        'line1' => $this->request->line1,
                        'postal_code' => $this->request->postal_code,
                        'state' => $this->request->state,
                    ]
                ]
            ]);
        }

        return $response;
    }
    public function attachCustomerToPaymentMethod($paymentMethod)
    {
        $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));
        return $stripe->paymentMethods->attach(
            $paymentMethod,
            ['customer' => $this->request->auth->stripe_customer_id]
        );
    }
    /**
     * @OA\Get(
     *     path="/trunking/stripe/get-customer-payment-method",
     *     summary="Get Stripe customer payment methods",
     *     description="Retrieves all saved card payment methods for the authenticated Stripe customer.",
     *     tags={"TrunkingPayment"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Stripe customer payment methods retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Stripe Customer Payment Methods"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="string", example="pm_1NvnM7IY6p0nDtxxxx"),
     *                     @OA\Property(property="type", type="string", example="card"),
     *                     @OA\Property(property="card", type="object",
     *                         @OA\Property(property="brand", type="string", example="visa"),
     *                         @OA\Property(property="last4", type="string", example="4242"),
     *                         @OA\Property(property="exp_month", type="integer", example=12),
     *                         @OA\Property(property="exp_year", type="integer", example=2026)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to get Stripe Customer Payment Methods"
     *     )
     * )
     */
    public function getStripeCustomerPaymentMethod()
    {
        $response = [];
        try {
            $response = $this->fetchStripeCustomerPaymentMethod();
            return $this->successResponse("Stripe Customer Payment Methods", [$response]);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to get Stripe Customer Payment Methods", [], $exception);
        }
    }
    public function fetchStripeCustomerPaymentMethod()
    {
        if ($this->request->auth->stripe_customer_id) {
            $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));
            return $stripe->paymentMethods->all([
                'customer' => $this->request->auth->stripe_customer_id,
                'type' => 'card',
            ]);
        }
    }

    /**
     * @OA\Post(
     *     path="/trunking/stripe/get-payment-method",
     *     summary="Retrieve a specific Stripe payment method by ID",
     *     tags={"TrunkingPayment"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"payment_method_id"},
     *             @OA\Property(property="payment_method_id", type="string", example="pm_1NvnM7IY6p0nDtxxxx")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Payment method retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Stripe Customer Id"),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="string", example="pm_1NvnM7IY6p0nDtxxxx"),
     *                     @OA\Property(property="type", type="string", example="card"),
     *                     @OA\Property(property="card", type="object",
     *                         @OA\Property(property="brand", type="string", example="visa"),
     *                         @OA\Property(property="last4", type="string", example="4242"),
     *                         @OA\Property(property="exp_month", type="integer", example=12),
     *                         @OA\Property(property="exp_year", type="integer", example=2026)
     *                     ),
     *                     @OA\Property(property="billing_details", type="object",
     *                         @OA\Property(property="name", type="string", example="John Doe")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid payment method ID"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error while retrieving payment method"
     *     )
     * )
     */
    public function getPaymentMethod()
    {
        $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));
        $payMethod = $stripe->paymentMethods->retrieve(
            $this->request->payment_method_id,
            []
        );
        return response()->json([
            'success' => 'true',
            'message' => 'Stripe Customer Id',
            'data' => [$payMethod]
        ]);
    }
    /**
     * @OA\Post(
     *     path="/trunking/stripe/update-card",
     *     summary="Update a Stripe payment method ",
     *     tags={"TrunkingPayment"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"payment_method_id", "exp_month", "exp_year", "full_name", "line1", "city", "state", "country", "postal_code"},
     *             @OA\Property(property="payment_method_id", type="string", example="pm_1NvxxxxxXyz"),
     *             @OA\Property(property="exp_month", type="integer", example=12),
     *             @OA\Property(property="exp_year", type="integer", example=2026),
     *             @OA\Property(property="full_name", type="string", example="John Doe"),
     *             @OA\Property(property="line1", type="string", example="123 Main Street"),
     *             @OA\Property(property="city", type="string", example="New York"),
     *             @OA\Property(property="state", type="string", example="NY"),
     *             @OA\Property(property="country", type="string", example="US"),
     *             @OA\Property(property="postal_code", type="string", example="10001")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Payment method updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Payment Method Updated"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="string", example="pm_1NvxxxxxXyz"),
     *                 @OA\Property(property="card", type="object",
     *                     @OA\Property(property="exp_month", type="integer", example=12),
     *                     @OA\Property(property="exp_year", type="integer", example=2026)
     *                 ),
     *                 @OA\Property(property="billing_details", type="object",
     *                     @OA\Property(property="name", type="string", example="John Doe"),
     *                     @OA\Property(property="address", type="object",
     *                         @OA\Property(property="line1", type="string", example="123 Main Street"),
     *                         @OA\Property(property="city", type="string", example="New York"),
     *                         @OA\Property(property="state", type="string", example="NY"),
     *                         @OA\Property(property="country", type="string", example="US"),
     *                         @OA\Property(property="postal_code", type="string", example="10001")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input or missing fields"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Stripe API or server error during card update"
     *     )
     * )
     */
    public function updateCard()
    {
        $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));
        $response = $stripe->paymentMethods->update(
            $this->request->payment_method_id,
            [
                'card' => [
                    'exp_month' => $this->request->exp_month,
                    'exp_year' => $this->request->exp_year,
                ],
                'billing_details' => [
                    'name' => $this->request->full_name,
                    'address' => [
                        'city' => $this->request->city,
                        'country' => $this->request->country,
                        'line1' => $this->request->line1,
                        'postal_code' => $this->request->postal_code,
                        'state' => $this->request->state,
                    ]
                ]
            ]
        );

        return response()->json([
            'success' => 'true',
            'message' => 'Payment Method Updated',
            'data' => $response
        ]);
    }
    /**
     * @OA\Post(
     *     path="/trunking/stripe/delete-stripe-payment_method",
     *     summary="Detach a Stripe payment method from a customer",
     *     description="Removes the association between a Stripe payment method and the customer.",
     *     tags={"TrunkingPayment"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"payment_method_id"},
     *             @OA\Property(property="payment_method_id", type="string", example="pm_1NvxxxxxXyz")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Payment method detached successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Stripe Customer And Payment Method detached"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="string", example="pm_1NvxxxxxXyz"),
     *                 @OA\Property(property="object", type="string", example="payment_method"),
     *                 @OA\Property(property="customer", type="string", nullable=true, example=null),
     *                 @OA\Property(property="livemode", type="boolean", example=false),
     *                 @OA\Property(property="type", type="string", example="card")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Missing or invalid payment_method_id"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Stripe API error or internal server error"
     *     )
     * )
     */
    public function deletePaymentMethod()
    {
        $this->validate($this->request, [
            'payment_method_id' => 'required'
        ]);
        $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));
        $response = $stripe->paymentMethods->detach(
            $this->request->payment_method_id
        );
        return response()->json([
            'success' => 'true',
            'message' => 'Stripe Customer And Payment Method detached',
            'data' => $response
        ]);
    }
    public function chargeUsingStripe($amount, $paymentMethod, $description = "charges")
    {
        if ($this->request->auth->stripe_customer_id) {
            \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
            $response = \Stripe\PaymentIntent::create([
                'amount' => $amount * 100,
                'currency' => 'usd',
                'customer' => $this->request->auth->stripe_customer_id,
                'payment_method' => $paymentMethod,
                'off_session' => true,
                'confirm' => true,
                'description' => $description
            ]);
        }
        return $response;
    }
    public function getStripeCustomerId()
    {
        if ($this->request->auth->stripe_customer_id) {
            $stripeCustomerId = $this->request->auth->stripe_customer_id;
        } else {
            \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
            $customer = \Stripe\Customer::create([
                'name' => $this->request->auth->first_name . " " . $this->request->auth->last_name,
                'email' => $this->request->auth->email
            ]);

            $data['id'] = $this->request->auth->id;
            $query = "UPDATE users set stripe_customer_id = '" . $customer->id . "' WHERE id = :id";
            DB::connection('master')->update($query, $data);

            $stripeCustomerId = $customer->id;
        }

        return response()->json([
            'success' => 'true',
            'message' => 'Stripe Customer Id',
            'data' => ['stripe_customer_id' => $stripeCustomerId]
        ]);
    }
}
