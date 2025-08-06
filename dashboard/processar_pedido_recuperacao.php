<?php
// Garantir que nenhuma saída seja enviada antes dos cabeçalhos
ob_start();
session_start();

// Definir cabeçalhos para evitar problemas de CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Log de diagnóstico para debug
$log_file = __DIR__ . '/debug_pedidos.log';
$log_data = date('[Y-m-d H:i:s]') . ' Requisição recebida: ' . $_SERVER['REQUEST_METHOD'] . PHP_EOL;
file_put_contents($log_file, $log_data, FILE_APPEND);

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

// Log dos caminhos
file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Caminhos: relativo=$pedidos_path, absoluto=$absolute_path" . PHP_EOL, FILE_APPEND);

// Verificar se os diretórios existem
if (!is_dir($pedidos_path) && !is_dir($absolute_path)) {
    // Tentar criar o diretório
    if (!mkdir($pedidos_path, 0755, true)) {
        error_log("Diretórios de pedidos não encontrados e não foi possível criar: $pedidos_path");
        json_response(['success' => false, 'error' => 'Diretório de pedidos não encontrado e não foi possível criar']);
    }
}

// Usar o caminho que existir
$local_path = is_dir($pedidos_path) ? $pedidos_path : $absolute_path;
file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Usando diretório de pedidos: $local_path" . PHP_EOL, FILE_APPEND);

// Verificar se é uma requisição POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Método não permitido.']);
}

// Obter dados da requisição
$json_data = file_get_contents('php://input');
file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Dados recebidos: $json_data" . PHP_EOL, FILE_APPEND);

$data = json_decode($json_data, true);
if (!$data) {
    json_response(['success' => false, 'error' => 'Dados inválidos.']);
}

// Extrair informações
$arquivo = $data['arquivo'] ?? '';
$tipo = $data['tipo'] ?? 'recuperacao';
$dados_pedido = $data['dados'] ?? [];

file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Processando pedido: arquivo=$arquivo, tipo=$tipo" . PHP_EOL, FILE_APPEND);

// Verificar se foram enviados dados do pedido
if (empty($dados_pedido)) {
    json_response(['success' => false, 'error' => 'Dados do pedido não informados.']);
}

// Verificar campos obrigatórios
if (empty($dados_pedido['NOME']) || empty($dados_pedido['CONTATO']) || empty($dados_pedido['ENDEREÇO'])) {
    json_response(['success' => false, 'error' => 'Campos obrigatórios não preenchidos (Nome, Contato e Endereço).']);
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
    file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Verificando caminho: $caminho" . PHP_EOL, FILE_APPEND);
    if (file_exists($caminho)) {
        $arquivo_path = $caminho;
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Arquivo encontrado em: $caminho" . PHP_EOL, FILE_APPEND);
        break;
    }
}

