<?php
// Garantir que nenhuma saída seja enviada antes dos cabeçalhos
ob_start();
session_start();

// Definir cabeçalhos para evitar problemas de CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Desabilitar qualquer buffer de saída que possa ter sido criado
if (ob_get_length()) ob_clean();

// Função para responder com JSON e sair
function json_response($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Tratar requisições OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    json_response(['success' => false, 'error' => 'Não autorizado']);
}

// Incluir configurações
require_once 'config.php';

// Definir caminho para a pasta de pedidos
$pedidos_path = '../2025/pedidos'; // Caminho relativo atualizado
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

// Verificar configurações do Trello antes de continuar
if (!defined('TRELLO_API_KEY') || empty(TRELLO_API_KEY) || 
    !defined('TRELLO_TOKEN') || empty(TRELLO_TOKEN) ||
    !defined('TRELLO_BOARD_ID') || empty(TRELLO_BOARD_ID)) {
    json_response(['success' => false, 'error' => 'Configurações do Trello incompletas']);
}

// Verificar se é uma requisição POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Método não permitido.']);
}

// Obter dados da requisição
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    json_response(['success' => false, 'error' => 'Dados inválidos.']);
}

// Extrair informações
$arquivo = $data['arquivo'] ?? '';
$fonte = $data['fonte'] ?? 'json';
$novo_status = $data['novo_status'] ?? 'aguardando';

// Verificar se é um pedido do Trello ou arquivo JSON
if ($fonte === 'trello') {
    // Reprocessar pedido do Trello
    try {
        error_log("Iniciando reprocessamento de pedido do Trello com ID: $arquivo");
        
        // Obter informações do card atual
        $cardId = $arquivo;
        $url_card = "https://api.trello.com/1/cards/{$cardId}?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_card);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            error_log("Erro ao acessar card do Trello: $error");
            curl_close($ch);
            throw new Exception('Erro ao acessar card do Trello: ' . $error);
        }
        
        curl_close($ch);
        
        $card = json_decode($response, true);
        if (!$card) {
            error_log("Erro ao decodificar resposta do Trello: " . json_last_error_msg());
            throw new Exception('Erro ao decodificar resposta do Trello: ' . json_last_error_msg());
        }
        
        error_log("Card do Trello encontrado: " . $card['name']);
        
        // Obter as listas do board para encontrar a lista "Novos Clientes"
        $url_lists = "https://api.trello.com/1/boards/" . TRELLO_BOARD_ID . "/lists?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_lists);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response_lists = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            error_log("Erro ao acessar listas do Trello: $error");
            curl_close($ch);
            throw new Exception('Erro ao acessar listas do Trello: ' . $error);
        }
        
        curl_close($ch);
        
        $lists = json_decode($response_lists, true);
        if (!is_array($lists)) {
            error_log("Erro ao obter listas do Trello: resposta não é um array");
            throw new Exception('Erro ao obter listas do Trello: resposta não é um array');
        }
        
        // Encontrar a lista "Novos Clientes" ou algo similar
        $novosClientesListId = null;
        foreach ($lists as $list) {
            error_log("Lista do Trello encontrada: " . $list['name'] . " (ID: " . $list['id'] . ")");
            if (stripos($list['name'], 'NOVO CLIENTE') !== false || 
                stripos($list['name'], 'NOVOS CLIENTES') !== false || 
                stripos($list['name'], 'AGUARDANDO') !== false) {
                $novosClientesListId = $list['id'];
                error_log("Lista 'Novos Clientes' encontrada: " . $list['name'] . " (ID: " . $list['id'] . ")");
                break;
            }
        }
        
        if (!$novosClientesListId) {
            // Usar ID específico se não encontrar a lista pelo nome
            $novosClientesListId = '67f70b1e66425a64aa863e9f';
            error_log("Lista 'Novos Clientes' não encontrada pelo nome, usando ID padrão: $novosClientesListId");
        }
        
        // Atualizar nome do card (remover código de rastreio se existir)
        $cardName = $card['name'];
        $originalName = $cardName;
        
        // Se o nome começa com código de rastreio (formato: CÓDIGO - Nome), removê-lo
        if (preg_match('/^[A-Z0-9]{7,}\s*-\s*(.+)$/i', $cardName, $matches)) {
            $cardName = $matches[1];
            error_log("Removido código de rastreio do nome do card: '$originalName' -> '$cardName'");
        } 
        // Remover também se tiver formato de devolução
        else if (preg_match('/^DEVOLUÇÃO\s*-\s*(.+)$/i', $cardName, $matches)) {
            $cardName = $matches[1];
            error_log("Removido prefixo 'DEVOLUÇÃO' do nome do card: '$originalName' -> '$cardName'");
        }
        
        // Atualizar descrição do card para remover informações de rastreio e estado
        $currentDesc = $card['desc'];
        $originalDesc = $currentDesc;
        
        // Remover linhas com "Código de Rastreio", "Estado" e "Última atualização"
        $linhas = explode("\n", $currentDesc);
        $novasLinhas = [];
        
        foreach ($linhas as $linha) {
            if (strpos($linha, '📌 Código de Rastreio:') === false && 
                strpos($linha, '🔵 Estado:') === false && 
                strpos($linha, '🕒 Última atualização:') === false) {
                $novasLinhas[] = $linha;
            } else {
                error_log("Removida linha da descrição: '$linha'");
            }
        }
        
        $newDesc = implode("\n", $novasLinhas);
        
        if ($newDesc !== $originalDesc) {
            error_log("Descrição do card modificada");
        }
        
        // Atualizar card
        $url_update = "https://api.trello.com/1/cards/{$cardId}?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
        $updateData = [
            'name' => $cardName,
            'desc' => $newDesc,
            'idList' => $novosClientesListId
        ];
        
        error_log("Atualizando card do Trello para lista 'Novos Clientes' (ID: $novosClientesListId)");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_update);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($updateData));
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            error_log("Erro ao atualizar card no Trello: $error");
            curl_close($ch);
            throw new Exception('Erro ao atualizar card no Trello: ' . $error);
        }
        
        curl_close($ch);
        
        // Verificar resposta
        $result = json_decode($response, true);
        if (!$result) {
            error_log("Erro ao processar resposta do Trello após atualização: " . json_last_error_msg());
            throw new Exception('Erro ao processar resposta do Trello após atualização: ' . json_last_error_msg());
        }
        
        error_log("Card do Trello atualizado com sucesso");
        
        // Sucesso ao reprocessar pedido
        json_response(['success' => true, 'message' => 'Pedido reprocessado com sucesso.']);
        
    } catch (Exception $e) {
        error_log("Erro ao reprocessar pedido do Trello: " . $e->getMessage());
        json_response(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    // Reprocessar pedido JSON
    if (empty($arquivo)) {
        error_log("Erro: arquivo não informado para reprocessamento");
        json_response(['success' => false, 'error' => 'Arquivo não informado.']);
    }
    
    error_log("Iniciando reprocessamento de pedido JSON: $arquivo");
    
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
        error_log("Erro: arquivo não encontrado em nenhum dos caminhos tentados");
        json_response(['success' => false, 'error' => 'Arquivo não encontrado. Verifique o caminho.']);
    }
    
    try {
        // Carregar o pedido atual
        $file_contents = file_get_contents($arquivo_path);
        if ($file_contents === false) {
            error_log("Erro ao ler o arquivo: $arquivo_path");
            throw new Exception('Erro ao ler o arquivo do pedido.');
        }
        
        $pedido = json_decode($file_contents, true);
        if (!$pedido) {
            error_log("Erro ao decodificar o arquivo JSON: " . json_last_error_msg());
            throw new Exception('Erro ao decodificar o arquivo JSON do pedido: ' . json_last_error_msg());
        }
        
        // Atualizar o pedido
        $pedido['status'] = $novo_status;
        $pedido['integrado'] = false;
        
        // Remover informações de rastreio e estado
        if (isset($pedido['rastreio'])) {
            error_log("Removendo campo 'rastreio' do pedido");
            unset($pedido['rastreio']);
        }
        if (isset($pedido['estado_encomenda'])) {
            error_log("Removendo campo 'estado_encomenda' do pedido");
            unset($pedido['estado_encomenda']);
        }
        if (isset($pedido['atualizacao'])) {
            error_log("Removendo campo 'atualizacao' do pedido");
            unset($pedido['atualizacao']);
        }
        
        // Salvar as mudanças localmente
        if (!file_put_contents($arquivo_path, json_encode($pedido, JSON_PRETTY_PRINT))) {
            error_log("Erro ao salvar alterações no arquivo: $arquivo_path");
            throw new Exception('Erro ao salvar as alterações no arquivo do pedido.');
        }
        
        error_log("Pedido atualizado com sucesso no arquivo");
        
        // Atualizar o índice
        if (atualizarIndice($pedido, $arquivo)) {
            error_log("Índice atualizado com sucesso");
        } else {
            error_log("Aviso: Índice não pôde ser atualizado, mas o pedido foi reprocessado");
        }
        
        // Retornar sucesso
        json_response(['success' => true, 'message' => 'Pedido reprocessado com sucesso e movido para Novos Clientes.']);
        exit;
    } catch (Exception $e) {
        error_log("Erro ao reprocessar pedido JSON: " . $e->getMessage());
        json_response(['success' => false, 'error' => $e->getMessage()]);
    }
}

