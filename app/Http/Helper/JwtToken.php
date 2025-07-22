<?php

namespace App\Http\Helper;

use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;

class JwtToken
{
    public static function createToken($userId)
    {
        $payload = [
            'iss' => "lumen-jwt", // Issuer of the token
            'sub' => $userId,     // Subject of the token
            'iat' => time(),      // Issued at
            'exp' => time() + 14 * 60 * 60 // Expires in 14 hours
        ];

        $secret = env('JWT_SECRET');
        $algorithm = 'HS256';

        $token = JWT::encode($payload, $secret, $algorithm);

        return [
            $token,
            Carbon::parse($payload['exp'])->toIso8601ZuluString()
        ];
    }
}
