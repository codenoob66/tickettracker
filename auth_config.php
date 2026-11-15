<?php
// Auth config — keep this file private. Password is stored as a hash only.
// Direct web access is also blocked via .htaccess / web.config; this is defense-in-depth
// in case those server rules are ignored on your host.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
$AUTH_USER = 'rafael-vincent.cordova@group.one';
$AUTH_HASH = '$2y$12$OC4Id2msRmPfBxEFmtwkU.odcNNqLAircS53K5FPCm4XGqvv7Xr6O';
