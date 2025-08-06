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

// Obter dados da requisição
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'criar_evento':
            criarEventoIA($input, $conn);
            break;
        case 'editar_evento':
            editarEventoIA($input, $conn);
            break;
        case 'deletar_evento':
            deletarEventoIA($input, $conn);
            break;
        case 'listar_eventos':
            listarEventosIA($input, $conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Ação não reconhecida']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
}

function criarEventoIA($data, $conn) {
    $usuario_id = $_SESSION['usuario_id'];
    
    // Validar dados obrigatórios
    if (empty($data['titulo']) || empty($data['data_inicio'])) {
        echo json_encode(['success' => false, 'message' => 'Título e data de início são obrigatórios']);
        return;
    }
    
    $titulo = trim($data['titulo']);
    $descricao = trim($data['descricao'] ?? '');
    $data_inicio = $data['data_inicio'];
    $data_fim = !empty($data['data_fim']) ? $data['data_fim'] : null;
    $cor = $data['cor'] ?? '#568C1C';
    
    // Validar e converter formato da data se necessário
    $data_inicio = converterDataParaMySQL($data_inicio);
    if ($data_fim) {
        $data_fim = converterDataParaMySQL($data_fim);
    }
    
    if (!$data_inicio) {
        echo json_encode(['success' => false, 'message' => 'Formato de data de início inválido']);
        return;
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

function editarEventoIA($data, $conn) {
    $usuario_id = $_SESSION['usuario_id'];
    
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        echo json_encode(['success' => false, 'message' => 'ID do evento inválido']);
        return;
    }
    
    $evento_id = (int)$data['id'];
    
    // Verificar se o evento existe
    $sql_check = "SELECT id FROM eventos_calendario WHERE id = ?";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("i", $evento_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Evento não encontrado']);
        return;
    }
    
    // Preparar campos para atualização
    $campos = [];
    $valores = [];
    $tipos = '';
    
    if (isset($data['titulo']) && !empty($data['titulo'])) {
        $campos[] = "titulo = ?";
        $valores[] = trim($data['titulo']);
        $tipos .= 's';
    }
    
    if (isset($data['descricao'])) {
        $campos[] = "descricao = ?";
        $valores[] = trim($data['descricao']);
        $tipos .= 's';
    }
    
    if (isset($data['data_inicio']) && !empty($data['data_inicio'])) {
        $data_inicio = converterDataParaMySQL($data['data_inicio']);
        if ($data_inicio) {
            $campos[] = "data_inicio = ?";
            $valores[] = $data_inicio;
            $tipos .= 's';
        }
    }
    
    if (isset($data['data_fim'])) {
        if (!empty($data['data_fim'])) {
            $data_fim = converterDataParaMySQL($data['data_fim']);
            if ($data_fim) {
                $campos[] = "data_fim = ?";
                $valores[] = $data_fim;
                $tipos .= 's';
            }
        } else {
            $campos[] = "data_fim = NULL";
        }
    }
    
    if (isset($data['cor']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $data['cor'])) {
        $campos[] = "cor = ?";
        $valores[] = $data['cor'];
        $tipos .= 's';
    }
    
    if (empty($campos)) {
        echo json_encode(['success' => false, 'message' => 'Nenhum campo válido para atualizar']);
        return;
    }
    
    // Adicionar campo de atualização
    $campos[] = "atualizado_em = CURRENT_TIMESTAMP";
    
    // Adicionar ID no final
    $valores[] = $evento_id;
    $tipos .= 'i';
    
    $sql = "UPDATE eventos_calendario SET " . implode(", ", $campos) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($tipos, ...$valores);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Evento atualizado com sucesso']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro ao atualizar evento: ' . $stmt->error]);
    }
    
    $stmt->close();
    $stmt_check->close();
}

function deletarEventoIA($data, $conn) {
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        echo json_encode(['success' => false, 'message' => 'ID do evento inválido']);
        return;
    }
    
    $evento_id = (int)$data['id'];
    
    // Verificar se o evento existe
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

function listarEventosIA($data, $conn) {
    $limite = isset($data['limite']) ? (int)$data['limite'] : 20;
    $data_inicio = $data['data_inicio'] ?? null;
    $data_fim = $data['data_fim'] ?? null;
    
    $sql = "SELECT * FROM eventos_calendario WHERE 1=1";
    $params = [];
    $tipos = '';
    
    if ($data_inicio) {
        $data_inicio = converterDataParaMySQL($data_inicio);
        if ($data_inicio) {
            $sql .= " AND data_inicio >= ?";
            $params[] = $data_inicio;
            $tipos .= 's';
        }
    }
    
    if ($data_fim) {
        $data_fim = converterDataParaMySQL($data_fim);
        if ($data_fim) {
            $sql .= " AND data_inicio <= ?";
            $params[] = $data_fim;
            $tipos .= 's';
        }
    }
    
    $sql .= " ORDER BY data_inicio ASC LIMIT ?";
    $params[] = $limite;
    $tipos .= 'i';
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $eventos = [];
    while ($row = $result->fetch_assoc()) {
        $eventos[] = [
            'id' => $row['id'],
            'titulo' => $row['titulo'],
            'descricao' => $row['descricao'],
            'data_inicio' => $row['data_inicio'],
            'data_fim' => $row['data_fim'],
            'cor' => $row['cor'],
            'criado_em' => $row['criado_em'],
            'atualizado_em' => $row['atualizado_em']
        ];
    }
    
    echo json_encode(['success' => true, 'eventos' => $eventos]);
    $stmt->close();
}

function converterDataParaMySQL($data) {
    // Tentar diferentes formatos de data
    $formatos = [
        'Y-m-d H:i:s',
        'Y-m-d\TH:i',
        'Y-m-d H:i',
        'Y-m-d',
        'd/m/Y H:i',
        'd/m/Y',
        'd-m-Y H:i',
        'd-m-Y'
    ];
    
    foreach ($formatos as $formato) {
        $date_obj = DateTime::createFromFormat($formato, $data);
        if ($date_obj !== false) {
            return $date_obj->format('Y-m-d H:i:s');
        }
    }
    
    // Tentar strtotime como último recurso
    $timestamp = strtotime($data);
    if ($timestamp !== false) {
        return date('Y-m-d H:i:s', $timestamp);
    }
    
    return false;
}

// Fechar conexão
$conn->close();
?> 