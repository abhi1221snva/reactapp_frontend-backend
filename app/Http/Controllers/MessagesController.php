<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use App\Model\Client\ChMessage as Message;
use App\Model\Client\ChFavorite as Favorite;
use App\Model\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;


class MessagesController extends Controller
{
    protected $perPage = 30;

    //TODO: Beautify this file
     /**
     * Authinticate the connection for pusher
     *
     * @param Request $request
     * @return void
     */
    public function pusherAuth(Request $request)
    {
        // Auth data
        $authData = json_encode([
            'user_id' => Auth::user()->id,
            'user_info' => [
                'name' => Auth::user()->name
            ]
        ]);
        // check if user authorized
        if (Auth::check()) {
            return Chatify::pusherAuth(
                $request['channel_name'],
                $request['socket_id'],
                $authData
            );
        }
        // if not authorized
        return response()->json(['message'=>'Unauthorized'], 401);
    }

    /**
     * Fetch data by id for (user/group)
     *
     * @param Request $request
     * @return collection
     */
    public function idFetchData(Request $request)
    {
        // Favorite
        $favorite = $this->inFavorite($request->auth->id, $request['to_id'], $request->auth->parent_id);

        // User data
        if ($request['type'] == 'user') {
            $fetch = User::where('id', $request['to_id'])->first();
            if($fetch){
                $userAvatar = ('/asset/img/' . $fetch->avatar);
                $fetch->name = $fetch->first_name . " " . $fetch->last_name;
            }
        }

        // send the response
        return $this->successResponse("idFetchData",
            ['favorite' => $favorite,
            'fetch' => $fetch ?? [],
            'user_avatar' => $userAvatar ?? null]
        );
    }

    //TODO: if not required then remove this
    /**
     * This method to make a links for the attachments
     * to be downloadable.
     *
     * @param string $fileName
     * @return void
     */
    public function download($fileName)
    {
        $path = storage_path() . '/app/public/' . config('chatify.attachments.folder') . '/' . $fileName;
        if (file_exists($path)) {
            return response()->json([
                'file_name' => $fileName,
                'download_path' => $path
            ], 200);
        } else {
            return response()->json([
                'message'=>"Sorry, File does not exist in our server or may have been deleted!"
            ], 404);
        }
    }

    /**
     * Send a message to database
     *
     * @param Request $request
     * @return JSON response
     */
    public function send(Request $request)
    {
        $error = (object)[
            'status' => 0,
            'message' => null
        ];
        $attachment = $request->get('attachment');

        if (!$error->status) {
            // send to database
            $messageID = mt_rand(9, 999999999) + time();
            $this->newMessage([
                'id' => $messageID,
                'client_id' => $request->auth->parent_id,
                'type' => $request->get('type'),
                'from_id' => $request->auth->id,
                'to_id' =>  $request->get('to_id'),
                'body' => htmlentities(trim($request->get('message')), ENT_QUOTES, 'UTF-8'),
                'attachment' => ($attachment) ? $attachment : null,
            ]);

            if($request->get('meeting_key')) {
                $objMessageController = new MeetingsController();
                $objMessageController->newMeeting([
                    'key' => $request->get('meeting_key'),
                    'from_id' => $request->auth->id,
                    'message_id' => $messageID
                ]);
            }

            // fetch message to send it with the response
            $messageData = $this->fetchMessage($messageID, $request->auth->id, $request->auth->parent_id);
        }

        // send the response
        return $this->successResponse("Api List Url", ['status' => '200',
            'error' => $error,
            "sender_name" => $request->auth->first_name . " " . $request->auth->last_name,
            'message' => $messageData ?? [],
            'tempID' => $request['temporaryMsgId']]);
    }

    /**
     * fetch [user/group] messages from database
     *
     * @param Request $request
     * @return JSON response
     */
    public function fetch(Request $request)
    {
        $arrMessageData = [];
        $query = $this->fetchMessagesQuery($request->auth->id, $request['to_id'],$request->auth->parent_id,'DESC');
        $messages = $query->paginate($request->per_page ?? $this->perPage);

        foreach($messages as $message) {
            $arrMessageData[$message->id] = $this->fetchMessage($message->id, $request->auth->id, $request->auth->parent_id);
        }
        $totalMessages = $messages->total();
        $lastPage = $messages->lastPage();
        $response = [
            'total' => $totalMessages,
            'last_page' => $lastPage,
            'last_message_id' => collect($messages->items())->last()->id ?? null,
            'messages' => array_reverse($messages->items()),
            'messageData' => array_reverse($arrMessageData, true),
        ];
        return $this->successResponse("Fetch messages", $response);
    }

