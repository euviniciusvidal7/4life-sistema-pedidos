<?php
// Configurações e verificações de segurança
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Log de diagnóstico para debug
$log_file = __DIR__ . '/debug_log.txt';

// ** INÍCIO DA FUNÇÃO sanitize_filename (GARANTIDA NO INÍCIO E GLOBAL) **
// MOVIDA PARA O INÍCIO DO ARQUIVO PARA GARANTIR QUE ESTEJA SEMPRE DEFINIDA ANTES DE QUALQUER USO.
function sanitize_filename($filename) {
    // Remover caracteres especiais e espaços
    $filename = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $filename);
    
    // Limitar o tamanho do nome do arquivo
    if (strlen($filename) > 100) {
        $filename = substr($filename, 0, 100);
    }
    
    return $filename;
}
// ** FIM DA FUNÇÃO sanitize_filename **

// ** INÍCIO DA ADIÇÃO PARA CAPTURA DE ERROS PHP (Aprimorado) **
// Define um manipulador de erros personalizado para capturar e registrar todos os erros.
set_error_handler(function($errno, $errstr, $errfile, $errline) use ($log_file) {
    // Erros que podem ser ignorados, como Notices, a menos que você queira logar tudo.
    if (!(error_reporting() & $errno)) {
        return false;
    }

    $error_type = "PHP Error";
    switch ($errno) {
        case E_ERROR:             $error_type = "FATAL ERROR"; break;
        case E_WARNING:           $error_type = "WARNING"; break;
        case E_PARSE:             $error_type = "PARSE ERROR"; break;
        case E_NOTICE:            $error_type = "NOTICE"; break;
        case E_CORE_ERROR:        $error_type = "CORE ERROR"; break;
        case E_CORE_WARNING:      $error_type = "CORE WARNING"; break;
        case E_COMPILE_ERROR:     $error_type = "COMPILE ERROR"; break;
        case E_COMPILE_WARNING:   $error_type = "COMPILE WARNING"; break;
        case E_USER_ERROR:        $error_type = "USER ERROR"; break;
        case E_USER_WARNING:      $error_type = "USER WARNING"; break;
        case E_USER_NOTICE:       $error_type = "USER NOTICE"; break;
        case E_STRICT:            $error_type = "STRICT"; break;
        case E_RECOVERABLE_ERROR: $error_type = "RECOVERABLE ERROR"; break;
        case E_DEPRECATED:        $error_type = "DEPRECATED"; break;
        case E_USER_DEPRECATED:   $error_type = "USER DEPRECATED"; break;
    }
    $log_message = date('[Y-m-d H:i:s]') . " [$error_type] $errstr in $errfile on line $errline" . PHP_EOL;
    file_put_contents($log_file, $log_message, FILE_APPEND);
    
    // Para erros fatais ou que impedem a execução, tentamos enviar uma resposta JSON
    if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR])) {
        if (!headers_sent()) { // Só tenta enviar cabeçalhos se não foram enviados
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Erro interno do servidor PHP: ' . $errstr]);
        }
        exit(); // Interrompe a execução
    }
    return false; // Permite que o manipulador de erros padrão do PHP continue (para warnings/notices)
});

// Define um manipulador para erros fatais não capturados por set_error_handler (ex: falta de memória)
register_shutdown_function(function() use ($log_file) {
    $last_error = error_get_last();
    if ($last_error && in_array($last_error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR])) {
        $error_type = "SHUTDOWN FATAL ERROR";
        $log_message = date('[Y-m-d H:i:s]') . " [$error_type] " . $last_error['message'] . " in " . $last_error['file'] . " on line " . $last_error['line'] . PHP_EOL;
        file_put_contents($log_file, $log_message, FILE_APPEND);
        
        if (!headers_sent()) { // Tenta enviar uma resposta JSON se ainda não foi enviada
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Erro fatal no script PHP: ' . $last_error['message']]);
        }
    }
});
// ** FIM DA ADIÇÃO PARA CAPTURA DE ERROS PHP **


$log_data = date('[Y-m-d H:i:s]') . ' Requisição recebida: ' . $_SERVER['REQUEST_METHOD'] . PHP_EOL;
file_put_contents($log_file, $log_data, FILE_APPEND);

// Lidar com solicitações OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verificar se a solicitação é do tipo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Método não permitido']);
    exit;
}

// Obter o corpo da solicitação
$json_data = file_get_contents('php://input');
file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Dados recebidos: ' . $json_data . PHP_EOL, FILE_APPEND);

// Decodificar o JSON
$request_data = json_decode($json_data, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'JSON inválido: ' . json_last_error_msg()]);
    exit;
}

// Diretório para os pedidos
$pedidos_dir = __DIR__ . '/pedidos';

// Verificar se o diretório existe, senão criar
if (!file_exists($pedidos_dir)) {
    if (!mkdir($pedidos_dir, 0755, true)) {
        http_response_code(500); // Internal Server Error
        echo json_encode(['status' => 'error', 'message' => 'Não foi possível criar o diretório de pedidos']);
        exit;
    }
}

// ** INÍCIO DA ADIÇÃO PARA N8N (Integração em Tempo Real) **

// URL do seu Webhook do n8n. Este é o URL que você pegou do nó Webhook no seu workflow do n8n.
$n8n_webhook_url = 'https://webhook.4lifenutrition.site/webhook/suplabase-dados-chat'; 

