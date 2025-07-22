<?php

namespace App\Http\Controllers;

use Session;
use App\Helper\Helper;
use Illuminate\Http\Request;
use App\Events\IncomingLead;
use Google\Cloud\TextToSpeech\V1\AudioConfig;
use Google\Cloud\TextToSpeech\V1\AudioEncoding;
use Google\Cloud\TextToSpeech\V1\SsmlVoiceGender;
use Google\Cloud\TextToSpeech\V1\SynthesisInput;
use Google\Cloud\TextToSpeech\V1\TextToSpeechClient;
use Google\Cloud\TextToSpeech\V1\VoiceSelectionParams;

/**
* Google client controller
* Text to Speech
*/
class VoiceAiExtensionUserController extends Controller {

    /**
    * Get request controller
    * @param Request $request
    */

    function voiceAi(Request $request)
    {
        try
        {
            $uniqueId = rand(1000,9999);

            $text = "Hello, You have reached abhi sharma Voice mail. Currently abhi is unavailable to take your call. Please leave your name, number & brief message after the beep and abhi will get back to you.";//$request->speech_text;
            $voice = "en-US ## en-US-Standard-A ## MALE";//$request->voice_name_ddl;
            $voice_name_ddl = explode("##", $voice);

            $langCode = trim($voice_name_ddl[0]);
            $stand_wave = trim($voice_name_ddl[1]);
            $gender = trim($voice_name_ddl[2]);

            $client = new TextToSpeechClient(['credentials' => env('GOOGLE_JSON_KEY')]);
            $input_text = (new SynthesisInput())
            //->setText($text);
            ->setSsml("<speak>".$text."</speak>");

            if($gender == 'FEMALE')
            {
                $voice = (new VoiceSelectionParams())
                ->setLanguageCode($langCode)
                ->setName($stand_wave)
                ->setSsmlGender(SsmlVoiceGender::FEMALE);
            }
            else
            {
                $voice = (new VoiceSelectionParams())
                ->setLanguageCode($langCode)
                ->setName($stand_wave)
                ->setSsmlGender(SsmlVoiceGender::MALE);
            }

            // Effects profile
            $effectsProfileId = "telephony-class-application";

            // select the type of audio file you want returned
            $audioConfig = (new AudioConfig())
            ->setAudioEncoding(AudioEncoding::MP3)
            /*->setPitch($_GET['pitch'])
                ->setSpeakingRate($_GET['speking_rate']);*/
            ->setEffectsProfileId(array($effectsProfileId));

            $response = $client->synthesizeSpeech($input_text, $voice, $audioConfig);
            $audioContent = $response->getAudioContent();
            $file = $uniqueId."_output.mp3";

            $filePath = 'upload/voice_audio/'.$file;

            if(file_exists($filePath))
            {
                unlink($filePath);
            }
            file_put_contents($filePath, $audioContent);

            $client->close();
            return response()->json(['file' => $file]);

            $textToSpeechClient->close();
        }
        catch(Exception $e)
        {
            echo $e->getMessage();
        }
    }
    

    
}


