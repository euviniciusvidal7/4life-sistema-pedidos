<?php
session_start();

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

// Garantir que ambos os campos de sessão estejam disponíveis
if (!isset($_SESSION['usuario']) && isset($_SESSION['usuario_id'])) {
    $_SESSION['usuario'] = $_SESSION['usuario_id'];
}

// Incluir configurações
require_once 'config.php';

// Conectar ao banco
$conn = conectarBD();

// Obter o nível de acesso do usuário
if (!isset($nivel_acesso)) {
    $stmt = $conn->prepare("SELECT nivel_acesso FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['usuario_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $usuario = $result->fetch_assoc();
    $nivel_acesso = $usuario['nivel_acesso'] ?? 'logistica'; // Padrão é logística se não especificado
}

// Verificar permissão de acesso
if ($nivel_acesso == 'recuperacao') {
    $_SESSION['erro_mensagem'] = "Você não tem permissão para acessar a área de pedidos.";
    header('Location: dashboard.php');
    exit;
}

// Log para debug
error_log('Pedidos.php - Nível de acesso do usuário: ' . $nivel_acesso);

// Definir caminho para a pasta de pedidos
$pedidos_path = '../2025/pedidos'; // Caminho relativo atualizado
$index_path = $pedidos_path . '/index.json'; // Caminho para o arquivo de índice

// Verificar se o diretório local existe
if (!is_dir($pedidos_path)) {
    $alternative_paths = [
        $_SERVER['DOCUMENT_ROOT'] . '/2025/pedidos',
        dirname($_SERVER['DOCUMENT_ROOT']) . '/2025/pedidos',
        '../../2025/pedidos'
    ];
    
    foreach ($alternative_paths as $path) {
        if (is_dir($path)) {
            $pedidos_path = $path;
            break;
        }
    }
}

// Registrar caminho para debug
error_log('Pedidos.php - Caminho local definido: ' . $pedidos_path);
$index_path = $pedidos_path . '/index.json';

// Definir períodos de tempo para identificar pedidos novos
$tempo_novo = strtotime('-48 hours'); // Pedidos das últimas 48 horas são considerados novos

// Processar filtro de busca
$busca = $_GET['busca'] ?? '';
$filtro_data_inicio = $_GET['data_inicio'] ?? '';
$filtro_data_fim = $_GET['data_fim'] ?? '';
$filtro_status = $_GET['status'] ?? '';
$estado_filtro = $_GET['estado'] ?? '';

// IDs das listas do Trello
$trello_list_ids = [
    'novos' => '67f70b1e66425a64aa863e9f',
    'ligar' => '67fe3f6feeaedb56af382407',
    'pronto_envio' => '67f70b1e66425a64aa863ea0', // Adicionar ID para "PRONTO PARA ENVIO"
    'transito' => '67f70b1e66425a64aa863ea1',
    'entregue' => '67f71b2d0034671f377035e8',
    'devolucao' => '6808cff12b9ae87d1d519329'
];

/**
 * Função para carregar pedidos do Trello
 */
function carregarPedidosTrello() {
    global $trello_list_ids;
    $pedidos_trello = [];
    
    // Verificar se as constantes do Trello estão definidas
    if (!defined('TRELLO_API_KEY') || !defined('TRELLO_TOKEN') || !defined('TRELLO_BOARD_ID')) {
        error_log('Constantes do Trello não definidas ou vazias');
        return [];
    }
    
    // Buscar cards do board do Trello
    $url = "https://api.trello.com/1/boards/" . TRELLO_BOARD_ID . "/cards?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN . "&lists=true";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        error_log('Erro ao acessar a API do Trello: ' . curl_error($ch));
        curl_close($ch);
        return [];
    }
    
    curl_close($ch);
    
    $cards = json_decode($response, true);
    if (!$cards || !is_array($cards)) {
        error_log('Erro ao decodificar resposta do Trello: ' . json_last_error_msg());
        return [];
    }
    
    // Primeiro, buscar informações sobre as listas do board
    $url_lists = "https://api.trello.com/1/boards/" . TRELLO_BOARD_ID . "/lists?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url_lists);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response_lists = curl_exec($ch);
    
    if (curl_errno($ch)) {
        error_log('Erro ao acessar listas do Trello: ' . curl_error($ch));
        curl_close($ch);
    }
    
    curl_close($ch);
    
    $trello_lists = [];
    if ($response_lists) {
        $lists = json_decode($response_lists, true);
        if (is_array($lists)) {
            foreach ($lists as $list) {
                $trello_lists[$list['id']] = $list['name'];
            }
        }
    }
    
    foreach ($cards as $card) {
        // Verificar se o card está marcado como removido na descrição
        if (!empty($card['desc']) && preg_match('/REMOVIDO:\s*(?:true|sim|yes|1)/i', $card['desc'])) {
            continue; // Pular este card
        }
        
        // Extrair informações relevantes do card
        $pedido = [
            'id_trello' => $card['id'],
            'NOME' => $card['name'],
            'ENDEREÇO' => '',
            'CONTATO' => '',
            'PROBLEMA_RELATADO' => '',
            'PACOTE_ESCOLHIDO' => '',
            'DATA' => date('d/m/Y', strtotime($card['dateLastActivity'])),
            'timestamp' => strtotime($card['dateLastActivity']),
            'fonte' => 'trello',
            'integrado' => true,
            'status' => 'aguardando', // Status padrão
            'novo' => false,
            'rastreio' => '',
            'estado_encomenda' => '',
            'atualizacao' => '',
            'tratamento' => '',
            'percurso' => []
        ];
        
        // Extrair informações da descrição
        if (!empty($card['desc'])) {
            $linhas = explode("\n", $card['desc']);
            $processando_percurso = false;
            $percurso = [];
            
            foreach ($linhas as $linha) {
                $linha = trim($linha);
                if (empty($linha)) continue;
                
                // Extrair informações básicas
                if (strpos($linha, 'Endereço:') !== false) {
                    $pedido['ENDEREÇO'] = trim(str_replace('Endereço:', '', $linha));
                } else if (strpos($linha, 'Morada:') !== false) {
                    $pedido['ENDEREÇO'] = trim(str_replace('Morada:', '', $linha));
                } else if (strpos($linha, 'Contato:') !== false) {
                    $pedido['CONTATO'] = trim(str_replace('Contato:', '', $linha));
                } else if (strpos($linha, 'Telefone:') !== false) {
                    $pedido['CONTATO'] = trim(str_replace('Telefone:', '', $linha));
                } else if (strpos($linha, 'Pacote:') !== false) {
                    $pedido['PACOTE_ESCOLHIDO'] = trim(str_replace('Pacote:', '', $linha));
                } else if (strpos($linha, 'Tratamento:') !== false) {
                    $pedido['tratamento'] = trim(str_replace('Tratamento:', '', $linha));
                } else if (strpos($linha, 'Problema:') !== false) {
                    $pedido['PROBLEMA_RELATADO'] = trim(str_replace('Problema:', '', $linha));
                } 
                // Extrair informações de estado da encomenda
                else if (strpos($linha, '🔵 Estado:') !== false || strpos($linha, 'Estado:') !== false) {
                    $pedido['estado_encomenda'] = trim(str_replace(['🔵 Estado:', 'Estado:'], '', $linha));
                } else if (strpos($linha, '🕒 Última atualização:') !== false) {
                    $pedido['atualizacao'] = trim(str_replace('🕒 Última atualização:', '', $linha));
                } else if (strpos($linha, '📌 Código de Rastreio:') !== false) {
                    $pedido['rastreio'] = trim(str_replace('📌 Código de Rastreio:', '', $linha));
                }
                // Extrair percurso da encomenda
                else if (strpos($linha, '📋 Percurso da Encomenda:') !== false) {
                    $processando_percurso = true;
                    continue;
                } else if ($processando_percurso && (strpos($linha, '📨') !== false || strpos($linha, '📝') !== false || 
                          strpos($linha, '🏢') !== false || strpos($linha, '✅') !== false)) {
                    $percurso[] = $linha;
                } else if ($processando_percurso && strpos($linha, '📌') !== false) {
                    $processando_percurso = false;
                }
            }
            
            if (!empty($percurso)) {
                $pedido['percurso'] = $percurso;
            }
            
            // Se não tiver um pacote escolhido explícito, usar o tratamento como pacote
            if (empty($pedido['PACOTE_ESCOLHIDO']) && !empty($pedido['tratamento'])) {
                $pedido['PACOTE_ESCOLHIDO'] = $pedido['tratamento'];
            }
        }
        
        // Determinar status com base na lista
        if (isset($card['idList']) && isset($trello_lists[$card['idList']])) {
            $list_name = $trello_lists[$card['idList']];
            
            // Mapear os nomes das listas para os status internos
            if (stripos($list_name, 'CLIENTES A LIGAR') !== false) {
                $pedido['status'] = 'ligar';
            } else if (stripos($list_name, 'NOVO CLIENTE') !== false) {
                $pedido['status'] = 'aguardando';
            } else if (stripos($list_name, 'PRONTO PARA ENVIO') !== false) {
                $pedido['status'] = 'em_processamento';
            } else if (stripos($list_name, 'EM TRANSITO') !== false || stripos($list_name, 'EM TRÂNSITO') !== false) {
                $pedido['status'] = 'processado';
            } else if (stripos($list_name, 'DEVOLUCAO') !== false || stripos($list_name, 'DEVOLUÇÃO') !== false || stripos($list_name, 'DEVOLVIDAS') !== false) {
                $pedido['status'] = 'devolucao';
            } else if (stripos($list_name, 'ENTREGUE') !== false || stripos($list_name, 'PAGO') !== false) {
                $pedido['status'] = 'concluido';
            } else {
                $pedido['status'] = 'nao_listado';
            }
        }
        
        // Verificar com os IDs específicos das listas (prioridade sobre nomes)
        if (isset($card['idList'])) {
            if ($card['idList'] === $trello_list_ids['novos']) {
                $pedido['status'] = 'aguardando';
            } else if ($card['idList'] === $trello_list_ids['ligar']) {
                $pedido['status'] = 'ligar';
            } else if ($card['idList'] === $trello_list_ids['pronto_envio']) {
                $pedido['status'] = 'em_processamento';
            } else if ($card['idList'] === $trello_list_ids['transito']) {
                $pedido['status'] = 'processado';
            } else if ($card['idList'] === $trello_list_ids['entregue']) {
                $pedido['status'] = 'concluido';
            } else if ($card['idList'] === $trello_list_ids['devolucao']) {
                $pedido['status'] = 'devolucao';
            }
        }
        
        // Se tiver código de rastreio, está em trânsito (a menos que já esteja em devolução ou concluido)
        if (!empty($pedido['rastreio']) && !in_array($pedido['status'], ['devolucao', 'concluido'])) {
            $pedido['status'] = 'processado';
        }
        
        // Verificar se é novo baseado na última atividade E no status
        if ($pedido['timestamp'] >= $tempo_novo && $pedido['status'] === 'aguardando') {
            $pedido['novo'] = true;
        }
        
        $pedidos_trello[] = $pedido;
    }
    
    return $pedidos_trello;
}

/**
 * Função para carregar pedidos do JSON
 */
