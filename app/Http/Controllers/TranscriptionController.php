<?php
namespace App\Http\Controllers;

/**
 * @OA\Get(
 *   path="/transcription-conversion-api",
 *   summary="Transcribe call recordings",
 *   operationId="transcriptionIndex",
 *   tags={"AI"},
 *   @OA\Response(response=200, description="Transcription result"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 */

use App\Model\Master\RinglessVoiceMail;
use App\Model\Master\RvmDomainList;
use App\Model\Master\RvmCdrLog;

use App\Model\Master\Client;
use App\Jobs\RinglessVoicemailDrop;
use DateTime;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TranscriptionController extends Controller
{

    private function diarizeWithDeepgram($audioUrl) {
    $apiToken = "3cd6a9df7dd92e7c8a524c75a7a6b6de70188a7d"; // Replace with your Deepgram API token

    $url = "https://api.deepgram.com/v1/listen?smart_format=true&punctuate=true&paragraphs=true&diarize=true&language=en&model=nova";

    $headers = [
        "Authorization: Token $apiToken",
        "Content-Type: application/json"
    ];

    $data = json_encode(["url" => $audioUrl]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => $headers
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception("CURL Error: " . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("Deepgram API Error: HTTP Status $httpCode\nResponse: $response");
    }

    return json_decode($response, true);
}

private function compileConversation($words) {
    $conversation = [];
    $currentSpeaker = null;
    $speakerText = '';

    foreach ($words as $wordData) {
        $speaker = $wordData['speaker'] ?? 'Unknown';
        $punctuatedWord = $wordData['punctuated_word'] ?? $wordData['word'];

        if ($currentSpeaker !== $speaker) {
            if ($currentSpeaker !== null) {
                $conversation[] = [
                    'speaker' => $currentSpeaker,
                    'text' => trim($speakerText)
                ];
            }
            $currentSpeaker = $speaker;
            $speakerText = $punctuatedWord . ' ';
        } else {
            $speakerText .= $punctuatedWord . ' ';
        }
    }

    if ($currentSpeaker !== null) {
        $conversation[] = [
            'speaker' => $currentSpeaker,
            'text' => trim($speakerText)
        ];
    }

    return $conversation;
}

private function analyzeConversationWithOpenAI($conversation) {
    $apiToken = "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"; // Replace with your OpenAI API token
    $url = "https://api.openai.com/v1/chat/completions";

    $context = "Analyze the following call conversation on the scale 1 to 10 where 1 is very bad and 10 is extremely outperforming based on  Clarity of Speech, Listening Skills, Tone and Energy, Pace of Conversation, Language Appropriateness, Handling Interruptions, Relationship-Building, Rapport Establishment, Trust and Credibility, Understanding the Prospect's Needs, Product and Service Knowledge, Solution Presentation,Benefit Highlighting, Competitor Differentiation, Answering Questions, Problem-Solving, Handling Objections, Negotiation Skills, Adaptability, Call Structure Introduction, Agenda Setting, Time Management, Closing Statement, Emotional Intelligence, Empathy, Handling Rejection, Call Outcome,Action Taken, Commitment Level, Information Gathering, Call Duration, Conversion Rate, Sales Funnel Progressio,Customer Experience, Prospect's Engagement, Respect for Privacy. Provide the analysis as a JSON object with 'factor', 'rating', and 'comments'.";

    $formattedConversation = implode("\n", array_map(function ($entry) {
        return "Speaker " . $entry['speaker'] . ": " . $entry['text'];
    }, $conversation));

    $prompt = $context . "\n\nConversation:\n" . $formattedConversation;

    $headers = [
        "Authorization: Bearer $apiToken",
        "Content-Type: application/json"
    ];

    $data = json_encode([
        "model" => "gpt-4",
        "messages" => [
            ["role" => "system", "content" => "You are an assistant analyzing call conversations."],
            ["role" => "user", "content" => $prompt]
        ]
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => $headers
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception("CURL Error: " . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("OpenAI API Error: HTTP Status $httpCode\nResponse: $response");
    }

    return json_decode($response, true);
}

/* DISABLED TEST CODE
//if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $audioUrl = 'https://sip2.voiptella.com/recording/93903-5613318703-20250122210324.wav';//$_POST['audio_url'] ?? '';

    if (filter_var($audioUrl, FILTER_VALIDATE_URL)) {
        try {
            $responseData = $this->diarizeWithDeepgram($audioUrl);

            if (isset($responseData['results']['channels'][0]['alternatives'][0]['words'])) {
                $words = $responseData['results']['channels'][0]['alternatives'][0]['words'];
                $conversation = $this->compileConversation($words);

                $analysis = $this->analyzeConversationWithOpenAI($conversation);

                echo '<!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>Analysis Result</title>
                    <style>
                        body { font-family: Arial, sans-serif; }
                        table { width: 100%; border-collapse: collapse; }
                        th, td { border: 1px solid #ddd; padding: 8px; }
                        th { background-color: #f4f4f4; }
                    </style>
                </head>
                <body>
                    <h1>AI Quality Coach</h1>
                    <audio controls>
                        <source src="' . htmlspecialchars($audioUrl) . '" type="audio/wav">
                        Your browser does not support the audio element.
                    </audio>
                    <h2>Transcript</h2>';

                foreach ($conversation as $entry) {
                    echo '<p><strong>Speaker ' . $entry['speaker'] . ':</strong> ' . htmlspecialchars($entry['text']) . '</p>';
                }

                echo '<h2>Analysis</h2>';

                try {
                    $responseContent = $analysis['choices'][0]['message']['content'] ?? '';
                    $ratings = json_decode($responseContent, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($ratings)) {
                        echo '<table>
                            <thead>
                                <tr>
                                    <th>Factor</th>
                                    <th>Rating</th>
                                    <th>Comments</th>
                                </tr>
                            </thead>
                            <tbody>';

                        foreach ($ratings as $factor => $details) {
                            echo '<tr>
                                    <td>' . htmlspecialchars($factor) . '</td>
                                    <td>' . htmlspecialchars($details['rating'] ?? 'N/A') . '</td>
                                    <td>' . htmlspecialchars($details['comments'] ?? 'No comments provided') . '</td>
                                </tr>';
                        }

                        echo '</tbody>
                        </table>';
                    } else {
                        echo '<p>' . nl2br(htmlspecialchars($responseContent)) . '</p>';
                    }
                } catch (Exception $e) {
                    echo '<p>Error processing analysis: ' . htmlspecialchars($e->getMessage()) . '</p>';
                }

                echo '</body></html>';
            } else {
                echo '<p>No words found in the response.</p>';
            }
        } catch (Exception $e) {
            echo '<p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    } else {
        echo '<p>Invalid URL. Please provide a valid WAV file URL.</p>';
    }
//}
END DISABLED TEST CODE */











}
