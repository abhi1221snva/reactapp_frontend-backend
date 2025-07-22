<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Facades\Excel;

class Conferencing extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'conferencing';
    /*
     *Fetch dnc list
     *@param integer $id
     *@return array
     */
    public function getConferencing($request)
    {


        try
        {
            $data = array();
            $searchStr = array();
            if($request->has('auto_id') && is_numeric($request->input('auto_id')))
            {
                array_push($searchStr, 'id = :id');
                $data['id'] = $request->input('auto_id');
            }

            $str = !empty($searchStr) ? "  WHERE ".implode(" AND ", $searchStr) : '';
            $sql = "SELECT * FROM ".$this->table.$str." ORDER BY title ASC";
            $record =  DB::connection('mysql_'.$request->auth->parent_id)->select($sql, $data);
            $data = (array)$record;
            if(!empty($data))
            {
                return array(
                    'success'=> 'true',
                    'message'=> 'Conferencing Data detail.',
                    'data'   => $data
                );
            }
            return array(
                'success'=> 'false',
                'message'=> 'Conferencing Data not created.',
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
     *Update dnc details
     *@param object $request
     *@return array
     */
    public function editConferencing($request)
    {
        try
        {
            if($request->has('auto_id') && is_numeric($request->input('auto_id')))
            {
                $updateString = array();
                $data['id'] = $request->input('auto_id');
                if($request->has('title') && $request->input('title')) {
                    array_push($updateString, 'title = :title');
                    $data['title'] = $request->input('title');
                }
               if($request->has('conference_id') && $request->input('conference_id')) {
                    array_push($updateString, 'conference_id = :conference_id');
                    $data['conference_id'] = $request->input('conference_id');
                }

                 if($request->has('host_pin') && $request->input('host_pin')) {
                    array_push($updateString, 'host_pin = :host_pin');
                    $data['host_pin'] = $request->input('host_pin');
                }

                 if($request->has('part_pin') && $request->input('part_pin')) {
                    array_push($updateString, 'part_pin = :part_pin');
                    $data['part_pin'] = $request->input('part_pin');
                }

                 if($request->has('max_part') && $request->input('max_part')) {
                    array_push($updateString, 'max_part = :max_part');
                    $data['max_part'] = $request->input('max_part');
                }

                if($request->has('locked') && is_numeric($request->input('locked'))) {
                    array_push($updateString, 'locked = :locked');
                    $data['locked'] = $request->input('locked');
                }

                if($request->has('mute') && is_numeric($request->input('mute'))) {
                    array_push($updateString, 'mute = :mute');
                    $data['mute'] = $request->input('mute');
                }

                 if($request->has('prompt') && $request->input('prompt')) {
                    array_push($updateString, 'prompt_file = :prompt_file');
                    $data['prompt_file'] = $request->input('prompt');
                }

                if($request->has('speech_text') && !empty($request->input('speech_text'))) {
                    array_push($updateString, 'speech_text = :speech_text');
                    $data['speech_text'] = $request->input('speech_text');
                }

                if($request->has('prompt_option')) {
                    array_push($updateString, 'prompt_option = :prompt_option');
                    $data['prompt_option'] = $request->input('prompt_option');
                }

                if($request->has('language') && !empty($request->input('language'))) {
                    array_push($updateString, 'language = :language');
                    $data['language'] = $request->input('language');
                }

                if($request->has('voice_name') && !empty($request->input('voice_name'))) {
                    array_push($updateString, 'voice_name = :voice_name');
                    $data['voice_name'] = $request->input('voice_name');
                }

                if(!empty($updateString) && !empty($data))
                {
                    $query = "UPDATE ".$this->table." set ".implode(" , ", $updateString)." WHERE id = :id";
                    $save =  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $data);
                    if($save == 1)
                    {
                        return array(
                            'success'=> 'true',
                            'message'=> 'Conferencing updated successfully.'
                        );
                    }
                    else
                    {
                        return array(
                            'success'=> 'false',
                            'message'=> 'Conferencing are not updated successfully.'
                        );
                    }
                }

            }
            return array(
                'success'=> 'false',
                'message'=> 'Conferencing doesn\'t exist.'
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
     *Add dnc details
     *@param object $request
     *@return array
     */
    public function addConferencing($request)
    {
        try
        {
            if($request->has('title') && !empty($request->input('title'))) {
                $data['title'] = $request->input('title');
                $data['conference_id'] = $request->input('conference_id');

                $data['host_pin'] = ($request->has('host_pin') && !empty($request->input('host_pin'))) ? $request->input('host_pin') :"";
                $data['part_pin'] = ($request->has('part_pin') && !empty($request->input('part_pin'))) ? $request->input('part_pin') :"";
                $data['max_part'] = ($request->has('max_part') && !empty($request->input('max_part'))) ? $request->input('max_part') :"";
                $data['locked'] = ($request->has('locked') && !empty($request->input('locked'))) ? $request->input('locked') :"";
                $data['mute'] = ($request->has('mute') && !empty($request->input('mute'))) ? $request->input('mute') :"";
                $data['prompt'] = ($request->has('prompt') && !empty($request->input('prompt'))) ? $request->input('prompt') :"";
                $data['speech_text'] = $request->input('speech_text');
                $data['prompt_option'] = $request->input('prompt_option');
                $data['language'] = $request->input('language');
                $data['voice_name'] = $request->input('voice_name');

                $query = "INSERT INTO ".$this->table." (title,conference_id,host_pin,part_pin,max_part,locked,mute,prompt_file,speech_text,prompt_option,language,voice_name) VALUE (:title, :conference_id, :host_pin, :part_pin,:max_part,:locked,:mute,:prompt,:speech_text,:prompt_option,:language,:voice_name)";
                $add =  DB::connection('mysql_'.$request->auth->parent_id)->insert($query, $data);
                if($add == 1)
                {
                    return array(
                        'success'=> 'true',
                        'message'=> 'Conference added successfully.'
                    );
                }
                else
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Conference are not added successfully.'
                    );
                }
            }

            return array(
                'success'=> 'false',
                'message'=> 'Dnc are not added successfully.'
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
     *Update dnc details
     *@param object $request
     *@return array
     */
    public function deleteConferencing($request)
    {
        try
        {
            if($request->has('auto_id') && is_numeric($request->input('auto_id')))
            {
                $data['id'] = $request->input('auto_id');
                $query = "DELETE FROM ".$this->table." WHERE id = :id";
                $save =  DB::connection('mysql_'.$request->auth->parent_id)->update($query, $data);
                if($save == 1)
                {
                    return array(
                        'success'=> 'true',
                        'message'=> 'Conferencing Id deleted successfully.'
                    );
                }
                else
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Conferencing deleted successfully.'
                    );
                }

            }
            return array(
                'success'=> 'false',
                'message'=> 'Conferencing doesn\'t exist.'
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



        public function uploadDnc($request, $filePath)
    {
        try
        {
            if
            (!empty($filePath))
            {
                $dataBase = 'mysql_'.$request->auth->parent_id;
                try
                {
                    $reader = Excel::toArray(new Excel(), $filePath);
                }
                catch (\Exception $e)
                {
                    return array(
                        'success'=> 'false',
                        'message'=> 'Unable to read excel.'
                    );
                }



                        if(!empty($reader))
                        {
                            $count = 0;
                            foreach ($reader as $row)
                            {
                                $i=0;
                                 foreach ($row as $item=>$value)
                                    {
                                        if($item!=0){
                               $data['number'] = $value[0];
                               $data['extension'] = $value[1];
                               $data['comment'] = $value[2];
                               $data['updated_at'] = $value[3];

                               //echo "<pre>";print_r($data);

                            $query = "INSERT INTO ".$this->table." (number, extension, comment,updated_at) VALUE (:number, :extension, :comment,:updated_at)";
                $add =  DB::connection('mysql_'.$request->auth->parent_id)->insert($query, $data);

                            }

                                    }
                                }


                                if($add == 1)
                {
                    return array(
                        'success'=> 'true',
                        'message'=> 'Dnc added successfully.'
                    );
                }




                        }
                        else
                        {
                            return array(
                                'success'=> 'false',
                                'message'=> 'DNC not added successfully, File is empty',

                            );
                        }


            }

            return array(
                'success'=> 'false',
                'message'=> 'ExcludeNumber are not added successfully.'
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
}
