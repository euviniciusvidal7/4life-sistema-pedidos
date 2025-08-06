<?php
session_start();

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

// Conectar ao banco
$conn = conectarBD();

// Processar filtros (mesma lógica da página entregas.php)
$filtro_status = $_GET['status'] ?? '';
$filtro_destino = $_GET['destino'] ?? '';
$filtro_data_inicio = $_GET['data_inicio'] ?? '';
$filtro_data_fim = $_GET['data_fim'] ?? '';
$busca = $_GET['busca'] ?? '';

// Construir consulta SQL com filtros
$where_clauses = [];
$params = [];
$types = '';

if (!empty($filtro_status)) {
    $where_clauses[] = "status = ?";
    $params[] = $filtro_status;
    $types .= 's';
}

if (!empty($filtro_destino)) {
    $where_clauses[] = "destino = ?";
    $params[] = $filtro_destino;
    $types .= 's';
}

if (!empty($filtro_data_inicio)) {
    $where_clauses[] = "data_criacao >= ?";
    $params[] = $filtro_data_inicio . ' 00:00:00';
    $types .= 's';
}

if (!empty($filtro_data_fim)) {
    $where_clauses[] = "data_criacao <= ?";
    $params[] = $filtro_data_fim . ' 23:59:59';
    $types .= 's';
}

if (!empty($busca)) {
    $where_clauses[] = "(cliente LIKE ? OR tracking LIKE ?)";
    $busca_param = "%$busca%";
    $params[] = $busca_param;
    $params[] = $busca_param;
    $types .= 'ss';
}

$sql = "SELECT * FROM entregas";
if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}
$sql .= " ORDER BY ultima_atualizacao DESC";

// Executar consulta
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Definir cabeçalhos para download do CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=entregas_4life_' . date('Y-m-d') . '.csv');

// Adicionar BOM para suportar caracteres UTF-8 no Excel
echo "\xEF\xBB\xBF";

// Abrir o output do PHP como um arquivo para escrita
$output = fopen('php://output', 'w');

// Escrever cabeçalhos do CSV
fputcsv($output, [
    'ID', 
    'Tracking', 
    'Cliente', 
    'Destino', 
    'Status', 
    'Data Criação', 
    'Última Atualização'
]);

// Escrever cada linha do resultado
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['id'],
        $row['tracking'],
        $row['cliente'],
        $row['destino'],
        $row['status'],
        date('d/m/Y H:i:s', strtotime($row['data_criacao'])),
        date('d/m/Y H:i:s', strtotime($row['ultima_atualizacao']))
    ]);
}

fclose($output);
exit;
?>