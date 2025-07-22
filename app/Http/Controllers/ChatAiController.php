<?php

namespace App\Http\Controllers;

use App\Model\Client\ChatAISetting;
use App\Model\Client\ChatAI;
use App\Model\Master\Did;
use Illuminate\Support\Facades\DB;





use Illuminate\Http\Request;

class ChatAiController extends Controller
{


    /**
     * @OA\Get(
     *     path="/chat-ai-setting", 
     *     summary="Get Chat AI Settings",
     *     description="Fetches the Chat AI settings for a specific client based on their parent ID.",
     *     tags={"ChatAi"},
     *     security={{"Bearer": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Successfully fetched Chat AI settings",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Chat AI Setting"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="setting_name", type="string", example="Default Setting"),
     *                     @OA\Property(property="value", type="string", example="enabled")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No Chat AI settings found for the client",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="No Chat AI Setting found"),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Failed to list Chat AI Setting"),
     *             @OA\Property(property="data", type="array", @OA\Items()),
     *             @OA\Property(property="error", type="string", example="Exception message")
     *         )
     *     )
     * )
     */

    public function list(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            //$clientId = 3;
            $setting = [];
            $setting = ChatAISetting::on("mysql_$clientId")->get()->first();

            return $this->successResponse("Chat AI Setting", [$setting]);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list of Chat AI Setting", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function createSetting(Request $request)
    {


        try {
            $input = $request->all();
            $chat = new ChatAISetting();
            $chat->setConnection("mysql_" . $request->auth->parent_id);

            if (!empty($input["introduction"])) $chat->introduction = $input["introduction"];
            if (!empty($input["description"])) $chat->description = $input["description"];
            if (!empty($input["access_token"])) $chat->access_token = $input["access_token"];

            $chat->saveOrFail();



            return $this->successResponse("Added Successfully", $chat->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to save Chat AI setting", [$exception->getMessage()], $exception, 500);
        }
    }


    /**
     * @OA\Post(
     *     path="/send-text-to-ai", 
     *     summary="Create a new Chat AI Message",
     *     description="This endpoint creates a new chat AI message, saves it to the database, and sends it to an external API for processing.",
     *     tags={"ChatAi"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *          required={"text", "customer_id"},
     *          @OA\Property(property="text", type="string", example="Hello, I need assistance"),
     *           @OA\Property(property="customer_id", type="integer", example=123),
     *            
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successfully created a new Chat AI message and sent it to external AI service",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Added Successfully"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request (e.g., missing required parameters)",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error (e.g., API failure, database issues)",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Failed to save SMS AI setting"),
     *             @OA\Property(property="error", type="string", example="Exception message")
     *         )
     *     )
     * )
     */



    public function create(Request $request)
    {

        //  return $this->successResponse("Added Successfully", [$request->text,$request->customer_id]);



        try {


            $chat = new ChatAI();
            $chat->setConnection("mysql_" . $request->auth->parent_id);
            //$smtp->mail_type = 'online application';

            if (!empty($request->text)) $chat->message = $request->text;
            $chat->date = date('Y-m-d') . 'T' . date('H:i:s');
            $chat->type = 'merchant';
            $chat->sms_type = 'outgoing';
            $chat->json_data = $request->text . '-' . $request->customer_id;
            $chat->customer_id = $request->customer_id;




            $chat->saveOrFail();



            $TELNYX_SMS_AI_URL   = env('TELNYX_SMS_AI_URL');
            $sendSms = $TELNYX_SMS_AI_URL . 'chat/inbound';




            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $sendSms);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: application/json',
                'x-api-key: ' . env('TELNYX_SMS_AI_TOKEN'),
                'Content-Type: application/json',
            ]);

            $array = ['customer_id' => $request->customer_id, 'text' => $request->text];
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($array));
            $response = curl_exec($ch);

            $data = json_decode($response);

            //  echo $data->response->user_id;die;


            $chat = new ChatAI();
            $chat->setConnection("mysql_" . $request->auth->parent_id);

            $chat->message = $data->response->text;
            $chat->date = date('Y-m-d') . 'T' . date('H:i:s');
            $chat->type = 'ai';
            $chat->sms_type = 'incoming';
            $chat->json_data = $response;
            $chat->customer_id = $data->response->customer_id;

