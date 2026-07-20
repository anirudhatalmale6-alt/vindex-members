<?php
require_once __DIR__ . '/lib/auth.php';
boot_session();
logout();
redirect('login.php');
