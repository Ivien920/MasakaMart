<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
unset($_SESSION['admin']);
redirect(APP_URL . '/admin/login.php');