    /**
     * Make messages as seen
     *
     * @param Request $request
     * @return void
     */
    public function seen(Request $request)
    {
        // make as seen
        $seen = $this->makeSeen($request['from_id'], $request->auth->id, $request->auth->parent_id);
        return $this->successResponse("Mark Seen", ['status' => $seen]);
    }

    /**
     * Get contacts list
     *
     * @param Request $request
     * @return JSON response
     */
    public function getContacts(Request $request)
    {
        $arrContactItemData = [];
        $intUserID = $request->auth->id;

        // get all users that received/sent message from/to [Auth user]
        $users = Message::on("mysql_" . $request->auth->parent_id)->join('master.users',  function ($join) {
            $join->on('ch_messages.from_id', '=', 'users.id')
                ->orOn('ch_messages.to_id', '=', 'users.id');
        })
        ->where('users.id','!=',$request->auth->id)
        ->select('users.*',DB::raw('MAX(ch_messages.created_at) max_created_at'))
        ->orderBy('max_created_at', 'desc')
        ->groupBy('users.id')
        ->paginate($request->per_page ?? $this->perPage);

        foreach ($users as $user) {
            list($lastMessage, $unseenCounter) = $this->getContactItem($user, $request->auth->id, $request->auth->parent_id);
            $arrContactItemData[$user->id]['lastMessage'] = $lastMessage;
            $arrContactItemData[$user->id]['unseenCounter'] = $unseenCounter;
        }

        return $this->successResponse("get Contacts", [
            'contacts' => $users->items(),
            'ContactItemData' => $arrContactItemData,
            'total' => $users->total() ?? 0,
            'lastPage' => $users->lastPage() ?? 1,
        ]);
    }

    /**
     * Put a user in the favorites list
     *
     * @param Request $request
     * @return void
     */
    public function markFavorite(Request $request)
    {
        // check action [star/unstar]
        if ($this->inFavorite($request->auth->id, $request['user_id'], $request->auth->parent_id)) {
            // UnStar
            $this->makeInFavorite($request['user_id'], 0, $request->auth->id, $request->auth->parent_id);
            $status = 0;
        } else {
            // Star
            $this->makeInFavorite($request['user_id'], 1, $request->auth->id, $request->auth->parent_id);
            $status = 1;
        }
        return $this->successResponse("get Contacts", [
            'status' => @$status,
        ]);
    }

    /**
     * Get favorites list
     *
     * @param Request $request
     * @return void
     */
    public function getFavorites(Request $request)
    {
        $favorites = Favorite::on('mysql_'.$request->auth->parent_id)->where('user_id', $request->auth->id)->get();
        foreach ($favorites as $favorite) {
            $favorite->user = User::where('id', $favorite->favorite_id)->first();
        }

        return $this->successResponse("Favorites info",[
            'total' => count($favorites),
            'favorites' => $favorites ?? [],
        ]);
    }

    /**
     * Search in messenger
     *
     * @param Request $request
     * @return void
     */
    public function search(Request $request)
    {
        $input = trim(filter_var($request['input'], FILTER_SANITIZE_STRING));
        $records = User::where('id','!=',$request->auth->id)
                    ->where('first_name', 'LIKE', "%{$input}%")
                    ->orwhere('last_name', 'LIKE', "%{$input}%")
                    ->where('parent_id', '=', $request->auth->parent_id)
                    ->paginate($request->per_page ?? $this->perPage);

        return $this->successResponse("Search info", [
            'records' => $records->items(),
            'total' => $records->total(),
            'last_page' => $records->lastPage()
        ]);
    }

    /**
     * Get shared photos
     *
     * @param Request $request
     * @return void
     */
    public function sharedPhotos(Request $request)
    {
        $images = $this->getSharedPhotos($request->auth->id, $request['to_id'],$request->auth->parent_id);
        return $this->successResponse("Shared Photos info", [
            'shared' => $images ?? [],
        ]);
    }

    /**
     * Delete conversation
     *
     * @param Request $request
     * @return void
     */
    public function deleteConversation(Request $request)
    {
        // delete
        $delete = $this->deleteConversationData($request->auth->id, $request['to_id'],$request->auth->parent_id);

        return $this->successResponse("Update Settings", [
            'deleted' => $delete ? 1 : 0,
        ]);
    }

