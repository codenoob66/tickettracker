<?php
// DISABLED for deploy: credentials are already set in auth_config.php.
// This file must NOT exist on the server. Delete it locally and on the host.
// It is also blocked by .htaccess / web.config. This stub never writes anything.
http_response_code(404);
exit('Not found. Delete this file (setup_password.php) from the server.');
