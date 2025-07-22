<?php

namespace App\Http\Controllers;

use App\Cart;
use App\Model\Master\Order;
use App\Model\Master\OrdersItem;
use App\Model\Master\Package;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrdersController extends Controller
{

    /**
     * @OA\Get(
     *     path="/orders",
     *     summary="Get Orders List",
     *     description="Fetches all orders for the authenticated client's account, including subscription details.",
     *     tags={"Orders"},
     *     security={{"Bearer":{}}},
     * *      @OA\Parameter(
     *         name="start",
     *         in="query",
     *         required=false,
     *         description="Start index for pagination",
     *         @OA\Schema(type="integer", default=0)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Limit number of records returned",
     *         @OA\Schema(type="integer", default=10)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful order list retrieval",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Order list"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=101),
     *                     @OA\Property(property="client_id", type="integer", example=55),
     *                     @OA\Property(property="total", type="number", format="float", example=299.99),
     *                     @OA\Property(property="status", type="string", example="completed"),
     *                     @OA\Property(property="subscriptions", type="string", example="Basic Plan, Premium Addon")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error during order retrieval",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to get order details")
     *         )
     *     )
     * )
     */

    public function index(Request $request)
    {
        try {
            $strOrderSql = "SELECT o.*,
                                GROUP_CONCAT(p.name SEPARATOR ', ') as subscriptions
                            FROM orders as o
                                JOIN orders_items as oi ON ( o.id = oi.order_id )
                                JOIN packages as p ON ( p.key = oi.package_key )
                            WHERE o.client_id = :client_id GROUP BY o.id";
            $arrOrders = DB::select($strOrderSql, array('client_id' => $request->auth->parent_id));

            if ($request->has('start') && $request->has('limit')) {
                $total_row = count($arrOrders);

                $start = (int) $request->input('start');  // Start index (0-based)
                $limit = (int) $request->input('limit');  // Number of records to fetch

                $arrOrders = array_slice($arrOrders, $start, $limit, false);

                return $this->successResponse("Order list", [
                    'start' => $start,
                    'limit' => $limit,
                    'total' => $total_row,
                    'data' => $arrOrders
                ]);
            }
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to get order details", [$exception->getMessage()], $exception, $exception->getCode());
        }
        return $this->successResponse("Order list", $arrOrders);
    }

    /**
     * @OA\Get(
     *     path="/order/{orderId}",
     *     summary="Get Order Details",
     *     description="Returns a specific order with all associated items and package details.",
     *     tags={"Orders"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="orderId",
     *         in="path",
     *         required=true,
     *         description="The ID of the order to retrieve.",
     *         @OA\Schema(type="integer", example=123)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Order details retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Order Details"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="order",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=123),
     *                     @OA\Property(property="client_id", type="integer", example=12),
     *                     @OA\Property(property="total", type="number", format="float", example=150.00),
     *                     @OA\Property(property="status", type="string", example="completed")
     *                 ),
     *                 @OA\Property(
     *                     property="orderItems",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=456),
     *                         @OA\Property(property="package_key", type="string", example="pro_plan"),
     *                         @OA\Property(property="billing_period", type="string", example="Monthly"),
     *                         @OA\Property(property="package_name", type="string", example="Pro Plan"),
     *                         @OA\Property(property="price", type="number", format="float", example=75.00)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid order ID or order not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to get order details")
     *         )
     *     )
     * )
     */

    public function show(Request $request, int $orderId)
    {

        $arrOrderData = [];
        try {
            //fetch packages
            $packages = Package::all()->toArray();
            $packagesRekeyed = UserPackagesController::rekeyArray($packages, 'key');

            $arrOrderData['order'] = Order::where('id', '=', $orderId)->where('client_id', '=', $request->auth->parent_id)->get()->first();

            if ($arrOrderData['order'] == NULL) {
                throw new Exception("Invalid Order ID");
            }

            $arrOrderItems = OrdersItem::where('order_id', '=', $orderId)->get()->toArray();
            foreach ($arrOrderItems as $key => $arrOrderItem) {
                $arrOrderData['orderItems'][$key] = $arrOrderItem;
                $arrOrderData['orderItems'][$key]['billing_period'] = Cart::$billingPeriod[$arrOrderItem['billed']];
                $arrOrderData['orderItems'][$key]['package_name'] = $packagesRekeyed[$arrOrderItem['package_key']]['name'];
            }
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to get order details", [$exception->getMessage()], $exception, $exception->getCode());
        }
        return $this->successResponse("Order Details", $arrOrderData);
    }
}
