<?php
$host = "localhost";
$db   = "test_bivaco";
$user = "root";   // đổi lại nếu bạn có mật khẩu
$pass = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Kết nối DB thất bại: " . $e->getMessage());
}
