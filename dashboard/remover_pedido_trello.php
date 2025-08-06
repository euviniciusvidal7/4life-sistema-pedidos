<?php
session_start();

header('Content-Type: application/json');

// Verificar se o usuário está logado
if (!isset($_SESSION['usuario']) && !isset($_SESSION['usuario_id'])) {
    // Verificar se os dados de sessão foram enviados com a requisição
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    if (isset($data['usuario_id']) && !empty($data['usuario_id'])) {
        // Usar os dados enviados para restaurar a sessão
        $_SESSION['usuario_id'] = $data['usuario_id'];
        $_SESSION['usuario'] = $data['usuario_id']; // Garantir que ambos estejam definidos
        error_log("RemoverPedidoTrello.php - Restaurando sessão com ID: " . $data['usuario_id']);
    } else {
        error_log("Acesso negado: usuário não logado e dados de sessão não fornecidos");
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Por favor, faça login.']);
        exit;
    }
}

// Verificar permissões (logística e vendas podem remover)
$nivel_acesso = $_SESSION['nivel_acesso'] ?? '';
if (!in_array($nivel_acesso, ['logistica', 'vendas', 'admin'])) {
    // Consultar o nível de acesso no banco de dados
    if (file_exists('config.php')) {
        require_once 'config.php';
        $conn = conectarBD();
        
        $usuario_id = $_SESSION['usuario_id'] ?? $_SESSION['usuario'] ?? null;
        if ($usuario_id) {
            $stmt = $conn->prepare("SELECT nivel_acesso FROM usuarios WHERE id = ?");
            $stmt->bind_param("i", $usuario_id);
            $stmt->execute();
            $resultado = $stmt->get_result();
            
            if ($resultado->num_rows > 0) {
                $usuario = $resultado->fetch_assoc();
                $nivel_acesso = $usuario['nivel_acesso'];
                $_SESSION['nivel_acesso'] = $nivel_acesso;
            }
        }
    }
    
    if (!in_array($nivel_acesso, ['logistica', 'vendas', 'admin'])) {
        error_log("Acesso negado: nível de acesso insuficiente ($nivel_acesso)");
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permissão negada para remover pedidos.']);
        exit;
    }
}

// Função para retornar resposta JSON
function json_response($data) {
    echo json_encode($data);
    exit;
}

// Obter dados da requisição - Verificar se é JSON ou form-data
$contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
if (strpos($contentType, 'application/json') !== false) {
    // Recebendo dados JSON
    $json_data = file_get_contents('php://input');
    error_log("Dados JSON recebidos: " . $json_data);
    $data = json_decode($json_data, true);
    
    if (!$data) {
        error_log("Erro ao decodificar JSON: " . json_last_error_msg());
        $data = [];
    }
} else {
    // Recebendo dados form-data
    $data = $_POST;
    error_log("Dados POST recebidos: " . json_encode($_POST));
}

// Obter dados das requisições
$id_trello = $data['id_trello'] ?? '';
$arquivo = $data['arquivo'] ?? '';

error_log("Solicitação para remover pedido: arquivo=$arquivo, id_trello=$id_trello");

if (empty($id_trello) && empty($arquivo)) {
    error_log("Erro: ID do Trello e arquivo não informados");
    json_response(['success' => false, 'error' => 'ID do Trello ou arquivo são obrigatórios.']);
}

// Verificar se $arquivo é na verdade um ID do Trello (24 caracteres hexadecimais)
if (empty($id_trello) && !empty($arquivo) && strlen($arquivo) === 24 && preg_match('/^[0-9a-f]{24}$/i', $arquivo)) {
    error_log("Arquivo identificado como ID do Trello: $arquivo");
    $id_trello = $arquivo;
}

// Incluir configurações
if (file_exists('config.php')) {
    require_once 'config.php';
}

// Configurações do Trello - verificar se constantes estão definidas
if (!defined('TRELLO_API_KEY')) {
    define('TRELLO_API_KEY', getenv('TRELLO_API_KEY') ?: 'YOUR_API_KEY');
}
if (!defined('TRELLO_TOKEN')) {
    define('TRELLO_TOKEN', getenv('TRELLO_TOKEN') ?: 'YOUR_TOKEN');
}

// Verificar se temos credenciais válidas para o Trello
if (empty(TRELLO_API_KEY) || TRELLO_API_KEY == 'YOUR_API_KEY' || 
    empty(TRELLO_TOKEN) || TRELLO_TOKEN == 'YOUR_TOKEN') {
    error_log("Erro: credenciais do Trello não configuradas");
    json_response(['success' => false, 'error' => 'Credenciais do Trello não configuradas.']);
}

