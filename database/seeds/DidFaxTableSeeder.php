<?php

use App\Model\Dids;
use App\Model\Role;
use App\Model\User;
use App\Model\Client\FaxDid;
use Illuminate\Database\Seeder;

class DidFaxTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $users = User::all();
        foreach ($users as $user) {
            
            $didObj = Dids::on('mysql_' . $user->parent_id)->where(['fax'=>1])->where('sms_email','<>','')->orderBy("sms_email")->get()->toArray();
            

            if(count($didObj)>0){
                foreach ($didObj as $key => $value) {
                    if($value['fax']==0 || empty(trim($value['sms_email'])) ){
                        continue;
                    }
                   $faxDid = FaxDid::on('mysql_' . $user->parent_id)->firstOrCreate(
                       ['userId' =>  $value['sms_email']],
                       ['did' => $value['cli']]
                    );
                }
            }
            
        }
    }
}