// Função para enviar dados JSON para o webhook do n8n via POST.
// Retorna true em caso de sucesso (status HTTP 2xx) ou false em caso de erro/alerta.
function send_to_n8n_webhook($data_to_send, $webhook_url, $log_file) {
    // Verifica se a extensão cURL está habilitada no PHP
    if (!function_exists('curl_init')) {
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' ERRO: Extensão PHP cURL não está habilitada. Não foi possível enviar para n8n.' . PHP_EOL, FILE_APPEND);
        return false;
    }

    $payload = json_encode($data_to_send, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); // Melhora a formatação do JSON
    
    $ch = curl_init($webhook_url); // Inicializa a sessão cURL
    curl_setopt($ch, CURLOPT_POST, 1); // Define como requisição POST
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); // Define os dados a serem enviados
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); // Define o cabeçalho como JSON
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Retorna a resposta como string
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Define um timeout de 10 segundos para a requisição
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Define um timeout de 5 segundos para a conexão

    $response = curl_exec($ch); // Executa a requisição cURL
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Obtém o código de status HTTP da resposta

    if (curl_errno($ch)) {
        // Registra erro se a requisição cURL falhar
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' ERRO cURL ao enviar para n8n: ' . curl_error($ch) . PHP_EOL, FILE_APPEND);
        curl_close($ch);
        return false;
    } else {
        // Registra sucesso e a resposta do webhook do n8n
        if ($http_code >= 200 && $http_code < 300) {
            file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' SUCESSO ao enviar para n8n. Status: ' . $http_code . '. Resposta: ' . $response . PHP_EOL, FILE_APPEND);
            curl_close($ch);
            return true;
        } else {
            // Se o status HTTP não for de sucesso (2xx), registre como alerta
            file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' ALERTA: n8n retornou Status HTTP: ' . $http_code . '. Resposta: ' . $response . PHP_EOL, FILE_APPEND);
            curl_close($ch);
            return false;
        }
    }
}

// Função para enviar dados para o Supabase
function send_to_supabase($data_to_send, $log_file) {
    $supabase_url = 'https://hjciadghbgnijcgwsuyh.supabase.co/functions/v1/sync-pedidos';
    
    if (!function_exists('curl_init')) {
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' ERRO: cURL não habilitado para Supabase.' . PHP_EOL, FILE_APPEND);
        return false;
    }

    $payload = json_encode($data_to_send, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($supabase_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' ERRO cURL Supabase: ' . curl_error($ch) . PHP_EOL, FILE_APPEND);
        curl_close($ch);
        return false;
    } else {
        if ($http_code >= 200 && $http_code < 300) {
            file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' SUCESSO Supabase. Status: ' . $http_code . '. Resposta: ' . $response . PHP_EOL, FILE_APPEND);
            curl_close($ch);
            return true;
        } else {
            file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' ERRO Supabase Status: ' . $http_code . '. Resposta: ' . $response . PHP_EOL, FILE_APPEND);
            curl_close($ch);
            return false;
        }
    }
}

// ** FIM DA ADIÇÃO PARA N8N **