    public function updateSettings(Request $request)
    {
        $msg = null;
        $error = $success = 0;

        // dark mode
        if ($request['dark_mode']) {
            $request['dark_mode'] == "dark"
                ? User::where('id', $request->auth->id)->update(['dark_mode' => 1])  // Make Dark
                : User::where('id', $request->auth->id)->update(['dark_mode' => 0]); // Make Light
        }

        // If messenger color selected
        if ($request['messengerColor']) {

            $messenger_color = explode('-', trim(filter_var($request['messengerColor'], FILTER_SANITIZE_STRING)));
            $messenger_color = $this->getMessengerColors()[$messenger_color[1]];
            User::where('id', $request->auth->id)
                ->update(['messenger_color' => $messenger_color]);
        }

        // if there is a [file]
        if ($request->hasFile('avatar')) {
            // allowed extensions
            $allowed_images = Chatify::getAllowedImages();

            $file = $request->file('avatar');
            // if size less than 150MB
            if ($file->getSize() < 150000000) {
                if (in_array($file->getClientOriginalExtension(), $allowed_images)) {
                    // delete the older one
                    if (Auth::user()->avatar != config('chatify.user_avatar.default')) {
                        $path = storage_path('app/public/' . config('chatify.user_avatar.folder') . '/' . Auth::user()->avatar);
                        if (file_exists($path)) {
                            @unlink($path);
                        }
                    }
                    // upload
                    $avatar = Str::uuid() . "." . $file->getClientOriginalExtension();
                    $update = User::where('id', Auth::user()->id)->update(['avatar' => $avatar]);
                    $file->storeAs("public/" . config('chatify.user_avatar.folder'), $avatar);
                    $success = $update ? 1 : 0;
                } else {
                    $msg = "File extension not allowed!";
                    $error = 1;
                }
            } else {
                $msg = "File extension not allowed!";
                $error = 1;
            }
        }

        return $this->successResponse("Update Settings", [
            'status' => $success ? 1 : 0,
            'error' => $error ? 1 : 0,
            'message' => $error ? $msg : 0,
        ]);

    }

    /**
     * Set user's active status
     *
     * @param Request $request
     * @return void
     */
    public function setActiveStatus(Request $request)
    {
        $update = $request['status'] > 0
            ? User::where('id', $request['user_id'])->update(['active_status' => 1])
            : User::where('id', $request['user_id'])->update(['active_status' => 0]);

        return $this->successResponse("Update Active Status", [
            'status' => $update,
        ]);
    }

    public function newMessage($data){
        $message = new Message();
        $message->setConnection("mysql_".$data['client_id']);
        $message->id = $data['id'];
        $message->type = $data['type'];
        $message->from_id = $data['from_id'];
        $message->to_id = $data['to_id'];
        $message->body = $data['body'];
        $message->attachment = $data['attachment'];
        $message->save();
    }

    public function fetchMessage($id, $fromUserId, $intClientId){
        $attachment = null;
        $attachment_type = null;
        $attachment_title = null;

        $msg = Message::on('mysql_' . $intClientId)->where('id',$id)->first();

        if(isset($msg->attachment)){
            $attachmentOBJ = json_decode($msg->attachment);
            $attachment = $attachmentOBJ->new_name;
            $attachment_title = htmlentities(trim($attachmentOBJ->old_name), ENT_QUOTES, 'UTF-8');

            $ext = pathinfo($attachment, PATHINFO_EXTENSION);
            $attachment_type = in_array($ext,$this->getAllowedImages()) ? 'image' : 'file';
        }

        return [
            'id' => $msg->id,
            'from_id' => $msg->from_id,
            'to_id' => $msg->to_id,
            'message' => $msg->body,
            'attachment' => [$attachment, $attachment_title, $attachment_type],
            'time' => $msg->created_at->diffForHumans(),
            'fullTime' => \Carbon\Carbon::parse($msg->created_at)->format('Y-m-d h:i:s'),
            'viewType' => ($msg->from_id == $fromUserId) ? 'sender' : 'default',
            'seen' => $msg->seen,
        ];
    }

    public function getAllowedImages(){
        return (array) ['png','jpg','jpeg','gif'];
    }

