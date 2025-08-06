<?php
session_start();

// Verificar se está logado (segurança)
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

// Incluir configurações
require_once 'config.php';

// Conectar ao banco
$conn = conectarBD();

// Registrar início
$inicio = microtime(true);
$log = [];
$log[] = "Iniciando atualização: " . date('Y-m-d H:i:s');

try {
    // Buscar dados do quadro no Trello
    $url = "https://api.trello.com/1/boards/" . TRELLO_BOARD_ID . "/cards?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
    $response = file_get_contents($url);
    
    if (!$response) {
        throw new Exception("Erro ao acessar a API do Trello");
    }
    
    $cards = json_decode($response, true);
    if (!$cards) {
        throw new Exception("Erro ao processar dados do Trello");
    }
    
    $log[] = "Encontrados " . count($cards) . " cartões no Trello";
    
    // Buscar todas as listas do quadro para mapear status
    $lists_url = "https://api.trello.com/1/boards/" . TRELLO_BOARD_ID . "/lists?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
    $lists_response = file_get_contents($lists_url);
    $lists = json_decode($lists_response, true);
    
    // Criar mapa de ID da lista -> Nome da lista
    $list_map = [];
    foreach ($lists as $list) {
        $list_map[$list['id']] = $list['name'];
    }
    
    $log[] = "Encontradas " . count($lists) . " listas no quadro";
    
    // Processar cada cartão
    $contador = 0;
    foreach ($cards as $card) {
        // Extrair dados básicos
        $id_trello = $card['id'];
        $nome = $card['name'];
        $list_id = $card['idList'];
        $status = $list_map[$list_id] ?? 'Desconhecido';
        
        // Extrair tracking do nome (assumindo padrão RU...PT)
        preg_match('/RU[0-9]+PT/', $nome, $matches);
        $tracking = $matches[0] ?? '';
        
        // Extrair dados da descrição do card
        $descricao = $card['desc'] ?? '';
        
        // Extrair destino das labels
        $destino = '';
        if (isset($card['labels']) && !empty($card['labels'])) {
            foreach ($card['labels'] as $label) {
                if (!empty($label['name']) && !in_array($label['name'], ['Green', 'Yellow', 'Red'])) {
                    $destino = $label['name'];
                    break;
                }
            }
        }
        
        // Verificar se cartão já existe
        $check = $conn->prepare("SELECT id FROM entregas WHERE id_trello = ?");
        $check->bind_param("s", $id_trello);
        $check->execute();
        $result = $check->get_result();
        
        if ($result->num_rows > 0) {
            // Atualizar cartão existente
            $row = $result->fetch_assoc();
            $sql = "UPDATE entregas SET 
                    cliente = ?, 
                    tracking = ?, 
                    destino = ?,
                    status = ?, 
                    ultima_atualizacao = NOW() 
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssi", $nome, $tracking, $destino, $status, $row['id']);
            $stmt->execute();
        } else {
            // Inserir novo cartão
            $sql = "INSERT INTO entregas 
                    (id_trello, cliente, tracking, destino, status, data_criacao, ultima_atualizacao) 
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssss", $id_trello, $nome, $tracking, $destino, $status);
            $stmt->execute();
        }
        
        $contador++;
    }
    
    $tempo_total = round(microtime(true) - $inicio, 2);
    $log[] = "Atualização concluída: $contador cartões processados em $tempo_total segundos";
    $log[] = "Data: " . date('d/m/Y H:i:s');
    
    // Mensagem de sucesso
    $mensagem = "Atualização concluída com sucesso! $contador cartões processados.";
    $tipo = "success";
    
} catch (Exception $e) {
    $log[] = "ERRO: " . $e->getMessage();
    $mensagem = "Erro na atualização: " . $e->getMessage();
    $tipo = "error";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>4Life Nutri - Atualização</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #568C1C;
            --secondary-color: #2C3E50;
            --bg-light: #f8f9fa;
        }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-light); 
            margin: 0; 
            padding: 0; 
            color: #333;
        }
        
        .sidebar {
            background-color: var(--secondary-color);
            color: white;
            width: 250px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1000;
            transition: all 0.3s;
        }
        
        .sidebar .logo {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar .nav-item {
            padding: 10px 20px;
            display: flex;
            align-items: center;
            transition: background-color 0.3s;
            color: white;
            text-decoration: none;
        }
        
        .sidebar .nav-item:hover {
            background-color: rgba(255,255,255,0.1);
        }
        
        .sidebar .nav-item i {
            margin-right: 15px;
        }
        
        .content-wrapper {
            margin-left: 250px;
            padding: 20px;
        }
        
        .header {
            background-color: white;
            padding: 15px 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .card {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .log {
            background-color: #f5f5f5;
            padding: 15px;
            border-radius: 4px;
            white-space: pre-line;
            font-family: monospace;
            margin-top: 20px;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            text-decoration: none;
            display: inline-block;
            margin-top: 20px;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
            }
            
            .sidebar .logo span, .sidebar .nav-item span {
                display: none;
            }
            
            .content-wrapper {
                margin-left: 70px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">
            <h4>4Life Nutri</h4>
            <span>Logística</span>
        </div>
        <nav>
            <a href="dashboard.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
            </a>
            <a href="entregas.php" class="nav-item">
                <i class="fas fa-box"></i> <span>Entregas</span>
            </a>
            <a href="metricas.php" class="nav-item">
                <i class="fas fa-chart-line"></i> <span>Métricas</span>
            </a>
            <a href="configuracoes.php" class="nav-item">
                <i class="fas fa-cog"></i> <span>Configurações</span>
            </a>
            <a href="logout.php" class="nav-item">
                <i class="fas fa-sign-out-alt"></i> <span>Sair</span>
            </a>
        </nav>
    </div>
    
    <div class="content-wrapper">
        <div class="header">
            <h4>Atualização de Dados</h4>
        </div>
        
        <div class="container">
            <div class="card">
                <h2>Atualização de Dados do Trello</h2>
                
                <div class="alert alert-<?= $tipo ?>">
                    <?= $mensagem ?>
                </div>
                
                <div class="log">
                    <?= implode("\n", $log) ?>
                </div>
                
                <a href="dashboard.php" class="btn">Voltar para o Dashboard</a>
            </div>
        </div>
    </div>
</body>
</html>