// Função para atualizar o índice
function atualizarIndice($pedido, $arquivo) {
    $pedidos_path = '/2025/pedidos';
    $index_path = $pedidos_path . '/index.json';
    
    try {
        // Carregar índice existente ou criar novo
        $index_content = @file_get_contents($index_path);
        if ($index_content !== false) {
            $index = json_decode($index_content, true);
            if (!is_array($index)) {
                $index = [];
            }
        } else {
            // Tentar com caminho relativo à raiz do servidor
            $index_path_alt = $_SERVER['DOCUMENT_ROOT'] . $index_path;
            $index_content = @file_get_contents($index_path_alt);
            
            if ($index_content !== false) {
                $index = json_decode($index_content, true);
                if (!is_array($index)) {
                    $index = [];
                }
            } else {
                $index = [];
            }
        }
        
        // Verificar se o pedido já existe no índice
        $found = false;
        foreach ($index as $key => $item) {
            if (isset($item['arquivo']) && $item['arquivo'] === $arquivo) {
                // Atualizar o pedido existente
                $index[$key] = $pedido;
                $index[$key]['arquivo'] = $arquivo;
                $found = true;
                break;
            }
        }
        
        // Se não encontrou, adicionar ao índice
        if (!$found) {
            $pedido['arquivo'] = $arquivo;
            $index[] = $pedido;
        }
        
        // Salvar o índice atualizado - como pode ser que não tenhamos permissão para escrita,
        // apenas logamos a tentativa de atualização
        $resultado = @file_put_contents($index_path, json_encode($index));
        if (!$resultado) {
            $index_path_alt = $_SERVER['DOCUMENT_ROOT'] . $index_path;
            $resultado = @file_put_contents($index_path_alt, json_encode($index));
            if (!$resultado) {
                error_log('Aviso: Não foi possível escrever no arquivo de índice: ' . $index_path);
                // Não lançamos uma exceção aqui, apenas registramos o erro
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log('Erro ao atualizar índice: ' . $e->getMessage());
        return false;
    }
} 