            $chat->saveOrFail();


            return $this->successResponse("Added Successfully", [$response->user_id]);





            curl_close($ch);
            // return $response;


            return $this->successResponse("Added Successfully", [$response]);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to save SMS AI setting", [$exception->getMessage()], $exception, 500);
        }
    }




    /**
     * @OA\Post(
     *     path="/update-chat-ai-setting/{id}", 
     *     summary="update Chat AI Settings",
     *     description="update the Chat AI settings for a specific client based on their parent ID.",
     *     tags={"ChatAi"},
     *     security={{"Bearer": {}}},
     *      @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="The ID of the Chat AI Setting to update",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *         @OA\Property(property="introduction", type="string", example="New introduction text"),
     *         @OA\Property(property="description", type="string", example="Updated description of the system."),
     *         @OA\Property(property="access_token", type="string", example="new_access_token_here")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successfully updateed Chat AI settings",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Chat AI Setting"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="setting_name", type="string", example="Default Setting"),
     *                     @OA\Property(property="value", type="string", example="enabled")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No Chat AI settings found for the client",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="No Chat AI Setting found"),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Failed to list Chat AI Setting"),
     *             @OA\Property(property="data", type="array", @OA\Items()),
     *             @OA\Property(property="error", type="string", example="Exception message")
     *         )
     *     )
     * )
     */

    public function update(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;




        try {
            $System = ChatAISetting::on("mysql_$clientId")->findOrFail($id);
            if ($request->has("introduction")) {
                $System->introduction = $request->input("introduction");
            }
            if ($request->has("description")) {
                $System->description = $request->input("description");
            }
            if ($request->has("access_token")) {
                $System->access_token = $request->input("access_token");
            }


            $System->saveOrFail();




            return $this->successResponse("Chat AI Seting Updated", $System->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Chat AI System Setting  Not Found", [
                "Invalid SMS AI System Setting  id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Chat AI System Setting ", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }


    /**
     * @OA\post(
     *     path="/chat-ai-history", 
     *     summary="Get Chat AI History for a customer",
     *     description="Fetches the chat history for a specific customer based on customer_id.",
     *     tags={"ChatAi"},
     *     security={{"Bearer": {}}},
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         description="ID of the customer for whom the chat history is requested",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successfully fetched the chat history",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="CHAT AI Data"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="message", type="string", example="Hello, how can I assist you?"),
     *                     @OA\Property(property="date", type="string", format="date-time", example="2025-04-25T10:00:00Z"),
     *                     @OA\Property(property="type", type="string", example="text"),
     *                     @OA\Property(property="status", type="string", example="delivered"),
     *                     @OA\Property(property="customer_id", type="integer", example=123),
     *                     @OA\Property(property="sms_type", type="string", example="outbound")
     *                 )
     *             ),
     *             @OA\Property(property="count", type="integer", example=10)
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request (e.g., missing required parameters)",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Failed to View CHAT AI Data"),
     *             @OA\Property(property="data", type="array", @OA\Items()),
     *             @OA\Property(property="error", type="string", example="Exception message")
     *         )
     *     )
     * )
     */
    public function chatHistory(Request $request)
    {
        $this->validate($request, [
            'customer_id' => 'required',
            //'number' => 'required',
        ]);

        try {
            $customer_id = $request->customer_id;
            $chat_ai = ChatAI::on("mysql_" . $request->auth->parent_id)->where('customer_id', $customer_id)->select(
                'message',
                'date',
                'type',
                'status',
                'customer_id',
                'sms_type'
            )->orderBy('id', 'desc')->get()->all();
            $chat_ai['count'] = count($chat_ai);
            return $this->successResponse("CHAT AI Data", $chat_ai);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to View CHAT AI Data", [$exception->getMessage()], $exception, 500);
        }
    }

    public function delete(Request $request)
    {
        try {
            $sms_ai_data = SmsAI::on("mysql_" . $request->auth->parent_id)->where('did', $request->cli)->where('number', $request->number);
            $sms_ai_data->delete();
            return $this->successResponse("Delete info", [$sms_ai_data]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No data Found");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to delete data info", [], $exception);
        }
    }
}
