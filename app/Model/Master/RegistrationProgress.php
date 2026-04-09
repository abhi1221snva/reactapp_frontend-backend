<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * Tracks registration provisioning progress for frontend polling.
 *
 * @property int         $id
 * @property int         $registration_id
 * @property string|null $email
 * @property string|null $phone
 * @property string      $path             fast | slow
 * @property string      $stage            queued → creating_record → … → completed | failed
 * @property int         $progress_pct     0-100
 * @property int|null    $client_id
 * @property int|null    $user_id
 * @property string|null $error_message
 * @property int         $retry_count
 */
class RegistrationProgress extends Model
{
    protected $connection = 'master';
    protected $table      = 'registration_progress';

    protected $fillable = [
        'registration_id',
        'email',
        'phone',
        'path',
        'stage',
        'progress_pct',
        'client_id',
        'user_id',
        'error_message',
        'retry_count',
    ];

    // Stage constants
    const STAGE_QUEUED            = 'queued';
    const STAGE_CREATING_RECORD   = 'creating_record';
    const STAGE_CREATING_DATABASE = 'creating_database';
    const STAGE_SEEDING_DATA      = 'seeding_data';
    const STAGE_ASSIGNING_TRIAL   = 'assigning_trial';
    const STAGE_SENDING_WELCOME   = 'sending_welcome';
    const STAGE_COMPLETED         = 'completed';
    const STAGE_FAILED            = 'failed';

    // Progress percentage per stage
    const STAGE_PROGRESS = [
        self::STAGE_QUEUED            => 5,
        self::STAGE_CREATING_RECORD   => 20,
        self::STAGE_CREATING_DATABASE => 50,
        self::STAGE_SEEDING_DATA      => 70,
        self::STAGE_ASSIGNING_TRIAL   => 85,
        self::STAGE_SENDING_WELCOME   => 95,
        self::STAGE_COMPLETED         => 100,
        self::STAGE_FAILED            => 0,
    ];

    /**
     * Advance to the next stage.
     */
    public function advanceTo(string $stage): void
    {
        $this->stage        = $stage;
        $this->progress_pct = self::STAGE_PROGRESS[$stage] ?? 0;
        $this->save();
    }

    /**
     * Mark as failed with an error message.
     */
    public function markFailed(string $message): void
    {
        $this->stage         = self::STAGE_FAILED;
        $this->progress_pct  = 0;
        $this->error_message = substr($message, 0, 500);
        $this->save();
    }

    /**
     * Mark as completed with client + user IDs.
     */
    public function markCompleted(int $clientId, int $userId): void
    {
        $this->stage        = self::STAGE_COMPLETED;
        $this->progress_pct = 100;
        $this->client_id    = $clientId;
        $this->user_id      = $userId;
        $this->save();
    }

    /**
     * Check if provisioning is still in progress.
     */
    public function isInProgress(): bool
    {
        return !in_array($this->stage, [self::STAGE_COMPLETED, self::STAGE_FAILED]);
    }
}
