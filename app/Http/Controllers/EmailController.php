<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EmailController extends Controller
{
   
public function index(Request $request)
{
    $validator = Validator::make($request->all(), [
        'type'  => 'required|in:inbox,sent,draft,archived',
        'start' => 'nullable|integer|min:0',
        'limit' => 'nullable|integer|min:1|max:100',
        'search'=> 'nullable|string'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $connection = 'mysql_' . $request->auth->parent_id;

    $start = (int) $request->get('start', 0);
    $limit = (int) $request->get('limit', 10);

    $query = DB::connection($connection)
        ->table('email_logs')
        ->where('folder', $request->type)
        ->orderBy('created_at', 'desc');

    if ($request->filled('search')) {
        $search = $request->search;
        $query->where(function ($q) use ($search) {
            $q->where('subject', 'like', "%{$search}%")
              ->orWhere('body', 'like', "%{$search}%")
              ->orWhere('to', 'like', "%{$search}%");
        });
    }

    $total = $query->count();

    $emails = $query
        ->offset($start)
        ->limit($limit)
        ->get()
        ->map(function ($email) {
            return [
                'id' => $email->id,
                'from' => $email->from,
                'to' => $email->to,
                'subject' => $email->subject,
                'snippet' => $email->body,
                'type' => $email->folder,
                'created_at' => gmdate('c', strtotime($email->created_at)),
                'has_attachments' => false // future-ready
            ];
        });

    return response()->json([
        'data' => $emails,
        'pagination' => [
            'start' => $start,
            'limit' => $limit,
            'total_rows' => $total,
            // 'has_more' => ($start + $limit) < $total
        ]
    ]);
}
public function show(Request $request, $id)
{
    // ✅ Dynamic DB connection
    $connection = 'mysql_' . $request->auth->parent_id;

    // ✅ Fetch email
    $email = DB::connection($connection)
        ->table('email_logs')
        ->where('id', $id)
        ->first();

    if (!$email) {
        return response()->json([
            'status' => false,
            'message' => 'Email not found'
        ], 404);
    }

    // ✅ Format response as per API doc
    $response = [
        'id' => $email->id,
        'from' => $email->from,
        'to' => array_filter(array_map('trim', explode(',', $email->to))),
        'cc' => [],   // future ready
        'bcc' => [],  // future ready
        'subject' => $email->subject,
        'body' => $email->body,
        'type' => $email->folder,
        'attachments' => [], // future attachment table mapping
        'created_at' => gmdate('c', strtotime($email->created_at))
    ];

    return response()->json($response);
}
public function storeDraft(Request $request)
{
    $validator = Validator::make($request->all(), [
        'to' => 'required|array',
        'subject' => 'nullable|string',
        'body' => 'nullable|string',
        'cc' => 'nullable|array',
        'bcc' => 'nullable|array',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $connection = 'mysql_' . $request->auth->parent_id;

    $id = DB::connection($connection)
        ->table('email_logs')
        ->insertGetId([
            'from' => $request->auth->email ??env('MAIL_FROM_ADDRESS') ,
            'senderType' => 'user',
             'user_id' => $request->auth->id,
            'to' => implode(',', $request->to),
            'cc' => json_encode($request->cc ?? []),
             'bcc' => json_encode($request->bcc ?? []),
            'subject' => $request->subject,
            'body' => $request->body,
            'folder' => 'draft',
            'created_at' => Carbon::now()
        ]);

    return response()->json([
        'status' => true,
        'message' => 'Draft saved successfully',
        'data' => [
            'id' => $id
        ]
    ], 201);
}



 
public function updateDraft(Request $request, $id)
{
    // ✅ Validate request
    $validator = Validator::make($request->all(), [
        'to' => 'required|array|min:1',
        'to.*' => 'email',
        'cc' => 'nullable|array',
        'cc.*' => 'email',
        'bcc' => 'nullable|array',
        'bcc.*' => 'email',
        'subject' => 'required|string|max:255',
        'body' => 'required|string',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $connection = 'mysql_' . $request->auth->parent_id;

    // 🔍 Check if draft exists
    $draft = DB::connection($connection)
        ->table('email_logs')
        ->where('id', $id)
        ->where('folder', 'draft')
        ->first();

    if (!$draft) {
        return response()->json([
            'status' => false,
            'message' => 'Draft email not found'
        ], 404);
    }

    // 📝 Update draft
    DB::connection($connection)
        ->table('email_logs')
        ->where('id', $id)
        ->update([
            'senderType' => 'user',
             'user_id' => $request->auth->id,
            'to' => ($request->to),
            'cc' => json_encode($request->cc ?? []),
            'bcc' => json_encode($request->bcc ?? []),
            'subject' => $request->subject,
            'body' => $request->body,
        ]);

    return response()->json([
        'status' => true,
        'message' => 'Draft updated successfully'
    ]);
}

public function deleteDraft(Request $request, $id)
{
    $connection = 'mysql_' . $request->auth->parent_id;

    // 🔍 Check if draft exists
    $draft = DB::connection($connection)
        ->table('email_logs')
        ->where('id', $id)
        ->where('folder', 'draft')
        ->first();

    if (!$draft) {
        return response()->json([
            'status' => false,
            'message' => 'Draft email not found'
        ], 404);
    }

    // 🗑 Delete draft
    DB::connection($connection)
        ->table('email_logs')
        ->where('id', $id)
        ->delete();

    return response()->json([
        'status' => true,
        'message' => 'Draft email removed successfully'
    ]);
}

public function archive(Request $request)
{
    $validator = Validator::make($request->all(), [
        'email_ids' => 'required|array|min:1',
        'email_ids.*' => 'integer',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $connection = 'mysql_' . $request->auth->parent_id;

    // 🔄 Update folder to "archive" only if it's not already archived
    $archivedCount = DB::connection($connection)
        ->table('email_logs')
        ->whereIn('id', $request->email_ids)
        ->where('folder', '!=', 'archived') // ✅ ignore already archived emails
        ->update(['folder' => 'archived']);

    return response()->json([
        'status' => true,
        'message' => 'Emails archived successfully',
        'archived_count' => $archivedCount
    ]);
}

public function unarchive(Request $request)
{
    $validator = Validator::make($request->all(), [
        'email_ids' => 'required|array|min:1',
        'email_ids.*' => 'integer',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $connection = 'mysql_' . $request->auth->parent_id;

    // 🔄 Update folder back to "inbox" and get count
    $unarchivedCount = DB::connection($connection)
        ->table('email_logs')
        ->whereIn('id', $request->email_ids)
        ->update(['folder' => 'inbox']);

    return response()->json([
        'status' => true,
        'message' => 'Emails restored successfully',
        'restored_count' => $unarchivedCount  // ✅ Number of emails unarchived
    ]);
}




}
