<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Model\Client\UploadHistoryDid;
use App\Model\User;



class ShowHistoryController extends Controller
{
  
    public function HistoryList(Request $request)
    {
        try {
            $start_date = $request->input('start_date');
            $end_date = $request->input('end_date');
            $url_title = $request->input('url_title');
            $limitString = '';
            $parameters = [];
    
            $query = "SELECT * FROM upload_history_did";

            // Apply filters
            if (!empty($start_date) && !empty($end_date)) {
                $query .= " WHERE upload_history_did.created_at >= ? AND upload_history_did.created_at < DATE_ADD(?, INTERVAL 1 DAY)";
                $parameters[] = $start_date;
                $parameters[] = $end_date;
            }
            if (!empty($url_title)) {
                $query .= " AND upload_history_did.url_title = ?";
                $parameters[] = $url_title;
            }

            $countQuery = "SELECT COUNT(*) as count " . substr($query, strpos($query, 'FROM'));
            $countParameters = $parameters;

            $query .= " ORDER BY upload_history_did.id DESC";

            if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
                $query .= " LIMIT ?, ?";
                $parameters[] = $request->input('lower_limit');
                $parameters[] = $request->input('upper_limit');
            }

            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($query, $parameters);

            $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($countQuery, $countParameters);
            $recordCount = (array)$recordCount;
    
            $show_history = [];

            foreach ($record as $item) {
                $user = User::on('master')
                    ->where('id', $item->user_id)
                    ->first();
    
                if ($user) {
                    $item->user_name = $user->first_name . ' ' . $user->last_name;
                } else {
                    $item->user_name = 'Unknown';
                }
    
                $show_history[] = $item;
            }
            //unique url title
            $uniqueUrlTitles = DB::connection('mysql_' . $request->auth->parent_id)
            ->table('upload_history_did')
            ->select('url_title')
            ->distinct()
            ->pluck('url_title')
            ->toArray();
            if (!empty($show_history)) {
                return [
                    'success' => true,
                    'message' => 'Show History detail.',
                    'data' => $show_history,
                    'record_count' => $recordCount['count'],
                    'start_date'=>$start_date,
                    'end_date'=>$end_date,
                    'url_title'=>$url_title,
                    'unique_url_titles' => $uniqueUrlTitles,
                ];
            }
    
            return [
                'success' => false,
                'message' => 'History not found.',
                'data' => [],
                'record_count' => 0,
                'errors' => [],
                'start_date'=>$start_date,
                'end_date'=>$end_date,
                'url_title'=>$url_title,
                'unique_url_titles' => $uniqueUrlTitles,

            ];
        } catch (Exception $e) {
            Log::error($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::error($e->getMessage());
        }
    }
}