function carregarPedidosJSON($path, $busca = '', $data_inicio = '', $data_fim = '', $tempo_novo = 0) {
    $pedidos = [];
    $index_path = $path . '/index.json';
    
    // Tentar carregar o arquivo index.json
    if (file_exists($index_path)) {
        $conteudo = file_get_contents($index_path);
        if ($conteudo) {
            $pedidos_json = json_decode($conteudo, true);
            
            if (is_array($pedidos_json)) {
                // Processar cada pedido do JSON
                foreach ($pedidos_json as $indice => $pedido) {
                    // Definir valores padrão
                    $pedido['fonte'] = 'json';
                    $pedido['integrado'] = isset($pedido['integrado']) ? $pedido['integrado'] : false;
                    $pedido['status'] = isset($pedido['status']) ? $pedido['status'] : 'aguardando';
                    $pedido['timestamp'] = isset($pedido['criado_em']) ? $pedido['criado_em'] : strtotime($pedido['DATA'] ?? 'now');
                    $pedido['novo'] = ($pedido['timestamp'] >= $tempo_novo && $pedido['status'] === 'aguardando');
                    
                    // Adicionar o nome do arquivo ao pedido para facilitar o processamento
                    $pedido['arquivo'] = $indice . '.json';
                    
                    // Verificar se o pedido atende aos critérios de busca
                    $incluir_pedido = true;
                    
                    // Aplicar filtro de busca
                    if (!empty($busca)) {
                        $encontrado = false;
                        foreach ($pedido as $campo => $valor) {
                            if (is_string($valor) && stripos($valor, $busca) !== false) {
                                $encontrado = true;
                                break;
                            }
                        }
                        if (!$encontrado) {
                            $incluir_pedido = false;
                        }
                    }
                    
                    // Aplicar filtro de data
                    if (!empty($data_inicio) && isset($pedido['timestamp']) && $pedido['timestamp'] < strtotime($data_inicio . ' 00:00:00')) {
                        $incluir_pedido = false;
                    }
                    
                    if (!empty($data_fim) && isset($pedido['timestamp']) && $pedido['timestamp'] > strtotime($data_fim . ' 23:59:59')) {
                        $incluir_pedido = false;
                    }
                    
                    if ($incluir_pedido) {
                        $pedidos[] = $pedido;
                    }
                }
                
                return $pedidos;
            }
        }
    }
    
    // Se não conseguiu usar o index.json, retornar array vazio
    error_log('Não foi possível carregar pedidos do index.json');
    return [];
}

// Carregar todos os pedidos (JSON e Trello)
try {
    $pedidos_json = carregarPedidosJSON($pedidos_path, $busca, $filtro_data_inicio, $filtro_data_fim, $tempo_novo);
    error_log('Carregou ' . count($pedidos_json) . ' pedidos do JSON');
} catch (Exception $e) {
    error_log('Erro ao carregar pedidos JSON: ' . $e->getMessage());
    $pedidos_json = [];
}

try {
    $pedidos_trello = carregarPedidosTrello();
    error_log('Carregou ' . count($pedidos_trello) . ' pedidos do Trello');
} catch (Exception $e) {
    error_log('Erro ao carregar pedidos do Trello: ' . $e->getMessage());
    $pedidos_trello = [];
}

// Mesclar pedidos
$pedidos = array_merge($pedidos_json, $pedidos_trello);
error_log('Total após mesclar JSON e Trello: ' . count($pedidos));

// Ordenar por timestamp, do mais recente para o mais antigo
usort($pedidos, function($a, $b) {
    if ($a['novo'] && !$b['novo']) return -1;
    if (!$a['novo'] && $b['novo']) return 1;
    return $b['timestamp'] - $a['timestamp'];
});

// Filtrar por status se solicitado
if (!empty($filtro_status)) {
    $pedidos_a_exibir = array_filter($pedidos, function($pedido) use ($filtro_status) {
        return $pedido['status'] == $filtro_status;
    });
} else {
    // Excluir pedidos com status 'nao_listado' da exibição
    $pedidos_a_exibir = array_filter($pedidos, function($pedido) {
        return $pedido['status'] != 'nao_listado';
    });
}

// Filtrar por nível de acesso do usuário
if (!empty($filtro_status)) {
    // Se há um filtro de status específico, aplicar as restrições de nível de acesso
    if ($nivel_acesso == 'vendas') {
        $pedidos_a_exibir = array_filter($pedidos_a_exibir, function($pedido) {
            return in_array($pedido['status'], ['aguardando', 'devolucao', 'concluido']);
        });
    } elseif ($nivel_acesso == 'ltv') {
        $pedidos_a_exibir = array_filter($pedidos_a_exibir, function($pedido) {
            return in_array($pedido['status'], ['concluido', 'devolucao', 'processado']);
        });
    } elseif ($nivel_acesso == 'recuperacao') {
        $pedidos_a_exibir = array_filter($pedidos_a_exibir, function($pedido) {
            return in_array($pedido['status'], ['aguardando']);
        });
    }
} else {
    // Se não há filtro de status, aplicar apenas restrições básicas por nível de acesso
    if ($nivel_acesso == 'recuperacao') {
        $pedidos_a_exibir = array_filter($pedidos_a_exibir, function($pedido) {
            return in_array($pedido['status'], ['aguardando']);
        });
    }
}

// Filtrar por estado_encomenda se solicitado
if (!empty($estado_filtro) && $estado_filtro === 'disponivel_para_levantamento') {
    // Primeiro aplicar filtro por estado
    $filtro_temporario = array_filter($pedidos, function($pedido) {
        return isset($pedido['estado_encomenda']) && 
               (stripos($pedido['estado_encomenda'], 'disponível para levantamento') !== false || 
                stripos($pedido['estado_encomenda'], 'disponivel para levantamento') !== false);
    });
    
    // Depois aplicar filtro por nível de acesso
    if ($nivel_acesso == 'recuperacao') {
        $pedidos_a_exibir = array_filter($filtro_temporario, function($pedido) {
            return in_array($pedido['status'], ['aguardando']);
        });
    } else {
        $pedidos_a_exibir = $filtro_temporario;
    }
}

// Calcular quantidade de pedidos com estado 'Disponível para Levantamento'
$pedidos_disponivel_levantamento = count(array_filter($pedidos, function($p) {
    return isset($p['estado_encomenda']) && 
           (stripos($p['estado_encomenda'], 'disponível para levantamento') !== false || 
            stripos($p['estado_encomenda'], 'disponivel para levantamento') !== false);
}));

// Obter estatísticas
$total_pedidos = count($pedidos);
$pedidos_novos = count(array_filter($pedidos, function($p) { return $p['novo']; }));
$pedidos_hoje = count(array_filter($pedidos, function($p) { 
    return $p['timestamp'] >= strtotime('today'); 
}));
$pedidos_pendentes = count(array_filter($pedidos, function($p) { 
    return $p['status'] == 'aguardando'; 
}));
$pedidos_ligar = count(array_filter($pedidos, function($p) { 
    return $p['status'] == 'ligar'; 
}));
$pedidos_processando = count(array_filter($pedidos, function($p) { 
    return $p['status'] == 'em_processamento'; 
}));
$pedidos_processados = count(array_filter($pedidos, function($p) { 
    return $p['status'] == 'processado'; 
}));
$pedidos_devolucao = count(array_filter($pedidos, function($p) { 
    return $p['status'] == 'devolucao'; 
}));
$pedidos_concluidos = count(array_filter($pedidos, function($p) { 
    return $p['status'] == 'concluido'; 
}));

// Incluir o header padronizado
include 'header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
/* Estilos gerais */
:root {
    --primary-color: #2E7D32;
    --secondary-color: #1B5E20;
    --accent-color: #81C784;
    --hover-color: rgba(129, 199, 132, 0.2);
    --bg-light: #f5f8f5;
    --text-light: #e8f5e9;
    --shadow-color: rgba(0, 77, 64, 0.15);
    --bs-purple-rgb: 103, 58, 183;
}

body {
    background-color: var(--bg-light);
}

/* Cards principais estilo dashboard */
.metric-card {
    color: white;
    border-radius: 15px;
    padding: 25px;
    text-align: center;
    transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
    height: 100%;
    overflow: hidden;
    position: relative;
    box-shadow: 0 8px 15px rgba(0,0,0,0.1);
    cursor: pointer;
    border: 1px solid rgba(255, 255, 255, 0.15);
}

.metric-card:hover {
    transform: translateY(-7px);
    box-shadow: 0 15px 25px rgba(0,0,0,0.2);
}

