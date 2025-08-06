<?php
session_start();
header('Content-Type: application/json');

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado']);
    exit;
}

// Incluir configurações
require_once 'config.php';

// Conectar ao banco
$conn = conectarBD();

// Obter a ação solicitada
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'listar':
            listarEventos($conn);
            break;
        case 'criar':
            criarEvento($conn);
            break;
        case 'deletar':
            deletarEvento($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Ação não especificada']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
}

function listarEventos($conn) {
    $usuario_id = $_SESSION['usuario_id'];
    
    $sql = "SELECT * FROM eventos_calendario ORDER BY data_inicio ASC";
    $result = $conn->query($sql);
    
    $eventos = [];
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $eventos[] = $row;
        }
    }
    
    echo json_encode(['success' => true, 'eventos' => $eventos]);
}

function criarEvento($conn) {
    $usuario_id = $_SESSION['usuario_id'];
    
    // Validar dados obrigatórios
    if (empty($_POST['titulo']) || empty($_POST['dataInicio'])) {
        echo json_encode(['success' => false, 'message' => 'Título e data de início são obrigatórios']);
        return;
    }
    
    $titulo = trim($_POST['titulo']);
    $descricao = trim($_POST['descricao'] ?? '');
    $data_inicio = $_POST['dataInicio'];
    $data_fim = !empty($_POST['dataFim']) ? $_POST['dataFim'] : null;
    $cor = $_POST['cor'] ?? '#568C1C';
    
    // Validar formato da data
    $data_inicio_obj = DateTime::createFromFormat('Y-m-d\TH:i', $data_inicio);
    if (!$data_inicio_obj) {
        echo json_encode(['success' => false, 'message' => 'Formato de data de início inválido']);
        return;
    }
    
    // Validar data fim se fornecida
    if ($data_fim) {
        $data_fim_obj = DateTime::createFromFormat('Y-m-d\TH:i', $data_fim);
        if (!$data_fim_obj) {
            echo json_encode(['success' => false, 'message' => 'Formato de data de fim inválido']);
            return;
        }
        
        // Verificar se data fim é posterior à data início
        if ($data_fim_obj <= $data_inicio_obj) {
            echo json_encode(['success' => false, 'message' => 'Data de fim deve ser posterior à data de início']);
            return;
        }
    }
    
    // Validar cor (formato hexadecimal)
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $cor)) {
        $cor = '#568C1C'; // Cor padrão se inválida
    }
    
    // Inserir evento no banco
    $sql = "INSERT INTO eventos_calendario (titulo, descricao, data_inicio, data_fim, cor, usuario_id) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Erro ao preparar consulta: ' . $conn->error]);
        return;
    }
    
    $stmt->bind_param("sssssi", $titulo, $descricao, $data_inicio, $data_fim, $cor, $usuario_id);
    
    if ($stmt->execute()) {
        $evento_id = $conn->insert_id;
        echo json_encode([
            'success' => true, 
            'message' => 'Evento criado com sucesso',
            'evento_id' => $evento_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro ao salvar evento: ' . $stmt->error]);
    }
    
    $stmt->close();
}

function deletarEvento($conn) {
    $usuario_id = $_SESSION['usuario_id'];
    
    // Obter dados JSON
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['id']) || !is_numeric($input['id'])) {
        echo json_encode(['success' => false, 'message' => 'ID do evento inválido']);
        return;
    }
    
    $evento_id = (int)$input['id'];
    
    // Verificar se o evento existe e pertence ao usuário (ou permitir para todos os usuários)
    $sql_check = "SELECT id FROM eventos_calendario WHERE id = ?";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("i", $evento_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Evento não encontrado']);
        return;
    }
    
    // Deletar evento
    $sql = "DELETE FROM eventos_calendario WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Erro ao preparar consulta: ' . $conn->error]);
        return;
    }
    
    $stmt->bind_param("i", $evento_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'Evento deletado com sucesso']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Nenhum evento foi deletado']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro ao deletar evento: ' . $stmt->error]);
    }
    
    $stmt->close();
    $stmt_check->close();
}

// Fechar conexão
$conn->close();
?> 