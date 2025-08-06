<?php
session_start();

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

require_once 'config.php';

// Verificar se ID foi fornecido
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'ID inválido']);
    exit;
}

$id = (int)$_GET['id'];

// Conectar ao banco
$conn = conectarBD();

try {
    // Buscar detalhes da entrega no banco local primeiro
    $stmt = $conn->prepare("SELECT * FROM entregas WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception('Entrega não encontrada no banco de dados');
    }

    $entrega = $result->fetch_assoc();
    
    // Verificar se temos um ID Trello válido
    if (empty($entrega['id_trello'])) {
        // Se não temos ID Trello, retornamos apenas os dados locais
        header('Content-Type: application/json');
        echo json_encode($entrega);
        exit;
    }
    
    // Se temos um ID Trello, tentamos buscar informações atualizadas
    try {
        $card_url = "https://api.trello.com/1/cards/" . $entrega['id_trello'] . "?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
        $context = stream_context_create([
            'http' => [
                'timeout' => 5, // Timeout de 5 segundos
            ]
        ]);
        $card_response = @file_get_contents($card_url, false, $context);
        
        if ($card_response === false) {
            // Se falhar, ainda retornamos os dados locais
            header('Content-Type: application/json');
            echo json_encode($entrega);
            exit;
        }
        
        $card_data = json_decode($card_response, true);
        
        // Se temos dados do card, atualizamos nossa resposta
        if ($card_data) {
            // Pegar nome da lista (status)
            $list_url = "https://api.trello.com/1/lists/" . $card_data['idList'] . "?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
            $list_response = @file_get_contents($list_url, false, $context);
            
            if ($list_response !== false) {
                $list_data = json_decode($list_response, true);
                if ($list_data) {
                    // Atualizar status na resposta
                    $entrega['status'] = $list_data['name'];
                    
                    // Também atualizar no banco para futura referência
                    $update = $conn->prepare("UPDATE entregas SET status = ? WHERE id = ?");
                    $update->bind_param("si", $list_data['name'], $id);
                    $update->execute();
                }
            }
            
            // Adicionar descrição do card à resposta
            $entrega['descricao_trello'] = $card_data['desc'] ?? '';
            
            // Adicionar URL do card
            $entrega['card_url'] = 'https://trello.com/c/' . $entrega['id_trello'];
        }
    } catch (Exception $e) {
        // Se ocorrer erro na API do Trello, ainda retornamos os dados locais
        // mas registramos o erro para diagnóstico
        error_log("Erro ao acessar API Trello: " . $e->getMessage());
    }
    
    // Retornar detalhes como JSON
    header('Content-Type: application/json');
    echo json_encode($entrega);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
?>