.metric-card.purple {
    background: linear-gradient(135deg, #673AB7, #9575CD);
}

.metric-card.blue {
    background: linear-gradient(135deg, #1976D2, #64B5F6);
}

.metric-card.green {
    background: linear-gradient(135deg, #2E7D32, #4CAF50);
}

.metric-card.orange {
    background: linear-gradient(135deg, #E65100, #FF9800);
}

.metric-card.gray {
    background: linear-gradient(135deg, #455A64, #607D8B);
}

.metric-card.yellow {
    background: linear-gradient(135deg, #FFA000, #FFC107);
}

.metric-card.red {
    background: linear-gradient(135deg, #C62828, #F44336);
}

.metric-card .number {
    font-size: 38px;
    font-weight: 700;
    margin: 12px 0;
    text-shadow: 1px 1px 5px rgba(0,0,0,0.3);
}

.metric-card .label {
    font-size: 15px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    opacity: 0.95;
    font-weight: bold;
    text-shadow: 0 1px 2px rgba(0,0,0,0.2);
}

.metric-card div:not(.number):not(.label):not(.icon) {
    margin-top: 8px;
    background-color: rgba(255, 255, 255, 0.15);
    border-radius: 30px;
    padding: 7px 15px;
    font-size: 14px;
    display: inline-block;
    font-weight: 500;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.metric-card .icon {
    position: absolute;
    bottom: -10px;
    right: -10px;
    font-size: 90px;
    opacity: 0.15;
    transform: rotate(-5deg);
    transition: transform 0.4s ease;
}

.metric-card:hover .icon {
    transform: rotate(0deg) scale(1.1);
    opacity: 0.2;
}

/* Status colors */
.status-badge {
    padding: 6px 15px;
    border-radius: 30px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.status-aguardando {
    background-color: #607D8B;
    color: white;
}

.status-ligar {
    background-color: #9C27B0;
    color: white;
}

.status-processando {
    background-color: #FF9800;
    color: #212529;
}

.status-processado {
    background-color: #FFC107;
    color: white;
}

.status-concluido {
    background-color: #4CAF50;
    color: white;
}

.status-devolucao {
    background-color: #F44336;
    color: white;
}

.status-transito {
    background-color: #FFC107;
    color: white;
}

.status-disponivel {
    background-color: #00BCD4;
    color: white;
}

/* Cards personalizados */
.pedido-card {
    transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
    border: none;
    box-shadow: 0 5px 15px var(--shadow-color);
    cursor: pointer;
    overflow: hidden;
    border-radius: 15px;
    background-color: white;
    animation: fadeIn 0.6s ease-out forwards;
}

.pedido-card:hover {
    transform: translateY(-7px);
    box-shadow: 0 15px 25px var(--shadow-color);
}

.pedido-card .card-header {
    background-color: white;
    border-bottom: 1px solid rgba(0,0,0,0.05);
    padding: 18px 22px;
}

.pedido-card .card-body {
    padding: 22px;
}

.pedido-card .card-footer {
    background-color: white;
    border-top: 1px solid rgba(0,0,0,0.05);
    padding: 15px 22px;
}

.pedido-card.novo {
    border-left: 5px solid #FFC107;
}

.novo-badge {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% {
        opacity: 1;
    }
    50% {
        opacity: 0.6;
    }
    100% {
        opacity: 1;
    }
}

/* Cabeçalho de filtro */
.filter-header {
    margin-top: 10px;
    margin-bottom: 25px;
    border: none;
    overflow: hidden;
    box-shadow: 0 8px 20px var(--shadow-color);
}

.filter-header .card-body {
    padding: 25px;
}

.filter-header h3 {
    font-weight: 700;
    letter-spacing: 0.5px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.filter-header .filter-icon {
    background-color: rgba(255, 255, 255, 0.15);
    padding: 15px;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    width: 75px;
    height: 75px;
}

.filter-header .badge {
    font-size: 14px;
    padding: 8px 15px;
    border-radius: 30px;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
    font-weight: 600;
}

/* Melhorias visuais para os containers de pedidos */
.row .col-md-6, .row .col-lg-4 {
    padding: 10px;
}

/* Estilo para botão de voltar */
.btn-outline-secondary {
    border-color: var(--shadow-color);
    color: var(--secondary-color);
    background-color: rgba(255, 255, 255, 0.7);
    box-shadow: 0 3px 10px var(--shadow-color);
    padding: 10px 20px;
    transition: all 0.3s;
    margin-bottom: 15px;
}

.btn-outline-secondary:hover {
    background-color: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
    transform: translateY(-3px);
    box-shadow: 0 5px 15px var(--shadow-color);
}

/* Animar aparecimento de novos itens */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Ajuste nas cores dos status */
.status-aguardando {
    background-color: #607D8B;
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.status-ligar {
    background-color: #673AB7;
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.status-processando {
    background-color: #FF9800;
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.status-processado, .status-transito {
    background-color: #FFC107;
    color: #212529;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.status-concluido {
    background-color: #2E7D32;
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.status-devolucao {
    background-color: #F44336;
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.status-disponivel {
    background-color: #00BCD4;
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

/* Detalhes do pedido */
.detail-item {
    margin-bottom: 18px;
    border-left: 4px solid var(--primary-color);
    padding-left: 15px;
    background-color: rgba(46, 125, 50, 0.03);
    border-radius: 0 5px 5px 0;
    padding: 12px 15px 12px 18px;
}

.detail-label {
    font-weight: 600;
    color: var(--secondary-color);
    font-size: 14px;
    text-transform: uppercase;
    margin-bottom: 6px;
    letter-spacing: 0.5px;
}

.detail-value {
    font-size: 16px;
}

/* Cores personalizadas */
.bg-purple {
    background-color: #673AB7 !important;
}

.text-purple {
    color: #673AB7 !important;
}

.btn-purple {
    background-color: #673AB7;
    border-color: #673AB7;
    color: white;
    box-shadow: 0 2px 5px rgba(103, 58, 183, 0.3);
}

.btn-purple:hover {
    background-color: #5e35b1;
    border-color: #5e35b1;
    color: white;
    box-shadow: 0 4px 8px rgba(103, 58, 183, 0.4);
    transform: translateY(-2px);
}

.btn-outline-purple {
    color: #673AB7;
    border-color: #673AB7;
}

.btn-outline-purple:hover, .btn-check:checked + .btn-outline-purple {
    background-color: #673AB7;
    color: white;
    transform: translateY(-2px);
}

.alert-purple {
    background-color: rgba(103, 58, 183, 0.1);
    border-color: rgba(103, 58, 183, 0.2);
    color: #673AB7;
    border-radius: 10px;
}

/* Dual badge para status múltiplos */
.dual-badge {
    display: flex;
    align-items: center;
    gap: 5px;
}

.dual-badge .status-badge {
    padding: 4px 10px;
    font-size: 11px;
    white-space: nowrap;
}

/* WhatsApp button */
.whatsapp-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background-color: #25D366;
    color: white;
    border: none;
    border-radius: 50%;
    width: 30px;
    height: 30px;
    padding: 0;
    margin-left: 8px;
    box-shadow: 0 3px 8px rgba(37, 211, 102, 0.3);
    transition: all 0.3s ease;
}

.whatsapp-btn:hover {
    background-color: #128C7E;
    transform: scale(1.15);
    box-shadow: 0 5px 12px rgba(37, 211, 102, 0.4);
}

/* Formulário de busca e filtros */
.search-card {
    border-radius: 15px;
    overflow: hidden;
    margin-bottom: 30px;
    box-shadow: 0 5px 15px var(--shadow-color);
    border: none;
}

.search-card .card-body {
    padding: 20px 25px;
}

.search-card .form-control {
    border-radius: 10px;
    padding: 12px 15px;
    border: 1px solid rgba(0,0,0,0.1);
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    transition: all 0.3s;
}

.search-card .form-control:focus {
    box-shadow: 0 2px 10px rgba(46, 125, 50, 0.15);
    border-color: var(--primary-color);
}

.search-card .btn-primary {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
    box-shadow: 0 3px 8px var(--shadow-color);
    padding: 12px 20px;
    border-radius: 10px;
    transition: all 0.3s;
}

.search-card .btn-primary:hover {
    background-color: #225f26;
    transform: translateY(-2px);
    box-shadow: 0 5px 12px var(--shadow-color);
}

/* Estilos gerais dos botões */
.btn {
    border-radius: 10px;
    padding: 8px 16px;
    transition: all 0.3s;
    font-weight: 500;
}

.btn:hover {
    transform: translateY(-2px);
}

.btn-sm {
    border-radius: 8px;
    font-size: 12px;
}

.btn-success {
    background-color: #2E7D32;
    border-color: #2E7D32;
    box-shadow: 0 3px 8px rgba(46, 125, 50, 0.2);
}

.btn-success:hover {
    background-color: #1B5E20;
    border-color: #1B5E20;
    box-shadow: 0 5px 12px rgba(46, 125, 50, 0.3);
}

.btn-danger {
    box-shadow: 0 3px 8px rgba(244, 67, 54, 0.2);
}

.btn-danger:hover {
    box-shadow: 0 5px 12px rgba(244, 67, 54, 0.3);
}
</style>

<script>
// Definir nível de acesso globalmente
var nivelAcesso = '<?php echo $nivel_acesso; ?>';
console.log('Nível de acesso definido:', nivelAcesso);

// Função para visualizar detalhes do pedido
function verDetalhesPedido(pedido) {
    console.log('Visualizando detalhes do pedido:', pedido);
    
    // Limpar quaisquer backdrops antigos que possam estar causando problemas
    document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
        if (backdrop && backdrop.parentNode) {
            backdrop.parentNode.removeChild(backdrop);
        }
    });
    
    // Restaurar a rolagem da página
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
    
    // Verificar se já existe um modal de detalhes aberto e removê-lo
    var detalhesModalExistente = document.getElementById('detalhesModal');
    if (detalhesModalExistente) {
        try {
            // Tentar remover o modal usando o Bootstrap
            var bsModal = bootstrap.Modal.getInstance(detalhesModalExistente);
            if (bsModal) {
                bsModal.dispose();
            }
        } catch (error) {
            console.error('Erro ao remover modal existente:', error);
        }
        
        // Verificar se o elemento tem um pai antes de tentar removê-lo
        if (detalhesModalExistente.parentNode) {
            detalhesModalExistente.parentNode.removeChild(detalhesModalExistente);
        }
    }
    
    // Armazenar o pedido atual em uma variável global para uso posterior
    window.pedidoAtual = pedido;
    
    // Criar o modal de detalhes dinamicamente
    var modalContainer = document.createElement('div');
    modalContainer.innerHTML = `
    <div class="modal fade" id="detalhesModal" tabindex="-1" aria-labelledby="detalhesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="detalhesModalLabel">Detalhes do Pedido</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header">
                                    <h5 class="mb-0">Informações do Cliente</h5>
                                </div>
                                <div class="card-body">
                                    <p><strong>Nome:</strong> <span id="detalhesNome">${pedido.NOME || 'Não informado'}</span></p>
                                    <p><strong>Endereço:</strong> <span id="detalhesEndereco">${pedido.ENDEREÇO || 'Não informado'}</span></p>
                                    <p><strong>Contato:</strong> <span id="detalhesContato">${pedido.CONTATO || 'Não informado'}</span></p>
                                    <p><strong>Data:</strong> <span id="detalhesData">${pedido.DATA || 'Não informada'}</span></p>
                                    <p><strong>Pacote:</strong> <span id="detalhesPacote">${pedido.PACOTE_ESCOLHIDO || 'Não informado'}</span></p>
                                    ${pedido.Rec ? '<p><strong class="text-danger">Pedido de Recuperação</strong></p>' : ''}
                                    ${pedido.versao ? `<p><strong>Versão:</strong> <span class="badge ${
                                        pedido.versao.toLowerCase() === 'v2-main-n8n' ? 'bg-primary' : 
                                        pedido.versao.toLowerCase() === 'v1-main' ? 'bg-secondary' : 'bg-info'
                                    }" title="${
                                        pedido.versao.toLowerCase() === 'v2-main-n8n' ? 'Versão principal n8n' : 
                                        pedido.versao.toLowerCase() === 'v1-main' ? 'Versão antiga principal' : 'Versão experimental'
                                    }">${
                                        pedido.versao.toLowerCase() === 'v2-main-n8n' ? 'main' : 
                                        pedido.versao.toLowerCase() === 'v1-main' ? 'OLD' : 'experimental'
                                    }</span></p>` : ''}
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header">
                                    <h5 class="mb-0">Informações do Pedido</h5>
                                </div>
                                <div class="card-body">
                                    <p><strong>Status:</strong> <span class="status-badge status-${pedido.status || 'aguardando'}">${formatarStatus(pedido.status || 'aguardando')}</span>
                                    ${pedido.versao ? `<span class="badge ${
                                        pedido.versao.toLowerCase() === 'v2-main-n8n' ? 'bg-primary' : 
                                        pedido.versao.toLowerCase() === 'v1-main' ? 'bg-secondary' : 'bg-info'
                                    } ms-1" style="font-size: 10px;" title="${
                                        pedido.versao.toLowerCase() === 'v2-main-n8n' ? 'Versão principal n8n' : 
                                        pedido.versao.toLowerCase() === 'v1-main' ? 'Versão antiga principal' : 'Versão experimental'
                                    }">${
                                        pedido.versao.toLowerCase() === 'v2-main-n8n' ? 'main' : 
                                        pedido.versao.toLowerCase() === 'v1-main' ? 'OLD' : 'experimental'
                                    }</span>` : ''}
                                    </p>
                                    <p><strong>Fonte:</strong> ${pedido.fonte || 'JSON'}</p>
                                    ${pedido.rastreio ? `<p><strong>Código de Rastreio:</strong> ${pedido.rastreio}</p>` : ''}
                                    ${pedido.tratamento ? `<p><strong>Tratamento:</strong> ${pedido.tratamento}</p>` : ''}
                                    ${pedido.data_ligacao ? `<p><strong>Data para Ligação:</strong> ${pedido.data_ligacao}</p>` : ''}
                                    ${pedido.estado_encomenda ? `<p><strong>Estado da Encomenda:</strong> ${pedido.estado_encomenda}</p>` : ''}
                                    ${pedido.atualizacao ? `<p><strong>Última Atualização:</strong> ${pedido.atualizacao}</p>` : ''}
                                    <p><strong>ID do arquivo:</strong> <span id="detalhesArquivo">${pedido.arquivo || (pedido.fonte === 'trello' ? pedido.id_trello : 'Não disponível')}</span></p>
                                    ${pedido.pedido_id ? `<p><strong>ID do pedido:</strong> ${pedido.pedido_id}</p>` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card mb-3">
                        <div class="card-header">
                            <h5 class="mb-0">Problema Relatado</h5>
                        </div>
                        <div class="card-body">
                            <p>${pedido.PROBLEMA_RELATADO || 'Não informado'}</p>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Perguntas e Respostas</h5>
                        </div>
                        <div class="card-body">
                            ${pedido.PERGUNTAS_FEITAS && pedido.PERGUNTAS_FEITAS.length > 0 ? 
                                `<ul>${pedido.PERGUNTAS_FEITAS.map(p => `<li>${p}</li>`).join('')}</ul>` : 
                                '<p>Não há perguntas registradas</p>'}
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="btnFecharModal" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Fechar
                    </button>
                    <button type="button" id="btnAcao" class="btn btn-primary">
                        <i class="fas fa-shipping-fast me-1"></i> Processar Pedido
                    </button>
                    <button type="button" id="btnRemover" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i> Remover
                    </button>
                    <a href="#" id="btnTrello" class="btn btn-info" target="_blank" style="display:none;">
                        <i class="fab fa-trello me-1"></i> Ver no Trello
                    </a>
                </div>
            </div>
        </div>
    </div>`;
    
    // Adicionar o modal ao DOM
    document.body.appendChild(modalContainer.firstElementChild);
    
    // Obter referência ao elemento do modal
    var modalElement = document.getElementById('detalhesModal');
    
    // Configurar botões
    var btnAcao = document.getElementById('btnAcao');
    var btnRemover = document.getElementById('btnRemover');
    var btnTrello = document.getElementById('btnTrello');
    var btnFecharModal = document.getElementById('btnFecharModal');
    
    // Adicionar um evento de clique ao botão de fechar para garantir limpeza adequada
    if (btnFecharModal) {
        btnFecharModal.addEventListener('click', function() {
            fecharModalDetalhes();
        });
    }
    
    // Adicionar um evento para quando o modal é fechado
    modalElement.addEventListener('hidden.bs.modal', function() {
        fecharModalDetalhes();
    });
    
    // Botão de ação principal
    if (btnAcao && (pedido.status === 'aguardando' || pedido.status === 'ligar') && 
        (nivelAcesso === 'logistica' || nivelAcesso === 'vendas')) {
        btnAcao.style.display = 'inline-block';
        btnAcao.onclick = function() {
            fecharModalDetalhes();
            processarPedido(pedido.fonte === 'trello' ? pedido.id_trello : pedido.arquivo);
        };
    } else if (btnAcao) {
        btnAcao.style.display = 'none';
    }
    
    // Botão de remover
    if (btnRemover && (nivelAcesso === 'logistica' || nivelAcesso === 'vendas')) {
        btnRemover.style.display = 'inline-block';
        btnRemover.onclick = function() {
            fecharModalDetalhes();
            
            if (pedido.id_trello) {
                removerPedidoTrello(pedido.id_trello, pedido.NOME || 'Cliente');
            } else {
                // Passar o pedido_id se disponível
                removerPedido(pedido.arquivo, pedido.NOME || 'Cliente', pedido.pedido_id || null);
            }
        };
    } else if (btnRemover) {
        btnRemover.style.display = 'none';
    }
    
    // Botão do Trello
    if (btnTrello && pedido.fonte === 'trello' && pedido.id_trello) {
        btnTrello.style.display = 'inline-block';
        btnTrello.href = 'https://trello.com/c/' + pedido.id_trello;
    } else if (btnTrello) {
        btnTrello.style.display = 'none';
    }
    
    // Exibir o modal
    try {
        var myModal = new bootstrap.Modal(modalElement);
        myModal.show();
    } catch (error) {
        console.error('Erro ao exibir modal:', error);
        // Fallback para exibir o modal manualmente
        if (modalElement) {
            modalElement.style.display = 'block';
            modalElement.classList.add('show');
            modalElement.setAttribute('aria-modal', 'true');
            modalElement.setAttribute('role', 'dialog');
            document.body.classList.add('modal-open');
            
            // Criar backdrop manual
            var backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            document.body.appendChild(backdrop);
            
            // Adicionar handler para botões de fechar
            var closeButtons = modalElement.querySelectorAll('[data-bs-dismiss="modal"]');
            closeButtons.forEach(function(btn) {
                btn.onclick = function() {
                    fecharModalDetalhes();
                };
            });
        }
    }
}

// Função auxiliar para fechar o modal e garantir a limpeza correta
function fecharModalDetalhes() {
    var modalElement = document.getElementById('detalhesModal');
    
    try {
        var modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) modal.hide();
    } catch (error) {
        console.error('Erro ao fechar modal:', error);
    }
    
    // Remover o modal do DOM
    if (modalElement && modalElement.parentNode) {
        modalElement.parentNode.removeChild(modalElement);
    }
    
    // Remover backdrops
    document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
        if (backdrop && backdrop.parentNode) {
            backdrop.parentNode.removeChild(backdrop);
        }
    });
    
    // Restaurar a rolagem da página
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
}

/**
 * Função para formatar o status do pedido
 */
function formatarStatus(status) {
    switch(status) {
        case 'aguardando':
            return 'Aguardando';
        case 'ligar':
            return 'Clientes a Ligar';
        case 'em_processamento':
            return 'Pronto para Envio';
        case 'processado':
            return 'Em Trânsito';
        case 'devolucao':
            return 'Devolução';
        case 'concluido':
            return 'Entregue';
        default:
            return status.charAt(0).toUpperCase() + status.slice(1);
    }
}

/**
 * Função para formatar data
 */
function formatarData(data) {
    if (data instanceof Date) {
        return data.getDate().toString().padStart(2, '0') + '/' + 
               (data.getMonth() + 1).toString().padStart(2, '0') + '/' + 
               data.getFullYear();
    }
    return data;
}

// Função para processar pedido
function processarPedido(arquivo) {
    console.log('Processando pedido:', arquivo);
    console.log('Tipo do arquivo:', typeof arquivo);
    console.log('Comprimento do arquivo:', arquivo ? arquivo.length : 'undefined');
    
    // Verificar se arquivo está definido
    if (!arquivo) {
        console.error('Erro: Identificador do pedido não encontrado');
        
        // Verificar se temos o pedido atual em memória
        if (window.pedidoAtual) {
            console.log('Usando dados do pedido atual para processamento');
            console.log('Pedido atual:', window.pedidoAtual);
            
            // Se temos o arquivo no pedido atual, usamos ele
            if (window.pedidoAtual.arquivo) {
                console.log('Usando arquivo do pedido atual:', window.pedidoAtual.arquivo);
                processarPedido(window.pedidoAtual.arquivo);
                return;
            }
            
            // Se é do Trello, usar o ID do Trello
            if (window.pedidoAtual.fonte === 'trello' && window.pedidoAtual.id_trello) {
                console.log('Usando ID do Trello:', window.pedidoAtual.id_trello);
                processarPedido(window.pedidoAtual.id_trello);
                return;
            }
            
            // Caso contrário, tentamos buscar o arquivo pelo nome e data
            var nome = window.pedidoAtual.NOME;
            var data = window.pedidoAtual.DATA;
            
            if (nome && data) {
                console.log('Buscando arquivo por nome e data:', nome, data);
                // Mostrar indicador de carregamento
                var loadingDiv = document.createElement('div');
                loadingDiv.id = 'tempLoadingIndicator';
                loadingDiv.style.position = 'fixed';
                loadingDiv.style.top = '0';
                loadingDiv.style.left = '0';
                loadingDiv.style.width = '100%';
                loadingDiv.style.height = '100%';
                loadingDiv.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
                loadingDiv.style.display = 'flex';
                loadingDiv.style.justifyContent = 'center';
                loadingDiv.style.alignItems = 'center';
                loadingDiv.style.zIndex = '9999';
                
                var content = document.createElement('div');
                content.style.backgroundColor = 'white';
                content.style.padding = '20px';
                content.style.borderRadius = '5px';
                content.style.textAlign = 'center';
                
                content.innerHTML = '<div class="spinner-border text-primary mb-3" role="status"></div>' +
                                  '<div>Buscando informações do pedido...</div>';
                
                loadingDiv.appendChild(content);
                document.body.appendChild(loadingDiv);
                
                // Buscar o arquivo pelo nome e data
                fetch('obter_pedido_json.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        nome: nome,
                        data: data
                    })
                })
                .then(response => response.json())
                .then(data => {
                    // Remover indicador de carregamento
                    if (document.body.contains(loadingDiv)) {
                        document.body.removeChild(loadingDiv);
                    }
                    
                    if (data.success && data.arquivo) {
                        console.log('Arquivo encontrado:', data.arquivo);
                        processarPedido(data.arquivo);
                    } else {
                        console.error('Erro ao buscar arquivo:', data.error);
                        alert('Erro: Não foi possível identificar o arquivo do pedido. ' + (data.error || ''));
                    }
                })
                .catch(error => {
                    // Remover indicador de carregamento
                    if (document.body.contains(loadingDiv)) {
                        document.body.removeChild(loadingDiv);
                    }
                    
                    console.error('Erro ao buscar arquivo:', error);
                    alert('Erro: Identificador do pedido não encontrado. Por favor, tente novamente.');
                });
                return;
            } else {
                console.error('Nome ou data não disponíveis:', nome, data);
                alert('Erro: Informações insuficientes para identificar o pedido.');
                return;
            }
        } else {
            console.error('Pedido atual não disponível');
            alert('Erro: Identificador do pedido não encontrado.');
            return;
        }
    }
    
    // Verificar se é um ID do Trello (24 caracteres hexadecimais)
    var isTrelloId = arquivo && arquivo.length === 24 && /^[0-9a-f]{24}$/i.test(arquivo);
    console.log('É ID do Trello?', isTrelloId);
    
    // Verificar se já existe um modal de processamento aberto e removê-lo
    var processoModalExistente = document.getElementById('processoModal');
    if (processoModalExistente) {
        try {
            // Tentar remover o modal usando o Bootstrap
            var bsModal = bootstrap.Modal.getInstance(processoModalExistente);
            if (bsModal) {
                bsModal.dispose();
            }
        } catch (error) {
            console.error('Erro ao remover modal existente:', error);
        }
        
        // Verificar se o elemento tem um pai antes de tentar removê-lo
        if (processoModalExistente.parentNode) {
            processoModalExistente.parentNode.removeChild(processoModalExistente);
        }
    }
    
    console.log('Abrindo modal de processamento para:', arquivo);
    
    // Criar o modal de processamento dinamicamente
    var modalContainer = document.createElement('div');
    modalContainer.innerHTML = `
    <div class="modal fade" id="processoModal" tabindex="-1" aria-labelledby="processoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="processoModalLabel">
                        <i class="fas fa-cogs me-2"></i>Processar Pedido
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info mb-4">
                        <div class="d-flex">
                            <div class="me-3 fs-3">
                                <i class="fas fa-info-circle"></i>
                            </div>
                            <div>
                                <h5 class="alert-heading">Instruções</h5>
                                <p class="mb-0">Escolha como deseja processar este pedido. Complete as informações necessárias para cada tipo de processamento.</p>
                            </div>
                        </div>
                    </div>
                    
                    <form id="formProcessamento">
                        <input type="hidden" id="processo-arquivo" name="arquivo" value="">
                        
                        <!-- Tipo de Processamento -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">Tipo de Processamento</label>
                            <div class="row">
                                <div class="col-md-4">
                            <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipoProcessamento" id="tipoLigar" value="ligar">
                                        <label class="form-check-label" for="tipoLigar">
                                            <i class="fas fa-phone-alt me-1 text-purple"></i> <strong>Agendar Ligação</strong>
                                            <small class="d-block text-muted">Mover para "Clientes a Ligar"</small>
                                </label>
                            </div>
                                </div>
                                <div class="col-md-4">
                            <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipoProcessamento" id="tipoProcessar" value="processar" checked>
                                        <label class="form-check-label" for="tipoProcessar">
                                            <i class="fas fa-box-open me-1 text-warning"></i> <strong>Pronto para Envio</strong>
                                            <small class="d-block text-muted">Preparar para despacho</small>
                                </label>
                            </div>
                        </div>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipoProcessamento" id="tipoEnviar" value="enviar">
                                        <label class="form-check-label" for="tipoEnviar">
                                            <i class="fas fa-shipping-fast me-1 text-success"></i> <strong>Enviar Pedido</strong>
                                            <small class="d-block text-muted">Mover para "Em Trânsito"</small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Seção para Agendar Ligação -->
                        <div id="secaoLigar" style="display:none;">
                            <div class="card border-purple mb-3">
                                <div class="card-header bg-purple text-white">
                                    <h6 class="mb-0"><i class="fas fa-phone-alt me-2"></i>Informações da Ligação</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <label for="dataLigacao" class="form-label">Data para Ligação <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                                                <input type="date" class="form-control" id="dataLigacao" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="horaLigacao" class="form-label">Hora para Ligação</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-clock"></i></span>
                                                <input type="time" class="form-control" id="horaLigacao" value="09:00">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <label for="observacoesLigacao" class="form-label">Observações</label>
                                        <textarea class="form-control" id="observacoesLigacao" rows="2" placeholder="Observações sobre a ligação..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Seção para Envio -->
                        <div id="secaoEnvio" style="display:block;">
                            <div class="card border-success mb-3">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0"><i class="fas fa-shipping-fast me-2"></i>Informações de Envio</h6>
                                </div>
                                <div class="card-body">
                        <div class="mb-3">
                                        <label for="codigoRastreio" class="form-label">Código de Rastreio</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-barcode"></i></span>
                                            <input type="text" class="form-control" id="codigoRastreio" placeholder="Insira o código de rastreio (opcional para 'Pronto para Envio')">
                                        </div>
                                        <small class="form-text text-muted">Obrigatório apenas para "Enviar Pedido"</small>
                                    </div>
                                    <div class="mb-3">
                                        <label for="transportadora" class="form-label">Transportadora</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-truck"></i></span>
                                            <select class="form-select" id="transportadora">
                                                <option value="CTT">CTT - Correios de Portugal</option>
                                                <option value="DPD">DPD</option>
                                                <option value="UPS">UPS</option>
                                                <option value="FedEx">FedEx</option>
                                                <option value="Outro">Outro</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Informações do Tratamento -->
                        <div class="card border-info mb-3">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0"><i class="fas fa-medkit me-2"></i>Informações do Tratamento</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <label for="tratamento" class="form-label">Tratamento</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-pills"></i></span>
                            <select class="form-select" id="tratamento" required>
                                <option value="1 Mês" selected data-price="74,98">1 Mês - 74,98€</option>
                                <option value="2 Meses" data-price="119,98">2 Meses - 119,98€</option>
                                <option value="3 Meses" data-price="149,98">3 Meses - 149,98€</option>
                                                <option value="personalizado" data-price="custom">Preço Personalizado</option>
                            </select>
                        </div>
                                        <!-- Campo para preço personalizado -->
                                        <div id="precoPersonalizadoContainer" style="display: none;" class="mt-2">
                                            <label for="precoPersonalizado" class="form-label">Valor Personalizado</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-euro-sign"></i></span>
                                                <input type="number" class="form-control" id="precoPersonalizado" 
                                                       placeholder="0,00" step="0.01" min="0">
                                                <span class="input-group-text">€</span>
                                            </div>
                                            <small class="form-text text-muted">Digite o valor em euros (ex: 99,99)</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="origem" class="form-label">Origem do Pedido</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-tag"></i></span>
                                            <select class="form-select" id="origem">
                                                <option value="whatsapp">WhatsApp</option>
                                                <option value="facebook">Facebook</option>
                                                <option value="site">Site</option>
                                                <option value="outro">Outro</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Preview da Ação -->
                        <div class="alert alert-light border" id="previewAcao">
                            <div class="d-flex align-items-center">
                                <div class="me-3 fs-4" id="previewIcon">
                                    <i class="fas fa-box-open text-warning"></i>
                                </div>
                                <div>
                                    <h6 class="mb-1" id="previewTitulo">Pronto para Envio</h6>
                                    <p class="mb-0 text-muted" id="previewDescricao">O pedido será marcado como pronto para envio e movido para a lista correspondente no Trello.</p>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancelar
                    </button>
                    <button type="button" id="btnConfirmarProcesso" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-1"></i> Confirmar Processamento
                    </button>
                </div>
            </div>
        </div>
    </div>`;
    
    // Adicionar o modal ao DOM
    document.body.appendChild(modalContainer.firstElementChild);
    
    // Obter referência ao elemento do modal
    var modalElement = document.getElementById('processoModal');
    
    // Configurar eventos do modal
    document.querySelectorAll('input[name="tipoProcessamento"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            var codigoRastreioInput = document.getElementById('codigoRastreio');
            var codigoRastreioLabel = document.querySelector('label[for="codigoRastreio"]');
            var helpText = codigoRastreioInput.parentNode.nextElementSibling;
            
            if (this.value === 'ligar') {
                document.getElementById('secaoLigar').style.display = 'block';
                document.getElementById('secaoEnvio').style.display = 'none';
                document.getElementById('previewIcon').innerHTML = '<i class="fas fa-phone-alt text-purple"></i>';
                document.getElementById('previewTitulo').textContent = 'Agendar Ligação';
                document.getElementById('previewDescricao').textContent = 'O pedido será movido para "Clientes a Ligar" e agendado para contato telefônico.';
            } else if (this.value === 'processar') {
                document.getElementById('secaoLigar').style.display = 'none';
                document.getElementById('secaoEnvio').style.display = 'block';
                document.getElementById('previewIcon').innerHTML = '<i class="fas fa-box-open text-warning"></i>';
                document.getElementById('previewTitulo').textContent = 'Pronto para Envio';
                document.getElementById('previewDescricao').textContent = 'O pedido será marcado como pronto para envio e preparado para despacho.';
                // Tornar o código de rastreio opcional para "processar"
                codigoRastreioInput.required = false;
                codigoRastreioInput.placeholder = 'Insira o código de rastreio (opcional para "Pronto para Envio")';
                if (codigoRastreioLabel) {
                    codigoRastreioLabel.innerHTML = 'Código de Rastreio';
                }
                if (helpText) {
                    helpText.textContent = 'Obrigatório apenas para "Enviar Pedido"';
                }
            } else if (this.value === 'enviar') {
                document.getElementById('secaoLigar').style.display = 'none';
                document.getElementById('secaoEnvio').style.display = 'block';
                document.getElementById('previewIcon').innerHTML = '<i class="fas fa-shipping-fast text-success"></i>';
                document.getElementById('previewTitulo').textContent = 'Enviar Pedido';
                document.getElementById('previewDescricao').textContent = 'O pedido será enviado e movido para "Em Trânsito" com código de rastreio.';
                // Tornar o código de rastreio obrigatório para "enviar"
                codigoRastreioInput.required = true;
                codigoRastreioInput.placeholder = 'Insira o código de rastreio';
                if (codigoRastreioLabel) {
                    codigoRastreioLabel.innerHTML = 'Código de Rastreio <span class="text-danger">*</span>';
                }
                if (helpText) {
                    helpText.textContent = 'Obrigatório para envio';
                }
            }
        });
    });
    
    // Configurar evento para o select de tratamento
    var tratamentoSelect = document.getElementById('tratamento');
    if (tratamentoSelect) {
        tratamentoSelect.addEventListener('change', function() {
            var precoContainer = document.getElementById('precoPersonalizadoContainer');
            var precoInput = document.getElementById('precoPersonalizado');
            
            if (this.value === 'personalizado') {
                precoContainer.style.display = 'block';
                precoInput.required = true;
                precoInput.focus();
            } else {
                precoContainer.style.display = 'none';
                precoInput.required = false;
                precoInput.value = '';
            }
        });
    }
    
    // Exibir o modal
    try {
        var myModal = new bootstrap.Modal(modalElement);
        myModal.show();
    } catch (error) {
        console.error('Erro ao exibir modal de processamento:', error);
        // Fallback para exibir o modal manualmente
        if (modalElement) {
            modalElement.style.display = 'block';
            modalElement.classList.add('show');
            modalElement.setAttribute('aria-modal', 'true');
            modalElement.setAttribute('role', 'dialog');
            document.body.classList.add('modal-open');
            
            // Criar backdrop manual
            var backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            document.body.appendChild(backdrop);
            
            // Adicionar handler para botões de fechar
            var closeButtons = modalElement.querySelectorAll('[data-bs-dismiss="modal"]');
            closeButtons.forEach(function(btn) {
                btn.onclick = function() {
                    modalElement.style.display = 'none';
                    modalElement.classList.remove('show');
                    document.body.classList.remove('modal-open');
                    if (backdrop && backdrop.parentNode) {
                        backdrop.parentNode.removeChild(backdrop);
                    }
                };
            });
        }
    }
    
    // Configurar o botão de confirmação
    var btnConfirmarProcesso = document.getElementById('btnConfirmarProcesso');
    btnConfirmarProcesso.addEventListener('click', function() {
        console.log('Botão de confirmação clicado');
        
        // Validar formulário
        var tipoProcessamento = document.querySelector('input[name="tipoProcessamento"]:checked').value;
        console.log('Tipo de processamento selecionado:', tipoProcessamento);
        
        if (tipoProcessamento === 'ligar' && !document.getElementById('dataLigacao').value) {
            alert('Por favor, selecione uma data para a ligação.');
            return;
        }
        
        if (tipoProcessamento === 'enviar' && !document.getElementById('codigoRastreio').value.trim()) {
            alert('Por favor, insira o código de rastreio para envio.');
            return;
        }
        
        // Validar preço personalizado se selecionado
        var tratamentoSelecionado = document.getElementById('tratamento').value;
        var precoPersonalizado = '';
        
        if (tratamentoSelecionado === 'personalizado') {
            var precoInput = document.getElementById('precoPersonalizado');
            if (!precoInput.value || parseFloat(precoInput.value) <= 0) {
                alert('Por favor, insira um valor válido para o preço personalizado.');
                precoInput.focus();
                return;
            }
            precoPersonalizado = parseFloat(precoInput.value).toFixed(2);
            tratamentoSelecionado = 'Personalizado - ' + precoPersonalizado + '€';
        }
        
        console.log('Tratamento selecionado:', tratamentoSelecionado);
        
        // Fechar o modal
        try {
            var bsModal = bootstrap.Modal.getInstance(modalElement);
            if (bsModal) {
                bsModal.hide();
            }
        } catch (error) {
            console.error('Erro ao fechar modal de processamento:', error);
            // Fallback para esconder o modal manualmente
            if (modalElement) {
                modalElement.style.display = 'none';
                modalElement.classList.remove('show');
                document.body.classList.remove('modal-open');
                var backdrop = document.querySelector('.modal-backdrop');
                if (backdrop && backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
            }
        }
        
        // Mostrar indicador de carregamento
        var loadingDiv = document.createElement('div');
        loadingDiv.id = 'loadingIndicator';
        loadingDiv.style.position = 'fixed';
        loadingDiv.style.top = '0';
        loadingDiv.style.left = '0';
        loadingDiv.style.width = '100%';
        loadingDiv.style.height = '100%';
        loadingDiv.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
        loadingDiv.style.display = 'flex';
        loadingDiv.style.justifyContent = 'center';
        loadingDiv.style.alignItems = 'center';
        loadingDiv.style.zIndex = '9999';
        
        var content = document.createElement('div');
        content.style.backgroundColor = 'white';
        content.style.padding = '20px';
        content.style.borderRadius = '5px';
        content.style.textAlign = 'center';
        
        content.innerHTML = '<div class="spinner-border text-primary mb-3" role="status"></div>' +
                           '<div>Processando pedido...</div>';
        
        loadingDiv.appendChild(content);
        document.body.appendChild(loadingDiv);
        
        // Construir dados para envio
        var dados = {
            arquivo: arquivo,
            processado: true
        };
        
        console.log('Arquivo para processamento:', arquivo);
        
        // Determinar o tipo correto baseado na seleção
        if (tipoProcessamento === 'ligar') {
            dados.tipo = 'ligar';
            dados.data_ligacao = formatarData(new Date(document.getElementById('dataLigacao').value));
            dados.tratamento = tratamentoSelecionado;
            dados.lista_id = '<?php echo $trello_list_ids['ligar']; ?>';
        } else if (tipoProcessamento === 'processar') {
            dados.tipo = 'processar';
            var codigoRastreio = document.getElementById('codigoRastreio').value.trim();
            if (codigoRastreio) {
                dados.rastreio = codigoRastreio;
            }
            dados.tratamento = tratamentoSelecionado;
        } else if (tipoProcessamento === 'enviar') {
            dados.tipo = 'envio'; // Usar 'envio' para compatibilidade com o backend
            dados.rastreio = document.getElementById('codigoRastreio').value.trim();
            dados.tratamento = tratamentoSelecionado;
            dados.transportadora = document.getElementById('transportadora').value;
            dados.origem = document.getElementById('origem').value;
        }
        
        // Adicionar preço personalizado se aplicável
        if (precoPersonalizado) {
            dados.preco_personalizado = precoPersonalizado;
        }
        
        console.log('Enviando dados para processamento:', dados);
        
        // Enviar requisição
        fetch('processar_pedido.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(dados),
        })
        .then(response => {
            console.log('Status da resposta:', response.status);
            console.log('Headers da resposta:', response.headers);
            return response.text();
        })
        .then(text => {
            console.log('Resposta do servidor (texto):', text);
            try {
                var data = JSON.parse(text);
                console.log('Resposta do servidor (JSON):', data);
                if (data.success) {
                    alert('Pedido processado com sucesso!');
                    window.location.reload();
                } else {
                    console.error('Erro no processamento:', data.error);
                    alert('Erro ao processar o pedido: ' + (data.error || 'Erro desconhecido'));
                }
            } catch (e) {
                console.error('Erro ao processar resposta JSON:', e);
                console.log('Resposta do servidor (raw):', text);
                alert('Erro ao processar a resposta do servidor: ' + e.message);
            }
        })
        .catch(error => {
            console.error('Erro na requisição:', error);
            alert('Erro ao processar o pedido: ' + error.message);
        })
        .finally(() => {
            // Remover indicador de carregamento
            if (document.body.contains(loadingDiv)) {
                document.body.removeChild(loadingDiv);
            }
        });
    });
    
    // Preencher o campo arquivo
    document.getElementById('processo-arquivo').value = arquivo;
}