try {
    $pedido = [];
    
    // Se encontrou o arquivo, carregar os dados existentes
    if ($arquivo_path !== null) {
        $file_contents = file_get_contents($arquivo_path);
        if ($file_contents !== false) {
            $pedido = json_decode($file_contents, true);
            if (!$pedido) {
                $pedido = [];
            }
        }
    } else {
        // Se não encontrou, criar um novo arquivo
        $arquivo_base = pathinfo($arquivo, PATHINFO_FILENAME);
        $arquivo_path = $local_path . '/' . $arquivo;
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Criando novo arquivo: $arquivo_path" . PHP_EOL, FILE_APPEND);
    }
    
    // Tempo atual
    $tempo_atual = time();
    
    // Manter informações originais que não foram fornecidas nos novos dados
    $pedido_atualizado = [
        'NOME' => $dados_pedido['NOME'],
        'CONTATO' => $dados_pedido['CONTATO'],
        'ENDEREÇO' => $dados_pedido['ENDEREÇO'],
        'PROBLEMA_RELATADO' => $dados_pedido['PROBLEMA_RELATADO'] ?? $pedido['PROBLEMA_RELATADO'] ?? '',
        'tratamento' => $dados_pedido['tratamento'] ?? $pedido['tratamento'] ?? '1 Mês',
        'origem' => $dados_pedido['origem'] ?? $pedido['origem'] ?? 'whatsapp',
        'Rec' => false, // Marcar como não em recuperação (processado)
        'processado' => true,
        'status' => 'processado',
        'timestamp' => $pedido['timestamp'] ?? date('Y-m-d H:i:s'),
        'criado_em' => $pedido['criado_em'] ?? $tempo_atual,
        'atualizado_em' => $tempo_atual,
        'session_id' => $pedido['session_id'] ?? $dados_pedido['session_id'] ?? session_id(),
        'recuperado_por' => $_SESSION['usuario_id'] ?? 'sistema',
        'processado_recuperacao' => true,
        'data_processamento_recuperacao' => date('Y-m-d H:i:s')
    ];
    
    // Salvar as mudanças no arquivo
    if (!file_put_contents($arquivo_path, json_encode($pedido_atualizado, JSON_PRETTY_PRINT))) {
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Erro ao salvar alterações no arquivo: $arquivo_path" . PHP_EOL, FILE_APPEND);
        throw new Exception('Erro ao salvar as alterações no arquivo do pedido.');
    }
    
    // Remover o pedido do índice para que não apareça mais na lista de recuperação
    removerDoIndice($arquivo);
    
    file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Pedido processado com sucesso" . PHP_EOL, FILE_APPEND);
    
    // Integrar com o Trello - enviar para "PRONTO PARA ENVIO"
    $resultado_trello = integrarComTrelloRecuperacao($pedido_atualizado);
    
    if ($resultado_trello['success']) {
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Card criado no Trello com ID: " . $resultado_trello['card_id'] . PHP_EOL, FILE_APPEND);
        
        // Adicionar o ID do Trello ao pedido
        $pedido_atualizado['id_trello'] = $resultado_trello['card_id'];
        $pedido_atualizado['status_trello'] = 'PRONTO PARA ENVIO';
        
        // Salvar novamente com o ID do Trello
        file_put_contents($arquivo_path, json_encode($pedido_atualizado, JSON_PRETTY_PRINT));
        
        json_response(['success' => true, 'message' => 'Pedido processado e enviado para o Trello com sucesso.', 'trello_id' => $resultado_trello['card_id']]);
    } else {
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Erro ao criar card no Trello: " . $resultado_trello['error'] . PHP_EOL, FILE_APPEND);
        
        // Mesmo com erro no Trello, o pedido foi processado com sucesso
        json_response(['success' => true, 'message' => 'Pedido processado com sucesso, mas houve erro ao enviar para o Trello: ' . $resultado_trello['error']]);
    }
    
    // Atualizar o arquivo original com as informações de processamento
    // Marcar o pedido como processado pela recuperação
    if (!empty($pedido_atualizado) && file_exists($arquivo_path)) {
        $pedido_original = json_decode(file_get_contents($arquivo_path), true);
        if (is_array($pedido_original)) {
            $pedido_original['processado_recuperacao'] = true;
            $pedido_original['data_processamento_recuperacao'] = date('Y-m-d H:i:s');
            file_put_contents($arquivo_path, json_encode($pedido_original, JSON_PRETTY_PRINT));
            
            // Se o arquivo de pedido também existe na pasta normal de pedidos, atualizar lá também
            $arquivo_pedido_normal = str_replace('pedidos_recuperacao', 'pedidos', $arquivo_path);
            if (file_exists($arquivo_pedido_normal)) {
                $pedido_normal = json_decode(file_get_contents($arquivo_pedido_normal), true);
                if (is_array($pedido_normal)) {
                    $pedido_normal['processado_recuperacao'] = true;
                    $pedido_normal['data_processamento_recuperacao'] = date('Y-m-d H:i:s');
                    file_put_contents($arquivo_pedido_normal, json_encode($pedido_normal, JSON_PRETTY_PRINT));
                }
            }
        }
    }
    
} catch (Exception $e) {
    file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Erro ao processar pedido: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    json_response(['success' => false, 'error' => $e->getMessage()]);
}

