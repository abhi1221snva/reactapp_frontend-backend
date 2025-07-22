<?php

namespace App\Services;
use GuzzleHttp\RequestOptions;
class TimezoneService
{
    public function findTimezoneValue(string $val)
    {
        $timezone_array = array("Pacific/Midway" => "-11:00","America/Adak" => "-10:00",
                                "Pacific/Marquesas" => "-09:30","Pacific/Gambier" => "-09:00",
                                "America/Anchorage" => "-09:00","America/Ensenada" => "-08:00",
                                "Etc/GMT+8" => "-08:00","America/Los_Angeles" => "-08:00",
                                "America/Denver" => "-07:00","America/Chihuahua" => "-07:00",
                                "America/Dawson_Creek" => "-07:00","America/Belize" => "-06:00",
                                "America/Cancun" => "-06:00","Chile/EasterIsland" => "-06:00",
                                "America/Chicago" => "-06:00","America/New_York" => "-05:00",
                                "America/Havana" => "-05:00","America/Bogota" => "-05:00",
                                "America/Caracas" => "-04:30","America/Santiago" => "-04:00",
                                "America/La_Paz" => "-04:00","Atlantic/Stanley" => "-04:00",
                                "America/Campo_Grande" => "-04:00","America/Goose_Bay" => "-04:00",
                                "America/Glace_Bay" => "-04:00", "America/St_Johns" => "-03:30",
                                "America/Araguaina" => "-03:00","America/Montevideo" => "-03:00",
                                "America/Miquelon" => "-03:00","America/Godthab" => "-03:00",
                                "America/Argentina/Buenos_Aires" => "-03:00","America/Sao_Paulo" => "-03:00",
                                "America/Noronha" => "-02:00","Atlantic/Cape_Verde" => "-01:00",
                                "Atlantic/Azores" => "-01:00","Europe/Belfast" => ") Gree",
                                "Europe/Dublin" => ") Gree","Europe/Lisbon" => ") Gree",
                                "Europe/London" => ") Gree","Africa/Abidjan" => ") Monr",
                                "Europe/Amsterdam" => "+01:00","Europe/Belgrade" => "+01:00", 
                                "Europe/Brussels" => "+01:00","Africa/Algiers" => "+01:00",
                                "Africa/Windhoek" => "+01:00","Asia/Beirut" => "+02:00",
                                "Africa/Cairo" => "+02:00","Asia/Gaza" => "+02:00",
                                "Africa/Blantyre" => "+02:00","Asia/Jerusalem" => "+02:00",
                                "Europe/Minsk" => "+02:00","Asia/Damascus" => "+02:00",
                                "Europe/Moscow" => "+03:00","Africa/Addis_Ababa" => "+03:00",
                                "Asia/Tehran" => "+03:30","Asia/Dubai" => "+04:00",
                                "Asia/Yerevan" => "+04:00","Asia/Kabul" => "+04:30",
                                "Asia/Yekaterinburg" => "+05:00","Asia/Tashkent" => "+05:00",
                                "Asia/Kolkata" => "+05:30","Asia/Katmandu" => "+05:45",
                                "Asia/Dhaka" => "+06:00","Asia/Novosibirsk" => "+06:00",
                                "Asia/Rangoon" => "+06:30","Asia/Bangkok" => "+07:00",
                                "Asia/Krasnoyarsk" => "+07:00","Asia/Hong_Kong" => "+08:00",
                                "Asia/Irkutsk" => "+08:00","Australia/Perth" => "+08:00",
                                "Australia/Eucla" => "+08:45","Asia/Tokyo" => "+09:00",
                                "Asia/Seoul" => "+09:00","Asia/Yakutsk" => "+09:00",
                                "Australia/Adelaide" => "+09:30","Australia/Darwin" => "+09:30",
                                "Australia/Brisbane" => "+10:00","Australia/Hobart" => "+10:00",
                                "Asia/Vladivostok" => "+10:00","Australia/Lord_Howe" => "+10:30",
                                "Etc/GMT-11" => "+11:00","Asia/Magadan" => "+11:00",
                                "Pacific/Norfolk" => "+11:30","Asia/Anadyr" => "+12:00",
                                "Pacific/Auckland" => "+12:00","Etc/GMT-12" => "+12:00",
                                "Pacific/Chatham" => "+12:45","Pacific/Tongatapu" => "+13:00",
                                "Pacific/Kiritimati" => "+14:00");
                
                return $timezone_array[$val];
            }
        }
