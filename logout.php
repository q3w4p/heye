<?php
/**
 * Logout Handler
 * Destroys session and redirects to landing page
 */

session_start();

// Unset all session variables
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Redirect to landing page
header('Location: /index.html');
exit;
?>