// Função para atualizar o índice
function atualizarIndice($pedido, $arquivo) {
    $pedidos_path = '../2025/pedidos';
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
            $index_path_alt = $_SERVER['DOCUMENT_ROOT'] . '/2025/pedidos/index.json';
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
        
        // Salvar o índice atualizado
        $resultado = @file_put_contents($index_path, json_encode($index, JSON_PRETTY_PRINT));
        if (!$resultado) {
            $index_path_alt = $_SERVER['DOCUMENT_ROOT'] . '/2025/pedidos/index.json';
            $resultado = @file_put_contents($index_path_alt, json_encode($index, JSON_PRETTY_PRINT));
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

// Função para remover pedido do índice
function removerDoIndice($arquivo) {
    global $local_path, $log_file;
    
    $index_path = $local_path . '/index.json';
    
    try {
        // Verificar se o arquivo de índice existe
        if (!file_exists($index_path)) {
            file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Arquivo de índice não encontrado: $index_path" . PHP_EOL, FILE_APPEND);
            return false;
        }
        
        // Carregar índice existente
        $index_content = file_get_contents($index_path);
        if ($index_content === false) {
            file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Não foi possível ler o arquivo de índice: $index_path" . PHP_EOL, FILE_APPEND);
            return false;
        }
        
        $index = json_decode($index_content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($index)) {
            file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Índice inválido: " . json_last_error_msg() . PHP_EOL, FILE_APPEND);
            return false;
        }
        
        // Remover o arquivo do índice se existir
        $arquivo_nome = basename($arquivo);
        $arquivo_base = pathinfo($arquivo_nome, PATHINFO_FILENAME);
        $novo_index = [];
        
        foreach ($index as $item) {
            // Pular o item que queremos remover
            if (isset($item['arquivo']) && (
                $item['arquivo'] === $arquivo_nome || 
                pathinfo($item['arquivo'], PATHINFO_FILENAME) === $arquivo_base
            )) {
                file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Removendo do índice: " . $item['arquivo'] . PHP_EOL, FILE_APPEND);
                continue;
            }
            $novo_index[] = $item;
        }
        
        // Salvar o índice atualizado
        $resultado = file_put_contents($index_path, json_encode($novo_index, JSON_PRETTY_PRINT));
        if ($resultado === false) {
            file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Erro ao atualizar o arquivo de índice: $index_path" . PHP_EOL, FILE_APPEND);
            return false;
        }
        
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Índice atualizado com sucesso" . PHP_EOL, FILE_APPEND);
        return true;
    } catch (Exception $e) {
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Erro ao remover do índice: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
        return false;
    }
}

// Função para integrar com o Trello (pedidos de recuperação)
function integrarComTrelloRecuperacao($pedido) {
    try {
        // Verificar se as constantes do Trello estão definidas
        if (!defined('TRELLO_API_KEY') || !defined('TRELLO_TOKEN') || !defined('TRELLO_LIST_ID_PRONTO_ENVIO')) {
            throw new Exception('Configurações do Trello não encontradas');
        }
        
        // Formatar o nome do card e descrição conforme solicitado
        $nome = $pedido['NOME'] ?? 'Cliente sem nome';
        $cardName = $nome;
        
        // Obter informações para a descrição
        $endereco = $pedido['ENDEREÇO'] ?? 'Endereço não informado';
        $telefone = $pedido['CONTATO'] ?? 'Telefone não informado';
        $tratamento = $pedido['tratamento'] ?? '1 Mês';
        
        // Obter preço do tratamento
        $preco = '';
        switch ($tratamento) {
            case '1 Mês':
                $preco = '74,98€';
                break;
            case '2 Meses':
                $preco = '119,98€';
                break;
            case '3 Meses':
                $preco = '149,98€';
                break;
            default:
                // Tentar extrair o preço do próprio tratamento se contiver
                if (preg_match('/\(([0-9,.]+)€?\)/', $tratamento, $matches)) {
                    $preco = $matches[1] . '€';
                } else {
                    $preco = '74,98€'; // Valor padrão
                }
        }
        
        // Marcar como recuperação
        $recuperacao = ' (Recuperação)';
        
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
        
        // Criar o card no Trello na lista "PRONTO PARA ENVIO"
        $url = "https://api.trello.com/1/cards?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
        $cardData = [
            'name' => $cardName,
            'desc' => $desc,
            'idList' => TRELLO_LIST_ID_PRONTO_ENVIO,
            'pos' => 'top'
        ];
        
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
            curl_close($ch);
            throw new Exception('Erro ao criar card no Trello: ' . $error);
        }
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            throw new Exception('Erro HTTP ao criar card no Trello: ' . $http_code . ' - ' . $response);
        }
        
        $result = json_decode($response, true);
        if (!$result || !isset($result['id'])) {
            throw new Exception('Erro ao criar card no Trello: resposta inválida do servidor - ' . $response);
        }
        
        return ['success' => true, 'card_id' => $result['id']];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?> 