    public function fetchMessagesQuery($userId, $toUserId, $parentId, $order){
        return Message::on("mysql_" . $parentId)->where('from_id',$userId)->where('to_id',$toUserId)
            ->orWhere('from_id',$toUserId)->where('to_id',$userId)->orderBy('created_at', $order);
    }

    public function inFavorite($userId, $toUserId, $parentId){
        return Favorite::on("mysql_" . $parentId)->where('user_id', $userId)->where('favorite_id', $toUserId)->count() > 0 ? true : false;;

    }

    public function getSharedPhotos($userId, $toUserId, $parentId){
        $images = array(); // Default
        // Get messages
        $msgs = $this->fetchMessagesQuery($userId, $toUserId, $parentId,'DESC');
        if($msgs->count() > 0){
            foreach ($msgs->get() as $msg) {
                // If message has attachment
                if($msg->attachment){
                    $attachment = json_decode($msg->attachment);
                    // determine the type of the attachment
                    in_array(pathinfo($attachment->new_name, PATHINFO_EXTENSION), $this->getAllowedImages())
                        ? array_push($images, $attachment->new_name) : '';
                }
            }
        }
        return $images;
    }

    public function makeSeen($user_id,$loggedInUserid, $parentId){
        Message::on("mysql_" . $parentId)->Where('from_id',$user_id)
            ->where('to_id',$loggedInUserid)
            ->where('seen',0)
            ->update(['seen' => 1]);
        return 1;
    }

    public function updateContactItem(Request $request){
        try {
            $user = User::where('id', $request['user_id'])->first();
            list($lastMessage, $unseenCounter) = $this->getContactItem($user, $request->auth->id, $request->auth->parent_id);

            // send the response
            return $this->successResponse("update Contact Item", [
                'user' => $user,
                'lastMessage' => $lastMessage,
                'unseenCounter' => $unseenCounter,
            ]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Contact Item", [], $exception);
        }
    }

    public function getContactItem($user, $intSourceUserId, $parentId){
        // get last message
        $lastMessage = $this->getLastMessageQuery($intSourceUserId, $user->id,$parentId);

        // Get Unseen messages counter
        $unseenCounter = $this->countUnseenMessages($user->id, $intSourceUserId, $parentId);

        return [$lastMessage, $unseenCounter];
    }

    public function getLastMessageQuery($intSourceUserId, $toUserId, $parentId){
        return $this->fetchMessagesQuery($intSourceUserId, $toUserId, $parentId,'DESC')->latest()->first();
    }

    public function countUnseenMessages($intDestUserId, $intSourceUserId, $parentId){
        return Message::on("mysql_" . $parentId)->where('from_id',$intDestUserId)->where('to_id',$intSourceUserId)->where('seen',0)->count();
    }

    public function getMessengerColors(){
        return [
            '1' => '#2180f3',
            '2' => '#2196F3',
            '3' => '#00BCD4',
            '4' => '#3F51B5',
            '5' => '#673AB7',
            '6' => '#4CAF50',
            '7' => '#FFC107',
            '8' => '#FF9800',
            '9' => '#ff2522',
            '10' => '#9C27B0',
        ];
    }

    /**
     * Make user in favorite list
     *
     * @param int $user_id
     * @param int $star
     * @return boolean
     */
    public function makeInFavorite($user_id, $action, $intLoggedInUserId, $intClientId){
        if ($action > 0) {
            // Star
            $star = new Favorite();
            $star->setConnection("mysql_" . $intClientId);
            $star->id = rand(9,99999999);
            $star->user_id = $intLoggedInUserId;
            $star->favorite_id = $user_id;
            $star->save();
            return $star ? true : false;
        }else{
            // UnStar
            $star = Favorite::on("mysql_" . $intClientId)->where('user_id',$intLoggedInUserId)->where('favorite_id',$user_id)->delete();
            return $star ? true : false;
        }
    }

    public function deleteConversationData($intSourceUserId, $toUserId, $parentId){
        try {
            foreach ($this->fetchMessagesQuery($intSourceUserId, $toUserId, $parentId,'ASC')->get() as $msg) {
                // delete file attached if exist
                if (isset($msg->attachment)) {
                    $path = storage_path(env('MESSENGER_FILES_PATH').'/'.json_decode( $msg->attachment)->new_name);
                    if(File::exists($path)){
                        File::delete($path);
                    }
                }
                // delete from database
                $msg->delete();
            }
            return 1;
        }catch(Exception $e) {
            return 0;
        }
    }
}
