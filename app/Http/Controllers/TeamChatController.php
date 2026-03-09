<?php

namespace App\Http\Controllers;

use App\Model\Client\TeamConversation;
use App\Model\Client\TeamConversationParticipant;
use App\Model\Client\TeamMessage;
use App\Model\Client\TeamMessageAttachment;
use App\Model\Client\TeamMessageReadReceipt;
use App\Model\TeamUserPresence;
use App\Model\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Pusher\Pusher;

class TeamChatController extends Controller
{
    protected $pusher;

    public function __construct()
    {
        $this->pusher = new Pusher(
            env('PUSHER_APP_KEY'),
            env('PUSHER_APP_SECRET'),
            env('PUSHER_APP_ID'),
            [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'useTLS' => true
            ]
        );
    }

    /**
     * Get widget data (conversations, unread count, online users)
     */
    public function getWidgetData(Request $request)
    {
        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            // Get conversations
            $conversations = TeamConversation::on("mysql_$parentId")
                ->whereHas('participants', function ($query) use ($userId) {
                    $query->where('user_id', $userId)->where('is_active', true);
                })
                ->with(['lastMessage', 'activeParticipants'])
                ->where('is_active', true)
                ->orderByDesc(
                    TeamMessage::on("mysql_$parentId")
                        ->select('created_at')
                        ->whereColumn('conversation_id', 'team_conversations.id')
                        ->where('is_deleted', false)
                        ->latest()
                        ->limit(1)
                )
                ->get();

            $convResult = $conversations->map(function ($conversation) use ($userId, $parentId) {
                $participantUserIds = $conversation->activeParticipants->pluck('user_id')->toArray();
                $users = User::whereIn('id', $participantUserIds)->where('is_deleted', 0)->get()->keyBy('id');

                return [
                    'uuid' => $conversation->uuid,
                    'type' => $conversation->type,
                    'name' => $conversation->type === 'group'
                        ? $conversation->name
                        : $this->getDirectChatName($conversation, $userId, $users),
                    'avatar' => $conversation->avatar,
                    'participants' => $conversation->activeParticipants->map(function ($p) use ($users) {
                        $user = $users->get($p->user_id);
                        return [
                            'user_id' => $p->user_id,
                            'name' => $this->getUserFullName($user),
                            'email' => $user ? $user->email : '',
                            'role' => $p->role,
                        ];
                    }),
                    'last_message' => $conversation->lastMessage ? [
                        'body' => $conversation->lastMessage->body,
                        'sender_id' => $conversation->lastMessage->sender_id,
                        'created_at' => $conversation->lastMessage->created_at->toIso8601String(),
                        'message_type' => $conversation->lastMessage->message_type,
                    ] : null,
                    'unread_count' => $conversation->unreadMessagesCount($userId),
                    'created_at' => $conversation->created_at->toIso8601String(),
                ];
            });

            // Calculate total unread
            $totalUnread = 0;
            foreach ($conversations as $conversation) {
                $totalUnread += $conversation->unreadMessagesCount($userId);
            }

            // Get online users (non-critical - don't fail if presence table has issues)
            $onlineResult = collect([]);
            try {
                $orgUsers = User::where('parent_id', $parentId)
                               ->where('is_deleted', 0)
                               ->pluck('id')
                               ->toArray();

                $onlineUsers = TeamUserPresence::getOnlineUsers($orgUsers);
                $userIds = $onlineUsers->pluck('user_id')->toArray();
                $users = User::whereIn('id', $userIds)->where('is_deleted', 0)->get()->keyBy('id');

                $onlineResult = $onlineUsers->map(function ($presence) use ($users) {
                    $user = $users->get($presence->user_id);
                    return [
                        'user_id' => $presence->user_id,
                        'name' => $this->getUserFullName($user),
                        'status' => $presence->status,
                        'last_seen_at' => $presence->last_seen_at ? $presence->last_seen_at->toIso8601String() : null,
                    ];
                });
            } catch (\Throwable $e) {
                Log::warning('Team Chat: Could not fetch online users', ['error' => $e->getMessage()]);
            }

            return $this->successResponse("Widget data fetched", [
                'conversations' => $convResult,
                'unread_count' => $totalUnread,
                'online_users' => $onlineResult
            ]);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch widget data", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Get all conversations for the authenticated user
     */
    public function getConversations(Request $request)
    {
        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            $conversations = TeamConversation::on("mysql_$parentId")
                ->whereHas('participants', function ($query) use ($userId) {
                    $query->where('user_id', $userId)->where('is_active', true);
                })
                ->with(['lastMessage', 'activeParticipants'])
                ->where('is_active', true)
                ->orderByDesc(
                    TeamMessage::on("mysql_$parentId")
                        ->select('created_at')
                        ->whereColumn('conversation_id', 'team_conversations.id')
                        ->where('is_deleted', false)
                        ->latest()
                        ->limit(1)
                )
                ->get();

            $result = $conversations->map(function ($conversation) use ($userId, $parentId) {
                $participantUserIds = $conversation->activeParticipants->pluck('user_id')->toArray();
                $users = User::whereIn('id', $participantUserIds)->where('is_deleted', 0)->get()->keyBy('id');

                return [
                    'uuid' => $conversation->uuid,
                    'type' => $conversation->type,
                    'name' => $conversation->type === 'group'
                        ? $conversation->name
                        : $this->getDirectChatName($conversation, $userId, $users),
                    'avatar' => $conversation->avatar,
                    'participants' => $conversation->activeParticipants->map(function ($p) use ($users) {
                        $user = $users->get($p->user_id);
                        return [
                            'user_id' => $p->user_id,
                            'name' => $this->getUserFullName($user),
                            'email' => $user ? $user->email : '',
                            'role' => $p->role,
                        ];
                    }),
                    'last_message' => $conversation->lastMessage ? [
                        'body' => $conversation->lastMessage->body,
                        'sender_id' => $conversation->lastMessage->sender_id,
                        'created_at' => $conversation->lastMessage->created_at->toIso8601String(),
                        'message_type' => $conversation->lastMessage->message_type,
                    ] : null,
                    'unread_count' => $conversation->unreadMessagesCount($userId),
                    'created_at' => $conversation->created_at->toIso8601String(),
                ];
            });

            return $this->successResponse("Conversations fetched", $result->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch conversations", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Get a single conversation by UUID
     */
    public function getConversation(Request $request, $uuid)
    {
        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            $conversation = TeamConversation::on("mysql_$parentId")
                ->where('uuid', $uuid)
                ->where('is_active', true)
                ->with(['activeParticipants'])
                ->first();

            if (!$conversation) {
                return $this->failResponse("Conversation not found", [], null, 404);
            }

            if (!$conversation->isParticipant($userId)) {
                return $this->failResponse("Access denied", [], null, 403);
            }

            $participantUserIds = $conversation->activeParticipants->pluck('user_id')->toArray();
            $users = User::whereIn('id', $participantUserIds)->where('is_deleted', 0)->get()->keyBy('id');

            $result = [
                'uuid' => $conversation->uuid,
                'type' => $conversation->type,
                'name' => $conversation->type === 'group'
                    ? $conversation->name
                    : $this->getDirectChatName($conversation, $userId, $users),
                'avatar' => $conversation->avatar,
                'participants' => $conversation->activeParticipants->map(function ($p) use ($users) {
                    $user = $users->get($p->user_id);
                    return [
                        'user_id' => $p->user_id,
                        'name' => $this->getUserFullName($user),
                        'email' => $user ? $user->email : '',
                        'role' => $p->role,
                    ];
                }),
                'created_at' => $conversation->created_at->toIso8601String(),
            ];

            return $this->successResponse("Conversation fetched", $result);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch conversation", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Get or create a direct conversation between two users
     */
    public function getOrCreateDirectConversation(Request $request)
    {
        $this->validate($request, [
            'user_id' => 'required|integer'
        ]);

        try {
            $currentUserId = $request->auth->id;
            $otherUserId = $request->input('user_id');
            $parentId = $request->auth->parent_id;

            // Verify other user belongs to same parent_id and is not deleted
            $otherUser = User::where('id', $otherUserId)
                            ->where('parent_id', $parentId)
                            ->where('is_deleted', 0)
                            ->first();

            if (!$otherUser) {
                return $this->failResponse("User not found or not in your organization", [], null, 404);
            }

            // Check if direct conversation already exists
            $existingConversation = TeamConversation::on("mysql_$parentId")
                ->where('type', 'direct')
                ->whereHas('participants', function ($q) use ($currentUserId) {
                    $q->where('user_id', $currentUserId)->where('is_active', true);
                })
                ->whereHas('participants', function ($q) use ($otherUserId) {
                    $q->where('user_id', $otherUserId)->where('is_active', true);
                })
                ->first();

            if ($existingConversation) {
                return $this->successResponse("Conversation found", [
                    'uuid' => $existingConversation->uuid,
                    'type' => $existingConversation->type,
                    'is_new' => false
                ]);
            }

            // Create new direct conversation
            DB::connection("mysql_$parentId")->beginTransaction();

            $conversation = new TeamConversation();
            $conversation->setConnection("mysql_$parentId");
            $conversation->type = 'direct';
            $conversation->created_by = $currentUserId;
            $conversation->saveOrFail();

            // Add both participants
            foreach ([$currentUserId, $otherUserId] as $uid) {
                $participant = new TeamConversationParticipant();
                $participant->setConnection("mysql_$parentId");
                $participant->conversation_id = $conversation->id;
                $participant->user_id = $uid;
                $participant->role = 'member';
                $participant->joined_at = Carbon::now();
                $participant->saveOrFail();
            }

            DB::connection("mysql_$parentId")->commit();

            return $this->successResponse("Conversation created", [
                'uuid' => $conversation->uuid,
                'type' => $conversation->type,
                'is_new' => true
            ]);
        } catch (\Throwable $exception) {
            DB::connection("mysql_$parentId")->rollBack();
            return $this->failResponse("Failed to create conversation", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Create a new group conversation
     */
    public function createConversation(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|string|max:255',
            'participant_ids' => 'required|array|min:1',
            'participant_ids.*' => 'integer'
        ]);

        try {
            $currentUserId = $request->auth->id;
            $parentId = $request->auth->parent_id;
            $participantIds = $request->input('participant_ids');

            // Verify all participants belong to same parent_id and are not deleted
            $validUsers = User::whereIn('id', $participantIds)
                             ->where('parent_id', $parentId)
                             ->where('is_deleted', 0)
                             ->pluck('id')
                             ->toArray();

            if (count($validUsers) !== count($participantIds)) {
                return $this->failResponse("Some users are not in your organization or have been deleted", [], null, 400);
            }

            DB::connection("mysql_$parentId")->beginTransaction();

            $conversation = new TeamConversation();
            $conversation->setConnection("mysql_$parentId");
            $conversation->type = 'group';
            $conversation->name = $request->input('name');
            $conversation->created_by = $currentUserId;
            $conversation->saveOrFail();

            // Add creator as admin
            $creatorParticipant = new TeamConversationParticipant();
            $creatorParticipant->setConnection("mysql_$parentId");
            $creatorParticipant->conversation_id = $conversation->id;
            $creatorParticipant->user_id = $currentUserId;
            $creatorParticipant->role = 'admin';
            $creatorParticipant->joined_at = Carbon::now();
            $creatorParticipant->saveOrFail();

            // Add other participants
            foreach ($participantIds as $uid) {
                if ($uid == $currentUserId) continue;

                $participant = new TeamConversationParticipant();
                $participant->setConnection("mysql_$parentId");
                $participant->conversation_id = $conversation->id;
                $participant->user_id = $uid;
                $participant->role = 'member';
                $participant->joined_at = Carbon::now();
                $participant->saveOrFail();
            }

            // Create system message
            $systemMessage = new TeamMessage();
            $systemMessage->setConnection("mysql_$parentId");
            $systemMessage->conversation_id = $conversation->id;
            $systemMessage->sender_id = $currentUserId;
            $systemMessage->message_type = 'system';
            $systemMessage->body = 'Group created';
            $systemMessage->saveOrFail();

            DB::connection("mysql_$parentId")->commit();

            return $this->successResponse("Group created", [
                'uuid' => $conversation->uuid,
                'type' => $conversation->type,
                'name' => $conversation->name
            ]);
        } catch (\Throwable $exception) {
            DB::connection("mysql_$parentId")->rollBack();
            return $this->failResponse("Failed to create group", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Get messages for a conversation
     */
    public function getMessages(Request $request, $uuid)
    {
        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;
            $limit = $request->input('limit', 50);
            $before = $request->input('before'); // message_id for pagination

            $conversation = TeamConversation::on("mysql_$parentId")
                ->where('uuid', $uuid)
                ->first();

            if (!$conversation) {
                return $this->failResponse("Conversation not found", [], null, 404);
            }

            if (!$conversation->isParticipant($userId)) {
                return $this->failResponse("Access denied", [], null, 403);
            }

            $query = TeamMessage::on("mysql_$parentId")
                ->where('conversation_id', $conversation->id)
                ->where('is_deleted', false)
                ->with('attachments')
                ->orderByDesc('id')
                ->limit($limit);

            if ($before) {
                $query->where('id', '<', $before);
            }

            $messages = $query->get();

            // Get sender info
            $senderIds = $messages->pluck('sender_id')->unique()->toArray();
            $senders = User::whereIn('id', $senderIds)->get()->keyBy('id');

            // Get conversation participants count (excluding sender) for read status
            $conversationParticipants = TeamConversationParticipant::on("mysql_$parentId")
                ->where('conversation_id', $conversation->id)
                ->where('is_active', true)
                ->pluck('user_id')
                ->toArray();

            $result = $messages->map(function ($message) use ($senders, $userId, $parentId, $conversationParticipants) {
                $sender = $senders->get($message->sender_id);

                // Get read receipts
                $readReceipts = TeamMessageReadReceipt::on("mysql_$parentId")
                    ->where('message_id', $message->id)
                    ->get();

                $readByUserIds = $readReceipts->pluck('user_id')->toArray();

                // For outgoing messages, check if all other participants have read it
                $isOwnMessage = $message->sender_id == $userId;
                $otherParticipants = array_filter($conversationParticipants, fn($id) => $id != $message->sender_id);
                $allRead = $isOwnMessage && count($otherParticipants) > 0 &&
                           count(array_intersect($readByUserIds, $otherParticipants)) >= count($otherParticipants);

                // Message is delivered once it's stored (for simplicity, we mark all sent messages as delivered)
                $isDelivered = $isOwnMessage && !$allRead;

                // Get the first read_at timestamp if available
                $firstReadReceipt = $readReceipts->first();
                $readAt = $firstReadReceipt ? $firstReadReceipt->read_at : null;

                return [
                    'id' => $message->id,
                    'uuid' => $message->uuid,
                    'sender' => [
                        'id' => $message->sender_id,
                        'name' => $this->getUserFullName($sender),
                    ],
                    'message_type' => $message->message_type,
                    'body' => $message->body,
                    'attachments' => $message->attachments->map(function ($att) {
                        return [
                            'id' => $att->id,
                            'original_name' => $att->original_name,
                            'file_type' => $att->file_type,
                            'file_size' => $att->getFileSizeFormatted(),
                            'is_image' => $att->isImage(),
                        ];
                    }),
                    'is_edited' => $message->is_edited,
                    'is_mine' => $isOwnMessage,
                    'is_read' => $allRead,
                    'is_delivered' => $isDelivered,
                    'read_at' => $readAt ? Carbon::parse($readAt)->toIso8601String() : null,
                    'read_by' => $readByUserIds,
                    'created_at' => $message->created_at->toIso8601String(),
                ];
            });

            return $this->successResponse("Messages fetched", $result->reverse()->values()->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch messages", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Send a message
     */
    public function sendMessage(Request $request, $uuid)
    {
        $this->validate($request, [
            'body' => 'required_without:attachment_ids|string|max:5000',
            'message_type' => 'in:text,image,file',
            'attachment_ids' => 'array'
        ]);

        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            $conversation = TeamConversation::on("mysql_$parentId")
                ->where('uuid', $uuid)
                ->first();

            if (!$conversation) {
                return $this->failResponse("Conversation not found", [], null, 404);
            }

            if (!$conversation->isParticipant($userId)) {
                return $this->failResponse("Access denied", [], null, 403);
            }

            $message = new TeamMessage();
            $message->setConnection("mysql_$parentId");
            $message->conversation_id = $conversation->id;
            $message->sender_id = $userId;
            $message->message_type = $request->input('message_type', 'text');
            $message->body = $request->input('body', '');
            $message->saveOrFail();

            // Get sender info
            $sender = User::find($userId);

            $messageData = [
                'id' => $message->id,
                'uuid' => $message->uuid,
                'conversation_uuid' => $uuid,
                'sender' => [
                    'id' => $userId,
                    'name' => $this->getUserFullName($sender),
                ],
                'message_type' => $message->message_type,
                'body' => $message->body,
                'attachments' => [],
                'is_edited' => false,
                'is_mine' => false,
                'is_read' => false,
                'is_delivered' => false,
                'read_by' => [],
                'created_at' => $message->created_at->toIso8601String(),
            ];

            // Broadcast to all participants
            $this->pusher->trigger(
                "private-team-chat.{$parentId}.{$uuid}",
                'message.sent',
                $messageData
            );

            // Also notify each participant on their personal channel
            $participants = TeamConversationParticipant::on("mysql_$parentId")
                ->where('conversation_id', $conversation->id)
                ->where('user_id', '!=', $userId)
                ->where('is_active', true)
                ->pluck('user_id');

            foreach ($participants as $participantId) {
                $this->pusher->trigger(
                    "private-team-user.{$parentId}.{$participantId}",
                    'new.message',
                    [
                        'conversation_uuid' => $uuid,
                        'sender_name' => $this->getUserFullName($sender),
                        'preview' => mb_substr($message->body, 0, 50),
                    ]
                );
            }

            return $this->successResponse("Message sent", $messageData);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to send message", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Mark messages as read
     */
    public function markAsRead(Request $request, $uuid)
    {
        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            $conversation = TeamConversation::on("mysql_$parentId")
                ->where('uuid', $uuid)
                ->first();

            if (!$conversation || !$conversation->isParticipant($userId)) {
                return $this->failResponse("Conversation not found", [], null, 404);
            }

            // Get latest message
            $latestMessage = TeamMessage::on("mysql_$parentId")
                ->where('conversation_id', $conversation->id)
                ->where('is_deleted', false)
                ->latest()
                ->first();

            if ($latestMessage) {
                // Update participant's last read
                $participant = TeamConversationParticipant::on("mysql_$parentId")
                    ->where('conversation_id', $conversation->id)
                    ->where('user_id', $userId)
                    ->first();

                if ($participant) {
                    $participant->markAsRead($latestMessage->id);
                }

                // Create read receipts for unread messages
                $unreadMessages = TeamMessage::on("mysql_$parentId")
                    ->where('conversation_id', $conversation->id)
                    ->where('sender_id', '!=', $userId)
                    ->where('is_deleted', false)
                    ->whereDoesntHave('readReceipts', function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    })
                    ->get();

                foreach ($unreadMessages as $msg) {
                    $receipt = new TeamMessageReadReceipt();
                    $receipt->setConnection("mysql_$parentId");
                    $receipt->message_id = $msg->id;
                    $receipt->user_id = $userId;
                    $receipt->read_at = Carbon::now();
                    $receipt->save();
                }

                // Broadcast read event to conversation channel
                $this->pusher->trigger(
                    "private-team-chat.{$parentId}.{$uuid}",
                    'message.read',
                    [
                        'conversation_uuid' => $uuid,
                        'reader_id' => $userId,
                        'user_id' => $userId,
                        'last_read_message_id' => $latestMessage->id,
                        'read_at' => Carbon::now()->toIso8601String()
                    ]
                );
            }

            return $this->successResponse("Marked as read", []);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to mark as read", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Update user presence
     */
    public function updatePresence(Request $request)
    {
        $this->validate($request, [
            'status' => 'required|in:online,away,busy,offline',
            'conversation_uuid' => 'nullable|string'
        ]);

        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;
            $status = $request->input('status');
            $conversationUuid = $request->input('conversation_uuid');

            // Try to update presence in database (non-critical)
            try {
                TeamUserPresence::updateUserPresence($userId, $status, $conversationUuid);
            } catch (\Throwable $e) {
                // Log but don't fail if presence table doesn't exist
                Log::warning('Team Chat: Could not update presence in database', ['error' => $e->getMessage()]);
            }

            // Get user name for broadcast
            $user = User::find($userId);
            $userName = $this->getUserFullName($user);

            // Broadcast presence change to all other users via batch trigger
            try {
                $otherUsers = User::where('parent_id', $parentId)
                    ->where('id', '!=', $userId)
                    ->where('is_deleted', 0)
                    ->pluck('id')
                    ->toArray();

                if (count($otherUsers) > 0) {
                    $presenceData = [
                        'user_id' => $userId,
                        'name' => $userName,
                        'status' => $status,
                        'last_seen_at' => Carbon::now()->toIso8601String()
                    ];

                    // Build channels array for batch trigger
                    $channels = array_map(function($otherUserId) use ($parentId) {
                        return "private-team-user.{$parentId}.{$otherUserId}";
                    }, $otherUsers);

                    // Pusher allows max 100 channels per batch
                    foreach (array_chunk($channels, 100) as $channelBatch) {
                        $this->pusher->trigger($channelBatch, 'presence.changed', $presenceData);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Team Chat: Could not broadcast presence', ['error' => $e->getMessage()]);
            }

            return $this->successResponse("Presence updated", ['status' => $status]);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update presence", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Get online users in the same organization
     */
    public function getOnlineUsers(Request $request)
    {
        try {
            $parentId = $request->auth->parent_id;
            $result = [];

            try {
                // Get all users in the organization
                $orgUsers = User::where('parent_id', $parentId)
                               ->where('is_deleted', 0)
                               ->pluck('id')
                               ->toArray();

                $onlineUsers = TeamUserPresence::getOnlineUsers($orgUsers);

                $userIds = $onlineUsers->pluck('user_id')->toArray();
                $users = User::whereIn('id', $userIds)->where('is_deleted', 0)->get()->keyBy('id');

                $result = $onlineUsers->map(function ($presence) use ($users) {
                    $user = $users->get($presence->user_id);
                    return [
                        'user_id' => $presence->user_id,
                        'name' => $this->getUserFullName($user),
                        'status' => $presence->status,
                        'last_seen_at' => $presence->last_seen_at ? $presence->last_seen_at->toIso8601String() : null,
                    ];
                })->toArray();
            } catch (\Throwable $e) {
                Log::warning('Team Chat: Could not fetch online users', ['error' => $e->getMessage()]);
            }

            return $this->successResponse("Online users", $result);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to get online users", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Search users in the organization
     */
    public function searchUsers(Request $request)
    {
        try {
            $parentId = $request->auth->parent_id;
            $currentUserId = $request->auth->id;
            $query = $request->input('q', '');

            $users = User::where('parent_id', $parentId)
                        ->where('is_deleted', 0)
                        ->where('id', '!=', $currentUserId)
                        ->where(function ($q) use ($query) {
                            $q->where('first_name', 'LIKE', "%{$query}%")
                              ->orWhere('last_name', 'LIKE', "%{$query}%")
                              ->orWhere('email', 'LIKE', "%{$query}%")
                              ->orWhere('extension', 'LIKE', "%{$query}%");
                        })
                        ->limit(20)
                        ->get();

            // Get presence status (non-critical)
            $userIds = $users->pluck('id')->toArray();
            $presences = collect([]);
            try {
                $presences = TeamUserPresence::whereIn('user_id', $userIds)->get()->keyBy('user_id');
            } catch (\Throwable $e) {
                Log::warning('Team Chat: Could not fetch user presences', ['error' => $e->getMessage()]);
            }

            $result = $users->map(function ($user) use ($presences) {
                $presence = $presences->get($user->id);
                $fullName = trim($user->first_name . ' ' . $user->last_name);
                return [
                    'id' => $user->id,
                    'name' => $fullName ?: $user->email,
                    'email' => $user->email,
                    'extension' => $user->extension,
                    'status' => $presence ? $presence->status : 'offline',
                ];
            });

            return $this->successResponse("Users found", $result->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to search users", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Get total unread count
     */
    public function getUnreadCount(Request $request)
    {
        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            $conversations = TeamConversation::on("mysql_$parentId")
                ->whereHas('participants', function ($query) use ($userId) {
                    $query->where('user_id', $userId)->where('is_active', true);
                })
                ->where('is_active', true)
                ->get();

            $totalUnread = 0;
            foreach ($conversations as $conversation) {
                $totalUnread += $conversation->unreadMessagesCount($userId);
            }

            return $this->successResponse("Unread count", ['count' => $totalUnread]);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to get unread count", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Typing indicator
     */
    public function typing(Request $request, $uuid)
    {
        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            $sender = User::find($userId);

            $this->pusher->trigger(
                "private-team-chat.{$parentId}.{$uuid}",
                'user.typing',
                [
                    'user_id' => $userId,
                    'name' => $this->getUserFullName($sender),
                ]
            );

            return $this->successResponse("Typing sent", []);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Upload attachment
     */
    public function uploadAttachment(Request $request, $uuid)
    {
        $this->validate($request, [
            'file' => 'required|file|max:25600' // 25MB max
        ]);

        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            $conversation = TeamConversation::on("mysql_$parentId")
                ->where('uuid', $uuid)
                ->first();

            if (!$conversation || !$conversation->isParticipant($userId)) {
                return $this->failResponse("Conversation not found", [], null, 404);
            }

            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $extension = strtolower($file->getClientOriginalExtension());
            $storedName = Str::uuid() . '.' . $extension;
            $fileSize = $file->getSize();
            $mimeType = $file->getMimeType();

            // Validate file type
            $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip', 'rar', '7z'];
            if (!in_array($extension, $allowedTypes)) {
                return $this->failResponse("File type not allowed", [], null, 400);
            }

            // Store file
            $storagePath = "team-chat/{$parentId}/{$uuid}";
            $filePath = $file->storeAs($storagePath, $storedName);

            // Determine message type
            $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $messageType = in_array($extension, $imageTypes) ? 'image' : 'file';

            // Create message
            $message = new TeamMessage();
            $message->setConnection("mysql_$parentId");
            $message->conversation_id = $conversation->id;
            $message->sender_id = $userId;
            $message->message_type = $messageType;
            $message->body = $originalName;
            $message->saveOrFail();

            // Create attachment
            $attachment = new TeamMessageAttachment();
            $attachment->setConnection("mysql_$parentId");
            $attachment->message_id = $message->id;
            $attachment->original_name = $originalName;
            $attachment->stored_name = $storedName;
            $attachment->file_path = $filePath;
            $attachment->file_type = $extension;
            $attachment->file_size = $fileSize;
            $attachment->mime_type = $mimeType;
            $attachment->saveOrFail();

            $sender = User::find($userId);

            $messageData = [
                'id' => $message->id,
                'uuid' => $message->uuid,
                'conversation_uuid' => $uuid,
                'sender' => [
                    'id' => $userId,
                    'name' => $this->getUserFullName($sender),
                ],
                'message_type' => $messageType,
                'body' => $originalName,
                'attachments' => [[
                    'id' => $attachment->id,
                    'original_name' => $originalName,
                    'file_type' => $extension,
                    'file_size' => $attachment->getFileSizeFormatted(),
                    'is_image' => $attachment->isImage(),
                ]],
                'is_edited' => false,
                'is_mine' => false,
                'is_read' => false,
                'is_delivered' => false,
                'read_by' => [],
                'created_at' => $message->created_at->toIso8601String(),
            ];

            // Broadcast
            $this->pusher->trigger(
                "private-team-chat.{$parentId}.{$uuid}",
                'message.sent',
                $messageData
            );

            return $this->successResponse("File uploaded", $messageData);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to upload file", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Download attachment
     */
    public function downloadAttachment(Request $request, $attachmentId)
    {
        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            $attachment = TeamMessageAttachment::on("mysql_$parentId")
                ->find($attachmentId);

            if (!$attachment) {
                return $this->failResponse("Attachment not found", [], null, 404);
            }

            $message = $attachment->message;
            $conversation = $message->conversation;

            if (!$conversation->isParticipant($userId)) {
                return $this->failResponse("Access denied", [], null, 403);
            }

            $filePath = storage_path('app/' . $attachment->file_path);

            if (!file_exists($filePath)) {
                return $this->failResponse("File not found", [], null, 404);
            }

            return response()->download($filePath, $attachment->original_name);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to download file", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Add participants to a group
     */
    public function addParticipants(Request $request, $uuid)
    {
        $this->validate($request, [
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer'
        ]);

        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;
            $newUserIds = $request->input('user_ids');

            $conversation = TeamConversation::on("mysql_$parentId")
                ->where('uuid', $uuid)
                ->where('type', 'group')
                ->first();

            if (!$conversation) {
                return $this->failResponse("Group not found", [], null, 404);
            }

            // Check if current user is admin
            $currentParticipant = TeamConversationParticipant::on("mysql_$parentId")
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->first();

            if (!$currentParticipant || $currentParticipant->role !== 'admin') {
                return $this->failResponse("Only admins can add participants", [], null, 403);
            }

            // Verify users belong to same parent_id and are not deleted
            $validUsers = User::whereIn('id', $newUserIds)
                             ->where('parent_id', $parentId)
                             ->where('is_deleted', 0)
                             ->pluck('id')
                             ->toArray();

            $addedUsers = [];
            foreach ($validUsers as $uid) {
                $existing = TeamConversationParticipant::on("mysql_$parentId")
                    ->where('conversation_id', $conversation->id)
                    ->where('user_id', $uid)
                    ->first();

                if ($existing) {
                    if (!$existing->is_active) {
                        $existing->is_active = true;
                        $existing->left_at = null;
                        $existing->joined_at = Carbon::now();
                        $existing->save();
                        $addedUsers[] = $uid;
                    }
                } else {
                    $participant = new TeamConversationParticipant();
                    $participant->setConnection("mysql_$parentId");
                    $participant->conversation_id = $conversation->id;
                    $participant->user_id = $uid;
                    $participant->role = 'member';
                    $participant->joined_at = Carbon::now();
                    $participant->save();
                    $addedUsers[] = $uid;
                }
            }

            return $this->successResponse("Participants added", ['added' => $addedUsers]);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to add participants", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Leave a group
     */
    public function leaveConversation(Request $request, $uuid)
    {
        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            $conversation = TeamConversation::on("mysql_$parentId")
                ->where('uuid', $uuid)
                ->where('type', 'group')
                ->first();

            if (!$conversation) {
                return $this->failResponse("Group not found", [], null, 404);
            }

            $participant = TeamConversationParticipant::on("mysql_$parentId")
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->first();

            if (!$participant) {
                return $this->failResponse("Not a participant", [], null, 404);
            }

            $participant->is_active = false;
            $participant->left_at = Carbon::now();
            $participant->save();

            return $this->successResponse("Left the group", []);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to leave group", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Pusher authentication endpoint
     */
    public function pusherAuth(Request $request)
    {
        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;
            $channelName = $request->input('channel_name');
            $socketId = $request->input('socket_id');

            // Validate channel access
            if (strpos($channelName, "private-team-chat.{$parentId}") === 0 ||
                strpos($channelName, "private-team-org.{$parentId}") === 0 ||
                strpos($channelName, "presence-team-chat.{$parentId}") === 0 ||
                strpos($channelName, "private-team-user.{$parentId}.{$userId}") === 0) {

                $user = User::find($userId);

                if (strpos($channelName, 'presence-') === 0) {
                    $presenceData = [
                        'user_id' => $userId,
                        'user_info' => [
                            'name' => $this->getUserFullName($user),
                        ]
                    ];
                    $auth = $this->pusher->presenceAuth($channelName, $socketId, $userId, $presenceData);
                } else {
                    $auth = $this->pusher->socketAuth($channelName, $socketId);
                }

                return response($auth);
            }

            return response()->json(['error' => 'Forbidden'], 403);
        } catch (\Throwable $exception) {
            return response()->json(['error' => $exception->getMessage()], 500);
        }
    }

    // Helper method to get user's full name
    private function getUserFullName($user)
    {
        if (!$user) {
            return 'Unknown';
        }
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        return $fullName ?: ($user->email ?? 'Unknown');
    }

    // Helper method to get direct chat name
    private function getDirectChatName($conversation, $currentUserId, $users)
    {
        $otherParticipant = $conversation->activeParticipants
            ->where('user_id', '!=', $currentUserId)
            ->first();

        if ($otherParticipant) {
            $user = $users->get($otherParticipant->user_id);
            return $this->getUserFullName($user);
        }

        return 'Chat';
    }

    /**
     * Initiate a call (supports both 1:1 and group calls)
     */
    public function initiateCall(Request $request, $uuid)
    {
        $this->validate($request, [
            'call_type' => 'required|in:audio,video',
        ]);

        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            $conversation = TeamConversation::on("mysql_$parentId")
                ->where('uuid', $uuid)
                ->first();

            if (!$conversation) {
                return $this->failResponse("Conversation not found", [], null, 404);
            }

            if (!$conversation->isParticipant($userId)) {
                return $this->failResponse("Access denied", [], null, 403);
            }

            $caller = User::find($userId);
            $callId = Str::uuid()->toString();

            // Get all other participants
            $participantRecords = TeamConversationParticipant::on("mysql_$parentId")
                ->where('conversation_id', $conversation->id)
                ->where('user_id', '!=', $userId)
                ->where('is_active', true)
                ->pluck('user_id');

            // Get participant details for group calls (exclude deleted users)
            $participantUsers = User::whereIn('id', $participantRecords)->where('is_deleted', 0)->get();
            $participantsList = $participantUsers->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $this->getUserFullName($user),
                ];
            })->toArray();

            $isGroupCall = $conversation->type === 'group' || count($participantsList) > 1;

            $callData = [
                'call_id' => $callId,
                'conversation_uuid' => $uuid,
                'conversation_type' => $conversation->type,
                'conversation_name' => $conversation->name,
                'call_type' => $request->input('call_type'),
                'is_group_call' => $isGroupCall,
                'caller' => [
                    'id' => $userId,
                    'name' => $this->getUserFullName($caller),
                ],
                'participants' => $participantsList,
                'active_participants' => [[
                    'id' => $userId,
                    'name' => $this->getUserFullName($caller),
                ]],
                'started_at' => Carbon::now()->toIso8601String(),
            ];

            // Notify all other participants
            foreach ($participantRecords as $participantId) {
                $this->pusher->trigger(
                    "private-team-user.{$parentId}.{$participantId}",
                    'call.incoming',
                    $callData
                );
            }

            return $this->successResponse("Call initiated", $callData);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to initiate call", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Send call signaling (offer, answer, ice-candidate)
     */
    public function callSignal(Request $request, $uuid)
    {
        $this->validate($request, [
            'call_id' => 'required|string',
            'signal_type' => 'required|in:offer,answer,ice-candidate',
            'signal_data' => 'required',
            'target_user_id' => 'required|integer',
        ]);

        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            $sender = User::find($userId);

            $signalData = [
                'call_id' => $request->input('call_id'),
                'conversation_uuid' => $uuid,
                'signal_type' => $request->input('signal_type'),
                'signal_data' => $request->input('signal_data'),
                'from_user' => [
                    'id' => $userId,
                    'name' => $this->getUserFullName($sender),
                ],
            ];

            $targetUserId = $request->input('target_user_id');

            $this->pusher->trigger(
                "private-team-user.{$parentId}.{$targetUserId}",
                'call.signal',
                $signalData
            );

            return $this->successResponse("Signal sent", []);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to send signal", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Accept a call (notifies caller and all other participants for group calls)
     */
    public function acceptCall(Request $request, $uuid)
    {
        $this->validate($request, [
            'call_id' => 'required|string',
            'caller_id' => 'required|integer',
            'active_participant_ids' => 'array',
        ]);

        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            $conversation = TeamConversation::on("mysql_$parentId")
                ->where('uuid', $uuid)
                ->first();

            if (!$conversation) {
                return $this->failResponse("Conversation not found", [], null, 404);
            }

            $user = User::find($userId);
            $callerId = $request->input('caller_id');

            // Get ALL participants from the conversation to notify everyone
            $allParticipants = TeamConversationParticipant::on("mysql_$parentId")
                ->where('conversation_id', $conversation->id)
                ->where('is_active', true)
                ->pluck('user_id')
                ->toArray();

            $acceptData = [
                'call_id' => $request->input('call_id'),
                'conversation_uuid' => $uuid,
                'accepted_by' => [
                    'id' => $userId,
                    'name' => $this->getUserFullName($user),
                ],
            ];

            // For group calls, notify ALL conversation participants (not just known active ones)
            // This ensures that participants who haven't joined yet know about new joiners
            foreach ($allParticipants as $participantId) {
                if ($participantId != $userId) {
                    $this->pusher->trigger(
                        "private-team-user.{$parentId}.{$participantId}",
                        'call.participant.joined',
                        $acceptData
                    );
                }
            }

            // Also notify caller with call.accepted event (for backward compatibility)
            $this->pusher->trigger(
                "private-team-user.{$parentId}.{$callerId}",
                'call.accepted',
                $acceptData
            );

            return $this->successResponse("Call accepted", []);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to accept call", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Decline/End a call
     */
    public function endCall(Request $request, $uuid)
    {
        $this->validate($request, [
            'call_id' => 'required|string',
            'reason' => 'in:declined,ended,busy,no_answer',
        ]);

        try {
            $userId = $request->auth->id;
            $parentId = $request->auth->parent_id;

            $conversation = TeamConversation::on("mysql_$parentId")
                ->where('uuid', $uuid)
                ->first();

            if (!$conversation) {
                return $this->failResponse("Conversation not found", [], null, 404);
            }

            $user = User::find($userId);

            // Notify all participants
            $participants = TeamConversationParticipant::on("mysql_$parentId")
                ->where('conversation_id', $conversation->id)
                ->where('user_id', '!=', $userId)
                ->where('is_active', true)
                ->pluck('user_id');

            foreach ($participants as $participantId) {
                $this->pusher->trigger(
                    "private-team-user.{$parentId}.{$participantId}",
                    'call.ended',
                    [
                        'call_id' => $request->input('call_id'),
                        'conversation_uuid' => $uuid,
                        'ended_by' => [
                            'id' => $userId,
                            'name' => $this->getUserFullName($user),
                        ],
                        'reason' => $request->input('reason', 'ended'),
                    ]
                );
            }

            return $this->successResponse("Call ended", []);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to end call", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Get ICE server configuration
     */
    public function getIceServers(Request $request)
    {
        return $this->successResponse("ICE servers", [
            'iceServers' => [
                ['urls' => 'stun:stun.l.google.com:19302'],
                ['urls' => 'stun:stun1.l.google.com:19302'],
                ['urls' => 'stun:stun2.l.google.com:19302'],
            ]
        ]);
    }
}