// Função para remover pedido
function removerPedido(arquivo, nome, pedido_id) {
    console.log('Removendo pedido:', arquivo, 'pedido_id:', pedido_id);
    
    if (confirm('Tem certeza que deseja remover o pedido de ' + nome + '?')) {
        // Mostrar indicador de carregamento
        var loadingDiv = document.createElement('div');
        loadingDiv.id = 'loadingIndicator';
        loadingDiv.style.position = 'fixed';
        loadingDiv.style.top = '0';
        loadingDiv.style.left = '0';
        loadingDiv.style.width = '100%';
        loadingDiv.style.height = '100%';
        loadingDiv.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
        loadingDiv.style.display = 'flex';
        loadingDiv.style.justifyContent = 'center';
        loadingDiv.style.alignItems = 'center';
        loadingDiv.style.zIndex = '9999';
        
        var content = document.createElement('div');
        content.style.backgroundColor = 'white';
        content.style.padding = '20px';
        content.style.borderRadius = '5px';
        content.style.textAlign = 'center';
        
        content.innerHTML = '<div class="spinner-border text-primary mb-3" role="status"></div>' +
                          '<div>Removendo pedido...</div>';
        
        loadingDiv.appendChild(content);
        document.body.appendChild(loadingDiv);
        
        // Verificar se arquivo parece um ID do Trello (24 caracteres hexadecimais)
        var isTrelloId = arquivo && arquivo.length === 24 && /^[0-9a-f]{24}$/i.test(arquivo);
        var endpoint = isTrelloId ? 'remover_pedido_trello.php' : 'remover_pedido.php';
        var dados = isTrelloId ? { id_trello: arquivo } : { arquivo: arquivo };
        
        // Adicionar pedido_id aos dados se disponível
        if (pedido_id) {
            dados.pedido_id = pedido_id;
        }
        
        // Garantir que o arquivo tenha a extensão .json se não for um ID do Trello
        if (!isTrelloId && !arquivo.endsWith('.json')) {
            dados.arquivo = arquivo + '.json';
            console.log('Nome do arquivo ajustado para:', dados.arquivo);
        }
        
        // Adicionar dados da sessão
        dados.sid = '<?php echo session_id(); ?>';
        dados.usuario_id = '<?php echo $_SESSION["usuario_id"]; ?>';
        
        console.log('Enviando dados para remoção:', dados);
        
        // Enviar requisição
        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(dados),
            credentials: 'same-origin'
        })
        .then(response => {
            console.log('Status da resposta:', response.status);
            return response.text();
        })
        .then(text => {
            console.log('Resposta do servidor:', text);
            try {
                var data = JSON.parse(text);
                if (data.success) {
                    alert('Pedido removido com sucesso!');
                    window.location.reload();
                } else {
                    alert('Erro ao remover o pedido: ' + (data.error || 'Erro desconhecido'));
                }
            } catch (e) {
                console.error('Erro ao processar resposta:', e);
                console.log('Resposta do servidor:', text);
                alert('Erro ao processar a resposta do servidor: ' + e.message);
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('Erro ao remover o pedido: ' + error.message);
        })
        .finally(() => {
            // Remover indicador de carregamento
            if (document.body.contains(loadingDiv)) {
                document.body.removeChild(loadingDiv);
            }
        });
    }
}

