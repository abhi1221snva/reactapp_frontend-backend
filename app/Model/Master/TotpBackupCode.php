<?php
namespace App\Model\Master;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class TotpBackupCode extends Model
{
    protected $connection = 'master';
    protected $table      = 'totp_backup_codes';
    public $timestamps    = false;

    protected $fillable = ['user_id','code_hash','used','used_at','created_at'];

    protected $casts = ['used' => 'boolean', 'used_at' => 'datetime'];

    /** Generate 10 random backup codes, store hashed, return plaintext. */
    public static function generateForUser(int $userId): array
    {
        static::where('user_id', $userId)->delete();
        $plainCodes = [];
        for ($i = 0; $i < 10; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4)));  // 8-char hex e.g. A1B2C3D4
            $plainCodes[] = $code;
            static::create([
                'user_id'    => $userId,
                'code_hash'  => Hash::make($code),
                'used'       => false,
                'created_at' => \Carbon\Carbon::now(),
            ]);
        }
        return $plainCodes;
    }

    /** Try to find and consume a backup code. Returns true if matched. */
    public static function consumeCode(int $userId, string $code): bool
    {
        $rows = static::where('user_id', $userId)->where('used', false)->get();
        foreach ($rows as $row) {
            if (Hash::check(strtoupper($code), $row->code_hash)) {
                $row->update(['used' => true, 'used_at' => \Carbon\Carbon::now()]);
                return true;
            }
        }
        return false;
    }
}
