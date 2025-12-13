<?php
require_once __DIR__ . '/../db.php';
session_destroy();
header('Location: security_login.php');
exit();