// Se temos um ID do Trello, tentar remover do Trello
if (!empty($id_trello)) {
    error_log("Removendo card do Trello com ID: $id_trello");
    $url = "https://api.trello.com/1/cards/" . $id_trello . "?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        error_log("Erro ao remover card do Trello: $error");
        curl_close($ch);
        json_response(['success' => false, 'error' => 'Erro ao remover card do Trello: ' . $error]);
    }
    
    curl_close($ch);
    
    if ($http_code == 200 || $http_code == 204) {
        error_log("Card removido com sucesso do Trello (HTTP code: $http_code)");
        
        // Se o arquivo estava vazio ou era igual ao ID do Trello, podemos retornar sucesso aqui
        // sem tentar remover um arquivo JSON
        if (empty($arquivo) || $arquivo == $id_trello) {
            error_log("Processo de remoção concluído com sucesso (apenas card do Trello)");
            json_response(['success' => true, 'message' => 'Pedido removido com sucesso do Trello.']);
        }
    } else {
        // Se o código HTTP não for 200 ou 204, verificamos se é 404 (Not Found), o que pode indicar
        // que o card já foi removido
        if ($http_code == 404) {
            error_log("Card não encontrado no Trello (404). Pode ter sido removido anteriormente.");
            // Se também não temos um arquivo para remover, não há muito mais a fazer
            if (empty($arquivo) || $arquivo == $id_trello) {
                json_response(['success' => true, 'message' => 'Card do Trello não encontrado. Pode ter sido removido anteriormente.']);
            }
            // Caso contrário, continua para tentar remover o arquivo local
        } else {
            error_log("Falha ao remover card do Trello. HTTP code: $http_code, Resposta: $response");
            json_response(['success' => false, 'error' => 'Falha ao remover card do Trello. Código HTTP: ' . $http_code]);
        }
    }
}

// Se temos um arquivo, remover o arquivo físico e referência no índice
if (!empty($arquivo)) {
    error_log("Removendo arquivo de pedido: $arquivo");
    
    // Definir caminho para a pasta de pedidos
    $pedidos_path = '../2025/pedidos'; // Caminho relativo
    $index_path = $pedidos_path . '/index.json'; // Caminho para o arquivo de índice
    $absolute_path = $_SERVER['DOCUMENT_ROOT'] . '/2025/pedidos'; // Caminho absoluto
    
    // Verificar se os diretórios existem
    if (!is_dir($pedidos_path) && !is_dir($absolute_path)) {
        error_log("Diretórios de pedidos não encontrados: $pedidos_path e $absolute_path");
        json_response(['success' => false, 'error' => 'Diretório de pedidos não encontrado']);
    }
    
    // Usar o caminho que existir
    $local_path = is_dir($pedidos_path) ? $pedidos_path : $absolute_path;
    error_log("Usando diretório de pedidos: $local_path");
    
    // Tentar vários caminhos possíveis para o arquivo
    $caminhos_possiveis = [
        $local_path . '/' . $arquivo,
        $_SERVER['DOCUMENT_ROOT'] . '/2025/pedidos/' . $arquivo,
        dirname($_SERVER['DOCUMENT_ROOT']) . '/2025/pedidos/' . $arquivo,
        '../../2025/pedidos/' . $arquivo
    ];
    
    $arquivo_path = null;
    foreach ($caminhos_possiveis as $caminho) {
        error_log("Verificando caminho: $caminho");
        if (file_exists($caminho)) {
            $arquivo_path = $caminho;
            error_log("Arquivo encontrado em: $caminho");
            break;
        }
    }
    
    if ($arquivo_path === null) {
        error_log("Aviso: arquivo não encontrado em nenhum dos caminhos tentados");
    } else {
        // Remover o arquivo
        if (unlink($arquivo_path)) {
            error_log("Arquivo removido com sucesso: $arquivo_path");
        } else {
            error_log("Falha ao remover arquivo: $arquivo_path");
            json_response(['success' => false, 'error' => 'Falha ao remover arquivo do pedido.']);
        }
    }
    
    // Atualizar o índice (remover o pedido)
    $index_path = $local_path . '/index.json';
    
    if (file_exists($index_path)) {
        error_log("Atualizando índice: $index_path");
        
        try {
            $index_json = file_get_contents($index_path);
            $index = json_decode($index_json, true);
            
            if (!$index) {
                error_log("Erro ao decodificar o arquivo de índice: " . json_last_error_msg());
                throw new Exception('Erro ao decodificar o arquivo de índice: ' . json_last_error_msg());
            }
            
            // Filtrar o índice para remover o pedido
            $index = array_filter($index, function($pedido) use ($arquivo) {
                return (!isset($pedido['arquivo']) || $pedido['arquivo'] != $arquivo);
            });
            
            // Reindexar array
            $index = array_values($index);
            
            // Salvar o índice atualizado
            if (file_put_contents($index_path, json_encode($index, JSON_PRETTY_PRINT))) {
                error_log("Índice atualizado com sucesso");
            } else {
                error_log("Falha ao atualizar o arquivo de índice");
                json_response(['success' => false, 'error' => 'Falha ao atualizar o arquivo de índice.']);
            }
        } catch (Exception $e) {
            error_log("Erro ao atualizar índice: " . $e->getMessage());
            json_response(['success' => false, 'error' => 'Erro ao atualizar índice: ' . $e->getMessage()]);
        }
    } else {
        error_log("Aviso: arquivo de índice não encontrado: $index_path");
    }
}

// Retornar sucesso se chegou até aqui
error_log("Processo de remoção concluído com sucesso");
json_response(['success' => true, 'message' => 'Pedido removido com sucesso.']); 