<?php

use App\Model\Client\ExtensionGroupMap;
use App\Model\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SimilarAltExtensionDataFixSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $strClientsSql = "SELECT id FROM master.clients";
        $arrClients = DB::select($strClientsSql);

        $strSql = "SELECT
                        u.*, ue.secret
                    FROM
                        users AS u
                        JOIN user_extensions AS ue ON (ue.name = u.extension)
                    WHERE
                        u.extension = u.alt_extension
                            AND u.is_deleted = 0";
        $arrUsersHavingSameExtension = DB::select($strSql);
        echo "Total Users found: " . count($arrUsersHavingSameExtension) . "\n";

        $arrUsersHavingSameExtensionRekeyed = $this->rekeyArrayOfObjects($arrUsersHavingSameExtension, 'parent_id');

        foreach ($arrClients as $client) {
            if (isset($arrUsersHavingSameExtensionRekeyed[$client->id])) {
                echo "Client ID($client->id): \n";

                //Get extension_group_map entries
                $arrExtensionGroupMap = ExtensionGroupMap::on("mysql_" . $client->id)->get()->toArray();
                $arrExtensionGroupMapRekeyed = $this->rekeyArray($arrExtensionGroupMap, 'extension');
                $arrExistingExtensions = array_column($arrUsersHavingSameExtensionRekeyed[$client->id], "extension");

                foreach ($arrUsersHavingSameExtensionRekeyed[$client->id] as $objUserHavingSameExtension) {
                    $intGeneratedAltExtension = $this->generateExtension($arrExistingExtensions);

                    //update into users
                    if (User::find($objUserHavingSameExtension->id)->update(['alt_extension' => $client->id . $intGeneratedAltExtension])) {
                        echo "User ID: $objUserHavingSameExtension->id, Table updating: Users";
                    }

                    //insert into user_extension
                    $arrDataForUserExtensions['name'] = $client->id . $intGeneratedAltExtension;
                    $arrDataForUserExtensions['username'] = $client->id . $intGeneratedAltExtension;
                    $arrDataForUserExtensions['secret'] = $objUserHavingSameExtension->secret;
                    $arrDataForUserExtensions['context'] = 'default';
                    $arrDataForUserExtensions['host'] = 'dynamic';
                    $arrDataForUserExtensions['nat'] = 'force_rport,comedia';
                    $arrDataForUserExtensions['qualify'] = 'no';
                    $arrDataForUserExtensions['type'] = 'friend';
                    $arrDataForUserExtensions['fullname'] = $objUserHavingSameExtension->first_name . ' ' . $objUserHavingSameExtension->last_name;
                    $arrDataForUserExtensions['rtptimeout'] = '7200';
                    $arrDataForUserExtensions['rtpholdtimeout'] = '7200';
                    $arrDataForUserExtensions['sendrpid'] = 'yes';
                    $arrDataForUserExtensions['subscribemwi'] = 'yes';
                    $arrDataForUserExtensions['t38pt_udptl'] = 'no';
                    $arrDataForUserExtensions['transport'] = 'UDP,WS,WSS';
                    $arrDataForUserExtensions['trustrpid'] = 'no';
                    $arrDataForUserExtensions['useclientcode'] = 'no';
                    $arrDataForUserExtensions['usereqphone'] = 'no';
                    $arrDataForUserExtensions['videosupport'] = 'no';
                    $arrDataForUserExtensions['icesupport'] = 'yes';
                    $arrDataForUserExtensions['force_avp'] = 'yes';
                    $arrDataForUserExtensions['dtlsenable'] = 'yes';
                    $arrDataForUserExtensions['dtlsverify'] = 'fingerprint';
                    $arrDataForUserExtensions['dtlscertfile'] = '/etc/asterisk/asterisk.pem';
                    $arrDataForUserExtensions['dtlssetup'] = 'actpass';
                    $arrDataForUserExtensions['rtcp_mux'] = 'yes';
                    $arrDataForUserExtensions['avpf'] = 'yes';
                    $arrDataForUserExtensions['webrtc'] = 'yes';

                    $insertData = "INSERT IGNORE INTO user_extensions SET fullname= :fullname, context= :context, name= :name, type= :type , qualify= :qualify , nat= :nat , host= :host, secret= :secret,username= :username, rtptimeout= :rtptimeout, rtpholdtimeout= :rtpholdtimeout,sendrpid= :sendrpid,subscribemwi= :subscribemwi,t38pt_udptl= :t38pt_udptl,transport= :transport,trustrpid= :trustrpid,useclientcode= :useclientcode,usereqphone= :usereqphone,videosupport= :videosupport,icesupport= :icesupport,force_avp =:force_avp,dtlsenable=:dtlsenable,dtlsverify=:dtlsverify,dtlscertfile= :dtlscertfile,dtlssetup= :dtlssetup,rtcp_mux= :rtcp_mux,avpf= :avpf,
                webrtc= :webrtc";
                    $response = DB::connection('master')->select($insertData, $arrDataForUserExtensions);
                    echo ", user_extensions";

                    //insert into extension_group_map
                    if (isset($arrExtensionGroupMapRekeyed[$objUserHavingSameExtension->extension])) {
                        foreach ($arrExtensionGroupMapRekeyed[$objUserHavingSameExtension->extension] as $arrExtensionGroupMap) {

                            $sql = "INSERT INTO extension_group_map (extension, group_id) VALUES (:extension, :group_id) ON DUPLICATE KEY UPDATE is_deleted = :is_deleted";
                            $updateGroupResponse = DB::connection('mysql_' . $client->id)->insert($sql, array('is_deleted' => $arrExtensionGroupMap['group_id'], 'extension' => $objUserHavingSameExtension->parent_id . $intGeneratedAltExtension, 'group_id' => $arrExtensionGroupMap['group_id']));
                        }
                        echo ", extension_group_map.";
                    }
                    echo "\n";
                    array_push($arrExistingExtensions, $objUserHavingSameExtension->parent_id . $intGeneratedAltExtension);
                }
            } else{
                echo "Client ID($client->id): No Similar Extension found. \n";
            }
        }
    }


    public static function rekeyArrayOfObjects($arrDataToRekey, $key)
    {
        if (empty($arrDataToRekey)) return [];

        $arrDataToReturn = [];
        foreach ($arrDataToRekey as $objSingleData) {
            $arrDataToReturn[$objSingleData->$key][] = $objSingleData;
        }
        return $arrDataToReturn;
    }

    public static function rekeyArray($arrDataToRekey, $key)
    {
        if (empty($arrDataToRekey)) return [];

        $arrDataToReturn = [];
        foreach ($arrDataToRekey as $arrSingleData) {
            $arrDataToReturn[$arrSingleData[$key]][] = $arrSingleData;
        }
        return $arrDataToReturn;
    }

    function generateExtension($arrExistingExtensions)
    {
        $intGeneratedExtension = '';
        $boolUniqueFound = true;

        while ($boolUniqueFound) {
            $intGeneratedExtension = mt_rand(1000, 9999);
            if (!in_array($intGeneratedExtension, $arrExistingExtensions)) {
                $boolUniqueFound = false;
            }
        }
        return $intGeneratedExtension;
    }
}
