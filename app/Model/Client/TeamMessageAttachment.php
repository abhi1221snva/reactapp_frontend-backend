<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class TeamMessageAttachment extends Model
{
    protected $table = 'team_message_attachments';

    public $timestamps = false;

    protected $fillable = [
        'message_id',
        'original_name',
        'stored_name',
        'file_path',
        'file_type',
        'file_size',
        'mime_type',
        'thumbnail_path',
        'created_at'
    ];

    protected $casts = [
        'file_size' => 'integer',
        'created_at' => 'datetime',
    ];

    protected $dates = [
        'created_at'
    ];

    public function message()
    {
        return $this->belongsTo(TeamMessage::class, 'message_id');
    }

    public function isImage()
    {
        return in_array($this->file_type, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    }

    public function isDocument()
    {
        return in_array($this->file_type, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv']);
    }

    public function isArchive()
    {
        return in_array($this->file_type, ['zip', 'rar', '7z']);
    }

    public function getFileSizeFormatted()
    {
        $bytes = $this->file_size;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
}
