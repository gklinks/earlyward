<?php
$protocol = $_SERVER['SERVER_PROTOCOL'];
header( "{$protocol} 503 Service Unavailable", true, 503 );
?>
<html><body>Back soon</body></html>
