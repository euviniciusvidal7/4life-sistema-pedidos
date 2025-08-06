<?php
// Iniciar sessão
session_start();

// Configurar cabeçalhos
header('Content-Type: application/json');

// Função para verificar se uma string termina com outra (compatível com PHP < 8.0)
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }
        return (substr($haystack, -$length) === $needle);
    }
}

// Log para debug
error_log("RemoverPedido.php - Sessão atual: " . json_encode($_SESSION));

// Verificar se o usuário está logado
if (!isset($_SESSION['usuario']) && !isset($_SESSION['usuario_id'])) {
    // Verificar se os dados de sessão foram enviados com a requisição
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    if (isset($data['usuario_id']) && !empty($data['usuario_id'])) {
        // Usar os dados enviados para restaurar a sessão
        $_SESSION['usuario_id'] = $data['usuario_id'];
        $_SESSION['usuario'] = $data['usuario_id'];
        error_log("RemoverPedido.php - Restaurando sessão com ID: " . $data['usuario_id']);
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

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Método inválido: " . $_SERVER['REQUEST_METHOD']);
    json_response(['success' => false, 'error' => 'Método não permitido.']);
}

// Obter dados do POST - Verificar se é JSON ou form-data
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
$arquivo = $data['arquivo'] ?? '';
$id_trello = $data['id_trello'] ?? '';
$motivo = $data['motivo'] ?? '';
$pedido_id = $data['pedido_id'] ?? '';

error_log("Solicitação para remover pedido: arquivo=$arquivo, id_trello=$id_trello, pedido_id=$pedido_id, motivo=$motivo");

if (empty($arquivo) && empty($id_trello) && empty($pedido_id)) {
    error_log("Erro: arquivo, ID do Trello ou ID do pedido não informados");
    json_response(['success' => false, 'error' => 'Arquivo, ID do Trello ou ID do pedido são obrigatórios.']);
}

// Definir caminho para a pasta de pedidos
$pedidos_path = '../2025/pedidos'; // Caminho relativo
$index_path = $pedidos_path . '/index.json'; // Caminho para o arquivo de índice
$absolute_path = $_SERVER['DOCUMENT_ROOT'] . '/2025/pedidos'; // Caminho absoluto

// Verificar se os diretórios existem
if (!is_dir($pedidos_path) && !is_dir($absolute_path)) {
    error_log("Diretórios de pedidos não encontrados: $pedidos_path e $absolute_path");
    
    // Tentar caminhos alternativos
    $alternative_paths = [
        dirname($_SERVER['DOCUMENT_ROOT']) . '/2025/pedidos',
        '../../2025/pedidos'
    ];
    
    $found = false;
    foreach ($alternative_paths as $path) {
        error_log("Tentando caminho alternativo: $path");
        if (is_dir($path)) {
            $local_path = $path;
            $found = true;
            error_log("Caminho alternativo encontrado: $path");
            break;
        }
    }
    
    if (!$found) {
        error_log("Nenhum diretório de pedidos encontrado após tentar caminhos alternativos");
        json_response(['success' => false, 'error' => 'Diretório de pedidos não encontrado']);
    }
} else {
    // Usar o caminho que existir
    $local_path = is_dir($pedidos_path) ? $pedidos_path : $absolute_path;
}

error_log("Usando diretório de pedidos: $local_path");

// Remover card do Trello se tiver ID
if (!empty($id_trello)) {
    error_log("Removendo card do Trello com ID: $id_trello");
    
    // Podemos chamar o script remover_pedido_trello.php usando curl ou file_get_contents
    $post_data = http_build_query([
        'id_trello' => $id_trello,
        'arquivo' => $arquivo
    ]);
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $post_data
        ]
    ];
    
    $context = stream_context_create($options);
    
    try {
        $response = file_get_contents('remover_pedido_trello.php', false, $context);
        
        if ($response === false) {
            error_log("Erro ao chamar remover_pedido_trello.php");
            // Continuar com a remoção do arquivo mesmo se a chamada ao Trello falhar
        } else {
            $result = json_decode($response, true);
            if (!$result || $result['success'] === false) {
                error_log("Erro ao remover card do Trello: " . ($result['error'] ?? 'Erro desconhecido'));
                // Continuar com a remoção do arquivo mesmo se a remoção do Trello falhar
            } else {
                error_log("Card removido com sucesso do Trello");
            }
        }
    } catch (Exception $e) {
        error_log("Exceção ao chamar remover_pedido_trello.php: " . $e->getMessage());
        // Continuar com a remoção do arquivo mesmo se houver uma exceção
    }
}

