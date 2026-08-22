<?php

require __DIR__ . '/db.php';

$stmt = $db->prepare("
    INSERT INTO glasses (
        code,
        order_number,
        glass_type,
        width,
        height,
        quantity,
        status,
        current_location,
        employee_id,
        comment
    ) VALUES (
        :code,
        :order_number,
        :glass_type,
        :width,
        :height,
        :quantity,
        :status,
        :current_location,
        :employee_id,
        :comment
    )
");

$stmt->execute([
    ':code' => 'GLASS-000001',
    ':order_number' => 'ORDER-0001',
    ':glass_type' => 'Закалённое',
    ':width' => 1200,
    ':height' => 800,
    ':quantity' => 1,
    ':status' => 'created',
    ':current_location' => 'Склад',
    ':employee_id' => 1,
    ':comment' => 'Тестовое стекло',
]);

echo "Glass created.\n";
