<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarEventsService;
use Illuminate\Http\Request;

/**
 * @OA\Get(
 *   path="/calendar/status",
 *   summary="Get Google Calendar connection status",
 *   operationId="calendarStatus",
 *   tags={"Google Calendar"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Calendar connection status"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *   path="/calendar/events",
 *   summary="List Google Calendar events for a time range",
 *   operationId="calendarEventsList",
 *   tags={"Google Calendar"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="time_min", in="query", required=true, @OA\Schema(type="string", format="date-time")),
 *   @OA\Parameter(name="time_max", in="query", required=true, @OA\Schema(type="string", format="date-time")),
 *   @OA\Response(response=200, description="Calendar events"),
 *   @OA\Response(response=400, description="Calendar not connected")
 * )
 *
 * @OA\Post(
 *   path="/calendar/events",
 *   summary="Create a Google Calendar event",
 *   operationId="calendarEventsCreate",
 *   tags={"Google Calendar"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(required={"title"},
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string"),
 *     @OA\Property(property="location", type="string"),
 *     @OA\Property(property="all_day", type="boolean"),
 *     @OA\Property(property="start_datetime", type="string", format="date-time"),
 *     @OA\Property(property="end_datetime", type="string", format="date-time"),
 *     @OA\Property(property="timezone", type="string")
 *   )),
 *   @OA\Response(response=200, description="Event created")
 * )
 *
 * @OA\Put(
 *   path="/calendar/events/{eventId}",
 *   summary="Update a Google Calendar event",
 *   operationId="calendarEventsUpdate",
 *   tags={"Google Calendar"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="eventId", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string"),
 *     @OA\Property(property="start_datetime", type="string", format="date-time"),
 *     @OA\Property(property="end_datetime", type="string", format="date-time")
 *   )),
 *   @OA\Response(response=200, description="Event updated"),
 *   @OA\Response(response=404, description="Event not found")
 * )
 *
 * @OA\Delete(
 *   path="/calendar/events/{eventId}",
 *   summary="Delete a Google Calendar event",
 *   operationId="calendarEventsDelete",
 *   tags={"Google Calendar"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="eventId", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Event deleted")
 * )
 */
class GoogleCalendarEventsController extends Controller
{
    protected GoogleCalendarEventsService $calendarService;

    public function __construct()
    {
        $this->calendarService = new GoogleCalendarEventsService();
    }

    /**
     * Get Google Calendar connection status.
     */
    public function status(Request $request)
    {
        try {
            $status = $this->calendarService->getStatus($request->auth->id);
            return $this->successResponse('Calendar status retrieved', $status);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to get status', [$e->getMessage()], $e, 500);
        }
    }

    /**
     * List events for a time range.
     * Query params: time_min (ISO8601), time_max (ISO8601)
     */
    public function list(Request $request)
    {
        $this->validate($request, [
            'time_min' => 'required|string',
            'time_max' => 'required|string',
        ]);

        try {
            $result = $this->calendarService->listEvents(
                $request->auth->id,
                $request->input('time_min'),
                $request->input('time_max')
            );

            if ($result === null) {
                return $this->failResponse('Google Calendar not connected or token expired. Please reconnect from your profile.', [], null, 400);
            }

            if (isset($result['error'])) {
                return $this->failResponse($result['error'], [], null, 400);
            }

            return $this->successResponse('Events retrieved', ['events' => $result]);

        } catch (\Throwable $e) {
            return $this->failResponse('Failed to fetch events', [$e->getMessage()], $e, 500);
        }
    }

    /**
     * Create a new calendar event.
     */
    public function create(Request $request)
    {
        $this->validate($request, [
            'title'          => 'required|string|max:500',
            'description'    => 'nullable|string',
            'location'       => 'nullable|string|max:500',
            'all_day'        => 'nullable|boolean',
            // All-day events require start_date; timed events require start_datetime + end_datetime
            'start_date'     => 'required_if:all_day,true|nullable|string',
            'end_date'       => 'nullable|string',
            'start_datetime' => 'required_unless:all_day,true|nullable|string',
            'end_datetime'   => 'required_unless:all_day,true|nullable|string',
            'timezone'       => 'nullable|string',
        ]);

        try {
            $event = $this->calendarService->createEvent($request->auth->id, $request->all());

            if ($event === null) {
                return $this->failResponse('Google Calendar token is missing or expired. Please reconnect from your profile.', [], null, 400);
            }

            if (isset($event['error'])) {
                return $this->failResponse($event['error'], [], null, 400);
            }

            return $this->successResponse('Event created', ['event' => $event]);

        } catch (\Throwable $e) {
            return $this->failResponse('Failed to create event', [$e->getMessage()], $e, 500);
        }
    }

    /**
     * Update a calendar event.
     */
    public function update(Request $request, string $eventId)
    {
        $this->validate($request, [
            'title'          => 'required|string|max:500',
            'description'    => 'nullable|string',
            'location'       => 'nullable|string|max:500',
            'all_day'        => 'nullable|boolean',
            'start_date'     => 'required_if:all_day,true|nullable|string',
            'end_date'       => 'nullable|string',
            'start_datetime' => 'required_unless:all_day,true|nullable|string',
            'end_datetime'   => 'required_unless:all_day,true|nullable|string',
            'timezone'       => 'nullable|string',
        ]);

        try {
            $event = $this->calendarService->updateEvent($request->auth->id, $eventId, $request->all());

            if ($event === null) {
                return $this->failResponse('Google Calendar token is missing or expired. Please reconnect from your profile.', [], null, 400);
            }

            if (isset($event['error'])) {
                return $this->failResponse($event['error'], [], null, 400);
            }

            return $this->successResponse('Event updated', ['event' => $event]);

        } catch (\Throwable $e) {
            return $this->failResponse('Failed to update event', [$e->getMessage()], $e, 500);
        }
    }

    /**
     * Delete a calendar event.
     */
    public function delete(Request $request, string $eventId)
    {
        try {
            $success = $this->calendarService->deleteEvent($request->auth->id, $eventId);

            if (!$success) {
                return $this->failResponse('Failed to delete event', [], null, 400);
            }

            return $this->successResponse('Event deleted');

        } catch (\Throwable $e) {
            return $this->failResponse('Failed to delete event', [$e->getMessage()], $e, 500);
        }
    }
}
