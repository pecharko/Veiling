<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
admin_session();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $p = session_get_cookie_params();
  setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
redirect('admin/login.php');