// Se temos um arquivo, remover o arquivo físico
if (!empty($arquivo)) {
    error_log("Removendo arquivo de pedido: $arquivo");
    
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
        
        // Verificar se o arquivo pode estar sem extensão .json
        if (!str_ends_with($arquivo, '.json')) {
            $arquivo_com_extensao = $arquivo . '.json';
            error_log("Tentando com extensão .json: $arquivo_com_extensao");
            
            foreach ($caminhos_possiveis as $caminho) {
                $caminho_com_extensao = str_replace($arquivo, $arquivo_com_extensao, $caminho);
                error_log("Verificando caminho com extensão: $caminho_com_extensao");
                if (file_exists($caminho_com_extensao)) {
                    $arquivo_path = $caminho_com_extensao;
                    $arquivo = $arquivo_com_extensao; // Atualizar o nome do arquivo para uso posterior
                    error_log("Arquivo encontrado com extensão: $caminho_com_extensao");
                    break;
                }
            }
        }
    }
    
    if ($arquivo_path === null) {
        error_log("Erro: arquivo não encontrado mesmo após tentativas adicionais");
        // Continuar mesmo sem encontrar o arquivo para atualizar o índice
    } else {
        // Verificar se é um arquivo JSON antes de tentar remover
        if (pathinfo($arquivo_path, PATHINFO_EXTENSION) == 'json') {
            // Remover o arquivo
            if (unlink($arquivo_path)) {
                error_log("Arquivo removido com sucesso: $arquivo_path");
            } else {
                error_log("Falha ao remover arquivo: $arquivo_path - " . error_get_last()['message']);
                json_response(['success' => false, 'error' => 'Falha ao remover arquivo do pedido: ' . error_get_last()['message']]);
            }
        } else {
            error_log("Aviso: não é um arquivo JSON: $arquivo_path");
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
            
            // Log da estrutura do índice para debugar
            $indice_info = [];
            foreach ($index as $key => $pedido) {
                $arquivo_info = isset($pedido['arquivo']) ? $pedido['arquivo'] : 'não definido';
                $pedido_id_info = isset($pedido['pedido_id']) ? $pedido['pedido_id'] : 'não definido';
                $indice_info[] = "[$key] => " . (is_array($pedido) ? json_encode($pedido) : $pedido) . " (arquivo: $arquivo_info, pedido_id: $pedido_id_info)";
            }
            error_log("Estrutura do índice antes da remoção: " . implode(" | ", $indice_info));
            error_log("Arquivo a ser removido: $arquivo, pedido_id: $pedido_id");
            
            // Filtrar o índice para remover o pedido
            $index = array_filter($index, function($pedido, $key) use ($arquivo, $pedido_id) {
                // Verificar se o pedido_id corresponde (prioridade máxima)
                if (!empty($pedido_id) && isset($pedido['pedido_id']) && $pedido['pedido_id'] === $pedido_id) {
                    error_log("Removendo pelo pedido_id: {$pedido['pedido_id']} == $pedido_id");
                    return false; // Remover este pedido
                }
                
                // Verificar se o nome do arquivo corresponde diretamente
                if (isset($pedido['arquivo']) && $pedido['arquivo'] == $arquivo) {
                    error_log("Removendo pelo arquivo: {$pedido['arquivo']} == $arquivo");
                    return false; // Remover este pedido
                }
                
                // Verificar se o nome do arquivo sem extensão corresponde à chave
                $arquivo_sem_extensao = pathinfo($arquivo, PATHINFO_FILENAME);
                if ($key === $arquivo_sem_extensao || $key . '.json' === $arquivo) {
                    error_log("Removendo pela chave: $key == $arquivo_sem_extensao ou $key.json == $arquivo");
                    return false; // Remover este pedido
                }
                
                return true; // Manter este pedido
            }, ARRAY_FILTER_USE_BOTH);
            
            // Verificar o que foi removido
            $count_antes = count(json_decode($index_json, true));
            $count_depois = count($index);
            error_log("Contagem de pedidos - Antes: $count_antes, Depois: $count_depois");
            
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

// Se temos o pedido_id mas não o arquivo, tentar remover apenas do index.json
if (!empty($pedido_id) && empty($arquivo)) {
    error_log("Tentando remover pedido apenas pelo pedido_id: $pedido_id");
    
    $index_path = $local_path . '/index.json';
    
    if (file_exists($index_path)) {
        try {
            $index_json = file_get_contents($index_path);
            $index = json_decode($index_json, true);
            
            if (!$index) {
                error_log("Erro ao decodificar o arquivo de índice: " . json_last_error_msg());
                throw new Exception('Erro ao decodificar o arquivo de índice: ' . json_last_error_msg());
            }
            
            // Filtrar o índice para remover o pedido pelo pedido_id
            $index_original = $index; // Manter uma cópia para comparação
            $index = array_filter($index, function($pedido) use ($pedido_id) {
                if (isset($pedido['pedido_id']) && $pedido['pedido_id'] === $pedido_id) {
                    error_log("Removendo do índice pelo pedido_id: {$pedido['pedido_id']}");
                    return false; // Remover este pedido
                }
                return true; // Manter este pedido
            });
            
            // Verificar se algo foi removido
            $removido = count($index_original) !== count($index);
            
            if ($removido) {
                // Reindexar array
                $index = array_values($index);
                
                // Salvar o índice atualizado
                if (file_put_contents($index_path, json_encode($index, JSON_PRETTY_PRINT))) {
                    error_log("Pedido removido do índice com sucesso pelo pedido_id: $pedido_id");
                } else {
                    error_log("Falha ao atualizar o arquivo de índice após remoção por pedido_id");
                    json_response(['success' => false, 'error' => 'Falha ao atualizar o arquivo de índice.']);
                }
            } else {
                error_log("Aviso: nenhum pedido com pedido_id=$pedido_id encontrado no índice");
            }
        } catch (Exception $e) {
            error_log("Erro ao processar remoção por pedido_id: " . $e->getMessage());
            json_response(['success' => false, 'error' => 'Erro ao processar remoção por pedido_id: ' . $e->getMessage()]);
        }
    } else {
        error_log("Aviso: arquivo de índice não encontrado para remoção por pedido_id: $index_path");
    }
}

// Registrar o motivo da remoção se fornecido
if (!empty($motivo)) {
    $usuario = $_SESSION['usuario'] ?? 'Sistema';
    $data_hora = date('Y-m-d H:i:s');
    $log_entry = [
        'data_hora' => $data_hora,
        'usuario' => $usuario,
        'acao' => 'remocao',
        'arquivo' => $arquivo,
        'id_trello' => $id_trello,
        'pedido_id' => $pedido_id,
        'motivo' => $motivo
    ];
    
    $log_path = $local_path . '/remocoes_log.json';
    
    error_log("Registrando motivo da remoção no log: $log_path");
    
    $log_entries = [];
    if (file_exists($log_path)) {
        $log_json = file_get_contents($log_path);
        $log_entries = json_decode($log_json, true) ?: [];
    }
    
    $log_entries[] = $log_entry;
    
    file_put_contents($log_path, json_encode($log_entries, JSON_PRETTY_PRINT));
}

// Retornar sucesso se chegou até aqui
error_log("Processo de remoção concluído com sucesso");
json_response(['success' => true, 'message' => 'Pedido removido com sucesso.']);
?> 