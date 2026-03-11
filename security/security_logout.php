<?php
require_once __DIR__ . '/../db.php';

destroy_session_completely();

send_no_cache_headers();
header('Location: security_login.php');
exit();
