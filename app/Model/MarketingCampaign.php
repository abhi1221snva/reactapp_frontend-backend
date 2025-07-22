<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
class MarketingCampaign extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'marketting_campaign';

    /*
     *Fetch Campaign list
     *@param integer $id
     *@return array
     */
    public function marketingCampaignDetail($request)
    {
        try
        {
            $searchStr = array('is_deleted = :is_deleted');
            $data['is_deleted'] = '0';
            if($request->has('marketing_id') && is_numeric($request->input('marketing_id')))
            {
                array_push($searchStr, 'id = :id');
                $data['id'] = $request->input('marketing_id');
            }
            $str = !empty($searchStr) ? "  WHERE ".implode(" AND ", $searchStr) : '';
            $sql = "SELECT * FROM ".$this->table.$str;
            $record =  DB::connection('mysql_'.$request->auth->parent_id)->select($sql, $data);
            $data = (array)$record;
            if(!empty($data))
            {
                return array(
                    'success'=> 'true',
                    'message'=> 'Marketing Campaign detail.',
                    'data'   => $data
                );
            }
            return array(
                'success'=> 'false',
                'message'=> 'Marketing Campaign not created.',
                'data'   => array()
            );
        }
        catch (Exception $e)
        {
            Log::log($e->getMessage());
        }
        catch (InvalidArgumentException $e)
        {
            Log::log($e->getMessage());
        }
    }

    /*
     *Update Campaign details
     *@param object $request
     *@return array
     */
    public function updateMarketingCampaign($request)
    {
        try
        {


            if($request->has('marketing_id') && is_numeric($request->input('marketing_id')))
            {
                $validate = $this->validateMarketingCampaign($request);
                $updateString = $validate['string'];
                $data = $validate['data'];

               // return $data;
                if(!empty($updateString) && !empty($data))
                {
                    //$date_time = date('Y-m-d h:i:s');
                    $data['id'] = $request->input('marketing_id');
                    $query = "UPDATE ".$this->table." set ".implode(" , ", $updateString)." WHERE id = :id";
                    
                    $save =  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $data);
                    if($save == 1)
                    {
                        return array(
                            'success'=> 'true',
                            'message'=> 'Campaign updated successfully.'
                        );
                    }
                    else
                    {
                        return array(
                            'success'=> 'false',
                            'message'=> 'Campaign are not updated successfully.'
                        );
                    }
                }

            }
            return array(
                'success'=> 'false',
                'message'=> 'Campaign doesn\'t exist.'
            );
        }
        catch (Exception $e)
        {
            Log::log($e->getMessage());
        }
        catch (InvalidArgumentException $e)
        {
            Log::log($e->getMessage());
        }
    }
    /*
     *Add group details
     *@param object $request
     * @return array
     */
    public function addMarketingCampaign($request)
    {
        try
        {
            if($request->has('title') && !empty($request->input('title'))) {


                $validate = $this->validateMarketingCampaign($request);
                $insertString = implode(" , ", $validate['string']);
                $data = $validate['data'];

               
                 $query = "INSERT INTO ".$this->table." SET ".$insertString;
                $add =  DB::connection('mysql_'.$request->auth->parent_id)->insert($query, $data);
                if($add == true)
                {
                   
                   return array(
                        'success'   => 'true',
                        'message'   => 'Marketing Campaign added successfully.',
                        
                    );
                }
                else
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Marketing Campaign are not added successfully, Due to some incorrect value.'
                    );
                }
            }

            return array(
                'success'=> 'false',
                'message'=> 'Marketing Campaign are not added successfully. Title is missing'
            );
        }
        catch (Exception $e)
        {
            Log::log($e->getMessage());
        }
        catch (InvalidArgumentException $e)
        {
            Log::log($e->getMessage());
        }
    }
    /*
     * Validate Campaign
     *
     */
    protected function validateMarketingCampaign($request)
    {
        $string = array();
        $data = array();
        if($request->has('title') && !empty($request->input('title'))) {
            array_push($string, 'title = :title');
            $data['title'] = $request->input('title');
        }
        if($request->has('description') && !empty($request->input('description'))) {
            array_push($string, 'description = :description');
            $data['description'] = $request->input('description');
        }
        if($request->has('mail_gateway_type') && !empty($request->input('mail_gateway_type'))) {
            array_push($string, 'mail_gateway_type = :mail_gateway_type');
            $data['mail_gateway_type'] = $request->input('mail_gateway_type');
        }
       
        if($request->has('mail_gateway') && !empty($request->input('mail_gateway'))) {
            array_push($string, 'mail_gateway = :mail_gateway');
            $data['mail_gateway'] = $request->input('mail_gateway');
        }
        if($request->has('mail_template') && !empty($request->input('mail_template'))) {
            array_push($string, 'mail_template = :mail_template');
            $data['mail_template'] = $request->input('mail_template');
        }
        if($request->has('sms_gateway_type') && !empty($request->input('sms_gateway_type'))) {
            array_push($string, 'sms_gateway_type = :sms_gateway_type');
            $data['sms_gateway_type'] = $request->input('sms_gateway_type');
        }
       
         if($request->has('sms_gateway') && !empty($request->input('sms_gateway'))) {
            array_push($string, 'sms_gateway = :sms_gateway');
            $data['sms_gateway'] = $request->input('sms_gateway');
        }
       

         if($request->has('is_deleted') && !empty($request->input('is_deleted'))) {
            array_push($string, 'is_deleted = :is_deleted');
            $data['is_deleted'] = $request->input('is_deleted');
        }
       
        if($request->has('sms_template') && !empty($request->input('sms_template'))) {
            array_push($string, 'sms_template = :sms_template');
            $data['sms_template'] = $request->input('sms_template');
        }
        if($request->has('campaign_run_times') && !empty($request->input('campaign_run_times'))) {
            array_push($string, 'campaign_run_times = :campaign_run_times');
            $data['campaign_run_times'] = $request->input('campaign_run_times');
        }
        if($request->has('send_report') && is_numeric($request->input('send_report'))) {
            array_push($string, 'send_report = :send_report');
            $data['send_report'] = $request->input('send_report');
        }

        if($request->has('group_id') && is_numeric($request->input('group_id'))) {
            array_push($string, 'group_id = :group_id');
            $data['group_id'] = $request->input('group_id');
        }
        return array('string' => $string, 'data' => $data);
    }

    /*
     *Fetch campaign for agent
     *@param object $request
     *@return array
     */
    public function getAgentCampaign($request)
    {
        try
        {
            if (!empty($request->input('id')) && $request->auth->role == '2')
            {
                $db = $request->auth->parent_id;
                $extension = $request->auth->extension;
                $extensionGroup = DB::connection('mysql_'.$db)->select(
                            "SELECT * FROM extension_group_map WHERE extension = :extension AND is_deleted = :is_deleted",
                            array('extension' => $extension,'is_deleted' => 0)
                        );
                if(!empty($extensionGroup))
                {
                    $inStr = array();
                    $data['is_deleted']  = 0;
                    $count = 1;
                    foreach ($extensionGroup as $item=>$value)
                    {
                        array_push($inStr, ":group_".$count);
                        $data["group_".$count] = $value->group_id;
                        $count++;
                    }
                    $campaign = DB::connection('mysql_'.$db)->select(
                                    "SELECT * FROM campaign WHERE group_id in (".implode(' , ', $inStr).") AND is_deleted = :is_deleted",
                                    $data
                                );
                    if(!empty($campaign))
                    {
                        return array(
                            'success'   => 'true',
                            'message'   => 'List of campaign for extension.',
                            'data'      => $campaign
                        );
                    }
                    else
                    {
                        return array(
                            'success'   => 'true',
                            'message'   => 'Extension is not belong to any campaign.',
                            'data'      => array()
                        );
                    }
                }
                else
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Extension not belong to any group'
                    );
                }
            }
        } catch (Exception $e) {
            echo $e->getMessage();
        } catch (InvalidArgumentException $e) {
            echo $e->getMessage();
        }
    }
    function getCampaignCount($request){
        try{
            $data['is_deleted'] = 0;
            $sql = "SELECT count(1) as rowCount FROM ".$this->table." WHERE is_deleted = :is_deleted ";
            $record =  DB::connection('mysql_'.$request->auth->parent_id)->selectOne($sql, $data);
            $data = (array)$record;
            return array(
                'success'   => 'true',
                'message'   => 'Extension is not belong to any campaign.',
                'data'      => $data['rowCount']
            );
            
        } catch (Exception $e) {
            echo $e->getMessage();
        } catch (InvalidArgumentException $e) {
            echo $e->getMessage();
        }
    }
}
