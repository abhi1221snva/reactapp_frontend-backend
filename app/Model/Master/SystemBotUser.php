<?php

namespace App\Model\Master;

use App\Model\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SystemBotUser extends Model
{
    protected $connection = 'master';
    protected $table = 'system_bot_users';

    protected $fillable = [
        'parent_id',
        'bot_type',
        'user_id',
        'display_name',
        'avatar',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the user associated with this bot.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get or create the Gmail bot for a specific client.
     */
    public static function getOrCreateGmailBot(int $parentId): self
    {
        $bot = static::where('parent_id', $parentId)
            ->where('bot_type', 'gmail_bot')
            ->first();

        if ($bot) {
            return $bot;
        }

        // Create the bot user first
        $userId = static::createBotUser($parentId, 'Gmail', 'Notifications');

        return static::create([
            'parent_id' => $parentId,
            'bot_type' => 'gmail_bot',
            'user_id' => $userId,
            'display_name' => 'Gmail Notifications',
            'avatar' => '/assets/images/bots/gmail-bot.png',
            'is_active' => true,
        ]);
    }

    /**
     * Create a bot user in the users table.
     */
    protected static function createBotUser(int $parentId, string $firstName, string $lastName): int
    {
        $uniqueId = $parentId . '_' . Str::random(6);

        $user = User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => "bot-gmail-{$uniqueId}@system.local",
            'password' => Hash::make(Str::random(32)),
            'role' => 1,
            'parent_id' => $parentId,
            'base_parent_id' => $parentId,
            'extension' => '9999' . $parentId,
            'asterisk_server_id' => 0,
            'status' => 1,
            'is_deleted' => 0,
            'user_level' => 0,
        ]);

        return $user->id;
    }

    /**
     * Get the bot user ID for a specific client and type.
     */
    public static function getBotUserId(int $parentId, string $botType = 'gmail_bot'): ?int
    {
        $bot = static::where('parent_id', $parentId)
            ->where('bot_type', $botType)
            ->where('is_active', true)
            ->first();

        return $bot ? $bot->user_id : null;
    }
}
