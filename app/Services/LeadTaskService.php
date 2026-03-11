<?php

namespace App\Services;

use App\Model\Client\CrmScheduledTask;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles CRM scheduled task operations for leads.
 */
class LeadTaskService
{
    /**
     * Create a new scheduled task for a lead.
     */
    public function create(string $clientId, int $leadId, array $data, int $userId): CrmScheduledTask
    {
        $task = new CrmScheduledTask();
        $task->setConnection("mysql_{$clientId}");
        $task->lead_id   = $leadId;
        $task->task_name = $data['task_name'] ?? null;
        $task->date      = $data['date'] ?? null;
        $task->time      = $data['time'] ?? null;
        $task->notes     = $data['notes'] ?? null;
        $task->user_id   = $userId;
        $task->created_at = Carbon::now();
        $task->saveOrFail();

        return $task;
    }

    /**
     * Get all tasks for a specific lead.
     */
    public function getForLead(string $clientId, int $leadId): Collection
    {
        return CrmScheduledTask::on("mysql_{$clientId}")->where('lead_id', $leadId)->get();
    }

    /**
     * Delete a scheduled task by lead ID and task ID.
     *
     * @throws NotFoundHttpException when no matching task is found
     */
    public function delete(string $clientId, int $leadId, int $taskId): void
    {
        $task = CrmScheduledTask::on("mysql_{$clientId}")
            ->where('lead_id', $leadId)
            ->where('id', $taskId)
            ->first();

        if (!$task) {
            throw new NotFoundHttpException("No Task found for lead_id {$leadId} and id {$taskId}");
        }

        $task->delete();
    }
}
