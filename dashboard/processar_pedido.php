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
    !defined('TRELLO_BOARD_ID') || empty(TRELLO_BOARD_ID) ||
    !defined('TRELLO_LIST_ID_TRANSITO') || empty(TRELLO_LIST_ID_TRANSITO) ||
    !defined('TRELLO_LIST_ID_PRONTO_ENVIO') || empty(TRELLO_LIST_ID_PRONTO_ENVIO)) {
    error_log('Configurações do Trello incompletas: ' . 
              'API_KEY=' . (defined('TRELLO_API_KEY') ? 'definida' : 'não definida') . ', ' .
              'TOKEN=' . (defined('TRELLO_TOKEN') ? 'definido' : 'não definido') . ', ' .
              'BOARD_ID=' . (defined('TRELLO_BOARD_ID') ? 'definido' : 'não definido') . ', ' .
              'LIST_ID_TRANSITO=' . (defined('TRELLO_LIST_ID_TRANSITO') ? 'definido' : 'não definido') . ', ' .
              'LIST_ID_PRONTO_ENVIO=' . (defined('TRELLO_LIST_ID_PRONTO_ENVIO') ? 'definido' : 'não definido'));
    
    json_response(['success' => false, 'error' => 'Configurações do Trello incompletas', 
        'config' => [
            'api_key_defined' => defined('TRELLO_API_KEY'),
            'token_defined' => defined('TRELLO_TOKEN'),
            'board_defined' => defined('TRELLO_BOARD_ID'),
            'list_transito_defined' => defined('TRELLO_LIST_ID_TRANSITO'),
            'list_pronto_envio_defined' => defined('TRELLO_LIST_ID_PRONTO_ENVIO')
        ]
    ]);
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
$processado = isset($data['processado']) ? $data['processado'] : false;
$tipo = $data['tipo'] ?? 'envio';
$fonte = $data['fonte'] ?? 'json';

error_log("Processando pedido: arquivo=$arquivo, tipo=$tipo, fonte=$fonte, processado=" . ($processado ? 'true' : 'false'));

// Verificar se é um ID do Trello
if (strlen($arquivo) === 24 && preg_match('/^[0-9a-f]{24}$/i', $arquivo)) {
    error_log("Detectado ID do Trello: $arquivo");
    $fonte = 'trello';
}

// Verificar se é um pedido do Trello ou arquivo JSON
if ($fonte === 'trello') {
    error_log("Processando pedido do Trello com ID: $arquivo");
    // Processar pedido do Trello
    try {
        if ($tipo === 'envio') {
            // Adicionar código de rastreio e mover para lista EM TRÂNSITO
            $codigoRastreio = $data['rastreio'] ?? '';
            $tratamento = $data['tratamento'] ?? '';
            
            if (empty($codigoRastreio)) {
                error_log('Erro: Código de rastreio não informado para pedido do Trello');
                throw new Exception('Código de rastreio é obrigatório.');
            }
            
            // Obter informações do card atual
            $cardId = $arquivo;
            $url_card = "https://api.trello.com/1/cards/{$cardId}?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
            error_log("Chamando API do Trello para obter informações do card: $url_card");
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url_card);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                error_log('Erro cURL ao acessar card do Trello: ' . curl_error($ch));
                throw new Exception('Erro ao acessar card do Trello: ' . curl_error($ch));
            }
            
            curl_close($ch);
            error_log("Resposta da API do Trello para o card: " . substr($response, 0, 200) . "...");
            
            $card = json_decode($response, true);
            if (!$card) {
                error_log('Erro ao decodificar resposta do Trello: ' . json_last_error_msg());
                throw new Exception('Erro ao decodificar resposta do Trello: ' . json_last_error_msg());
            }
            
            // Obter as listas do board para encontrar a lista "EM TRÂNSITO"
            $url_lists = "https://api.trello.com/1/boards/" . TRELLO_BOARD_ID . "/lists?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
            error_log("Obtendo listas do board do Trello: $url_lists");
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url_lists);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response_lists = curl_exec($ch);
            
            if (curl_errno($ch)) {
                error_log('Erro cURL ao acessar listas do Trello: ' . curl_error($ch));
                throw new Exception('Erro ao acessar listas do Trello: ' . curl_error($ch));
            }
            
            curl_close($ch);
            error_log("Resposta da API do Trello para as listas: " . substr($response_lists, 0, 200) . "...");
            
            $lists = json_decode($response_lists, true);
            if (!is_array($lists)) {
                error_log('Erro ao obter listas do Trello: resposta não é um array');
                throw new Exception('Erro ao obter listas do Trello: resposta não é um array');
            }
            
            // Encontrar a lista "EM TRÂNSITO"
            $transitoListId = null;
            foreach ($lists as $list) {
                error_log("Verificando lista: " . $list['name'] . " (ID: " . $list['id'] . ")");
                if (stripos($list['name'], 'EM TRANSITO') !== false || stripos($list['name'], 'EM TRÂNSITO') !== false) {
                    $transitoListId = $list['id'];
                    error_log("Lista 'EM TRÂNSITO' encontrada: " . $list['name'] . " (ID: " . $list['id'] . ")");
                    break;
                }
            }
            
            if (!$transitoListId) {
                // Verificar se TRELLO_LIST_ID_TRANSITO está definido
                if (defined('TRELLO_LIST_ID_TRANSITO') && !empty(TRELLO_LIST_ID_TRANSITO)) {
                    $transitoListId = TRELLO_LIST_ID_TRANSITO;
                    error_log("Lista 'EM TRÂNSITO' não encontrada pelo nome, usando ID definido em TRELLO_LIST_ID_TRANSITO: $transitoListId");
                } else {
                    error_log("Lista 'EM TRÂNSITO' não encontrada e TRELLO_LIST_ID_TRANSITO não definido");
                    throw new Exception("Lista 'EM TRÂNSITO' não encontrada. Por favor, configure corretamente o Trello.");
                }
            }
            
            error_log("PROCESSAMENTO TRELLO DIRETO - Lista final selecionada para EM TRÂNSITO: " . $transitoListId);
            
            // Atualizar nome do card com código de rastreio
            $cardName = $card['name'];
            // Remover "LIGAR DIA XX/XX/XXXX - " se existir no nome do card
            if (strpos($cardName, 'LIGAR DIA') !== false) {
                // Extrair apenas o nome do cliente da string "LIGAR DIA XX/XX/XXXX - Nome do Cliente"
                preg_match('/LIGAR DIA \d{2}\/\d{2}\/\d{4} - (.+)/', $cardName, $matches);
                $nomeCliente = $matches[1] ?? preg_replace('/LIGAR DIA \d{2}\/\d{2}\/\d{4} - /', '', $cardName);
                $newCardName = "{$codigoRastreio} - {$nomeCliente}";
            } else {
                $newCardName = "{$codigoRastreio} - " . preg_replace('/^\[.*?\]\s*-\s*/', '', $cardName);
            }
            
            // Reformatar descrição do card para seguir o padrão solicitado
            $currentDesc = $card['desc'];
            
            // Extrair informações da descrição atual
            $nomeCliente = preg_replace('/^\[.*?\]\s*-\s*/', '', $cardName);
            if (strpos($cardName, 'LIGAR DIA') !== false) {
                preg_match('/LIGAR DIA \d{2}\/\d{2}\/\d{4} - (.+)/', $cardName, $matches);
                $nomeCliente = $matches[1] ?? preg_replace('/LIGAR DIA \d{2}\/\d{2}\/\d{4} - /', '', $cardName);
            }
            
            // Extrair informações existentes
            $endereco = 'Não informado';
            $telefone = 'Não informado';
            $problema = 'Não informado';
            $origem = 'Trello';
            
            if (preg_match('/Morada:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $endereco = trim($matches[1]);
            } elseif (preg_match('/Endereço:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $endereco = trim($matches[1]);
            }
            
            if (preg_match('/Telefone:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $telefone = trim($matches[1]);
            } elseif (preg_match('/Contato:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $telefone = trim($matches[1]);
            }
            
            if (preg_match('/Problema:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $problema = trim($matches[1]);
            }
            
            if (preg_match('/Origem:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $origem = trim($matches[1]);
            }
            
            // Obter preço do tratamento
            $preco = '';
            switch ($tratamento) {
                case '1 Mês':
                    $preco = '74,98';
                    break;
                case '2 Meses':
                    $preco = '119,98';
                    break;
                case '3 Meses':
                    $preco = '149,98';
                    break;
                default:
                    if (preg_match('/Valor:(.*?)($|\n)/i', $currentDesc, $matches)) {
                        $preco = trim($matches[1]);
                    } else {
                        $preco = '74,98';
                    }
            }
            
            // Criar nova descrição no formato padrão
            $newDesc = "{$nomeCliente}\n";
            $newDesc .= "Valor: {$preco}\n";
            $newDesc .= "Morada: {$endereco}\n";
            $newDesc .= "Telefone: {$telefone}\n";
            $newDesc .= "Tratamento: {$tratamento}\n";
            $newDesc .= "Problema: {$problema}\n";
            $newDesc .= "Origem: {$origem}";
            
            // Adicionar informações de rastreio
            $newDesc .= "\n\n📌 Código de Rastreio: {$codigoRastreio}";
            $newDesc .= "\n🔵 Estado: Em processamento";
            $newDesc .= "\n🕒 Última atualização: " . date('d/m/Y H:i');
            
            // Atualizar card
            $url_update = "https://api.trello.com/1/cards/{$cardId}?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
            $updateData = [
                'name' => $newCardName,
                'desc' => $newDesc,
                'idList' => $transitoListId
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url_update);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($updateData));
            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                throw new Exception('Erro ao atualizar card no Trello: ' . curl_error($ch));
            }
            
            curl_close($ch);
            
            // Verificar resposta
            $result = json_decode($response, true);
            if (!$result) {
                throw new Exception('Erro ao processar resposta do Trello após atualização.');
            }
            
            // Retornar sucesso com informações do Trello se disponível
            $response_data = [
                'success' => true, 
                'message' => 'Pedido processado com sucesso.'
            ];
            
            if (!empty($result['id'])) {
                $response_data['trello_card_id'] = $result['id'];
            }
            
            json_response($response_data);
            
        } else if ($tipo === 'ligar') {
            // Adicionar à lista A LIGAR
            $dataLigacao = $data['data_ligacao'] ?? date('d/m/Y');
            $tratamento = $data['tratamento'] ?? '';
            $listaId = $data['lista_id'] ?? '';
            
            if (empty($listaId)) {
                throw new Exception('ID da lista de ligações não informado.');
            }
            
            // Obter informações do card atual
            $cardId = $arquivo;
            $url_card = "https://api.trello.com/1/cards/{$cardId}?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url_card);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                throw new Exception('Erro ao acessar card do Trello: ' . curl_error($ch));
            }
            
            curl_close($ch);
            
            $card = json_decode($response, true);
            if (!$card) {
                throw new Exception('Erro ao decodificar resposta do Trello.');
            }
            
            // Atualizar nome do card
            $nomeCliente = preg_replace('/^\[.*?\]\s*-\s*/', '', $card['name']);
            $newCardName = "LIGAR DIA {$dataLigacao} - {$nomeCliente}";
            
            // Extrair informações da descrição atual
            $currentDesc = $card['desc'];
            $endereco = '';
            $telefone = '';
            
            // Extrair endereço
            if (preg_match('/Morada:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $endereco = trim($matches[1]);
            } elseif (preg_match('/Endereço:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $endereco = trim($matches[1]);
            }
            
            // Extrair telefone
            if (preg_match('/Telefone:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $telefone = trim($matches[1]);
            } elseif (preg_match('/Contato:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $telefone = trim($matches[1]);
            }
            
            // Obter preço do tratamento
            $preco = '';
            switch ($tratamento) {
                case '1 Mês':
                    $preco = '74,98';
                    break;
                case '2 Meses':
                    $preco = '119,98';
                    break;
                case '3 Meses':
                    $preco = '149,98';
                    break;
            }
            
            // Obter informações adicionais
            $problema = 'Não informado';
            $origem = 'Trello';
            
            // Criar nova descrição no formato solicitado
            $newDesc = "{$nomeCliente}\n";
            $newDesc .= "Valor: {$preco}\n";
            $newDesc .= "Morada: {$endereco}\n";
            $newDesc .= "Telefone: {$telefone}\n";
            $newDesc .= "Tratamento: {$tratamento}\n";
            $newDesc .= "Problema: {$problema}\n";
            $newDesc .= "Origem: {$origem}";
            
            // Atualizar card
            $url_update = "https://api.trello.com/1/cards/{$cardId}?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
            $updateData = [
                'name' => $newCardName,
                'desc' => $newDesc,
                'idList' => $listaId
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url_update);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($updateData));
            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                throw new Exception('Erro ao atualizar card no Trello: ' . curl_error($ch));
            }
            
            curl_close($ch);
            
            // Verificar resposta
            $result = json_decode($response, true);
            if (!$result) {
                throw new Exception('Erro ao processar resposta do Trello após atualização.');
            }
            
            // Retornar sucesso com informações do Trello se disponível
            $response_data = [
                'success' => true, 
                'message' => 'Pedido processado com sucesso.'
            ];
            
            if (!empty($result['id'])) {
                $response_data['trello_card_id'] = $result['id'];
            }
            
            json_response($response_data);
        } else if ($tipo === 'processar') {
            // Processar pedido para "PRONTO PARA ENVIO" (sem código de rastreio obrigatório)
            $codigoRastreio = $data['rastreio'] ?? ''; // Opcional para este tipo
            $tratamento = $data['tratamento'] ?? '';
            
            error_log("Processando pedido do Trello para 'PRONTO PARA ENVIO' - tratamento: $tratamento, código de rastreio: " . ($codigoRastreio ?: 'não informado'));
            
            // Obter informações do card atual
            $cardId = $arquivo;
            $url_card = "https://api.trello.com/1/cards/{$cardId}?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
            error_log("Chamando API do Trello para obter informações do card: $url_card");
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url_card);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                error_log('Erro cURL ao acessar card do Trello: ' . curl_error($ch));
                throw new Exception('Erro ao acessar card do Trello: ' . curl_error($ch));
            }
            
            curl_close($ch);
            error_log("Resposta da API do Trello para o card: " . substr($response, 0, 200) . "...");
            
            $card = json_decode($response, true);
            if (!$card) {
                error_log('Erro ao decodificar resposta do Trello: ' . json_last_error_msg());
                throw new Exception('Erro ao decodificar resposta do Trello: ' . json_last_error_msg());
            }
            
            // Obter as listas do board para encontrar a lista "PRONTO PARA ENVIO"
            $url_lists = "https://api.trello.com/1/boards/" . TRELLO_BOARD_ID . "/lists?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
            error_log("Obtendo listas do board do Trello: $url_lists");
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url_lists);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response_lists = curl_exec($ch);
            
            if (curl_errno($ch)) {
                error_log('Erro cURL ao acessar listas do Trello: ' . curl_error($ch));
                throw new Exception('Erro ao acessar listas do Trello: ' . curl_error($ch));
            }
            
            curl_close($ch);
            error_log("Resposta da API do Trello para as listas: " . substr($response_lists, 0, 200) . "...");
            
            $lists = json_decode($response_lists, true);
            if (!is_array($lists)) {
                error_log('Erro ao obter listas do Trello: resposta não é um array');
                throw new Exception('Erro ao obter listas do Trello: resposta não é um array');
            }
            
            // Encontrar a lista "PRONTO PARA ENVIO"
            $prontoEnvioListId = null;
            foreach ($lists as $list) {
                error_log("Verificando lista: " . $list['name'] . " (ID: " . $list['id'] . ")");
                if (stripos($list['name'], 'PRONTO PARA ENVIO') !== false) {
                    $prontoEnvioListId = $list['id'];
                    error_log("Lista 'PRONTO PARA ENVIO' encontrada: " . $list['name'] . " (ID: " . $list['id'] . ")");
                    break;
                }
            }
            
            if (!$prontoEnvioListId) {
                error_log("Lista 'PRONTO PARA ENVIO' não encontrada pelo nome, usando ID configurado: " . TRELLO_LIST_ID_PRONTO_ENVIO);
                $prontoEnvioListId = TRELLO_LIST_ID_PRONTO_ENVIO;
            }
            
            error_log("PROCESSAMENTO TRELLO DIRETO - Lista final selecionada para PRONTO PARA ENVIO: " . $prontoEnvioListId);
            
            // Atualizar nome do card (remover prefixos se existirem)
            $cardName = $card['name'];
            // Remover "LIGAR DIA XX/XX/XXXX - " se existir no nome do card
            if (strpos($cardName, 'LIGAR DIA') !== false) {
                // Extrair apenas o nome do cliente da string "LIGAR DIA XX/XX/XXXX - Nome do Cliente"
                preg_match('/LIGAR DIA \d{2}\/\d{2}\/\d{4} - (.+)/', $cardName, $matches);
                $nomeCliente = $matches[1] ?? preg_replace('/LIGAR DIA \d{2}\/\d{2}\/\d{4} - /', '', $cardName);
            } else {
                $nomeCliente = preg_replace('/^\[.*?\]\s*-\s*/', '', $cardName);
            }
            
            // Definir novo nome do card
            if (!empty($codigoRastreio)) {
                $newCardName = "{$codigoRastreio} - {$nomeCliente}";
            } else {
                $newCardName = $nomeCliente;
            }
            
            // Reformatar descrição do card para seguir o padrão solicitado
            $currentDesc = $card['desc'];
            
            // Extrair informações existentes
            $endereco = 'Não informado';
            $telefone = 'Não informado';
            $problema = 'Não informado';
            $origem = 'Trello';
            
            if (preg_match('/Morada:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $endereco = trim($matches[1]);
            } elseif (preg_match('/Endereço:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $endereco = trim($matches[1]);
            }
            
            if (preg_match('/Telefone:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $telefone = trim($matches[1]);
            } elseif (preg_match('/Contato:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $telefone = trim($matches[1]);
            }
            
            if (preg_match('/Problema:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $problema = trim($matches[1]);
            }
            
            if (preg_match('/Origem:(.*?)($|\n)/i', $currentDesc, $matches)) {
                $origem = trim($matches[1]);
            }
            
            // Obter preço do tratamento
            $preco = '';
            switch ($tratamento) {
                case '1 Mês':
                    $preco = '74,98';
                    break;
                case '2 Meses':
                    $preco = '119,98';
                    break;
                case '3 Meses':
                    $preco = '149,98';
                    break;
                default:
                    if (preg_match('/Valor:(.*?)($|\n)/i', $currentDesc, $matches)) {
                        $preco = trim($matches[1]);
                    } else {
                        $preco = '74,98';
                    }
            }
            
            // Criar nova descrição no formato padrão
            $newDesc = "{$nomeCliente}\n";
            $newDesc .= "Valor: {$preco}\n";
            $newDesc .= "Morada: {$endereco}\n";
            $newDesc .= "Telefone: {$telefone}\n";
            $newDesc .= "Tratamento: {$tratamento}\n";
            $newDesc .= "Problema: {$problema}\n";
            $newDesc .= "Origem: {$origem}";
            
            // Adicionar informações de rastreio se fornecido
            if (!empty($codigoRastreio)) {
                $newDesc .= "\n\n📌 Código de Rastreio: {$codigoRastreio}";
            }
            $newDesc .= "\n🔵 Estado: Pronto para envio";
            $newDesc .= "\n🕒 Última atualização: " . date('d/m/Y H:i');
            
            // Atualizar card
            $url_update = "https://api.trello.com/1/cards/{$cardId}?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
            $updateData = [
                'name' => $newCardName,
                'desc' => $newDesc,
                'idList' => $prontoEnvioListId
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url_update);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($updateData));
            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                throw new Exception('Erro ao atualizar card no Trello: ' . curl_error($ch));
            }
            
            curl_close($ch);
            
            // Verificar resposta
            $result = json_decode($response, true);
            if (!$result) {
                throw new Exception('Erro ao processar resposta do Trello após atualização.');
            }
            
            // Retornar sucesso com informações do Trello se disponível
            $response_data = [
                'success' => true, 
                'message' => 'Pedido processado com sucesso.'
            ];
            
            if (!empty($result['id'])) {
                $response_data['trello_card_id'] = $result['id'];
            }
            
            json_response($response_data);
        } else if ($processado === false) {
            // Despendenciar um pedido (voltar para aguardando)
            error_log("Despendenciando pedido (voltando para status 'aguardando')");
            
            $pedido['integrado'] = false;
            $pedido['status'] = 'aguardando';
            
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
                error_log("Aviso: Índice não pôde ser atualizado, mas o pedido foi despendenciado");
            }
            
            // Retornar sucesso
            json_response(['success' => true, 'message' => 'Pedido atualizado com sucesso.']);
            exit;
        }
    } catch (Exception $e) {
        error_log("Erro ao processar pedido do Trello: " . $e->getMessage());
        json_response(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    // Processar pedido JSON
    error_log("Processando pedido JSON: $arquivo");
    
    if (empty($arquivo)) {
        error_log("Erro: arquivo não informado");
        json_response(['success' => false, 'error' => 'Arquivo não informado.']);
        exit;
    }
    
    // Verificar se o arquivo já contém a extensão .json
    if (substr($arquivo, -5) !== '.json') {
        $arquivo .= '.json';
    }
    
    // Tentar vários caminhos possíveis para o arquivo
    $caminhos_possiveis = [
        $local_path . '/' . $arquivo,
        $_SERVER['DOCUMENT_ROOT'] . '/2025/pedidos/' . $arquivo,
        dirname($_SERVER['DOCUMENT_ROOT']) . '/2025/pedidos/' . $arquivo,
        '../../2025/pedidos/' . $arquivo,
        '../2025/pedidos/' . $arquivo,
        './2025/pedidos/' . $arquivo
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
    
    // Se não encontrou o arquivo, tentar buscar pelo index.json
    if ($arquivo_path === null) {
        error_log("Arquivo não encontrado nos caminhos padrão, tentando buscar pelo index.json");
        
        // Caminhos possíveis para o index.json
        $index_paths = [
            '../../2025/pedidos/index.json',
            $_SERVER['DOCUMENT_ROOT'] . '/2025/pedidos/index.json',
            dirname($_SERVER['DOCUMENT_ROOT']) . '/2025/pedidos/index.json',
            '../2025/pedidos/index.json',
            './2025/pedidos/index.json'
        ];
        
        $index_path = null;
        foreach ($index_paths as $path) {
            if (file_exists($path)) {
                $index_path = $path;
                break;
            }
        }
        
        if ($index_path !== null) {
            // Extrair o nome do arquivo sem a extensão .json
            $arquivo_base = pathinfo($arquivo, PATHINFO_FILENAME);
            
            // Ler o index.json
            $index_content = file_get_contents($index_path);
            if ($index_content !== false) {
                $index_data = json_decode($index_content, true);
                if (is_array($index_data) && isset($index_data[$arquivo_base])) {
                    // Encontrou o pedido no index
                    error_log("Pedido encontrado no index.json");
                    
                    // Criar o arquivo individual se não existir
                    $dir_path = dirname($index_path);
                    $arquivo_path = $dir_path . '/' . $arquivo;
                    
                    if (!file_exists($arquivo_path)) {
                        error_log("Criando arquivo individual: $arquivo_path");
                        $pedido_data = $index_data[$arquivo_base];
                        file_put_contents($arquivo_path, json_encode($pedido_data, JSON_PRETTY_PRINT));
                    } else {
                        error_log("Arquivo individual já existe: $arquivo_path");
                    }
                }
            }
        }
    }
    
    if ($arquivo_path === null) {
        error_log("Erro: arquivo não encontrado em nenhum dos caminhos tentados");
        json_response(['success' => false, 'error' => 'Arquivo não encontrado. Verifique o caminho.']);
        exit;
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
        
        error_log("Pedido carregado com sucesso");
        
        // Verificar o tipo de processamento
        if ($tipo === 'envio') {
            // Processo normal de envio com código de rastreio (EM TRÂNSITO)
            $codigoRastreio = $data['rastreio'] ?? '';
            $tratamento = $data['tratamento'] ?? '';
            
            if (empty($codigoRastreio)) {
                error_log("Erro: código de rastreio não informado");
                throw new Exception('Código de rastreio é obrigatório.');
            }
            
            error_log("Processando pedido para 'EM TRÂNSITO' com código de rastreio: $codigoRastreio, tratamento: $tratamento");
            
            // Atualizar o pedido
            $pedido['integrado'] = true;
            $pedido['status'] = 'processado'; // Status para "EM TRÂNSITO"
            $pedido['rastreio'] = $codigoRastreio;
            $pedido['tratamento'] = $tratamento;
            
            // Integrar com o Trello na lista "EM TRÂNSITO"
            error_log("Iniciando integração com o Trello (lista EM TRÂNSITO)");
            $pedidoTrello = integrarComTrello($pedido, $codigoRastreio, $tratamento);
            
            if (!$pedidoTrello['success']) {
                error_log("Erro ao integrar com o Trello: " . ($pedidoTrello['error'] ?? 'Erro desconhecido'));
                throw new Exception('Erro ao integrar com o Trello: ' . ($pedidoTrello['error'] ?? 'Erro desconhecido'));
            }
            
            error_log("Integração com o Trello concluída com sucesso, Card ID: " . ($pedidoTrello['card_id'] ?? 'não informado'));
            
            // Salvar o ID do Trello no pedido local se disponível
            if (!empty($pedidoTrello['card_id'])) {
                $pedido['id_trello'] = $pedidoTrello['card_id'];
                $pedido['enviado_trello'] = true;
                $pedido['data_envio_trello'] = date('Y-m-d H:i:s');
            }
            $pedido['integrado'] = true;
            
            // Salvar as mudanças localmente antes de remover
            if (!file_put_contents($arquivo_path, json_encode($pedido, JSON_PRETTY_PRINT))) {
                error_log("Erro ao salvar alterações no arquivo: $arquivo_path");
                throw new Exception('Erro ao salvar as alterações no arquivo do pedido.');
            }
            
            error_log("Pedido atualizado com sucesso no arquivo");
            
            // SEMPRE remover o pedido do JSON após processamento bem-sucedido
            // (independentemente do card_id, pois o processamento foi concluído)
            error_log("=== INICIANDO REMOÇÃO DE ARQUIVOS APÓS PROCESSAMENTO ===");
            error_log("Removendo pedido do índice JSON após processamento bem-sucedido");
            $remocao_indice = removerPedidoDoIndice($arquivo);
            error_log("Resultado da remoção do índice: " . ($remocao_indice ? 'sucesso' : 'falha'));
            
            // Também remover o arquivo individual se existir
            if (file_exists($arquivo_path)) {
                error_log("Removendo arquivo individual: $arquivo_path");
                $permissao_escrita = is_writable($arquivo_path);
                error_log("Arquivo é gravável: " . ($permissao_escrita ? 'sim' : 'não'));
                
                if ($permissao_escrita) {
                    if (unlink($arquivo_path)) {
                        error_log("✅ Arquivo individual removido com sucesso");
                    } else {
                        error_log("❌ Erro ao remover arquivo individual");
                    }
                } else {
                    error_log("❌ Sem permissão para remover arquivo individual");
                }
            } else {
                error_log("Arquivo individual não existe (já foi removido?): $arquivo_path");
            }
            error_log("=== REMOÇÃO DE ARQUIVOS CONCLUÍDA ===");
            
            // Retornar sucesso com informações do Trello se disponível
            $response_data = [
                'success' => true, 
                'message' => 'Pedido processado com sucesso.'
            ];
            
            if (!empty($pedidoTrello['card_id'])) {
                $response_data['trello_card_id'] = $pedidoTrello['card_id'];
            }
            
            json_response($response_data);
        } else if ($tipo === 'ligar') {
            // Adicionar à fila de ligações
            $dataLigacao = $data['data_ligacao'] ?? date('d/m/Y');
            $tratamento = $data['tratamento'] ?? '';
            $listaId = $data['lista_id'] ?? '';
            
            if (empty($listaId)) {
                error_log("Erro: ID da lista de ligações não informado");
                throw new Exception('ID da lista de ligações não informado.');
            }
            
            error_log("Adicionando pedido à fila de ligações para data: $dataLigacao, tratamento: $tratamento, lista ID: $listaId");
            
            // Atualizar o pedido
            $pedido['status'] = 'ligar';
            $pedido['data_ligacao'] = $dataLigacao;
            $pedido['tratamento'] = $tratamento;
            
            // Integrar com o Trello na lista "A LIGAR"
            error_log("Iniciando integração com o Trello (lista A LIGAR)");
            $pedidoTrello = integrarComTrelloLigar($pedido, $dataLigacao, $tratamento, $listaId);
            
            if (!$pedidoTrello['success']) {
                error_log("Erro ao integrar com o Trello: " . ($pedidoTrello['error'] ?? 'Erro desconhecido'));
                throw new Exception('Erro ao integrar com o Trello: ' . ($pedidoTrello['error'] ?? 'Erro desconhecido'));
            }
            
            error_log("Integração com o Trello concluída com sucesso, Card ID: " . ($pedidoTrello['card_id'] ?? 'não informado'));
            
            // Salvar o ID do Trello no pedido local se disponível
            if (!empty($pedidoTrello['card_id'])) {
                $pedido['id_trello'] = $pedidoTrello['card_id'];
                $pedido['enviado_trello'] = true;
                $pedido['data_envio_trello'] = date('Y-m-d H:i:s');
            }
            $pedido['integrado'] = true;
            
            // Salvar as mudanças localmente antes de remover
            if (!file_put_contents($arquivo_path, json_encode($pedido, JSON_PRETTY_PRINT))) {
                error_log("Erro ao salvar alterações no arquivo: $arquivo_path");
                throw new Exception('Erro ao salvar as alterações no arquivo do pedido.');
            }
            
            error_log("Pedido atualizado com sucesso no arquivo");
            
            // SEMPRE remover o pedido do JSON após processamento bem-sucedido
            // (independentemente do card_id, pois o processamento foi concluído)
            error_log("=== INICIANDO REMOÇÃO DE ARQUIVOS APÓS PROCESSAMENTO ===");
            error_log("Removendo pedido do índice JSON após processamento bem-sucedido");
            $remocao_indice = removerPedidoDoIndice($arquivo);
            error_log("Resultado da remoção do índice: " . ($remocao_indice ? 'sucesso' : 'falha'));
            
            // Também remover o arquivo individual se existir
            if (file_exists($arquivo_path)) {
                error_log("Removendo arquivo individual: $arquivo_path");
                $permissao_escrita = is_writable($arquivo_path);
                error_log("Arquivo é gravável: " . ($permissao_escrita ? 'sim' : 'não'));
                
                if ($permissao_escrita) {
                    if (unlink($arquivo_path)) {
                        error_log("✅ Arquivo individual removido com sucesso");
                    } else {
                        error_log("❌ Erro ao remover arquivo individual");
                    }
                } else {
                    error_log("❌ Sem permissão para remover arquivo individual");
                }
            } else {
                error_log("Arquivo individual não existe (já foi removido?): $arquivo_path");
            }
            error_log("=== REMOÇÃO DE ARQUIVOS CONCLUÍDA ===");
            
            // Retornar sucesso com informações do Trello se disponível
            $response_data = [
                'success' => true, 
                'message' => 'Pedido processado com sucesso.'
            ];
            
            if (!empty($pedidoTrello['card_id'])) {
                $response_data['trello_card_id'] = $pedidoTrello['card_id'];
            }
            
            json_response($response_data);
        } else if ($tipo === 'processar') {
            // Processar pedido para "PRONTO PARA ENVIO" (sem código de rastreio obrigatório)
            $codigoRastreio = $data['rastreio'] ?? ''; // Opcional para este tipo
            $tratamento = $data['tratamento'] ?? '';
            
            error_log("Processando pedido para 'PRONTO PARA ENVIO' - tratamento: $tratamento, código de rastreio: " . ($codigoRastreio ?: 'não informado'));
            
            // Atualizar o pedido
            $pedido['integrado'] = true;
            $pedido['status'] = 'em_processamento'; // Status para "PRONTO PARA ENVIO"
            $pedido['tratamento'] = $tratamento;
            
            // Adicionar código de rastreio se fornecido
            if (!empty($codigoRastreio)) {
                $pedido['rastreio'] = $codigoRastreio;
            }
            
            // Integrar com o Trello na lista "PRONTO PARA ENVIO"
            error_log("Iniciando integração com o Trello (lista PRONTO PARA ENVIO)");
            $pedidoTrello = integrarComTrelloProntoEnvio($pedido, $codigoRastreio, $tratamento);
            
            if (!$pedidoTrello['success']) {
                error_log("Erro ao integrar com o Trello: " . ($pedidoTrello['error'] ?? 'Erro desconhecido'));
                throw new Exception('Erro ao integrar com o Trello: ' . ($pedidoTrello['error'] ?? 'Erro desconhecido'));
            }
            
            error_log("Integração com o Trello concluída com sucesso, Card ID: " . ($pedidoTrello['card_id'] ?? 'não informado'));
            
            // Salvar o ID do Trello no pedido local se disponível
            if (!empty($pedidoTrello['card_id'])) {
                $pedido['id_trello'] = $pedidoTrello['card_id'];
                $pedido['enviado_trello'] = true;
                $pedido['data_envio_trello'] = date('Y-m-d H:i:s');
            }
            $pedido['integrado'] = true;
            
            // Salvar as mudanças localmente antes de remover
            if (!file_put_contents($arquivo_path, json_encode($pedido, JSON_PRETTY_PRINT))) {
                error_log("Erro ao salvar alterações no arquivo: $arquivo_path");
                throw new Exception('Erro ao salvar as alterações no arquivo do pedido.');
            }
            
            error_log("Pedido atualizado com sucesso no arquivo");
            
            // SEMPRE remover o pedido do JSON após processamento bem-sucedido
            // (independentemente do card_id, pois o processamento foi concluído)
            error_log("=== INICIANDO REMOÇÃO DE ARQUIVOS APÓS PROCESSAMENTO ===");
            error_log("Removendo pedido do índice JSON após processamento bem-sucedido");
            $remocao_indice = removerPedidoDoIndice($arquivo);
            error_log("Resultado da remoção do índice: " . ($remocao_indice ? 'sucesso' : 'falha'));
            
            // Também remover o arquivo individual se existir
            if (file_exists($arquivo_path)) {
                error_log("Removendo arquivo individual: $arquivo_path");
                $permissao_escrita = is_writable($arquivo_path);
                error_log("Arquivo é gravável: " . ($permissao_escrita ? 'sim' : 'não'));
                
                if ($permissao_escrita) {
                    if (unlink($arquivo_path)) {
                        error_log("✅ Arquivo individual removido com sucesso");
                    } else {
                        error_log("❌ Erro ao remover arquivo individual");
                    }
                } else {
                    error_log("❌ Sem permissão para remover arquivo individual");
                }
            } else {
                error_log("Arquivo individual não existe (já foi removido?): $arquivo_path");
            }
            error_log("=== REMOÇÃO DE ARQUIVOS CONCLUÍDA ===");
            
            // Retornar sucesso com informações do Trello se disponível
            $response_data = [
                'success' => true, 
                'message' => 'Pedido processado com sucesso.'
            ];
            
            if (!empty($pedidoTrello['card_id'])) {
                $response_data['trello_card_id'] = $pedidoTrello['card_id'];
            }
            
            json_response($response_data);
        } else if ($processado === false) {
            // Despendenciar um pedido (voltar para aguardando)
            error_log("Despendenciando pedido (voltando para status 'aguardando')");
            
            $pedido['integrado'] = false;
            $pedido['status'] = 'aguardando';
            
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
                error_log("Aviso: Índice não pôde ser atualizado, mas o pedido foi despendenciado");
            }
            
            // Retornar sucesso
            json_response(['success' => true, 'message' => 'Pedido atualizado com sucesso.']);
            exit;
        }
    } catch (Exception $e) {
        error_log("Erro ao processar pedido JSON: " . $e->getMessage());
        json_response(['success' => false, 'error' => $e->getMessage()]);
    }
}

// Função para integrar com o Trello (enviando para Em Trânsito)
function integrarComTrello($pedido, $codigoRastreio, $tratamento) {
    error_log("Iniciando integração com Trello para código de rastreio: $codigoRastreio");
    
    try {
        // Obter as listas do board
        $url_lists = "https://api.trello.com/1/boards/" . TRELLO_BOARD_ID . "/lists?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_lists);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            error_log("Erro ao acessar listas do Trello: $error");
            curl_close($ch);
            throw new Exception('Erro ao acessar listas do Trello: ' . $error);
        }
        
        curl_close($ch);
        
        $lists = json_decode($response, true);
        if (!is_array($lists)) {
            error_log("Erro ao obter listas do Trello: resposta não é um array");
            throw new Exception('Erro ao obter listas do Trello: resposta não é um array');
        }
        
        error_log("Listas do Trello obtidas: " . count($lists) . " listas");
        
        // Encontrar a lista "EM TRÂNSITO"
        $transitoListId = null;
        foreach ($lists as $list) {
            error_log("Lista encontrada: " . $list['name'] . " (ID: " . $list['id'] . ")");
            if (stripos($list['name'], 'EM TRANSITO') !== false || stripos($list['name'], 'EM TRÂNSITO') !== false) {
                $transitoListId = $list['id'];
                error_log("Lista 'EM TRÂNSITO' encontrada: " . $list['name'] . " (ID: " . $list['id'] . ")");
                break;
            }
        }
        
        if (!$transitoListId) {
            error_log("Lista 'EM TRÂNSITO' não encontrada pelo nome, usando ID configurado: " . TRELLO_LIST_ID_TRANSITO);
            $transitoListId = TRELLO_LIST_ID_TRANSITO;
        }
        
        error_log("FUNÇÃO integrarComTrello - Lista final selecionada para EM TRÂNSITO: " . $transitoListId);
        
        // Formatar o nome do card e descrição conforme solicitado
        $nome = $pedido['NOME'] ?? 'Cliente sem nome';
        $cardName = "{$codigoRastreio} - {$nome}";
        
        // Obter informações para a descrição
        $endereco = $pedido['ENDEREÇO'] ?? 'Endereço não informado';
        $telefone = $pedido['CONTATO'] ?? 'Telefone não informado';
        
        // Obter preço do tratamento
        $preco = '';
        switch ($tratamento) {
            case '1 Mês':
                $preco = '74,98';
                break;
            case '2 Meses':
                $preco = '119,98';
                break;
            case '3 Meses':
                $preco = '149,98';
                break;
            default:
                // Tentar extrair o preço do próprio tratamento se contiver
                if (preg_match('/\(([0-9,.]+)€?\)/', $tratamento, $matches)) {
                    $preco = $matches[1];
                }
        }
        
        // Verificar se é um pedido de recuperação
        $recuperacao = '';
        if (isset($pedido['Rec']) && $pedido['Rec']) {
            $recuperacao = ' (Recuperação)';
        }
        
        // Obter informações adicionais
        $problema = $pedido['PROBLEMA_RELATADO'] ?? 'Não informado';
        $origem = $pedido['origem'] ?? 'Sistema';
        
        // Criar descrição no formato solicitado
        $desc = "{$nome}{$recuperacao}\n";
        $desc .= "Valor: {$preco}\n";
        $desc .= "Morada: {$endereco}\n";
        $desc .= "Telefone: {$telefone}\n";
        $desc .= "Tratamento: {$tratamento}\n";
        $desc .= "Problema: {$problema}\n";
        $desc .= "Origem: {$origem}";
        
        // Criar o card no Trello
        $url = "https://api.trello.com/1/cards?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
        $cardData = [
            'name' => $cardName,
            'desc' => $desc,
            'idList' => $transitoListId,
            'pos' => 'top'
        ];
        
        error_log("Enviando dados do card para o Trello, lista ID: $transitoListId");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($cardData));
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            error_log("Erro ao criar card no Trello: $error");
            curl_close($ch);
            throw new Exception('Erro ao criar card no Trello: ' . $error);
        }
        
        curl_close($ch);
        
        $result = json_decode($response, true);
        if (!$result || !isset($result['id'])) {
            error_log("Erro ao criar card no Trello: " . ($response ? $response : 'resposta vazia'));
            throw new Exception('Erro ao criar card no Trello: resposta inválida do servidor');
        }
        
        error_log("Card criado com sucesso no Trello, ID: " . $result['id']);
        
        return ['success' => true, 'card_id' => $result['id']];
    } catch (Exception $e) {
        error_log("Erro na integração com Trello: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Função para integrar com o Trello (adicionando à fila de ligações)
function integrarComTrelloLigar($pedido, $dataLigacao, $tratamento, $listaId) {
    try {
        // Formatar o nome do card e descrição conforme solicitado
        $nome = $pedido['NOME'] ?? 'Cliente sem nome';
        $cardName = "LIGAR DIA {$dataLigacao} - {$nome}";
        
        // Obter informações para a descrição
        $endereco = $pedido['ENDEREÇO'] ?? 'Endereço não informado';
        $telefone = $pedido['CONTATO'] ?? 'Telefone não informado';
        
        // Obter preço do tratamento
        $preco = '';
        switch ($tratamento) {
            case '1 Mês':
                $preco = '74,98';
                break;
            case '2 Meses':
                $preco = '119,98';
                break;
            case '3 Meses':
                $preco = '149,98';
                break;
            default:
                // Tentar extrair o preço do próprio tratamento se contiver
                if (preg_match('/\(([0-9,.]+)€?\)/', $tratamento, $matches)) {
                    $preco = $matches[1];
                }
        }
        
        // Verificar se é um pedido de recuperação
        $recuperacao = '';
        if (isset($pedido['Rec']) && $pedido['Rec']) {
            $recuperacao = ' (Recuperação)';
        }
        
        // Obter informações adicionais
        $problema = $pedido['PROBLEMA_RELATADO'] ?? 'Não informado';
        $origem = $pedido['origem'] ?? 'Sistema';
        
        // Criar descrição no formato solicitado
        $desc = "{$nome}{$recuperacao}\n";
        $desc .= "Valor: {$preco}\n";
        $desc .= "Morada: {$endereco}\n";
        $desc .= "Telefone: {$telefone}\n";
        $desc .= "Tratamento: {$tratamento}\n";
        $desc .= "Problema: {$problema}\n";
        $desc .= "Origem: {$origem}";
        
        // Criar o card no Trello
        $url = "https://api.trello.com/1/cards?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
        $cardData = [
            'name' => $cardName,
            'desc' => $desc,
            'idList' => $listaId,
            'pos' => 'top'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($cardData));
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception('Erro ao criar card no Trello: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        $result = json_decode($response, true);
        if (!$result || !isset($result['id'])) {
            throw new Exception('Erro ao criar card no Trello: resposta inválida do servidor');
        }
        
        return ['success' => true, 'card_id' => $result['id']];
    } catch (Exception $e) {
        error_log("Erro na integração com Trello (ligar): " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Função para integrar com o Trello (adicionando à fila de ligações)
function integrarComTrelloProntoEnvio($pedido, $codigoRastreio, $tratamento) {
    try {
        // Obter as listas do board
        $url_lists = "https://api.trello.com/1/boards/" . TRELLO_BOARD_ID . "/lists?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_lists);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            error_log("Erro ao acessar listas do Trello: $error");
            curl_close($ch);
            throw new Exception('Erro ao acessar listas do Trello: ' . $error);
        }
        
        curl_close($ch);
        
        $lists = json_decode($response, true);
        if (!is_array($lists)) {
            error_log("Erro ao obter listas do Trello: resposta não é um array");
            throw new Exception('Erro ao obter listas do Trello: resposta não é um array');
        }
        
        error_log("Listas do Trello obtidas: " . count($lists) . " listas");
        
        // Encontrar a lista "PRONTO PARA ENVIO"
        $prontoEnvioListId = null;
        foreach ($lists as $list) {
            error_log("Lista encontrada: " . $list['name'] . " (ID: " . $list['id'] . ")");
            if (stripos($list['name'], 'PRONTO PARA ENVIO') !== false) {
                $prontoEnvioListId = $list['id'];
                error_log("Lista 'PRONTO PARA ENVIO' encontrada: " . $list['name'] . " (ID: " . $list['id'] . ")");
                break;
            }
        }
        
        if (!$prontoEnvioListId) {
            error_log("Lista 'PRONTO PARA ENVIO' não encontrada pelo nome, usando ID configurado: " . TRELLO_LIST_ID_PRONTO_ENVIO);
            $prontoEnvioListId = TRELLO_LIST_ID_PRONTO_ENVIO;
        }
        
        error_log("FUNÇÃO integrarComTrelloProntoEnvio - Lista final selecionada para PRONTO PARA ENVIO: " . $prontoEnvioListId);
        
        // Formatar o nome do card e descrição conforme solicitado
        $nome = $pedido['NOME'] ?? 'Cliente sem nome';
        $cardName = "{$codigoRastreio} - {$nome}";
        
        // Obter informações para a descrição
        $endereco = $pedido['ENDEREÇO'] ?? 'Endereço não informado';
        $telefone = $pedido['CONTATO'] ?? 'Telefone não informado';
        
        // Obter preço do tratamento
        $preco = '';
        switch ($tratamento) {
            case '1 Mês':
                $preco = '74,98';
                break;
            case '2 Meses':
                $preco = '119,98';
                break;
            case '3 Meses':
                $preco = '149,98';
                break;
            default:
                // Tentar extrair o preço do próprio tratamento se contiver
                if (preg_match('/\(([0-9,.]+)€?\)/', $tratamento, $matches)) {
                    $preco = $matches[1];
                }
        }
        
        // Verificar se é um pedido de recuperação
        $recuperacao = '';
        if (isset($pedido['Rec']) && $pedido['Rec']) {
            $recuperacao = ' (Recuperação)';
        }
        
        // Obter informações adicionais
        $problema = $pedido['PROBLEMA_RELATADO'] ?? 'Não informado';
        $origem = $pedido['origem'] ?? 'Sistema';
        
        // Criar descrição no formato solicitado
        $desc = "{$nome}{$recuperacao}\n";
        $desc .= "Valor: {$preco}\n";
        $desc .= "Morada: {$endereco}\n";
        $desc .= "Telefone: {$telefone}\n";
        $desc .= "Tratamento: {$tratamento}\n";
        $desc .= "Problema: {$problema}\n";
        $desc .= "Origem: {$origem}";
        
        // Criar o card no Trello
        $url = "https://api.trello.com/1/cards?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
        $cardData = [
            'name' => $cardName,
            'desc' => $desc,
            'idList' => $prontoEnvioListId,
            'pos' => 'top'
        ];
        
        error_log("Enviando dados do card para o Trello, lista ID: $prontoEnvioListId");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($cardData));
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            error_log("Erro ao criar card no Trello: $error");
            curl_close($ch);
            throw new Exception('Erro ao criar card no Trello: ' . $error);
        }
        
        curl_close($ch);
        
        $result = json_decode($response, true);
        if (!$result || !isset($result['id'])) {
            error_log("Erro ao criar card no Trello: " . ($response ? $response : 'resposta vazia'));
            throw new Exception('Erro ao criar card no Trello: resposta inválida do servidor');
        }
        
        error_log("Card criado com sucesso no Trello, ID: " . $result['id']);
        
        return ['success' => true, 'card_id' => $result['id']];
    } catch (Exception $e) {
        error_log("Erro na integração com Trello (pronto envio): " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
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

// Função para remover um pedido do índice JSON
function removerPedidoDoIndice($arquivo) {
    error_log("=== INICIANDO REMOÇÃO DO PEDIDO DO ÍNDICE ===");
    error_log("Arquivo solicitado para remoção: $arquivo");
    
    // Múltiplos caminhos possíveis para o index.json
    $caminhos_index = [
        '../2025/pedidos/index.json',
        '../../2025/pedidos/index.json',
        $_SERVER['DOCUMENT_ROOT'] . '/2025/pedidos/index.json',
        dirname($_SERVER['DOCUMENT_ROOT']) . '/2025/pedidos/index.json',
        './2025/pedidos/index.json'
    ];
    
    $index_path = null;
    $index_content = null;
    
    // Encontrar o arquivo index.json
    foreach ($caminhos_index as $caminho) {
        error_log("Tentando caminho do índice: $caminho");
        if (file_exists($caminho)) {
            $index_path = $caminho;
            $index_content = @file_get_contents($caminho);
            if ($index_content !== false) {
                error_log("Index.json encontrado em: $caminho");
                break;
            }
        }
    }
    
    if ($index_path === null || $index_content === false) {
        error_log("ERRO: index.json não encontrado em nenhum dos caminhos");
        return false;
    }
    
    try {
        $index = json_decode($index_content, true);
        if (!is_array($index)) {
            error_log("ERRO: index.json não contém um array válido");
            return false;
        }
        
        $original_count = count($index);
        error_log("Index carregado com $original_count itens");
        
        // Preparar diferentes variações do nome do arquivo para busca
        $arquivo_base = str_replace('.json', '', $arquivo);
        $arquivo_com_json = $arquivo_base . '.json';
        
        error_log("Procurando por:");
        error_log("- arquivo_base: '$arquivo_base'");
        error_log("- arquivo_com_json: '$arquivo_com_json'");
        error_log("- arquivo_original: '$arquivo'");
        
        // Procurar e remover o pedido do índice
        $found = false;
        $removed_keys = [];
        
        // Primeira passagem: procurar por chave direta
        if (isset($index[$arquivo_base])) {
            error_log("Encontrado por chave direta: $arquivo_base");
            unset($index[$arquivo_base]);
            $removed_keys[] = $arquivo_base;
            $found = true;
        }
        
        // Segunda passagem: procurar por campo 'arquivo'
        foreach ($index as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            
            $item_arquivo = isset($item['arquivo']) ? $item['arquivo'] : '';
            $item_arquivo_base = str_replace('.json', '', $item_arquivo);
            
            // Verificar múltiplas formas de correspondência
            if ($item_arquivo === $arquivo || 
                $item_arquivo === $arquivo_base || 
                $item_arquivo === $arquivo_com_json ||
                $item_arquivo_base === $arquivo_base ||
                $key === $arquivo_base) {
                
                error_log("Pedido encontrado no índice - removendo:");
                error_log("- chave: '$key'");
                error_log("- item_arquivo: '$item_arquivo'");
                error_log("- nome: " . ($item['NOME'] ?? 'não informado'));
                
                unset($index[$key]);
                $removed_keys[] = $key;
                $found = true;
            }
        }
        
        if (!$found) {
            error_log("AVISO: Pedido não encontrado no índice para remoção");
            error_log("Listando todos os itens do índice para debug:");
            foreach ($index as $key => $item) {
                $item_arquivo = is_array($item) && isset($item['arquivo']) ? $item['arquivo'] : 'não definido';
                $item_nome = is_array($item) && isset($item['NOME']) ? $item['NOME'] : 'não definido';
                error_log("- chave: '$key', arquivo: '$item_arquivo', nome: '$item_nome'");
            }
            // Não retornar false aqui, pois pode ser que o pedido já tenha sido removido
        } else {
            error_log("Pedidos removidos: " . implode(', ', $removed_keys));
        }
        
        $new_count = count($index);
        error_log("Itens no índice: antes=$original_count, depois=$new_count");
        
        // Reindexar o array para evitar problemas com chaves numéricas
        $index = array_values($index);
        $final_count = count($index);
        error_log("Após reindexação: $final_count itens");
        
        // Salvar o índice atualizado
        $json_data = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $resultado = @file_put_contents($index_path, $json_data);
        
        if ($resultado === false) {
            error_log("ERRO ao salvar index.json atualizado em: $index_path");
            return false;
        }
        
        error_log("Index.json atualizado com sucesso, bytes escritos: $resultado");
        error_log("=== REMOÇÃO DO PEDIDO CONCLUÍDA ===");
        return true;
        
    } catch (Exception $e) {
        error_log('ERRO ao remover pedido do índice: ' . $e->getMessage());
        return false;
    }
}
?> 