// Função para atualizar o index.json (mantida como está, sem chamar o n8n para evitar duplicidade de chamadas)
function atualizar_index_json($pedido) {
    global $pedidos_dir, $log_file;
    global $request_data; // Usar global para acessar $request_data aqui
    
    $index_path = $pedidos_dir . '/index.json';
    
    // Gerar um ID único para o pedido se não existir
    if (!isset($pedido['pedido_id'])) {
        $pedido['pedido_id'] = uniqid('pedido_', true);
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' ID único gerado para o pedido: ' . $pedido['pedido_id'] . PHP_EOL, FILE_APPEND);
    }
    
    // Adicionar nome do arquivo ao pedido para facilitar a remoção
    if (!isset($pedido['arquivo']) && isset($pedido['session_id'])) {
        $pedido['arquivo'] = sanitize_filename($pedido['session_id']) . '.json';
    }
    
    // Rastrear marcos de progresso do lead
    if (!isset($pedido['marcos_progresso'])) {
        $pedido['marcos_progresso'] = [];
    }
    
    // Adicionar novos marcos de progresso se forem enviados
    if (isset($request_data['marcos_progresso']) && is_array($request_data['marcos_progresso'])) {
        foreach ($request_data['marcos_progresso'] as $marco) {
            if (!in_array($marco, $pedido['marcos_progresso'])) {
                $pedido['marcos_progresso'][] = $marco;
            }
        }
    }
    
    // Verificar e adicionar marcos de progresso baseados nos dados existentes
    if (!empty($pedido['NOME']) && !in_array('nome', $pedido['marcos_progresso'])) {
        $pedido['marcos_progresso'][] = 'nome';
    }
    
    if (!empty($pedido['CONTATO']) && !in_array('telefone', $pedido['marcos_progresso'])) {
        $pedido['marcos_progresso'][] = 'telefone';
    }
    
    if (!empty($pedido['PROBLEMA_RELATADO']) && !in_array('problema', $pedido['marcos_progresso'])) {
        $pedido['marcos_progresso'][] = 'problema';
    }
    
    // Verificar se o lead passou pela etapa "Está bem" (etapa de entrega)
    if (isset($pedido['etapa_atual']) && $pedido['etapa_atual'] == 'apresentacao' && !in_array('entrega', $pedido['marcos_progresso'])) {
        $pedido['marcos_progresso'][] = 'entrega';
    }
    
    // Verificar se o lead fez perguntas para a IA
    if (!empty($pedido['PERGUNTAS_FEITAS']) && is_array($pedido['PERGUNTAS_FEITAS']) && count($pedido['PERGUNTAS_FEITAS']) > 0 && !in_array('perguntas', $pedido['marcos_progresso'])) {
        $pedido['marcos_progresso'][] = 'perguntas';
    }
    
    // Adicionar timestamps para cada marco de progresso
    if (!isset($pedido['marcos_timestamp'])) {
        $pedido['marcos_timestamp'] = [];
    }
    
    foreach ($pedido['marcos_progresso'] as $marco) {
        if (!isset($pedido['marcos_timestamp'][$marco])) {
            $pedido['marcos_timestamp'][$marco] = date('Y-m-d H:i:s');
        }
    }
    
    // Log dos dados básicos do pedido para diagnóstico
    $log_info = '';
    if (isset($pedido['NOME'])) $log_info .= ' Nome: ' . $pedido['NOME'];
    if (isset($pedido['CONTATO'])) $log_info .= ' | Contato: ' . $pedido['CONTATO'];
    if (isset($pedido['Rec'])) $log_info .= ' | Rec: ' . ($pedido['Rec'] ? 'true' : 'false');
    if (isset($pedido['finalizado'])) $log_info .= ' | Finalizado: ' . ($pedido['finalizado'] ? 'true' : 'false');
    if (isset($pedido['marcos_progresso'])) $log_info .= ' | Marcos: ' . implode(', ', $pedido['marcos_progresso']);
    
    file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Tentando atualizar index.json com pedido -' . $log_info . PHP_EOL, FILE_APPEND);
    
    // Carregar o índice existente ou criar um novo array se não existir
    if (file_exists($index_path)) {
        $json_content = file_get_contents($index_path);
        $index = json_decode($json_content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Se o arquivo existe mas não é um JSON válido, criar novo array
            $index = [];
            file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Index.json inválido, criando novo' . PHP_EOL, FILE_APPEND);
        }
    } else {
        // Se o arquivo não existe, criar um novo array
        $index = [];
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Index.json não encontrado, criando novo' . PHP_EOL, FILE_APPEND);
    }
    
    // Verificar se o pedido já existe no índice pelo ID único, ID de sessão ou por conteúdo similar
    $found = false;
    $found_key = null;
    
    foreach ($index as $key => $item) {
        // Verificar por pedido_id (prioridade máxima)
        if (isset($item['pedido_id']) && isset($pedido['pedido_id']) && $item['pedido_id'] === $pedido['pedido_id']) {
            $found = true;
            $found_key = $key;
            break;
        }
        
        // Verificar por session_id
        if (isset($item['session_id']) && isset($pedido['session_id']) && $item['session_id'] === $pedido['session_id']) {
            $found = true;
            $found_key = $key;
            break;
        }
        
        // Verificar por conteúdo similar (mesmos dados principais)
        if (isset($item['NOME']) && isset($pedido['NOME']) && 
            isset($item['ENDEREÇO']) && isset($pedido['ENDEREÇO']) && 
            isset($item['CONTATO']) && isset($pedido['CONTATO']) && 
            isset($item['DATA']) && isset($pedido['DATA'])) {
            
            if ($item['NOME'] === $pedido['NOME'] && 
                $item['ENDEREÇO'] === $pedido['ENDEREÇO'] && 
                $item['CONTATO'] === $pedido['CONTATO'] && 
                $item['DATA'] === $pedido['DATA']) {
                
                $found = true;
                $found_key = $key;
                break;
            }
        }
    }
    
    // Se encontrado, atualizar o item existente
    if ($found && $found_key !== null) {
        // Preservar marcos de progresso existentes e adicionar novos
        if (isset($index[$found_key]['marcos_progresso']) && is_array($index[$found_key]['marcos_progresso'])) {
            foreach ($index[$found_key]['marcos_progresso'] as $marco) {
                if (!in_array($marco, $pedido['marcos_progresso'])) {
                    $pedido['marcos_progresso'][] = $marco;
                }
            }
        }
        
        // Preservar timestamps de marcos existentes
        if (isset($index[$found_key]['marcos_timestamp']) && is_array($index[$found_key]['marcos_timestamp'])) {
            foreach ($index[$found_key]['marcos_timestamp'] as $marco => $timestamp) {
                if (!isset($pedido['marcos_timestamp'][$marco])) {
                    $pedido['marcos_timestamp'][$marco] = $timestamp;
                }
            }
        }
        
        $index[$found_key] = $pedido;
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Pedido existente atualizado no index.json' . PHP_EOL, FILE_APPEND);
    } else {
        // Se não for encontrado, adicionar ao índice
        $index[] = $pedido;
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Novo pedido adicionado ao index.json' . PHP_EOL, FILE_APPEND);
    }
    
    // Salvar o índice atualizado
    // Usando FILE_APPEND | LOCK_EX para garantir bloqueio exclusivo.
    if (file_put_contents($index_path, json_encode($index, JSON_PRETTY_PRINT), LOCK_EX)) {
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Index.json atualizado com sucesso' . PHP_EOL, FILE_APPEND);
        return $pedido['pedido_id']; // Retornar o ID do pedido em vez de true
    } else {
        file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Erro ao atualizar index.json' . PHP_EOL, FILE_APPEND);
        return false;
    }
}

// Processar diferentes tipos de ações
$action = isset($request_data['action']) ? $request_data['action'] : 'unknown';

