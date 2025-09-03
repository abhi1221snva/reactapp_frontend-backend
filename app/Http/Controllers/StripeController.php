<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Model\User;
use DB;
use Stripe\Stripe;
use Stripe\PaymentMethod;
use Stripe\Customer;
class StripeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /*
     * Get/Set Stripe Customer ID
     * @return json
     */
    /**
     * @OA\Get(
     *     path="/stripe/get-customer-id",
     *     summary="Get or Create Stripe Customer ID",
     *     description="Returns the Stripe Customer ID for the authenticated user. Creates a new customer in Stripe if one does not exist.",
     *     tags={"Stripe"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Stripe Customer ID retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Stripe Customer Id"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="stripe_customer_id", type="string", example="cus_NxE8X7k9rPQqHf")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to create or retrieve Stripe Customer ID",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Internal Server Error")
     *         )
     *     )
     * )
     */

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

    /*
     * Stripe Payment Method
     * @return json
     */
    /**
     * @OA\Get(
     *     path="/stripe/get-customer-payment-method",
     *     summary="Get Stripe Customer Payment Methods",
     *     description="Fetches all saved card payment methods associated with the Stripe customer ID of the authenticated user.",
     *     tags={"Stripe"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Stripe Customer Payment Methods retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Stripe Customer Payment Methods"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="string", example="pm_1NXR7yJHq9O9w7HGVKoD1zRo"),
     *                     @OA\Property(property="type", type="string", example="card"),
     *                     @OA\Property(
     *                         property="card",
     *                         type="object",
     *                         @OA\Property(property="brand", type="string", example="visa"),
     *                         @OA\Property(property="last4", type="string", example="4242"),
     *                         @OA\Property(property="exp_month", type="integer", example=12),
     *                         @OA\Property(property="exp_year", type="integer", example=2025)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch Stripe Customer Payment Methods",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to get Stripe Customer Payment Methods")
     *         )
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

    /*
     * Create Stripe Payment Method
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/stripe/create-customer-payment-method",
     *     summary="Create a Stripe Customer Payment Method",
     *     description="Creates a new card payment method and attaches it to the authenticated user's Stripe customer account.",
     *     tags={"Stripe"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"full_name","line1","city","state","country","postal_code","number","exp_month","exp_year","cvc"},
     *             @OA\Property(property="full_name", type="string", example="John Doe"),
     *             @OA\Property(property="line1", type="string", example="123 Main St"),
     *             @OA\Property(property="city", type="string", example="San Francisco"),
     *             @OA\Property(property="state", type="string", example="CA"),
     *             @OA\Property(property="country", type="string", example="US"),
     *             @OA\Property(property="postal_code", type="string", example="94105"),
     *             @OA\Property(property="number", type="string", example="4242424242424242"),
     *             @OA\Property(property="exp_month", type="integer", example=12),
     *             @OA\Property(property="exp_year", type="integer", example=2026),
     *             @OA\Property(property="cvc", type="string", example="123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Stripe Payment Method created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="string", example="pm_1NXRYxJHq9O9w7HGzqvKDiSA"),
     *             @OA\Property(property="object", type="string", example="payment_method"),
     *             @OA\Property(property="type", type="string", example="card"),
     *             @OA\Property(
     *                 property="card",
     *                 type="object",
     *                 @OA\Property(property="brand", type="string", example="visa"),
     *                 @OA\Property(property="last4", type="string", example="4242"),
     *                 @OA\Property(property="exp_month", type="integer", example=12),
     *                 @OA\Property(property="exp_year", type="integer", example=2026)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 @OA\Property(property="full_name", type="array", @OA\Items(type="string", example="The full name field is required."))
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error or Stripe error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="An error occurred while creating the payment method.")
     *         )
     *     )
     * )
     */

    public function createStripeCustomerPaymentMethod()
    {
        $this->validate($this->request, [
            'full_name' => 'required',
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
                    'name' => $this->request->full_name,
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

    /**
     * Attach Stripe Customer Payment Method
     * @param type $param
     */
    /**
     * @OA\Post(
     *     path="/stripe/attach-customer-and-payment-method",
     *     summary="Attach Stripe Payment Method to Customer",
     *     description="Attaches a given Stripe payment method to the authenticated user's Stripe customer account.",
     *     tags={"Stripe"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"payment_method"},
     *             @OA\Property(property="payment_method", type="string", example="pm_1NXRYxJHq9O9w7HGzqvKDiSA")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Stripe Payment Method successfully attached to customer",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Stripe Customer And Payment Method Attached"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="string", example="pm_1NXRYxJHq9O9w7HGzqvKDiSA"),
     *                 @OA\Property(property="customer", type="string", example="cus_O7nHg4Gy9HhX5J")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Invalid Stripe customer ID or payment method.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error or Stripe error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to attach payment method to customer."),
     *             @OA\Property(property="data", type="object")
     *         )
     *     )
     * )
     */

    public function attachCustomerAndPaymentMethod(Request $request)
    {
        $response = [];
        if ($this->request->auth->stripe_customer_id) {
            $response = $this->attachCustomerToPaymentMethod($request->payment_method);
        }

        return response()->json([
            'success' => 'true',
            'message' => 'Stripe Customer And Payment Method Attached',
            'data' => $response
        ]);
    }

    /**
     * Charge
     * @param type $param
     */
    /**
     * @OA\Post(
     *     path="/stripe/charge",
     *     summary="Charge Stripe Customer",
     *     description="Creates a charge on the Stripe customer using the provided payment method and amount.",
     *     tags={"Stripe"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"amount", "payment_method"},
     *             @OA\Property(property="amount", type="number", format="float", example=49.99, description="Amount to be charged"),
     *             @OA\Property(property="payment_method", type="string", example="pm_1NXRYxJHq9O9w7HGzqvKDiSA", description="Stripe payment method ID")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Charge initiated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Stripe Intent"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="string", example="pi_1NXRYpJHq9O9w7HGxqgVuKaH"),
     *                     @OA\Property(property="status", type="string", example="succeeded"),
     *                     @OA\Property(property="amount", type="number", format="float", example=49.99),
     *                     @OA\Property(property="currency", type="string", example="usd")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Invalid input parameters.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Stripe Charge Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to charge the customer."),
     *             @OA\Property(property="data", type="object")
     *         )
     *     )
     * )
     */

    public function charge()
    {
        $response = [];
        $response = $this->chargeUsingStripe($this->request->amount, $this->request->payment_method);

        return response()->json([
            'success' => 'true',
            'message' => 'Stripe Intent',
            'data' => [$response]
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

    public function attachCustomerToPaymentMethod($paymentMethod)
    {
        $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));
        return $stripe->paymentMethods->attach(
            $paymentMethod,
            ['customer' => $this->request->auth->stripe_customer_id]
        );
    }

    public function setDefaultPaymentMethod($paymentMethod)
    {
        $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));
        return $stripe->customers->update(
            $this->request->auth->stripe_customer_id,
            ['invoice_settings' => ['default_payment_method' => $paymentMethod]]
        );
    }

    /**
     * Delete Payment method
     * @param type $paymentMethod
     * @return type
     */

    /**
     * @OA\Post(
     *     path="/stripe/delete-stripe-payment_method",
     *     summary="Detach Stripe Payment Method",
     *     description="Detaches a saved payment method from the Stripe customer.",
     *     tags={"Payment"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"payment_method_id"},
     *             @OA\Property(
     *                 property="payment_method_id",
     *                 type="string",
     *                 example="pm_1NhlYJSGUYkN0cX0eABC1234"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Payment method detached successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Stripe Customer And Payment Method detached"),
     *             @OA\Property(property="data", type="object",
     *                 description="Stripe payment method response"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request or Missing Parameter",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error")
     *         )
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

    /*
     * Get Payment method
     * @return json
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
     * Add new card
     * @return type
     */
    /**
     * @OA\Post(
     *     path="/stripe/save-card",
     *     summary="Create a Stripe Customer Payment Method",
     *     description="Creates a new Stripe payment method for an existing customer using card details.",
     *     tags={"Stripe"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"full_name", "line1", "city", "state", "country", "postal_code", "number", "exp_month", "exp_year", "cvc"},
     *             @OA\Property(property="full_name", type="string", example="John Doe", description="Full name of the cardholder"),
     *             @OA\Property(property="line1", type="string", example="123 Main Street", description="Billing address line 1"),
     *             @OA\Property(property="city", type="string", example="San Francisco", description="Billing city"),
     *             @OA\Property(property="state", type="string", example="CA", description="Billing state"),
     *             @OA\Property(property="country", type="string", example="US", description="Billing country"),
     *             @OA\Property(property="postal_code", type="string", example="94111", description="Billing postal code"),
     *             @OA\Property(property="number", type="string", example="4242424242424242", description="Card number"),
     *             @OA\Property(property="exp_month", type="integer", example=12, description="Card expiration month"),
     *             @OA\Property(property="exp_year", type="integer", example=2025, description="Card expiration year"),
     *             @OA\Property(property="cvc", type="string", example="123", description="Card CVC code")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Payment Method Created Successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Payment Method Created Successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="string", example="pm_1NXRYxJHq9O9w7HGzqvKDiSA"),
     *                 @OA\Property(property="type", type="string", example="card"),
     *                 @OA\Property(property="card", type="object", 
     *                     @OA\Property(property="brand", type="string", example="visa"),
     *                     @OA\Property(property="last4", type="string", example="4242"),
     *                     @OA\Property(property="exp_month", type="integer", example=12),
     *                     @OA\Property(property="exp_year", type="integer", example=2025)
     *                 ),
     *                 @OA\Property(
     *                     property="billing_details",
     *                     type="object",
     *                     @OA\Property(property="name", type="string", example="John Doe"),
     *                     @OA\Property(
     *                         property="address",
     *                         type="object",
     *                         @OA\Property(property="line1", type="string", example="123 Main Street"),
     *                         @OA\Property(property="city", type="string", example="San Francisco"),
     *                         @OA\Property(property="state", type="string", example="CA"),
     *                         @OA\Property(property="country", type="string", example="US"),
     *                         @OA\Property(property="postal_code", type="string", example="94111")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Missing or Invalid Parameters.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to create payment method.")
     *         )
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

public function saveCardNew(Request $request)
{
    // Validate that the payment_method token is present.


    try {
        Stripe::setApiKey(env('STRIPE_SECRET'));

        $email = $request->auth->email;
        $first_name = $request->auth->first_name;
        $stripe_customer_id = $request->auth->stripe_customer_id;

        // Ensure customer exists in Stripe
        if (!$stripe_customer_id) {
            $customer = Customer::create([
                'email' => $email,
                'name'  => $first_name,
            ]);
            $user->stripe_customer_id = $customer->id;
            $user->save();
        }

        // Retrieve the new PaymentMethod.
        $newPaymentMethod = PaymentMethod::retrieve($request->payment_method);

        // Attach the new PaymentMethod to the Stripe customer.
        $newPaymentMethod->attach([
            'customer' => $stripe_customer_id,
        ]);

        // Optionally, make it the default payment method.
        Customer::update($stripe_customer_id, [
            'invoice_settings' => [
                'default_payment_method' => $request->payment_method,
            ]
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment Method Added',
            'data'    => $newPaymentMethod,
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to add payment method: ' . $e->getMessage(),
        ], 500);
    }
}

    /**
     * Edit card
     * @return type
     */

    /**
     * @OA\Post(
     *     path="/stripe/update-card",
     *     summary="Update a Stripe Customer Payment Method",
     *     description="Updates the card details and billing information of an existing Stripe payment method.",
     *     tags={"Stripe"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"payment_method_id","full_name", "line1", "city", "state", "country", "postal_code", "exp_month", "exp_year"},
     *             @OA\Property(property="payment_method_id", type="string", example="pm_22454543", description="payment Id"),
      
     *             @OA\Property(property="full_name", type="string", example="John Doe", description="Full name of the cardholder"),
     *             @OA\Property(property="line1", type="string", example="123 Main Street", description="Billing address line 1"),
     *             @OA\Property(property="city", type="string", example="San Francisco", description="Billing city"),
     *             @OA\Property(property="state", type="string", example="CA", description="Billing state"),
     *             @OA\Property(property="country", type="string", example="US", description="Billing country"),
     *             @OA\Property(property="postal_code", type="string", example="94111", description="Billing postal code"),
     *             @OA\Property(property="exp_month", type="integer", example=12, description="Card expiration month"),
     *             @OA\Property(property="exp_year", type="integer", example=2025, description="Card expiration year")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Payment Method Updated Successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Payment Method Updated"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="string", example="pm_1NXRYxJHq9O9w7HGzqvKDiSA"),
     *                 @OA\Property(property="type", type="string", example="card"),
     *                 @OA\Property(property="card", type="object", 
     *                     @OA\Property(property="brand", type="string", example="visa"),
     *                     @OA\Property(property="last4", type="string", example="4242"),
     *                     @OA\Property(property="exp_month", type="integer", example=12),
     *                     @OA\Property(property="exp_year", type="integer", example=2025)
     *                 ),
     *                 @OA\Property(
     *                     property="billing_details",
     *                     type="object",
     *                     @OA\Property(property="name", type="string", example="John Doe"),
     *                     @OA\Property(
     *                         property="address",
     *                         type="object",
     *                         @OA\Property(property="line1", type="string", example="123 Main Street"),
     *                         @OA\Property(property="city", type="string", example="San Francisco"),
     *                         @OA\Property(property="state", type="string", example="CA"),
     *                         @OA\Property(property="country", type="string", example="US"),
     *                         @OA\Property(property="postal_code", type="string", example="94111")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Missing or Invalid Parameters.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to update payment method.")
     *         )
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

    public static function getDefaultPaymentMethod($strStripeCustomerId)
    {
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
        $customer = \Stripe\Customer::retrieve($strStripeCustomerId);
        return $customer->invoice_settings->default_payment_method;
    }
}
