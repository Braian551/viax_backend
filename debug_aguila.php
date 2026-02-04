<?php
require_once __DIR__ . '/config/database.php';

$db = (new Database())->getConnection();

$email = 'aguila@gmail.com'; // Adjust if user meant something else, e.g. search by approximate email

echo "Searching for '$email'...\n";

// 1. Find user
$stmt = $db->prepare("SELECT * FROM usuarios WHERE email LIKE :email");
$stmt->bindValue(':email', '%aguila%');
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($users) === 0) {
    echo "❌ User not found!\n";
} else {
    foreach ($users as $user) {
        echo "Found User: ID={$user['id']}, Email={$user['email']}, Tipo={$user['tipo_usuario']}, EmpresaID={$user['empresa_id']}\n";
        
        // 2. Check requests
        $stmt2 = $db->prepare("SELECT * FROM solicitudes_vinculacion_conductor WHERE conductor_id = :uid");
        $stmt2->bindValue(':uid', $user['id']);
        $stmt2->execute();
        $requests = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($requests) > 0) {
            foreach ($requests as $req) {
                 echo "   Request ID: {$req['id']}, Empresa: {$req['empresa_id']}, Estado: {$req['estado']}, Creado: {$req['creado_en']}\n";
            }
        } else {
            echo "   No requests found for this user.\n";
        }
    }
}
?>
