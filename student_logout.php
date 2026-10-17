<?php
require_once __DIR__ . '/includes/student_auth.php';

unset($_SESSION['student_id'], $_SESSION['student_name'], $_SESSION['student_number'], $_SESSION['student_email']);
redirect('index.php');
