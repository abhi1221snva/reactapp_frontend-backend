<?php

namespace App\Http\Controllers;

use App\Cart;
use App\Model\Master\ClientPackage;
use App\Model\Master\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    public function getCartItems(Request $request){

        $arrCartData = [];
        try {
            //fetch packages
            $packages = Package::all()->toArray();
            $packagesRekeyed = UserPackagesController::rekeyArray($packages, 'key');

            $cartItems = Cart::on("mysql_" . $request->auth->parent_id)->get()->toArray();
            foreach($cartItems as $cartItem){
                $arrCartData[$cartItem['id']]['quantity'] = $cartItem['quantity'];
                $arrCartData[$cartItem['id']]['product'] = $packagesRekeyed[$cartItem['package_key']]['name'];
                $arrCartData[$cartItem['id']]['billing_period'] = Cart::$billingPeriod[$cartItem['billed']];
                $arrCartData[$cartItem['id']]['billing'] = Cart::$billingMonths[$cartItem['billed']];
                $arrCartData[$cartItem['id']]['base_rate_monthly_billed'] = $packagesRekeyed[$cartItem['package_key']]['base_rate_monthly_billed'];
                $arrCartData[$cartItem['id']]['subtotal'] = $cartItem['quantity'] * $packagesRekeyed[$cartItem['package_key']][ClientPackage::$billingMapping[$cartItem['billed']]];
            }
            return $this->successResponse("All cart items", $arrCartData);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to load cart items", [], $exception);
        }
    }
public function getCartItemsNew(Request $request)
{
    try {
        $packages = Package::all()->toArray();
        $packagesRekeyed = UserPackagesController::rekeyArray($packages, 'key');

        $cartItem = Cart::on("mysql_" . $request->auth->parent_id)
            ->latest()
            ->first();

        if (!$cartItem) {
            return $this->successResponse("No cart items found", []);
        }

        $arrCartData = [];
        $arrCartData['id'] = $cartItem->id;
        $arrCartData['quantity'] = $cartItem->quantity;
        $arrCartData['product'] = $packagesRekeyed[$cartItem->package_key]['name'];
        $arrCartData['billing_period'] = Cart::$billingPeriod[$cartItem->billed];
        $arrCartData['billing'] = Cart::$billingMonths[$cartItem->billed];
        $arrCartData['base_rate_monthly_billed'] = $packagesRekeyed[$cartItem->package_key]['base_rate_monthly_billed'];
        $arrCartData['subtotal'] = $cartItem->quantity * $packagesRekeyed[$cartItem->package_key][ClientPackage::$billingMapping[$cartItem->billed]];

        // Return the single item wrapped in an array to be compatible with the foreach loop
        return $this->successResponse("Last cart item fetched successfully", [$arrCartData]);
        
    } catch (\Throwable $exception) {
        return $this->failResponse("Failed to load last cart item", [], $exception);
    }
}
    public function addToCart(Request $request, string $packageName)
    {
        $this->validate($request, [
            'billingPeriod' => 'required|string|max:20',
            'NoOfUsers' => 'required|numeric|int',
        ]);

        //fetch packages
        $packages = Package::all()->toArray();
        $packagesRekeyed = UserPackagesController::rekeyArray($packages, 'name');

        try {
            $objCart = new Cart();
            $objCart->setConnection("mysql_" . $request->auth->parent_id);
            $objCart->user_id = $request->auth->id;
            $objCart->package_key = $packagesRekeyed[$packageName]['key'];
            $objCart->quantity = $request->get('NoOfUsers');
            $objCart->billed = $request->get('billingPeriod');
            $objCart->saveOrFail();
            return $this->successResponse($packageName." plan added to cart", []);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to add into cart", [], $exception);
        }
    }

    public function getCartCount(Request $request){
        try {
            //fetch Cart Items count
            $cartItemsCount = Cart::on("mysql_" . $request->auth->parent_id)->get()->count();
            return $this->successResponse("Cart count", [$cartItemsCount]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to load cart count", [], $exception);
        }
    }

    public function updateCart(Request $request, $cartId){
        try {
            if($request->get('operation') == "plus"){
                DB::connection('mysql_'.$request->auth->parent_id)->table('cart')->where('id',$cartId)->increment('quantity',1);
            } elseif ($request->get('operation') == "minus"){
                DB::connection('mysql_'.$request->auth->parent_id)->table('cart')->where('id',$cartId)->decrement('quantity',1);
            }

            return $this->successResponse("Quantity Updated", []);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Update Quantity", [], $exception);
        }
    }

    public function deleteCart(Request $request, $cartId){
        try {
            Cart::on("mysql_" . $request->auth->parent_id)->find($cartId)->delete();
            return $this->successResponse("Product removed successfully", []);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to remove Product", [], $exception);
        }
    }

    public function getCartTotalAmount(Request $request){
        try {
            $intCartTotal = $this->calculateCartTotalAmount($request->auth->parent_id);
            return $this->successResponse("Cart Total Amount", [$intCartTotal]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to load cart total Amount", [], $exception);
        }
    }

    public function calculateCartTotalAmount($intClientId){
        $intCartTotal = 0;

        //fetch packages
        $packages = Package::all()->toArray();
        $packagesRekeyed = UserPackagesController::rekeyArray($packages, 'key');

        $cartItems = Cart::on("mysql_" . $intClientId)->get()->toArray();
        foreach($cartItems as $cartItem){
            $intCartTotal += $cartItem['quantity'] * $packagesRekeyed[$cartItem['package_key']][ClientPackage::$billingMapping[$cartItem['billed']]];
        }
        return $intCartTotal;
    }
}
