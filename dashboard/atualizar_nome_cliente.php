<?php
session_start();

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

// Incluir configurações
require_once 'config.php';

// Definir caminho para a pasta de pedidos
$pedidos_path = '/2025/pedidos';
$local_path = '/2025/pedidos'; // Caminho correto para os pedidos

// Função para enviar resposta JSON
function json_response($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Obter dados da requisição
$request_data = json_decode(file_get_contents('php://input'), true);

if (!$request_data || !isset($request_data['arquivo']) || !isset($request_data['nome_novo'])) {
    json_response(['success' => false, 'error' => 'Dados incompletos']);
}

$arquivo = $request_data['arquivo'];
$nome_antigo = $request_data['nome_antigo'] ?? '';
$nome_novo = trim($request_data['nome_novo']);
$fonte = $request_data['fonte'] ?? 'json';

// Verificar se o nome novo não está vazio
if (empty($nome_novo)) {
    json_response(['success' => false, 'error' => 'O nome não pode ser vazio']);
}

try {
    // Atualizar nome com base na fonte (Trello ou JSON)
    if ($fonte === 'trello') {
        // Atualizar no Trello via API
        $cardId = $arquivo;
        
        // Obter informações do card atual
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
        
        // Obter o nome atual para preservar formato (ex: LIGAR DIA XX/XX/XXXX - NOME ou [RASTREIO] - NOME)
        $cardName = $card['name'];
        $newCardName = '';
        
        // Verificar formato do nome do card
        if (strpos($cardName, 'LIGAR DIA') !== false) {
            // Formato: LIGAR DIA XX/XX/XXXX - Nome do Cliente
            $newCardName = preg_replace('/(LIGAR DIA \d{2}\/\d{2}\/\d{4} - ).+/', '$1' . $nome_novo, $cardName);
        } else if (preg_match('/^(?:\[.*?\])?\s*-\s*/', $cardName)) {
            // Formatos: 
            // - [CÓDIGO] - Nome do Cliente
            // - CÓDIGO - Nome do Cliente
            $newCardName = preg_replace('/^((?:\[.*?\])?\s*-\s*).+/', '$1' . $nome_novo, $cardName);
        } else if (preg_match('/^[A-Z0-9]+\s*-\s*/', $cardName)) {
            // Formato: CÓDIGO - Nome do Cliente (sem colchetes)
            $newCardName = preg_replace('/^([A-Z0-9]+\s*-\s*).+/', '$1' . $nome_novo, $cardName);
        } else {
            // Apenas substituir o nome
            $newCardName = $nome_novo;
        }
        
        // Atualizar a descrição se necessário
        $currentDesc = $card['desc'];
        $newDesc = $currentDesc;
        
        // Se tiver a linha "nome: Nome Cliente", atualizar
        if (preg_match('/^nome:.*$/m', $currentDesc)) {
            $newDesc = preg_replace('/^nome:.*$/m', 'nome: ' . $nome_novo, $currentDesc);
        }
        
        // Atualizar card no Trello
        $url_update = "https://api.trello.com/1/cards/{$cardId}?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
        $updateData = [
            'name' => $newCardName,
            'desc' => $newDesc
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
        
        $result = json_decode($response, true);
        if (!$result) {
            throw new Exception('Erro ao processar resposta do Trello após atualização.');
        }
        
        // Registrar alteração no log
        $log_message = date('Y-m-d H:i:s') . " - Nome de cliente alterado no Trello por {$_SESSION['usuario_login']}. Card ID: {$cardId}. De: \"{$nome_antigo}\" Para: \"{$nome_novo}\"\n";
        file_put_contents('../logs/alteracoes.log', $log_message, FILE_APPEND);
        
        json_response(['success' => true, 'message' => 'Nome atualizado com sucesso no Trello']);
    } else {
        // Para arquivos JSON locais, tentamos atualizar diretamente
        $arquivo_path = $local_path . '/' . $arquivo;
        $file_contents = @file_get_contents($arquivo_path);
        
        if ($file_contents === false) {
            // Tentar com caminho relativo à raiz do servidor
            $arquivo_path_alt = $_SERVER['DOCUMENT_ROOT'] . $local_path . '/' . $arquivo;
            $file_contents = @file_get_contents($arquivo_path_alt);
            
            if ($file_contents === false) {
                // Se não conseguir ler o arquivo, registramos no log e informamos o usuário
                $log_message = date('Y-m-d H:i:s') . " - Solicitação de alteração de nome de cliente por {$_SESSION['usuario_login']}. Arquivo: {$arquivo}. De: \"{$nome_antigo}\" Para: \"{$nome_novo}\". Arquivo não encontrado.\n";
                file_put_contents('../logs/alteracoes_pendentes.log', $log_message, FILE_APPEND);
                
                json_response([
                    'success' => true, 
                    'message' => 'Arquivo não encontrado. Alteração registrada e será sincronizada em breve', 
                    'pendente' => true
                ]);
            } else {
                $arquivo_path = $arquivo_path_alt;
            }
        }
        
        // Atualizar o pedido
        $pedido = json_decode($file_contents, true);
        if (!$pedido) {
            throw new Exception('Erro ao decodificar o arquivo JSON do pedido.');
        }
        
        // Atualizar o nome
        $pedido['NOME'] = $nome_novo;
        
        // Tentar salvar as alterações
        $resultado = @file_put_contents($arquivo_path, json_encode($pedido));
        if (!$resultado) {
            // Se não conseguir salvar, registramos no log e informamos o usuário
            $log_message = date('Y-m-d H:i:s') . " - Solicitação de alteração de nome de cliente por {$_SESSION['usuario_login']}. Arquivo: {$arquivo}. De: \"{$nome_antigo}\" Para: \"{$nome_novo}\". Não foi possível salvar as alterações.\n";
            file_put_contents('../logs/alteracoes_pendentes.log', $log_message, FILE_APPEND);
            
            json_response([
                'success' => true, 
                'message' => 'Não foi possível salvar as alterações diretamente. Alteração registrada e será sincronizada em breve', 
                'pendente' => true
            ]);
        }
        
        // Atualizar o índice se o arquivo foi salvo com sucesso
        atualizarIndice($pedido, $arquivo);
        
        // Registrar alteração no log
        $log_message = date('Y-m-d H:i:s') . " - Nome de cliente alterado com sucesso por {$_SESSION['usuario_login']}. Arquivo: {$arquivo}. De: \"{$nome_antigo}\" Para: \"{$nome_novo}\"\n";
        file_put_contents('../logs/alteracoes.log', $log_message, FILE_APPEND);
        
        json_response(['success' => true, 'message' => 'Nome atualizado com sucesso']);
    }
} catch (Exception $e) {
    json_response(['success' => false, 'error' => $e->getMessage()]);
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
                // Atualizar apenas o nome no índice, preservando outros dados
                $index[$key]['NOME'] = $pedido['NOME'];
                $found = true;
                break;
            }
        }
        
        // Se não encontrou, adicionar ao índice (caso raro)
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
?> 