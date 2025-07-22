<?php

namespace App\Http\Controllers\Merchant;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Exceptions\RenderableException;
use App\Model\Merchant\Merchants;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use App\Http\Helper\JwtToken;
use App\Model\Client\Lead;

class AuthController extends \App\Http\Controllers\Controller
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

    public function add(Merchants $merchants)
    {
      $this->validate($this->request, [
        'email' => 'required|email|unique:merchants',
        'client_id' => 'required',
        'lead_id' => 'required'
      ]);
 
      try {
          $password = Hash::make("123456");
          $response = $merchants->create([
              "email" => $this->request->input('email'),
              "password" => $password,
              "client_id" => $this->request->input('client_id'),
              "lead_id" => $this->request->input('lead_id'),
            ]
          );

        return $this->successResponse("Mechant added successfuly", $response->toArray());
      } catch (\Throwable $exception) {
        return $this->failResponse($exception->getMessage(), [], $exception, $exception->getCode());
      }
    }

    public function login(Merchants $merchants)
    {
        $this->validate($this->request, [
          'password' => 'required',
          'email' => 'required|email'
        ]);
   
        try {
          $email = $this->request->email;
          $password = $this->request->password;
          $merchant = $merchants::where('email', $email)->first();
          if (!$merchant) {
              throw new RenderableException('Email not registered', [], 401);
          }

          // Verify the password and generate the token
          if (Hash::check($password, $merchant->password)) {
              $data = $merchant->toArray();        
              $token = JwtToken::createToken($merchant->id);
              $data['token'] = $token[0];
              $data['expires_at'] = $token[1];
              $data['level'] = 999999;
              $data['role'] = "MERCHANT";
              
              $data['lead_data'] = Lead::on("mysql_$merchant->client_id")->findorfail($merchant->lead_id)->toArray();
              
              return $this->successResponse("Login successful", $data);
          } else {
            // Bad Request response
            throw new RenderableException('Invalid email or password', [], 401);
          }                    
        } catch (\Throwable $exception) {
          return $this->failResponse($exception->getMessage(), [], $exception, $exception->getCode());
        }
    }

    public function get(Merchants $merchants)
    {
      $this->validate($this->request, [
        'client_id' => 'required'
      ]);
 
      try {
          $response = $merchants->select(["lead_id"])->where(["client_id" => $this->request->input('client_id')])->get();

          if ($response) {
            $response = array_column($response->toArray(), 'lead_id');
          }

        return $this->successResponse("Mechants list", $response);
      } catch (\Throwable $exception) {
        return $this->failResponse($exception->getMessage(), [], $exception, $exception->getCode());
      }
    }
}
