<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$auth->logout();
redirect('/login.php');
