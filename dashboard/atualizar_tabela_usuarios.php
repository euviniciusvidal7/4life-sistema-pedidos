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

// Verificar se a coluna já existe
$checkColumn = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'nivel_acesso'");
$columnExists = $checkColumn->num_rows > 0;

$mensagem = "";

if (!$columnExists) {
    // Adicionar a coluna nivel_acesso à tabela usuarios
    $alterTable = $conn->query("ALTER TABLE usuarios ADD COLUMN nivel_acesso VARCHAR(20) DEFAULT 'logistica'");
    
    if ($alterTable) {
        $mensagem = "Coluna 'nivel_acesso' adicionada com sucesso à tabela 'usuarios'.";
    } else {
        $mensagem = "Erro ao adicionar coluna: " . $conn->error;
    }
} else {
    $mensagem = "A coluna 'nivel_acesso' já existe na tabela 'usuarios'.";
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atualização da Tabela de Usuários</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header">
                        <h4>Atualização da Tabela de Usuários</h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <?php echo $mensagem; ?>
                        </div>
                        
                        <p>Este script atualizou a estrutura da tabela de usuários para suportar níveis de acesso.</p>
                        <p>Os níveis de acesso disponíveis são:</p>
                        <ul>
                            <li><strong>logistica</strong> - Acesso total ao sistema</li>
                            <li><strong>ltv</strong> - Acesso a Clientes Entregues, Devoluções e Disponíveis para Levantamento</li>
                            <li><strong>vendas</strong> - Acesso a Novos Clientes, Devoluções e Entregas</li>
                            <li><strong>recuperacao</strong> - Acesso a Clientes que não completaram (em implementação)</li>
                        </ul>
                        
                        <div class="mt-4">
                            <a href="configuracoes.php" class="btn btn-primary">Voltar para Configurações</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 