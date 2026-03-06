<?php
$newPassword = 'Admin@2024!';
echo password_hash($newPassword, PASSWORD_DEFAULT);
echo "\n";
echo "新密码: $newPassword\n";
?>