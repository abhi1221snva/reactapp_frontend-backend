<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarEventsService
{
    protected const CALENDAR_API = 'https://www.googleapis.com/calendar/v3';
    protected const CALENDAR_ID  = 'primary';

    protected GoogleCalendarOAuthService $oauthService;

    public function __construct()
    {
        $this->oauthService = new GoogleCalendarOAuthService();
    }

    /**
     * Get connection status for the user.
     */
    public function getStatus(int $userId): array
    {
        return $this->oauthService->getConnectionStatus($userId);
    }

    /**
     * List events in a time range.
     */
    public function listEvents(int $userId, string $timeMin, string $timeMax): ?array
    {
        $accessToken = $this->oauthService->getValidAccessToken($userId);
        if (!$accessToken) {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(20)
                ->get(self::CALENDAR_API . '/calendars/' . self::CALENDAR_ID . '/events', [
                    'timeMin'      => $timeMin,
                    'timeMax'      => $timeMax,
                    'singleEvents' => 'true',
                    'orderBy'      => 'startTime',
                    'maxResults'   => 250,
                ]);

            if (!$response->successful()) {
                Log::error('Google Calendar API: listEvents failed', [
                    'user_id' => $userId,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);
                return ['error' => $this->userFriendlyError($response->status(), 'load calendar events')];
            }

            $items = $response->json('items') ?? [];

            return array_values(array_map(function ($item) {
                return $this->parseEvent($item);
            }, $items));

        } catch (\Throwable $e) {
            Log::error('Google Calendar API: listEvents exception', ['error' => $e->getMessage()]);
            return ['error' => 'Unable to load calendar events. Please reconnect Google Calendar.'];
        }
    }

    /**
     * Create a new calendar event.
     */
    public function createEvent(int $userId, array $data): ?array
    {
        $accessToken = $this->oauthService->getValidAccessToken($userId);
        if (!$accessToken) {
            return null;
        }

        try {
            $body = [
                'summary'     => $data['title'],
                'description' => $data['description'] ?? '',
                'location'    => $data['location'] ?? '',
            ];

            // All-day vs timed event
            if (!empty($data['all_day'])) {
                if (empty($data['start_date'])) {
                    return ['error' => 'start_date is required for all-day events'];
                }
                $body['start'] = ['date' => $data['start_date']];
                $body['end']   = ['date' => $data['end_date'] ?? $data['start_date']];
            } else {
                if (empty($data['start_datetime']) || empty($data['end_datetime'])) {
                    return ['error' => 'start_datetime and end_datetime are required for timed events'];
                }
                $body['start'] = ['dateTime' => $data['start_datetime'], 'timeZone' => $data['timezone'] ?? 'UTC'];
                $body['end']   = ['dateTime' => $data['end_datetime'],   'timeZone' => $data['timezone'] ?? 'UTC'];
            }

            $response = Http::withToken($accessToken)
                ->timeout(20)
                ->post(self::CALENDAR_API . '/calendars/' . self::CALENDAR_ID . '/events', $body);

            if (!$response->successful()) {
                Log::error('Google Calendar API: createEvent failed', [
                    'user_id' => $userId,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);
                return ['error' => $this->userFriendlyError($response->status(), 'create calendar event')];
            }

            return $this->parseEvent($response->json());

        } catch (\Throwable $e) {
            Log::error('Google Calendar API: createEvent exception', ['error' => $e->getMessage()]);
            return ['error' => 'Unable to create calendar event. Please reconnect Google Calendar.'];
        }
    }

    /**
     * Update an existing calendar event.
     */
    public function updateEvent(int $userId, string $eventId, array $data): ?array
    {
        $accessToken = $this->oauthService->getValidAccessToken($userId);
        if (!$accessToken) {
            return null;
        }

        try {
            $body = [
                'summary'     => $data['title'],
                'description' => $data['description'] ?? '',
                'location'    => $data['location'] ?? '',
            ];

            if (!empty($data['all_day'])) {
                if (empty($data['start_date'])) {
                    return ['error' => 'start_date is required for all-day events'];
                }
                $body['start'] = ['date' => $data['start_date']];
                $body['end']   = ['date' => $data['end_date'] ?? $data['start_date']];
            } else {
                if (empty($data['start_datetime']) || empty($data['end_datetime'])) {
                    return ['error' => 'start_datetime and end_datetime are required for timed events'];
                }
                $body['start'] = ['dateTime' => $data['start_datetime'], 'timeZone' => $data['timezone'] ?? 'UTC'];
                $body['end']   = ['dateTime' => $data['end_datetime'],   'timeZone' => $data['timezone'] ?? 'UTC'];
            }

            $response = Http::withToken($accessToken)
                ->timeout(20)
                ->put(self::CALENDAR_API . '/calendars/' . self::CALENDAR_ID . '/events/' . $eventId, $body);

            if (!$response->successful()) {
                Log::error('Google Calendar API: updateEvent failed', [
                    'user_id'  => $userId,
                    'event_id' => $eventId,
                    'status'   => $response->status(),
                    'body'     => $response->body(),
                ]);
                return ['error' => $this->userFriendlyError($response->status(), 'update calendar event')];
            }

            return $this->parseEvent($response->json());

        } catch (\Throwable $e) {
            Log::error('Google Calendar API: updateEvent exception', ['error' => $e->getMessage()]);
            return ['error' => 'Unable to update calendar event. Please reconnect Google Calendar.'];
        }
    }

    /**
     * Delete a calendar event.
     */
    public function deleteEvent(int $userId, string $eventId): bool
    {
        $accessToken = $this->oauthService->getValidAccessToken($userId);
        if (!$accessToken) {
            return false;
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(20)
                ->delete(self::CALENDAR_API . '/calendars/' . self::CALENDAR_ID . '/events/' . $eventId);

            // 204 No Content is success for DELETE
            return $response->status() === 204 || $response->successful();

        } catch (\Throwable $e) {
            Log::error('Google Calendar API: deleteEvent exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Map a Google API HTTP status to a safe, user-facing message.
     * The raw API body is always logged before calling this; never pass it to callers.
     */
    protected function userFriendlyError(int $status, string $action): string
    {
        if ($status === 401) {
            return "Your Google Calendar session has expired. Please reconnect from your profile.";
        }
        return "Unable to {$action}. Please reconnect Google Calendar from your profile.";
    }

    /**
     * Parse a raw Google Calendar event into a consistent shape.
     */
    protected function parseEvent(array $item): array
    {
        $start   = $item['start'] ?? [];
        $end     = $item['end']   ?? [];
        $allDay  = isset($start['date']) && !isset($start['dateTime']);

        return [
            'id'          => $item['id'],
            'title'       => $item['summary'] ?? '(No title)',
            'description' => $item['description'] ?? '',
            'location'    => $item['location'] ?? '',
            'all_day'     => $allDay,
            'start'       => $start['dateTime'] ?? $start['date'] ?? null,
            'end'         => $end['dateTime']   ?? $end['date']   ?? null,
            'status'      => $item['status'] ?? 'confirmed',
            'html_link'   => $item['htmlLink'] ?? null,
            'creator'     => $item['creator']['email'] ?? null,
            'color_id'    => $item['colorId'] ?? null,
        ];
    }
}