try { // ** Bloco try para capturar exceções durante o processamento da ação **
    switch ($action) {
        case 'iniciar_pedido':
            // Iniciar um novo pedido temporário
            if (!isset($request_data['session_id'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'ID de sessão é obrigatório']);
                exit;
            }
            
            // Criar dados iniciais do pedido
            $pedido_data = [
                'session_id' => $request_data['session_id'],
                'origem' => isset($request_data['origem']) ? $request_data['origem'] : 'desconhecida',
                'versao' => isset($request_data['versao']) ? $request_data['versao'] : 'v1-main', // Identificador único de versão
                'timestamp' => date('Y-m-d H:i:s'),
                'criado_em' => time(), // Adicionar timestamp Unix para controle de tempo de abandono
                'Rec' => true, // Marcar como em recuperação
                'etapa_atual' => 'inicio'
            ];
            
            // Criar nome do arquivo baseado no ID da sessão
            $filename = sanitize_filename($request_data['session_id']) . '.json';
            $file_path = $pedidos_dir . '/' . $filename;

            // Salvar o arquivo
            // Usando LOCK_EX para garantir bloqueio exclusivo durante a escrita.
            if (file_put_contents($file_path, json_encode($pedido_data, JSON_PRETTY_PRINT), LOCK_EX)) {
                file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Pedido temporário iniciado: ' . $filename . PHP_EOL, FILE_APPEND);
                echo json_encode(['status' => 'success', 'message' => 'Pedido temporário iniciado com sucesso', 'session_id' => $request_data['session_id']]);

                // ** CHAMA O WEBHOOK DO N8N AQUI **
                send_to_n8n_webhook($pedido_data, $n8n_webhook_url, $log_file);
                // NOVO: Enviar para Supabase também
                send_to_supabase($pedido_data, $log_file);

            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Não foi possível salvar o arquivo do pedido']);
            }
            break;
        
        case 'create':
            // Criar um novo pedido temporário
            if (!isset($request_data['data']) || !is_array($request_data['data'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Dados de pedido ausentes ou inválidos']);
                exit;
            }

            // Garantir que temos pelo menos o ID da sessão
            if (!isset($request_data['data']['session_id'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'ID de sessão é obrigatório']);
                exit;
            }
            
            // Adicionar timestamp de criação se não existir
            if (!isset($request_data['data']['timestamp'])) {
                $request_data['data']['timestamp'] = date('Y-m-d H:i:s');
            }
            
            // Adicionar timestamp Unix para controle de tempo de abandono
            if (!isset($request_data['data']['criado_em'])) {
                $request_data['data']['criado_em'] = time();
            }
            
            // Definir como em recuperação por padrão
            if (!isset($request_data['data']['Rec'])) {
                $request_data['data']['Rec'] = true;
            }
            
            // Criar nome do arquivo baseado no ID da sessão
            $filename = sanitize_filename($request_data['data']['session_id']) . '.json';
            $file_path = $pedidos_dir . '/' . $filename;
            
            // Salvar o arquivo
            // Usando LOCK_EX para garantir bloqueio exclusivo durante a escrita.
            if (file_put_contents($file_path, json_encode($request_data['data'], JSON_PRETTY_PRINT), LOCK_EX)) {
                file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Pedido criado: ' . $filename . PHP_EOL, FILE_APPEND);
                echo json_encode(['status' => 'success', 'message' => 'Pedido temporário criado com sucesso', 'file' => $filename]);
                
                // ** CHAMA O WEBHOOK DO N8N AQUI **
                send_to_n8n_webhook($request_data['data'], $n8n_webhook_url, $log_file);
                // NOVO: Enviar para Supabase também
                send_to_supabase($request_data['data'], $log_file);

            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Não foi possível salvar o arquivo do pedido']);
            }
            break;
        
        case 'update':
            // Atualizar um pedido existente
            if (!isset($request_data['data']) || !is_array($request_data['data'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Dados de atualização ausentes ou inválidos']);
                exit;
            }
            
            // Verificar ID de sessão
            if (!isset($request_data['data']['session_id'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'ID de sessão é obrigatório para atualização']);
                exit;
            }
            
            // Localizar o arquivo
            $filename = sanitize_filename($request_data['data']['session_id']) . '.json';
            $file_path = $pedidos_dir . '/' . $filename;
            
            if (!file_exists($file_path)) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Pedido não encontrado']);
                exit;
            }
            
            // Ler o arquivo existente
            // Usando LOCK_SH para bloqueio compartilhado durante a leitura.
            $existing_data_json = file_get_contents($file_path, false, null, 0, filesize($file_path));
            if ($existing_data_json === false) { // Verifica se houve erro na leitura
                 file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' ERRO: Falha ao ler arquivo: ' . $file_path . PHP_EOL, FILE_APPEND);
                 http_response_code(500);
                 echo json_encode(['status' => 'error', 'message' => 'Não foi possível ler o arquivo existente.']);
                 exit;
            }
            $existing_data = json_decode($existing_data_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Erro ao ler o arquivo existente: ' . json_last_error_msg()]);
                exit;
            }
            
            // Mesclar os dados existentes com os novos dados
            $updated_data = array_merge($existing_data, $request_data['data']);
            
            // Atualizar o timestamp da última modificação
            $updated_data['ultima_atualizacao'] = date('Y-m-d H:i:s'); 
            
            // Verificar se o pedido está sendo finalizado (Rec=false ou step=8)
            $sendo_finalizado = (isset($request_data['data']['Rec']) && 
                                $request_data['data']['Rec'] === false && 
                                (!isset($existing_data['Rec']) || $existing_data['Rec'] === true)) ||
                                (isset($request_data['data']['step']) && 
                                $request_data['data']['step'] === 8 && 
                                (!isset($existing_data['step']) || $existing_data['step'] < 8));
            
            // Se estiver sendo finalizado através do step=8 e Rec não tiver sido definido explicitamente, definir como false
            if ($sendo_finalizado && isset($request_data['data']['step']) && $request_data['data']['step'] === 8 && !isset($request_data['data']['Rec'])) {
                $updated_data['Rec'] = false;
                file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Pedido marcado como não recuperável automaticamente por step=8: ' . $filename . PHP_EOL, FILE_APPEND);
            }
            
            // Se estiver sendo finalizado, adicionar campos extras
            if ($sendo_finalizado) {
                $updated_data['finalizado'] = true;
                $updated_data['data_finalizacao'] = date('Y-m-d H:i:s');
                file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Pedido está sendo finalizado: ' . $filename . PHP_EOL, FILE_APPEND);
            }
            
            // Salvar o arquivo atualizado
            // Usando LOCK_EX para garantir bloqueio exclusivo durante a escrita.
            if (file_put_contents($file_path, json_encode($updated_data, JSON_PRETTY_PRINT), LOCK_EX)) {
                file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Pedido atualizado: ' . $filename . PHP_EOL, FILE_APPEND);
                
                // Se o pedido está sendo finalizado, atualizar index.json
                if ($sendo_finalizado) {
                    // ... (lógica existente para index.json) ...
                }
                
                echo json_encode(['status' => 'success', 'message' => 'Pedido atualizado com sucesso', 'file' => $filename]);

                // ** CHAMA O WEBHOOK DO N8N AQUI **
                send_to_n8n_webhook($updated_data, $n8n_webhook_url, $log_file);
                // NOVO: Enviar para Supabase também
                send_to_supabase($updated_data, $log_file);

            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Não foi possível atualizar o arquivo do pedido']);
            }
            break;
            
        case 'retrieve':
            // Recuperar dados de um pedido existente
            if (!isset($request_data['session_id'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'ID de sessão é obrigatório para recuperação']);
                exit;
            }
            
            // Localizar o arquivo
            $filename = sanitize_filename($request_data['session_id']) . '.json';
            $file_path = $pedidos_dir . '/' . $filename;
            
            if (!file_exists($file_path)) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Pedido não encontrado']);
                exit;
            }
            
            // Ler o arquivo
            // Usando LOCK_SH para bloqueio compartilhado durante a leitura.
            $pedido_data_json = file_get_contents($file_path, false, null, 0, filesize($file_path));
            if ($pedido_data_json === false) { // Verifica se houve erro na leitura
                 file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' ERRO: Falha ao ler arquivo: ' . $file_path . PHP_EOL, FILE_APPEND);
                 http_response_code(500);
                 echo json_encode(['status' => 'error', 'message' => 'Não foi possível ler o arquivo do pedido.']);
                 exit;
            }
            $pedido_data = json_decode($pedido_data_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Erro ao ler o arquivo do pedido: ' . json_last_error_msg()]);
                exit;
            }
            
            // Retornar os dados
            echo json_encode(['status' => 'success', 'data' => $pedido_data]);
            break;
            
        case 'atualizar_pedido': // Similar to update, but receives data at the root level (from n8n chatbot)
            // No seu log do n8n, a requisição do chatbot para o PHP tinha os dados no ROOT:
            // { "action": "atualizar_pedido", "session_id": "...", "step": 2, "CONTATO": "..." }
            
            if (!isset($request_data['session_id'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'ID de sessão é obrigatório para atualização']);
                exit;
            }
            
            // Remover o campo session_id e action do array para evitar duplicação ou conflitos na mesclagem final
            $session_id_from_request = $request_data['session_id']; // Captura o session_id antes de unset
            // Remove a action e session_id do request_data, pois já foram processados
            unset($request_data['session_id']);
            unset($request_data['action']); 
            
            // Localizar o arquivo
            $filename = sanitize_filename($session_id_from_request) . '.json';
            $file_path = $pedidos_dir . '/' . $filename;
            
            if (!file_exists($file_path)) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Pedido não encontrado']);
                exit;
            }
            
            // Ler o arquivo existente
            // Usando LOCK_SH para bloqueio compartilhado durante a leitura.
            $existing_data_json = file_get_contents($file_path, false, null, 0, filesize($file_path));
            if ($existing_data_json === false) { // Verifica se houve erro na leitura
                 file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' ERRO: Falha ao ler arquivo: ' . $file_path . PHP_EOL, FILE_APPEND);
                 http_response_code(500);
                 echo json_encode(['status' => 'error', 'message' => 'Não foi possível ler o arquivo existente.']);
                 exit;
            }
            $existing_data = json_decode($existing_data_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Erro ao ler o arquivo existente: ' . json_last_error_msg()]);
                exit;
            }
            
            // Garantir que o campo criado_em existe (se não existir no existing_data)
            if (!isset($existing_data['criado_em'])) {
                $existing_data['criado_em'] = time();
            }
            
            // Processar marcos de progresso
            // A lógica aqui é mais complexa: mesclar marcos_progresso existentes com os novos.
            if (isset($request_data['marcos_progresso']) && is_array($request_data['marcos_progresso'])) {
                if (!isset($existing_data['marcos_progresso']) || !is_array($existing_data['marcos_progresso'])) {
                    $existing_data['marcos_progresso'] = []; // Inicializa se não existir
                }
                
                // Adicionar novos marcos sem duplicar
                foreach ($request_data['marcos_progresso'] as $marco) {
                    if (!in_array($marco, $existing_data['marcos_progresso'])) {
                        $existing_data['marcos_progresso'][] = $marco;
                    }
                }
                // Remover do request_data para evitar que sobrescreva incorretamente na mesclagem principal
                unset($request_data['marcos_progresso']);
            }
            
            // Mesclar os dados existentes com os novos dados recebidos do chatbot.
            // Os dados restantes em $request_data (após unset de session_id, action e marcos_progresso)
            // são os dados atualizados que o chatbot enviou.
            $updated_data = array_merge($existing_data, $request_data);
            
            // Certifica-se de que marcos_progresso (agora mesclados) estejam no $updated_data final
            // Esta linha é CRÍTICA, pois o unset acima removeu de request_data.
            if (isset($existing_data['marcos_progresso'])) {
                $updated_data['marcos_progresso'] = $existing_data['marcos_progresso'];
            }
            
            // Atualizar o timestamp da última modificação
            // Use o valor vindo do request_data se existir, senão use o atual.
            if (isset($request_data['ultima_atualizacao'])) {
                $updated_data['ultima_atualizacao'] = $request_data['ultima_atualizacao'];
            } else {
                $updated_data['ultima_atualizacao'] = date('Y-m-d H:i:s');
            }
            
            // Verificar se o pedido está sendo finalizado (Rec=false ou step=8)
            $sendo_finalizado = (isset($request_data['Rec']) && 
                                $request_data['Rec'] === false && 
                                (!isset($existing_data['Rec']) || $existing_data['Rec'] === true)) ||
                                (isset($request_data['step']) && 
                                $request_data['step'] === 8 && 
                                (!isset($existing_data['step']) || $existing_data['step'] < 8));
            
            // Se estiver sendo finalizado através do step=8 e Rec não tiver sido definido explicitamente, definir como false
            if ($sendo_finalizado && isset($request_data['step']) && $request_data['step'] === 8 && !isset($request_data['Rec'])) {
                $updated_data['Rec'] = false;
                file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Pedido marcado como não recuperável automaticamente por step=8: ' . $filename . PHP_EOL, FILE_APPEND);
            }
            
            // Se estiver sendo finalizado, adicionar campos extras
            if ($sendo_finalizado) {
                $updated_data['finalizado'] = true;
                $updated_data['data_finalizacao'] = date('Y-m-d H:i:s');
                file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Pedido está sendo finalizado (via atualizar_pedido): ' . $filename . PHP_EOL, FILE_APPEND);
            }
            
            // Salvar o arquivo atualizado
            // Usando LOCK_EX para garantir bloqueio exclusivo durante a escrita.
            if (file_put_contents($file_path, json_encode($updated_data, JSON_PRETTY_PRINT), LOCK_EX)) {
                file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Pedido atualizado: ' . $filename . PHP_EOL, FILE_APPEND);
                
                // Se o pedido está sendo finalizado, atualizar index.json
                if ($sendo_finalizado) {
                    // Lógica para atualizar index.json (existente no seu código)
                    $index_path = $pedidos_dir . '/index.json';
                    $already_in_index = false;
                    
                    if (file_exists($index_path)) {
                        $index_content = file_get_contents($index_path);
                        $index = json_decode($index_content, true);
                        
                        if (json_last_error() === JSON_ERROR_NONE && is_array($index)) {
                            foreach ($index as $item) {
                                if (isset($item['NOME']) && isset($updated_data['NOME']) && 
                                    isset($item['ENDEREÇO']) && isset($updated_data['ENDEREÇO']) && 
                                    isset($item['CONTATO']) && isset($updated_data['CONTATO'])) {
                                    
                                    if ($item['NOME'] === $updated_data['NOME'] && 
                                        $item['ENDEREÇO'] === $updated_data['ENDEREÇO'] && 
                                        $item['CONTATO'] === $updated_data['CONTATO']) {
                                        
                                        $already_in_index = true;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                    
                    if (!$already_in_index) {
                        $success = atualizar_index_json($updated_data);
                        
                        if ($success) {
                            file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Index.json atualizado com o pedido finalizado (via atualizar_pedido)' . PHP_EOL, FILE_APPEND);
                        } else {
                            file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Falha ao atualizar Index.json com o pedido finalizado (via atualizar_pedido)' . PHP_EOL, FILE_APPEND);
                        }
                    } else {
                        file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Pedido já existe no index.json, não foi adicionado novamente (via atualizar_pedido)' . PHP_EOL, FILE_APPEND);
                    }
                }
                
                echo json_encode(['status' => 'success', 'message' => 'Pedido atualizado com sucesso']);

                // ** CHAMA O WEBHOOK DO N8N AQUI **
                send_to_n8n_webhook($updated_data, $n8n_webhook_url, $log_file);
                // NOVO: Enviar para Supabase também
                send_to_supabase($updated_data, $log_file);

            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Não foi possível atualizar o arquivo do pedido']);
            }
            break;
            
        case 'finalizar_pedido':
            // Lógica existente para finalizar_pedido.
            if (!isset($request_data['session_id'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'ID de sessão é obrigatório para finalização']);
                exit;
            }
            
            // Remover o campo session_id do array para evitar duplicação nos dados
            $session_id = $request_data['session_id'];
            unset($request_data['session_id']);
            unset($request_data['action']);
            
            // Localizar o arquivo
            $filename = sanitize_filename($session_id) . '.json';
            $file_path = $pedidos_dir . '/' . $filename;
            
            if (!file_exists($file_path)) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Pedido não encontrado']);
                exit;
            }
            
            // Ler o arquivo existente
            // Usando LOCK_SH para bloqueio compartilhado durante a leitura.
            $existing_data_json = file_get_contents($file_path, false, null, 0, filesize($file_path));
            if ($existing_data_json === false) { // Verifica se houve erro na leitura
                 file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' ERRO: Falha ao ler arquivo: ' . $file_path . PHP_EOL, FILE_APPEND);
                 http_response_code(500);
                 echo json_encode(['status' => 'error', 'message' => 'Não foi possível ler o arquivo existente.']);
                 exit;
            }
            $existing_data = json_decode($existing_data_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Erro ao ler o arquivo existente: ' . json_last_error_msg()]);
                exit;
            }
            
            // Processar marcos de progresso especialmente para não perder os existentes
            if (isset($request_data['marcos_progresso']) && is_array($request_data['marcos_progresso'])) {
                if (!isset($existing_data['marcos_progresso']) || !is_array($existing_data['marcos_progresso'])) {
                    $existing_data['marcos_progresso'] = [];
                }
                
                // Adicionar novos marcos sem duplicar
                foreach ($request_data['marcos_progresso'] as $marco) {
                    if (!in_array($marco, $existing_data['marcos_progresso'])) {
                        $existing_data['marcos_progresso'][] = $marco;
                    }
                }
                
                // Remover do request_data para evitar que sobrescreva na mesclagem
                unset($request_data['marcos_progresso']);
            }
            
            // Mesclar os dados existentes com os novos dados
            $updated_data = array_merge($existing_data, $request_data);
            
            // Garantir que os marcos de progresso estão presentes no resultado final
            if (isset($existing_data['marcos_progresso'])) {
                $updated_data['marcos_progresso'] = $existing_data['marcos_progresso'];
            }
            
            // Marcar como não recuperável
            $updated_data['Rec'] = false;
            $updated_data['finalizado'] = true;
            $updated_data['data_finalizacao'] = date('Y-m-d H:i:s');
            
            // Verificar se o pedido já foi adicionado ao index.json
            $index_path = $pedidos_dir . '/index.json';
            $already_in_index = false;
            
            if (file_exists($index_path)) {
                $index_content = file_get_contents($index_path);
                $index = json_decode($index_content, true);
                
                if (json_last_error() === JSON_ERROR_NONE && is_array($index)) {
                    foreach ($index as $item) {
                        // Verificar se já existe um pedido com os mesmos dados principais
                        if (isset($item['NOME']) && isset($updated_data['NOME']) && 
                            isset($item['ENDEREÇO']) && isset($updated_data['ENDEREÇO']) && 
                            isset($item['CONTATO']) && isset($updated_data['CONTATO'])) {
                            
                            if ($item['NOME'] === $updated_data['NOME'] && 
                                $item['ENDEREÇO'] === $updated_data['ENDEREÇO'] && 
                                $item['CONTATO'] === $updated_data['CONTATO']) {
                                
                                $already_in_index = true;
                                break;
                            }
                        }
                    }
                }
            }
            
            // Salvar o arquivo atualizado
            // Usando LOCK_EX para garantir bloqueio exclusivo durante a escrita.
            if (file_put_contents($file_path, json_encode($updated_data, JSON_PRETTY_PRINT), LOCK_EX)) {
                file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Pedido finalizado: ' . $filename . PHP_EOL, FILE_APPEND);
                
                // Atualizar o index.json com o pedido finalizado apenas se ainda não estiver no índice
                if (!$already_in_index) {
                    $success = atualizar_index_json($updated_data);
                    
                    if ($success) {
                        file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Index.json atualizado com o pedido finalizado' . PHP_EOL, FILE_APPEND);
                    } else {
                        file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Falha ao atualizar Index.json com o pedido finalizado' . PHP_EOL, FILE_APPEND);
                    }
                } else {
                    file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Pedido já existe no index.json, não foi adicionado novamente' . PHP_EOL, FILE_APPEND);
                }
                
                echo json_encode(['status' => 'success', 'message' => 'Pedido finalizado com sucesso']);

                // ** CHAMA O WEBHOOK DO N8N AQUI **
                send_to_n8n_webhook($updated_data, $n8n_webhook_url, $log_file);
                // NOVO: Enviar para Supabase também
                send_to_supabase($updated_data, $log_file);

            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Não foi possível atualizar o arquivo do pedido']);
            }
            break;
            
        case 'remover_pedido': 
            // ... (lógica existente para remover_pedido) ...
            $filename = '';
            $pedido_id = isset($request_data['pedido_id']) ? $request_data['pedido_id'] : null;
            
            if (isset($request_data['arquivo'])) {
                $filename = sanitize_filename($request_data['arquivo']);
            } elseif (isset($request_data['session_id'])) {
                $filename = sanitize_filename($request_data['session_id']) . '.json';
            } else if ($pedido_id === null) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Nome do arquivo, ID de pedido ou ID de sessão é obrigatório para remoção']);
                exit;
            }
            
            // Se temos um pedido_id, precisamos encontrar o arquivo correspondente
            if ($pedido_id !== null && empty($filename)) {
                $index_path = $pedidos_dir . '/index.json';
                if (file_exists($index_path)) {
                    $index_content = file_get_contents($index_path);
                    $index = json_decode($index_content, true);
                    
                    if (json_last_error() === JSON_ERROR_NONE && is_array($index)) {
                        foreach ($index as $item) {
                            if (isset($item['pedido_id']) && $item['pedido_id'] === $pedido_id) {
                                if (isset($item['arquivo'])) {
                                    $filename = $item['arquivo'];
                                    file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Arquivo encontrado para pedido_id: ' . $pedido_id . ' - ' . $filename . PHP_EOL, FILE_APPEND);
                                    break;
                                } else if (isset($item['session_id'])) {
                                    $filename = sanitize_filename($item['session_id']) . '.json';
                                    file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Arquivo derivado de session_id para pedido_id: ' . $pedido_id . ' - ' . $filename . PHP_EOL, FILE_APPEND);
                                    break;
                                }
                            }
                        }
                    }
                }
                
                // Se ainda não encontramos o arquivo, retornar erro
                if (empty($filename)) {
                    file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Não foi possível encontrar o arquivo para o pedido_id: ' . $pedido_id . PHP_EOL, FILE_APPEND);
                }
            }
            
            $file_path = $pedidos_dir . '/' . $filename;
            
            // Verificar se o arquivo existe
            if (!empty($filename) && !file_exists($file_path)) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Arquivo do pedido não encontrado: ' . $filename]);
                exit;
            }
            
            // Ler os dados do pedido antes de excluir (para remover do index)
            $pedido_data = null;
            $session_id = null;
            
            if (!empty($filename)) {
                try {
                    // Usando LOCK_SH para bloqueio compartilhado durante a leitura.
                    $pedido_content = file_get_contents($file_path, false, null, 0, filesize($file_path));
                     if ($pedido_content === false) { // Verifica se houve erro na leitura
                        file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' ERRO: Falha ao ler arquivo: ' . $file_path . ' antes da remocao.' . PHP_EOL, FILE_APPEND);
                        throw new Exception('Falha ao ler arquivo antes da remocao.');
                    }
                    $pedido_data = json_decode($pedido_content, true);
                    
                    if (json_last_error() === JSON_ERROR_NONE) {
                        if (isset($pedido_data['session_id'])) {
                            $session_id = $pedido_data['session_id'];
                        }
                        if (!$pedido_id && isset($pedido_data['pedido_id'])) {
                            $pedido_id = $pedido_data['pedido_id'];
                        }
                    }
                } catch (Exception $e) {
                    file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Erro ao ler arquivo antes da remoção: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
                }
                
                // Excluir o arquivo do pedido
                if (unlink($file_path)) {
                    file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Arquivo de pedido removido: ' . $filename . PHP_EOL, FILE_APPEND);
                } else {
                    file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Falha ao remover arquivo: ' . $filename . PHP_EOL, FILE_APPEND);
                    http_response_code(500);
                    echo json_encode(['status' => 'error', 'message' => 'Não foi possível remover o arquivo do pedido']);
                    exit;
                }
            }
            
            // Atualizar o index.json removendo o pedido
            $index_path = $pedidos_dir . '/index.json';
            $updated_index = false;
            
            if (file_exists($index_path)) {
                // Usando LOCK_SH para bloqueio compartilhado durante a leitura.
                $index_content = file_get_contents($index_path, false, null, 0, filesize($index_path));
                 if ($index_content === false) { // Verifica se houve erro na leitura
                    file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' ERRO: Falha ao ler index.json antes da remocao.' . PHP_EOL, FILE_APPEND);
                    throw new Exception('Falha ao ler index.json antes da remocao.');
                }
                $index = json_decode($index_content, true);
                
                if (json_last_error() === JSON_ERROR_NONE && is_array($index)) {
                    // Filtrar o índice para remover o pedido
                    $index_filtrado = array_filter($index, function($item) use ($session_id, $pedido_id, $filename) {
                        // Remover por pedido_id (se disponível)
                        if ($pedido_id && isset($item['pedido_id']) && $item['pedido_id'] === $pedido_id) {
                            return false;
                        }
                        
                        // Remover por session_id
                        if ($session_id && isset($item['session_id']) && $item['session_id'] === $session_id) {
                            return false;
                        }
                        
                        // Remover por nome de arquivo
                        if ($filename && isset($item['arquivo']) && $item['arquivo'] === $filename) {
                            return false;
                        }
                        
                        return true;
                    });
                    
                    // Reindexar o array (para evitar buracos no índice numérico)
                    $index_filtrado = array_values($index_filtrado);
                    
                    // Salvar o índice atualizado
                    // Usando LOCK_EX para garantir bloqueio exclusivo.
                    if (file_put_contents($index_path, json_encode($index_filtrado, JSON_PRETTY_PRINT), LOCK_EX)) {
                        $updated_index = true;
                        file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Pedido removido do index.json' . PHP_EOL, FILE_APPEND);
                    }
                }
            }
            
            echo json_encode([
                'status' => 'success', 
                'message' => 'Pedido removido com sucesso',
                'arquivo' => $filename,
                'pedido_id' => $pedido_id,
                'index_atualizado' => $updated_index
            ]);
            break;
            
        default:
            // Compatibilidade com versões anteriores (método antigo de salvar pedidos)
            if (isset($request_data['fileName']) && isset($request_data['fileContent'])) {
                $filename = sanitize_filename($request_data['fileName']);
                $file_path = $pedidos_dir . '/' . $filename;
                
                // Verificar se o conteúdo é um JSON válido
                $file_content = $request_data['fileContent'];
                $pedido_data = json_decode($file_content, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Conteúdo do pedido não é um JSON válido']);
                    exit;
                }
                
                // Adicionar flag de recuperação e timestamp se não existirem
                if (!isset($pedido_data['Rec'])) {
                    $pedido_data['Rec'] = false; // Pedido completo por padrão no método antigo
                }
                
                if (!isset($pedido_data['timestamp'])) {
                    $pedido_data['timestamp'] = date('Y-m-d H:i:s');
                }
                
                if (!isset($pedido_data['criado_em'])) {
                    $pedido_data['criado_em'] = time();
                }
                
                // Verificar se já existe um pedido com o mesmo session_id no diretório
                // e removê-lo antes de salvar o novo pedido finalizado
                if (isset($pedido_data['session_id'])) {
                    $session_file = $pedidos_dir . '/' . sanitize_filename($pedido_data['session_id']) . '.json';
                    if (file_exists($session_file)) {
                        // Ler o arquivo do pedido temporário para verificar se é o mesmo pedido
                        // Usando LOCK_SH para bloqueio compartilhado durante a leitura.
                        $temp_content = file_get_contents($session_file, false, null, 0, filesize($session_file));
                         if ($temp_content === false) { // Verifica se houve erro na leitura
                            file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' ERRO: Falha ao ler arquivo: ' . $session_file . ' (temp) antes da remocao.' . PHP_EOL, FILE_APPEND);
                            throw new Exception('Falha ao ler arquivo (temp) antes da remocao.');
                        }
                        $temp_data = json_decode($temp_content, true);
                        
                        if (json_last_error() === JSON_ERROR_NONE) {
                            // Verificar se é o mesmo pedido comparando dados principais
                            $same_order = false;
                            if (isset($temp_data['NOME']) && isset($pedido_data['NOME']) && 
                                isset($temp_data['ENDEREÇO']) && isset($pedido_data['ENDEREÇO'])) {
                                
                                if ($temp_data['NOME'] === $pedido_data['NOME'] && 
                                    $temp_data['ENDEREÇO'] === $pedido_data['ENDEREÇO']) {
                                    $same_order = true;
                                }
                            }
                            
                            // Se for o mesmo pedido, remover o arquivo temporário
                            if ($same_order) {
                                unlink($session_file);
                                file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Removido arquivo temporário: ' . basename($session_file) . PHP_EOL, FILE_APPEND);
                            }
                        }
                    }
                }
                
                // Salvar o arquivo com os dados atualizados
                // Usando LOCK_EX para garantir bloqueio exclusivo durante a escrita.
                if (file_put_contents($file_path, json_encode($pedido_data, JSON_PRETTY_PRINT), LOCK_EX)) {
                    file_put_contents($log_file, date('[Y-m-d H:i:s]') . ' Pedido salvo (método antigo): ' . $filename . PHP_EOL, FILE_APPEND);
                    
                    // Se for um pedido completo, atualizar index.json
                    if ($pedido_data['Rec'] === false) {
                        atualizar_index_json($pedido_data);
                    }
                    
                    echo json_encode(['status' => 'success', 'message' => 'Arquivo salvo com sucesso', 'file' => $filename]);
                } else {
                    http_response_code(500);
                    echo json_encode(['status' => 'error', 'message' => 'Não foi possível salvar o arquivo']);
                }
            } else {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Ação desconhecida ou parâmetros inválidos']);
            }
            break;
    }
} catch (Throwable $e) { // ** Captura qualquer exceção não tratada **
    $error_message = $e->getMessage();
    $error_file = $e->getFile();
    $error_line = $e->getLine();
    $log_message = date('[Y-m-d H:i:s]') . " [UNCAUGHT EXCEPTION] $error_message in $error_file on line $error_line" . PHP_EOL;
    file_put_contents($log_file, $log_message, FILE_APPEND);
    
    if (!headers_sent()) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Erro fatal inesperado no script PHP: ' . $error_message]);
    }
    exit(); // Garante que o script pare
}
?>