// Função para remover pedido do Trello
function removerPedidoTrello(id_trello, nome) {
    console.log('Removendo pedido do Trello:', id_trello);
    
    if (confirm('Tem certeza que deseja remover o pedido de ' + nome + ' do Trello?')) {
        // Mostrar indicador de carregamento
        var loadingDiv = document.createElement('div');
        loadingDiv.id = 'loadingIndicator';
        loadingDiv.style.position = 'fixed';
        loadingDiv.style.top = '0';
        loadingDiv.style.left = '0';
        loadingDiv.style.width = '100%';
        loadingDiv.style.height = '100%';
        loadingDiv.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
        loadingDiv.style.display = 'flex';
        loadingDiv.style.justifyContent = 'center';
        loadingDiv.style.alignItems = 'center';
        loadingDiv.style.zIndex = '9999';
        
        var content = document.createElement('div');
        content.style.backgroundColor = 'white';
        content.style.padding = '20px';
        content.style.borderRadius = '5px';
        content.style.textAlign = 'center';
        
        content.innerHTML = '<div class="spinner-border text-primary mb-3" role="status"></div>' +
                          '<div>Removendo pedido do Trello...</div>';
        
        loadingDiv.appendChild(content);
        document.body.appendChild(loadingDiv);
        
        // Preparar dados para envio
        var dados = {
            id_trello: id_trello,
            sid: '<?php echo session_id(); ?>',
            usuario_id: '<?php echo $_SESSION["usuario_id"]; ?>'
        };
        
        // Enviar requisição
        fetch('remover_pedido_trello.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(dados),
            credentials: 'same-origin'
        })
        .then(response => response.text())
        .then(text => {
            try {
                var data = JSON.parse(text);
                if (data.success) {
                    alert('Pedido removido com sucesso do Trello!');
                    window.location.reload();
                } else {
                    alert('Erro ao remover o pedido: ' + (data.error || 'Erro desconhecido'));
                }
            } catch (e) {
                console.error('Erro ao processar resposta:', e);
                console.log('Resposta do servidor:', text);
                alert('Erro ao processar a resposta do servidor');
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('Erro ao remover o pedido: ' + error.message);
        })
        .finally(() => {
            // Remover indicador de carregamento
            if (document.body.contains(loadingDiv)) {
                document.body.removeChild(loadingDiv);
            }
        });
    }
}

