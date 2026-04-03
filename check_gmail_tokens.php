<?php
require __DIR__ . '/bootstrap/app.php';
$app->boot();

// Check master DB for Gmail tokens
$pdo = new PDO(
    'mysql:host=' . env('DB_HOST') . ';dbname=' . env('DB_DATABASE'),
    env('DB_USERNAME'),
    env('DB_PASSWORD')
);

$stmt = $pdo->prepare("SELECT id, user_id, client_id, email, is_active FROM gmail_oauth_tokens WHERE client_id = 11");
$stmt->execute();
$tokens = $stmt->fetchAll(PDO::FETCH_OBJ);

echo "Gmail OAuth tokens for client 11:\n";
foreach ($tokens as $t) {
    echo "  id={$t->id} user_id={$t->user_id} email={$t->email} active={$t->is_active}\n";
}
if (empty($tokens)) {
    echo "  (none found)\n";
}

// Also check email_parsed_attachments for sender info
$conn = 'mysql_11';
$attachments = Illuminate\Support\Facades\DB::connection($conn)
    ->table('email_parsed_attachments')
    ->select('id','gmail_message_id','filename','sender_email','sender_name','email_subject','doc_type','parse_status','linked_lead_id')
    ->orderByDesc('id')
    ->limit(20)
    ->get();

echo "\nRecent parsed attachments:\n";
foreach ($attachments as $a) {
    echo "  [{$a->id}] from={$a->sender_email} ({$a->sender_name}) subj=" . substr($a->email_subject ?? '', 0, 60) . " doc={$a->doc_type} status={$a->parse_status} lead=" . ($a->linked_lead_id ?? 'null') . "\n";
}
