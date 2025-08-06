<?php
session_start();

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

// Conectar ao banco
$conn = conectarBD();

// Buscar todos os dados
$entregas = $conn->query("SELECT * FROM entregas ORDER BY id");
$usuarios = $conn->query("SELECT id, login FROM usuarios ORDER BY id");

// Criar array com todos os dados
$dados = [
    'entregas' => [],
    'usuarios' => [],
    'info' => [
        'data_backup' => date('Y-m-d H:i:s'),
        'usuario' => $_SESSION['usuario_login'],
        'version' => '2.0'
    ]
];

// Preencher dados de entregas
while ($row = $entregas->fetch_assoc()) {
    $dados['entregas'][] = $row;
}

// Preencher dados de usuários (sem senhas por segurança)
while ($row = $usuarios->fetch_assoc()) {
    $dados['usuarios'][] = [
        'id' => $row['id'],
        'login' => $row['login']
    ];
}

// Definir cabeçalhos para download do backup
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename=backup_4life_' . date('Y-m-d_His') . '.json');

// Enviar dados como JSON
echo json_encode($dados, JSON_PRETTY_PRINT);
exit;
?>