// Função para reprocessar pedido
function reprocessarPedido(id, fonte) {
    console.log('Reprocessando pedido:', id, fonte);
    
    if (confirm('Tem certeza que deseja reprocessar este pedido?')) {
        // Mostrar indicador de carregamento
        var loadingDiv = document.createElement('div');
        loadingDiv.id = 'loadingIndicator';
        loadingDiv.style.position = 'fixed';
        loadingDiv.style.top = '0';
        loadingDiv.style.left = '0';
        loadingDiv.style.width = '100%';
        loadingDiv.style.height = '100%';
        loadingDiv.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
        loadingDiv.style.display = 'flex';
        loadingDiv.style.justifyContent = 'center';
        loadingDiv.style.alignItems = 'center';
        loadingDiv.style.zIndex = '9999';
        
        var content = document.createElement('div');
        content.style.backgroundColor = 'white';
        content.style.padding = '20px';
        content.style.borderRadius = '5px';
        content.style.textAlign = 'center';
        
        content.innerHTML = '<div class="spinner-border text-primary mb-3" role="status"></div>' +
                           '<div>Reprocessando pedido...</div>';
        
        loadingDiv.appendChild(content);
        document.body.appendChild(loadingDiv);
        
        // Enviar requisição para mudar estado do pedido
        fetch('processar_pedido.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                arquivo: id,
                fonte: fonte,
                processado: false
            }),
        })
        .then(response => response.text())
        .then(text => {
            try {
                var data = JSON.parse(text);
                if (data.success) {
                    alert('Pedido reprocessado com sucesso!');
                    window.location.reload();
                } else {
                    alert('Erro ao reprocessar o pedido: ' + (data.error || 'Erro desconhecido'));
                }
            } catch (e) {
                console.error('Erro ao processar resposta:', e);
                console.log('Resposta do servidor:', text);
                alert('Erro ao processar a resposta do servidor');
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('Erro ao reprocessar o pedido: ' + error.message);
        })
        .finally(() => {
            // Remover indicador de carregamento
            if (document.body.contains(loadingDiv)) {
                document.body.removeChild(loadingDiv);
            }
        });
    }
}

// Inicializar eventos quando o DOM estiver carregado
document.addEventListener('DOMContentLoaded', function() {
    // Adicionar evento de clique a todos os cards
    document.querySelectorAll('.pedido-card').forEach(function(card) {
        card.addEventListener('click', function(e) {
            abrirDetalhesCard(this, e);
        });
    });
    
    // Configurar eventos para os modais estáticos
    document.querySelectorAll('.modal').forEach(function(modal) {
        modal.addEventListener('hidden.bs.modal', function() {
            // Limpar backdrops
            document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
                if (backdrop && backdrop.parentNode) {
                    backdrop.parentNode.removeChild(backdrop);
                }
            });
            
            // Restaurar a rolagem da página
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
    });
    
    // Configurar eventos para botões que fecham modais
    document.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            // Esperar um momento para permitir que o Bootstrap processe o evento primeiro
            setTimeout(function() {
                // Limpar backdrops
                document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
                    if (backdrop && backdrop.parentNode) {
                        backdrop.parentNode.removeChild(backdrop);
                    }
                });
                
                // Restaurar a rolagem da página
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }, 300);
        });
    });
});

// Função para abrir detalhes do card
function abrirDetalhesCard(card, event) {
    // Verificar se o clique foi em um botão dentro do card
    if (event && event.target && event.target.closest('.btn')) {
        console.log('Clique em botão, não abrindo modal');
        return; // Não fazer nada, deixar o botão tratar o evento
    }
    
    // Se não foi em um botão, abrir o modal de forma segura
    try {
        if (!card) {
            console.error('Card não encontrado');
            return;
        }
        
        var pedidoData = card.getAttribute('data-pedido');
        if (!pedidoData) {
            console.error('Card sem dados de pedido');
            return;
        }
        
        var pedido = JSON.parse(pedidoData);
        if (!pedido) {
            console.error('Falha ao parsear dados do pedido');
            return;
        }
        
        // Verificação adicional para garantir que o pedido é um objeto válido
        if (typeof pedido !== 'object') {
            console.error('Dados do pedido não é um objeto válido:', pedido);
            return;
        }
        
        verDetalhesPedido(pedido);
    } catch (e) {
        console.error('Erro ao processar dados do pedido:', e);
        
        // Restaurar a rolagem da página em caso de erro
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
        
        // Remover qualquer backdrop que possa ter sido criado
        document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
            if (backdrop && backdrop.parentNode) {
                backdrop.parentNode.removeChild(backdrop);
            }
        });
    }
}
</script>

