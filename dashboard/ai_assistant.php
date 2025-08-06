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

// Configurações da OpenRouter
define('OPENROUTER_API_KEY', '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');
define('OPENROUTER_MODEL', 'qwen/qwen-32b-preview');

// Obter dados da requisição
$input = json_decode(file_get_contents('php://input'), true);
$message = $input['message'] ?? '';
$action = $input['action'] ?? 'chat';

if (empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Mensagem não pode estar vazia']);
    exit;
}

try {
    switch ($action) {
        case 'chat':
            processarMensagemIA($message, $conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Ação não reconhecida']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
}

function processarMensagemIA($message, $conn) {
    // Analisar a intenção da mensagem
    $intent = analisarIntencao($message);
    
    // Obter contexto relevante baseado na intenção
    $context = obterContexto($intent, $conn);
    
    // Preparar prompt para a IA
    $system_prompt = construirSystemPrompt($context);
    
    // Enviar para OpenRouter
    $response = enviarParaOpenRouter($system_prompt, $message);
    
    echo json_encode(['success' => true, 'response' => $response]);
}

function analisarIntencao($message) {
    $message_lower = strtolower($message);
    
    // Palavras-chave para diferentes intenções
    $keywords = [
        'calendario' => ['calendario', 'evento', 'agendamento', 'data', 'horario', 'compromisso', 'reuniao'],
        'clientes' => ['cliente', 'novo', 'pedido', 'contato', 'telefone', 'endereco'],
        'dashboard' => ['dashboard', 'metricas', 'estatisticas', 'numeros', 'relatorio', 'dados'],
        'entregas' => ['entrega', 'envio', 'transito', 'rastreio', 'tracking']
    ];
    
    $scores = [];
    foreach ($keywords as $intent => $words) {
        $score = 0;
        foreach ($words as $word) {
            if (strpos($message_lower, $word) !== false) {
                $score++;
            }
        }
        $scores[$intent] = $score;
    }
    
    // Retornar a intenção com maior score
    $max_intent = array_keys($scores, max($scores))[0];
    return max($scores) > 0 ? $max_intent : 'geral';
}

function obterContexto($intent, $conn) {
    $context = [];
    
    switch ($intent) {
        case 'calendario':
            $context['eventos'] = obterEventosCalendario($conn);
            break;
        case 'clientes':
            $context['clientes'] = obterDadosClientes($conn);
            break;
        case 'dashboard':
            $context['metricas'] = obterMetricasDashboard($conn);
            break;
        case 'entregas':
            $context['entregas'] = obterDadosEntregas($conn);
            break;
        default:
            // Contexto geral - dados básicos
            $context['resumo'] = obterResumoGeral($conn);
    }
    
    return $context;
}

function obterEventosCalendario($conn) {
    $sql = "SELECT * FROM eventos_calendario ORDER BY data_inicio ASC LIMIT 20";
    $result = $conn->query($sql);
    
    $eventos = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $eventos[] = [
                'id' => $row['id'],
                'titulo' => $row['titulo'],
                'descricao' => $row['descricao'],
                'data_inicio' => $row['data_inicio'],
                'data_fim' => $row['data_fim'],
                'cor' => $row['cor']
            ];
        }
    }
    
    return $eventos;
}

function obterDadosClientes($conn) {
    // Obter dados dos clientes da tabela entregas
    $sql = "SELECT cliente, status, destino, tracking, ultima_atualizacao 
            FROM entregas 
            WHERE status = 'NOVO CLIENTE' 
            ORDER BY ultima_atualizacao DESC 
            LIMIT 10";
    
    $result = $conn->query($sql);
    $clientes = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $clientes[] = [
                'nome' => $row['cliente'],
                'status' => $row['status'],
                'destino' => $row['destino'],
                'tracking' => $row['tracking'],
                'ultima_atualizacao' => $row['ultima_atualizacao']
            ];
        }
    }
    
    return $clientes;
}

function obterMetricasDashboard($conn) {
    $metricas = [];
    
    // Contar por status
    $status_counts = [
        'NOVO CLIENTE' => 0,
        'PRONTO PARA ENVIO' => 0,
        'EM TRÂNSITO' => 0,
        'ENTREGUE - PAGO' => 0
    ];
    
    foreach ($status_counts as $status => $count) {
        $sql = "SELECT COUNT(*) as total FROM entregas WHERE status = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $status);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $status_counts[$status] = $row['total'];
    }
    
    $metricas['status_counts'] = $status_counts;
    
    // Última atualização
    $sql = "SELECT MAX(ultima_atualizacao) as ultima_att FROM entregas";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $metricas['ultima_atualizacao'] = $row['ultima_att'];
    
    return $metricas;
}

function obterDadosEntregas($conn) {
    $sql = "SELECT cliente, status, destino, tracking, ultima_atualizacao 
            FROM entregas 
            ORDER BY ultima_atualizacao DESC 
            LIMIT 15";
    
    $result = $conn->query($sql);
    $entregas = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $entregas[] = [
                'cliente' => $row['cliente'],
                'status' => $row['status'],
                'destino' => $row['destino'],
                'tracking' => $row['tracking'],
                'ultima_atualizacao' => $row['ultima_atualizacao']
            ];
        }
    }
    
    return $entregas;
}

function obterResumoGeral($conn) {
    $resumo = [];
    
    // Total de entregas
    $result = $conn->query("SELECT COUNT(*) as total FROM entregas");
    $row = $result->fetch_assoc();
    $resumo['total_entregas'] = $row['total'];
    
    // Total de eventos
    $result = $conn->query("SELECT COUNT(*) as total FROM eventos_calendario");
    $row = $result->fetch_assoc();
    $resumo['total_eventos'] = $row['total'];
    
    return $resumo;
}

function construirSystemPrompt($context) {
    $prompt = "Você é um assistente de IA para o sistema de logística da 4Life Nutri. ";
    $prompt .= "Você pode ajudar com:\n";
    $prompt .= "1. Gerenciamento de eventos do calendário (criar, editar, visualizar)\n";
    $prompt .= "2. Informações sobre clientes e pedidos\n";
    $prompt .= "3. Dados do dashboard e métricas\n";
    $prompt .= "4. Informações sobre entregas e rastreamento\n\n";
    
    $prompt .= "Contexto atual:\n";
    $prompt .= json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $prompt .= "\n\nResponda sempre em português e seja útil e preciso. ";
    $prompt .= "Se precisar criar ou modificar eventos, forneça instruções claras sobre como fazer isso.";
    
    return $prompt;
}

function enviarParaOpenRouter($system_prompt, $user_message) {
    $url = 'https://openrouter.ai/api/v1/chat/completions';
    
    $data = [
        'model' => OPENROUTER_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => $system_prompt
            ],
            [
                'role' => 'user',
                'content' => $user_message
            ]
        ],
        'max_tokens' => 1000,
        'temperature' => 0.7
    ];
    
    $headers = [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: http://localhost',
        'X-Title: 4Life Nutri Assistant'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        curl_close($ch);
        throw new Exception('Erro na requisição: ' . curl_error($ch));
    }
    
    curl_close($ch);
    
    if ($http_code !== 200) {
        throw new Exception('Erro na API: HTTP ' . $http_code . ' - ' . $response);
    }
    
    $result = json_decode($response, true);
    
    if (!$result || !isset($result['choices'][0]['message']['content'])) {
        throw new Exception('Resposta inválida da API');
    }
    
    return $result['choices'][0]['message']['content'];
}

// Fechar conexão
$conn->close();
?> 