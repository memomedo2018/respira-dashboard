<?php
if (($_GET['secret'] ?? '') !== 'Y2gQTfAwNRL51PKOU_JgZfb5J45oBy0v') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo '{"error":"forbidden"}';
    exit;
}
header('X-LiteSpeed-Purge: *');
header('Content-Type: application/json');
echo '{"status":"purged","time":"' . date('c') . '"}';