<!-- Indicadores Principais (Cards grandes e clicáveis) -->
<div class="row mb-4 g-3">
    <?php if ($nivel_acesso == 'logistica' || $nivel_acesso == 'ltv'): ?>
    <div class="col-md mb-3">
        <div class="metric-card" style="background: linear-gradient(135deg, #00BCD4, #4DD0E1);" onclick="window.location.href='pedidos.php?estado=disponivel_para_levantamento'">
            <div class="label">DISPONÍVEL PARA LEVANTAMENTO</div>
            <div class="number"><?= $pedidos_disponivel_levantamento ?></div>
            <div>Pedidos disponíveis para levantamento</div>
            <div class="icon">
                <i class="fas fa-store"></i>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($nivel_acesso == 'logistica' || $nivel_acesso == 'vendas'): ?>
    <div class="col-md mb-3">
        <div class="metric-card gray" onclick="window.location.href='pedidos.php?status=aguardando'">
            <div class="label">NOVOS CLIENTES</div>
            <div class="number"><?= $pedidos_pendentes ?></div>
            <div>Pedidos a processar</div>
            <div class="icon">
                <i class="fas fa-user-plus"></i>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($nivel_acesso == 'logistica'): ?>
    <div class="col-md mb-3">
        <div class="metric-card purple" onclick="window.location.href='pedidos.php?status=ligar'">
            <div class="label">CLIENTES A LIGAR</div>
            <div class="number"><?= $pedidos_ligar ?></div>
            <div>Aguardando contato telefônico</div>
            <div class="icon">
                <i class="fas fa-phone"></i>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($nivel_acesso == 'logistica'): ?>
    <div class="col-md mb-3">
        <div class="metric-card orange" onclick="window.location.href='pedidos.php?status=em_processamento'">
            <div class="label">PRONTO PARA ENVIO</div>
            <div class="number"><?= $pedidos_processando ?></div>
            <div>Aguardando despacho</div>
            <div class="icon">
                <i class="fas fa-box-open"></i>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($nivel_acesso == 'logistica' || $nivel_acesso == 'ltv'): ?>
    <div class="col-md mb-3">
        <div class="metric-card yellow" onclick="window.location.href='pedidos.php?status=processado'">
            <div class="label">EM TRÂNSITO</div>
            <div class="number"><?= $pedidos_processados ?></div>
            <div>Pedidos a caminho</div>
            <div class="icon">
                <i class="fas fa-shipping-fast"></i>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($nivel_acesso == 'logistica' || $nivel_acesso == 'ltv' || $nivel_acesso == 'vendas'): ?>
    <div class="col-md mb-3">
        <div class="metric-card red" onclick="window.location.href='pedidos.php?status=devolucao'">
            <div class="label">DEVOLUÇÕES</div>
            <div class="number"><?= $pedidos_devolucao ?></div>
            <div>Pedidos devolvidos</div>
            <div class="icon">
                <i class="fas fa-undo-alt"></i>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($nivel_acesso == 'logistica' || $nivel_acesso == 'ltv' || $nivel_acesso == 'vendas'): ?>
    <div class="col-md mb-3">
        <div class="metric-card green" onclick="window.location.href='pedidos.php?status=concluido'">
            <div class="label">ENTREGAS CONCLUÍDAS</div>
            <div class="number"><?= $pedidos_concluidos ?></div>
            <div>Pedidos entregues</div>
            <div class="icon">
                <i class="fas fa-check-circle"></i>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
// Se tiver um filtro ativo, mostrar um título indicando a categoria selecionada
if (!empty($filtro_status)) {
    $titulo_filtro = '';
    $descricao_filtro = '';
    $icon_filtro = '';
    $cor_filtro = '';
    
    switch ($filtro_status) {
        case 'aguardando':
            $titulo_filtro = 'NOVOS CLIENTES';
            $descricao_filtro = 'Pedidos a processar';
            $icon_filtro = 'fas fa-user-plus';
            $cor_filtro = 'secondary';
            break;
        case 'ligar':
            $titulo_filtro = 'CLIENTES A LIGAR';
            $descricao_filtro = 'Aguardando contato telefônico';
            $icon_filtro = 'fas fa-phone';
            $cor_filtro = 'purple';
            break;
        case 'em_processamento':
            $titulo_filtro = 'PRONTO PARA ENVIO';
            $descricao_filtro = 'Aguardando despacho';
            $icon_filtro = 'fas fa-box-open';
            $cor_filtro = 'warning';
            break;
        case 'processado':
            $titulo_filtro = 'EM TRÂNSITO';
            $descricao_filtro = 'Pedidos a caminho';
            $icon_filtro = 'fas fa-shipping-fast';
            $cor_filtro = 'warning';
            break;
        case 'devolucao':
            $titulo_filtro = 'DEVOLUÇÕES';
            $descricao_filtro = 'Pedidos devolvidos';
            $icon_filtro = 'fas fa-undo-alt';
            $cor_filtro = 'danger';
            break;
        case 'concluido':
            $titulo_filtro = 'ENTREGAS CONCLUÍDAS';
            $descricao_filtro = 'Pedidos entregues';
            $icon_filtro = 'fas fa-check-circle';
            $cor_filtro = 'success';
            break;
    }
?>
    <!-- Título da seção filtrada -->
    <div class="card mb-4 filter-header">
        <div class="card-body <?= $cor_filtro === 'purple' ? 'bg-purple' : 'bg-'.$cor_filtro ?> text-white" style="background-color: <?= $cor_filtro === 'purple' ? 'rgba(103, 58, 183, 0.9)' : 'rgba(var(--bs-'.$cor_filtro.'-rgb), 0.9)' ?> !important; backdrop-filter: blur(10px); border-radius: 15px;">
            <div class="d-flex align-items-center">
                <div class="me-4 filter-icon">
                    <i class="<?= $icon_filtro ?> fa-3x"></i>
                </div>
                <div>
                    <h3 class="mb-0"><?= $titulo_filtro ?></h3>
                    <p class="mb-0 mt-1 text-white-50"><?= $descricao_filtro ?></p>
                </div>
                <div class="ms-auto">
                    <span class="badge bg-white <?= $cor_filtro === 'purple' ? 'text-purple' : 'text-'.$cor_filtro ?> p-2 fs-6">
                        <?= count($pedidos_a_exibir) ?> pedidos
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Botão para voltar a todos os pedidos -->
    <div class="mb-4">
        <a href="pedidos.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i> Voltar para todos os pedidos
        </a>
    </div>
<?php } else if (!empty($estado_filtro) && $estado_filtro === 'disponivel_para_levantamento') { ?>
    <!-- Título da seção filtrada para Estado Disponível para Levantamento -->
    <div class="card mb-4 filter-header">
        <div class="card-body bg-info text-white" style="background-color: rgba(0, 188, 212, 0.9) !important; backdrop-filter: blur(10px); border-radius: 15px;">
            <div class="d-flex align-items-center">
                <div class="me-4 filter-icon">
                    <i class="fas fa-store fa-3x"></i>
                </div>
                <div>
                    <h3 class="mb-0">DISPONÍVEL PARA LEVANTAMENTO</h3>
                    <p class="mb-0 mt-1 text-white-50">Pedidos disponíveis para retirada</p>
                </div>
                <div class="ms-auto">
                    <span class="badge bg-white text-info p-2 fs-6">
                        <?= count($pedidos_a_exibir) ?> pedidos
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Botão para voltar a todos os pedidos -->
    <div class="mb-4">
        <a href="pedidos.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i> Voltar para todos os pedidos
        </a>
    </div>
<?php } ?>

<!-- Formulário de busca e filtros -->
<div class="card search-card">
    <div class="card-body">
        <form action="pedidos.php" method="GET" class="row g-3">
            <div class="col-md-4">
                <div class="input-group">
                    <input type="text" class="form-control" name="busca" placeholder="Buscar pedidos..." value="<?= htmlspecialchars($busca) ?>">
                    <button class="btn btn-primary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            <div class="col-md-3">
                <input type="date" class="form-control" name="data_inicio" placeholder="Data inicial" value="<?= $filtro_data_inicio ?>">
            </div>
            <div class="col-md-3">
                <input type="date" class="form-control" name="data_fim" placeholder="Data final" value="<?= $filtro_data_fim ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Filtrar</button>
            </div>
        </form>
    </div>
</div>

<!-- Lista de Pedidos -->
<div class="row">
    <?php if (empty($pedidos_a_exibir)): ?>
        <div class="col-12">
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i> Nenhum pedido encontrado nesta categoria.
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($pedidos_a_exibir as $pedido): ?>
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card pedido-card h-100 <?= $pedido['novo'] ? 'novo' : '' ?>" 
                     data-pedido='<?= json_encode($pedido, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>'
                     onclick="abrirDetalhesCard(this, event);">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="m-0">
                            <i class="fas fa-user me-2"></i> 
                            <?= !empty($pedido['NOME']) ? htmlspecialchars($pedido['NOME']) : 'Cliente sem nome' ?>
                            <?php if ($pedido['novo']): ?>
                                <span class="badge bg-warning text-dark ms-1 novo-badge">
                                    <i class="fas fa-bolt"></i> NOVO
                                </span>
                            <?php endif; ?>
                        </h6>
                        <?php
                        // Definir classe de status
                        $status_class = '';
                        $status_text = '';
                        
                        switch($pedido['status']) {
                            case 'aguardando':
                                $status_class = 'status-aguardando';
                                $status_text = 'Aguardando';
                                break;
                            case 'ligar':
                                $status_class = 'status-ligar';
                                $status_text = 'Clientes a Ligar';
                                break;
                            case 'em_processamento':
                                $status_class = 'status-processando';
                                $status_text = 'Pronto para Envio';
                                break;
                            case 'processado':
                                $status_class = 'status-transito';
                                $status_text = 'Em Trânsito';
                                break;
                            case 'devolucao':
                                $status_class = 'status-devolucao';
                                $status_text = 'Devolução';
                                break;
                            case 'concluido':
                                $status_class = 'status-concluido';
                                $status_text = 'Entregue';
                                break;
                            default:
                                $status_class = 'status-aguardando';
                                $status_text = 'Aguardando';
                        }
                        
                        // Verificar se tem estado de "Disponível para Levantamento"
                        $estado_disponivel = false;
                        if (isset($pedido['estado_encomenda']) && 
                            (stripos($pedido['estado_encomenda'], 'disponível para levantamento') !== false || 
                             stripos($pedido['estado_encomenda'], 'disponivel para levantamento') !== false)) {
                            $estado_disponivel = true;
                        }
                        ?>
                        
                        <?php if ($estado_disponivel): ?>
                            <!-- Mostrar dois badges se tiver estado disponível para levantamento -->
                            <div class="dual-badge">
                                <span class="status-badge <?= $status_class ?>"><?= $status_text ?></span>
                                <span class="status-badge status-disponivel">Disponível</span>
                            </div>
                        <?php else: ?>
                            <span class="status-badge <?= $status_class ?>"><?= $status_text ?></span>
                        <?php endif; ?>
                        
                        <?php
                        // Exibir badge da versão do pedido
                        if (isset($pedido['versao'])) {
                            $versao_lower = strtolower($pedido['versao']);
                            if ($versao_lower === 'v2-main-n8n') {
                                $versao_texto = 'main';
                                $versao_class = 'bg-primary';
                                $versao_tooltip = 'Versão principal n8n';
                            } elseif ($versao_lower === 'v1-main') {
                                $versao_texto = 'OLD';
                                $versao_class = 'bg-secondary';
                                $versao_tooltip = 'Versão antiga principal';
                            } else {
                                $versao_texto = 'experimental';
                                $versao_class = 'bg-info';
                                $versao_tooltip = 'Versão experimental';
                            }
                            echo '<span class="badge ' . $versao_class . ' ms-1" style="font-size: 10px;" title="' . $versao_tooltip . '">' . $versao_texto . '</span>';
                        }
                        ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($pedido['ENDEREÇO'])): ?>
                        <div class="mb-2">
                            <strong><i class="fas fa-map-marker-alt me-1"></i> Endereço:</strong>
                            <p class="mb-0"><?= htmlspecialchars($pedido['ENDEREÇO']) ?></p>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($pedido['CONTATO'])): ?>
                        <div class="mb-2">
                            <strong><i class="fas fa-phone me-1"></i> Contato:</strong>
                            <p class="mb-0 d-flex align-items-center">
                                <?= htmlspecialchars($pedido['CONTATO']) ?>
                                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $pedido['CONTATO']) ?>" target="_blank" class="whatsapp-btn" title="Abrir WhatsApp">
                                    <i class="fab fa-whatsapp"></i>
                                </a>
                            </p>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($pedido['PACOTE_ESCOLHIDO'])): ?>
                        <div class="mb-2">
                            <strong><i class="fas fa-box me-1"></i> Produto:</strong>
                            <p class="mb-0"><?= htmlspecialchars($pedido['PACOTE_ESCOLHIDO']) ?></p>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($pedido['DATA'])): ?>
                        <div class="mb-2">
                            <strong><i class="fas fa-calendar me-1"></i> Data:</strong>
                            <p class="mb-0"><?= htmlspecialchars($pedido['DATA']) ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($pedido['fonte'] === 'trello'): ?>
                        <div class="mt-2 text-info">
                            <i class="fab fa-trello me-1"></i> Integrado com Trello
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer text-muted d-flex justify-content-between align-items-center">
                        <small>
                            <i class="far fa-calendar-alt me-1"></i> 
                            <?= date('d/m/Y H:i', $pedido['timestamp']) ?>
                        </small>
                        <div>
                            <?php if (($pedido['status'] === 'aguardando' || $pedido['status'] === 'ligar' || $pedido['status'] === 'em_processamento') && 
                                ($nivel_acesso === 'logistica' || $nivel_acesso === 'vendas')): ?>
                            <!-- Botão processar -->
                            <button type="button" class="btn btn-sm btn-success btn-processar" 
                                    onclick="event.stopPropagation(); processarPedido('<?= $pedido['fonte'] === 'trello' ? htmlspecialchars($pedido['id_trello']) : htmlspecialchars($pedido['arquivo']) ?>');"
                                    data-arquivo="<?= $pedido['fonte'] === 'trello' ? htmlspecialchars($pedido['id_trello']) : htmlspecialchars($pedido['arquivo']) ?>">
                                <i class="fas fa-check me-1"></i> Processar
                            </button>
                            <!-- Botão remover -->
                            <button type="button" class="btn btn-sm btn-danger ms-1 btn-remover" 
                                    onclick="event.stopPropagation(); <?= !empty($pedido['id_trello']) ? 'removerPedidoTrello(\'' . htmlspecialchars($pedido['id_trello']) . '\', \'' . htmlspecialchars($pedido['NOME'] ?? 'Cliente') . '\')' : 'removerPedido(\'' . htmlspecialchars($pedido['arquivo'] ?? '') . '\', \'' . htmlspecialchars($pedido['NOME'] ?? 'Cliente') . '\'' . (isset($pedido['pedido_id']) ? ', \'' . htmlspecialchars($pedido['pedido_id']) . '\'' : ', null') . ')' ?>"
                                    data-arquivo="<?= htmlspecialchars($pedido['arquivo'] ?? '') ?>"
                                    data-id-trello="<?= htmlspecialchars($pedido['id_trello'] ?? '') ?>"
                                    data-pedido-id="<?= htmlspecialchars($pedido['pedido_id'] ?? '') ?>"
                                    data-nome="<?= htmlspecialchars($pedido['NOME'] ?? 'Cliente') ?>">
                                <i class="fas fa-trash me-1"></i> Remover
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($pedido['status'] === 'devolucao' && ($nivel_acesso === 'logistica' || $nivel_acesso === 'vendas')): ?>
                            <!-- Botão reprocessar -->
                            <button type="button" class="btn btn-sm btn-warning btn-reprocessar" 
                                    onclick="event.stopPropagation(); reprocessarPedido('<?= $pedido['fonte'] === 'trello' ? htmlspecialchars($pedido['id_trello']) : htmlspecialchars($pedido['arquivo']) ?>', '<?= htmlspecialchars($pedido['fonte']) ?>')"
                                    data-arquivo="<?= $pedido['fonte'] === 'trello' ? htmlspecialchars($pedido['id_trello']) : htmlspecialchars($pedido['arquivo']) ?>"
                                    data-fonte="<?= htmlspecialchars($pedido['fonte']) ?>">
                                <i class="fas fa-redo me-1"></i> Reprocessar
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal de Detalhes -->
<div class="modal fade" id="detalhesModal" tabindex="-1" aria-labelledby="detalhesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detalhesModalLabel">
                    <i class="fas fa-info-circle me-2"></i> Detalhes do Pedido
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="d-flex justify-content-center my-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> Fechar
                </button>
                <button type="button" id="btnAcao" class="btn btn-success">
                    <i class="fas fa-check me-1"></i> Processar Pedido
                </button>
                <button type="button" id="btnRemover" class="btn btn-danger">
                    <i class="fas fa-trash me-1"></i> Remover
                </button>
                <a href="#" id="btnTrello" class="btn btn-info" target="_blank" style="display:none;">
                    <i class="fab fa-trello me-1"></i> Ver no Trello
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Processamento -->
<div class="modal fade" id="processoModal" tabindex="-1" aria-labelledby="processoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="processoModalLabel">
                    <i class="fas fa-cogs me-2"></i>Processar Pedido
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-4">
                    <div class="d-flex">
                        <div class="me-3 fs-3">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <div>
                            <h5 class="alert-heading">Instruções</h5>
                            <p class="mb-0">Escolha como deseja processar este pedido. Complete as informações necessárias para cada tipo de processamento.</p>
                        </div>
                    </div>
                </div>
                
                <form id="formProcessamento">
                    <input type="hidden" id="processo-arquivo" name="arquivo" value="">
                    
                    <!-- Tipo de Processamento -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Tipo de Processamento</label>
                        <div class="row">
                            <div class="col-md-4">
                        <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tipoProcessamento" id="tipoLigar" value="ligar">
                                    <label class="form-check-label" for="tipoLigar">
                                        <i class="fas fa-phone-alt me-1 text-purple"></i> <strong>Agendar Ligação</strong>
                                        <small class="d-block text-muted">Mover para "Clientes a Ligar"</small>
                            </label>
                        </div>
                            </div>
                            <div class="col-md-4">
                        <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tipoProcessamento" id="tipoProcessar" value="processar" checked>
                                    <label class="form-check-label" for="tipoProcessar">
                                        <i class="fas fa-box-open me-1 text-warning"></i> <strong>Pronto para Envio</strong>
                                        <small class="d-block text-muted">Preparar para despacho</small>
                            </label>
                        </div>
                    </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tipoProcessamento" id="tipoEnviar" value="enviar">
                                    <label class="form-check-label" for="tipoEnviar">
                                        <i class="fas fa-shipping-fast me-1 text-success"></i> <strong>Enviar Pedido</strong>
                                        <small class="d-block text-muted">Mover para "Em Trânsito"</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Seção para Agendar Ligação -->
                    <div id="secaoLigar" style="display:none;">
                        <div class="card border-purple mb-3">
                            <div class="card-header bg-purple text-white">
                                <h6 class="mb-0"><i class="fas fa-phone-alt me-2"></i>Informações da Ligação</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <label for="dataLigacao" class="form-label">Data para Ligação <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                                            <input type="date" class="form-control" id="dataLigacao" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="horaLigacao" class="form-label">Hora para Ligação</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-clock"></i></span>
                                            <input type="time" class="form-control" id="horaLigacao" value="09:00">
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <label for="observacoesLigacao" class="form-label">Observações</label>
                                    <textarea class="form-control" id="observacoesLigacao" rows="2" placeholder="Observações sobre a ligação..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Seção para Envio -->
                    <div id="secaoEnvio" style="display:block;">
                        <div class="card border-success mb-3">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="fas fa-shipping-fast me-2"></i>Informações de Envio</h6>
                            </div>
                            <div class="card-body">
                    <div class="mb-3">
                                    <label for="codigoRastreio" class="form-label">Código de Rastreio</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-barcode"></i></span>
                                        <input type="text" class="form-control" id="codigoRastreio" placeholder="Insira o código de rastreio (opcional para 'Pronto para Envio')">
                                    </div>
                                    <small class="form-text text-muted">Obrigatório apenas para "Enviar Pedido"</small>
                                </div>
                                <div class="mb-3">
                                    <label for="transportadora" class="form-label">Transportadora</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-truck"></i></span>
                                        <select class="form-select" id="transportadora">
                                            <option value="CTT">CTT - Correios de Portugal</option>
                                            <option value="DPD">DPD</option>
                                            <option value="UPS">UPS</option>
                                            <option value="FedEx">FedEx</option>
                                            <option value="Outro">Outro</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Informações do Tratamento -->
                    <div class="card border-info mb-3">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0"><i class="fas fa-medkit me-2"></i>Informações do Tratamento</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="tratamento" class="form-label">Tratamento</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-pills"></i></span>
                        <select class="form-select" id="tratamento" required>
                            <option value="1 Mês" selected data-price="74,98">1 Mês - 74,98€</option>
                            <option value="2 Meses" data-price="119,98">2 Meses - 119,98€</option>
                            <option value="3 Meses" data-price="149,98">3 Meses - 149,98€</option>
                                            <option value="personalizado" data-price="custom">Preço Personalizado</option>
                        </select>
                    </div>
                                    <!-- Campo para preço personalizado -->
                                    <div id="precoPersonalizadoContainer" style="display: none;" class="mt-2">
                                        <label for="precoPersonalizado" class="form-label">Valor Personalizado</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-euro-sign"></i></span>
                                            <input type="number" class="form-control" id="precoPersonalizado" 
                                                   placeholder="0,00" step="0.01" min="0">
                                            <span class="input-group-text">€</span>
                                        </div>
                                        <small class="form-text text-muted">Digite o valor em euros (ex: 99,99)</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="origem" class="form-label">Origem do Pedido</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-tag"></i></span>
                                        <select class="form-select" id="origem">
                                            <option value="whatsapp">WhatsApp</option>
                                            <option value="facebook">Facebook</option>
                                            <option value="site">Site</option>
                                            <option value="outro">Outro</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Preview da Ação -->
                    <div class="alert alert-light border" id="previewAcao">
                        <div class="d-flex align-items-center">
                            <div class="me-3 fs-4" id="previewIcon">
                                <i class="fas fa-box-open text-warning"></i>
                            </div>
                            <div>
                                <h6 class="mb-1" id="previewTitulo">Pronto para Envio</h6>
                                <p class="mb-0 text-muted" id="previewDescricao">O pedido será marcado como pronto para envio e movido para a lista correspondente no Trello.</p>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> Cancelar
                </button>
                <button type="button" id="btnConfirmarProcesso" class="btn btn-primary">
                    <i class="fas fa-paper-plane me-1"></i> Confirmar Processamento
                </button>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>