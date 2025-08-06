<?php
// Incluir o header com a navegação e verificações de login
include 'header.php'; 

// Conectar ao banco
$conn = conectarBD();

// Processar filtros
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
$sql .= " ORDER BY ultima_atualizacao DESC LIMIT 100";

// Executar consulta
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$entregas = $stmt->get_result();

// Obter lista de destinos para filtro
$destinos = $conn->query("SELECT DISTINCT destino FROM entregas WHERE destino != '' ORDER BY destino");

// Obter estatísticas de status
$status_counts = $conn->query("SELECT status, COUNT(*) as total FROM entregas GROUP BY status");
$status_data = [];
while ($row = $status_counts->fetch_assoc()) {
    $status_data[$row['status']] = $row['total'];
}

// Obter contagens rápidas para os indicadores
$total_entregas = $conn->query("SELECT COUNT(*) as total FROM entregas")->fetch_assoc()['total'];
$entregas_transito = $conn->query("SELECT COUNT(*) as total FROM entregas WHERE status LIKE '%Trânsito%'")->fetch_assoc()['total'];
$entregas_pendentes = $conn->query("SELECT COUNT(*) as total FROM entregas WHERE status LIKE '%PRONTO%'")->fetch_assoc()['total'];
$entregas_concluidas = $conn->query("SELECT COUNT(*) as total FROM entregas WHERE status = 'ENTREGUE - PAGO'")->fetch_assoc()['total'];
?>

<!-- Cards de Resumo -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body text-center p-4">
                <h1 class="display-4 fw-bold text-primary mb-3"><?php echo $total_entregas; ?></h1>
                <p class="text-uppercase fw-bold mb-3">Total de Entregas</p>
                <div class="d-flex justify-content-center">
                    <span class="badge bg-primary-subtle text-primary px-3 py-2">
                        Total no Sistema
                    </span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body text-center p-4">
                <h1 class="display-4 fw-bold text-success mb-3"><?php echo $entregas_concluidas; ?></h1>
                <p class="text-uppercase fw-bold mb-3">Entregas Concluídas</p>
                <div class="d-flex justify-content-center">
                    <span class="badge bg-success-subtle text-success px-3 py-2">
                        <?php echo round(($entregas_concluidas/$total_entregas)*100, 1); ?>% do Total
                    </span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body text-center p-4">
                <h1 class="display-4 fw-bold text-warning mb-3"><?php echo $entregas_transito; ?></h1>
                <p class="text-uppercase fw-bold mb-3">Em Trânsito</p>
                <div class="d-flex justify-content-center">
                    <span class="badge bg-warning-subtle text-warning px-3 py-2">
                        Em Andamento
                    </span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body text-center p-4">
                <h1 class="display-4 fw-bold text-info mb-3"><?php echo $entregas_pendentes; ?></h1>
                <p class="text-uppercase fw-bold mb-3">Aguardando Envio</p>
                <div class="d-flex justify-content-center">
                    <span class="badge bg-info-subtle text-info px-3 py-2">
                        Prontas para Despacho
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="card mb-4 filtros-card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filtros</h5>
        <button class="btn btn-sm btn-link" type="button" data-bs-toggle="collapse" data-bs-target="#filtrosCollapse">
            <i class="fas fa-chevron-down"></i>
        </button>
    </div>
    <div class="collapse show" id="filtrosCollapse">
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select form-select-sm shadow-none">
                        <option value="">Todos</option>
                        <?php foreach ($status_data as $status => $count): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" 
                                <?php echo ($filtro_status == $status) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status) . ' (' . $count . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Destino</label>
                    <select name="destino" class="form-select form-select-sm shadow-none">
                        <option value="">Todos</option>
                        <?php while ($row = $destinos->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($row['destino']); ?>" 
                                <?php echo ($filtro_destino == $row['destino']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($row['destino']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Data Início</label>
                    <input type="date" name="data_inicio" class="form-control form-control-sm shadow-none" value="<?php echo $filtro_data_inicio; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Data Fim</label>
                    <input type="date" name="data_fim" class="form-control form-control-sm shadow-none" value="<?php echo $filtro_data_fim; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Busca</label>
                    <input type="text" name="busca" class="form-control form-control-sm shadow-none" placeholder="Cliente ou tracking" value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-12 pt-2">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>Filtrar
                        </button>
                        <a href="entregas.php" class="btn btn-outline-secondary">
                            <i class="fas fa-eraser me-2"></i>Limpar
                        </a>
                        <a href="exportar.php?<?php echo http_build_query($_GET); ?>" class="btn btn-success">
                            <i class="fas fa-file-excel me-2"></i>Exportar
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Tabela de Entregas -->
<div class="card mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Listagem de Entregas</h5>
        <span class="badge bg-primary rounded-pill px-3 py-2"><?php echo $entregas->num_rows; ?> resultados</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Tracking</th>
                        <th>Cliente</th>
                        <th>Destino</th>
                        <th>Status</th>
                        <th>Data Criação</th>
                        <th>Últ. Atualização</th>
                        <th class="text-center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($entregas->num_rows == 0): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <div class="empty-state">
                                    <i class="fas fa-search fa-3x text-muted mb-3"></i>
                                    <h6 class="text-muted">Nenhuma entrega encontrada</h6>
                                    <p class="small text-muted">Tente ajustar os filtros de busca</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php while ($entrega = $entregas->fetch_assoc()): ?>
                            <?php
                            // Determinar a classe de status
                            $status_class = 'status-novo';
                            $badge_class = 'bg-secondary';
                            if (strpos($entrega['status'], 'TRÂNSITO') !== false) {
                                $status_class = 'status-transito';
                                $badge_class = 'bg-warning';
                            } elseif (strpos($entrega['status'], 'ENTREGUE') !== false) {
                                $status_class = 'status-entregue';
                                $badge_class = 'bg-success';
                            } elseif (strpos($entrega['status'], 'DEVOLVIDA') !== false) {
                                $status_class = 'status-devolvido';
                                $badge_class = 'bg-danger';
                            } elseif (strpos($entrega['status'], 'PRONTO') !== false) {
                                $badge_class = 'bg-info';
                            }
                            ?>
                            <tr class="entrega-row">
                                <td class="ps-3 fw-medium"><?php echo htmlspecialchars($entrega['tracking']); ?></td>
                                <td><?php echo htmlspecialchars($entrega['cliente']); ?></td>
                                <td><span class="badge bg-light text-dark rounded-pill px-2"><?php echo htmlspecialchars($entrega['destino']); ?></span></td>
                                <td><span class="badge <?php echo $badge_class; ?> text-white"><?php echo htmlspecialchars($entrega['status']); ?></span></td>
                                <td><?php echo date('d/m/Y', strtotime($entrega['data_criacao'])); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($entrega['ultima_atualizacao'])); ?></td>
                                <td class="text-center">
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick="verDetalhes(<?php echo $entrega['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a href="https://trello.com/c/<?php echo htmlspecialchars($entrega['id_trello']); ?>" 
                                           target="_blank" class="btn btn-sm btn-outline-info">
                                            <i class="fab fa-trello"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Detalhes -->
<div class="modal fade" id="detalhesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light">
                <h5 class="modal-title" id="modalTitle">Detalhes da Entrega</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <a href="#" id="btnTrello" class="btn btn-info" target="_blank">
                    <i class="fab fa-trello me-2"></i>Ver no Trello
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function verDetalhes(id) {
    // Resetar conteúdo modal
    document.getElementById('modalBody').innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Carregando...</span>
            </div>
        </div>
    `;
    
    // Exibir modal
    const detalhesModal = new bootstrap.Modal(document.getElementById('detalhesModal'));
    detalhesModal.show();
    
    // Buscar dados via AJAX
    fetch('get_detalhes.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            // Formatar timeline de status
            document.getElementById('modalTitle').textContent = 
                'Entrega: ' + (data.tracking || 'Sem tracking') + ' - ' + data.cliente;
            
            // Atualizar link do Trello
            document.getElementById('btnTrello').href = 'https://trello.com/c/' + data.id_trello;
            
            // Preencher conteúdo
            let statusClass = 'status-novo';
            let badgeClass = 'bg-secondary';
            
            if (data.status.includes('TRÂNSITO')) {
                statusClass = 'status-transito';
                badgeClass = 'bg-warning';
            } else if (data.status.includes('ENTREGUE')) {
                statusClass = 'status-entregue';
                badgeClass = 'bg-success';
            } else if (data.status.includes('DEVOLVIDA')) {
                statusClass = 'status-devolvido';
                badgeClass = 'bg-danger';
            } else if (data.status.includes('PRONTO')) {
                badgeClass = 'bg-info';
            }
            
            let html = `
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="card h-100 border-0 bg-light">
                            <div class="card-body">
                                <h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2"></i>Informações Básicas</h6>
                                <div class="info-item mb-3">
                                    <span class="info-label">Cliente:</span>
                                    <span class="info-value fw-medium">${data.cliente}</span>
                                </div>
                                <div class="info-item mb-3">
                                    <span class="info-label">Tracking:</span>
                                    <span class="info-value">${data.tracking || 'N/A'}</span>
                                </div>
                                <div class="info-item mb-3">
                                    <span class="info-label">Destino:</span>
                                    <span class="info-value">${data.destino || 'N/A'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Status Atual:</span>
                                    <span class="badge ${badgeClass} text-white px-3 py-2">${data.status}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 border-0 bg-light">
                            <div class="card-body">
                                <h6 class="fw-bold mb-3"><i class="fas fa-clock me-2"></i>Dados de Acompanhamento</h6>
                                <div class="info-item mb-3">
                                    <span class="info-label">Data de Criação:</span>
                                    <span class="info-value">${new Date(data.data_criacao).toLocaleString('pt-BR')}</span>
                                </div>
                                <div class="info-item mb-3">
                                    <span class="info-label">Última Atualização:</span>
                                    <span class="info-value">${new Date(data.ultima_atualizacao).toLocaleString('pt-BR')}</span>
                                </div>
                                <div class="info-item mb-3">
                                    <span class="info-label">Tempo em Trânsito:</span>
                                    <span class="info-value fw-medium">${calcularTempoTransito(data.data_criacao, data.ultima_atualizacao)}</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">ID Trello:</span>
                                    <span class="info-value"><a href="https://trello.com/c/${data.id_trello}" target="_blank" class="text-primary">${data.id_trello}</a></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <h6 class="fw-bold mb-3"><i class="fas fa-history me-2"></i>Timeline de Status</h6>
                    <div class="timeline">
                        <div class="timeline-item">
                            <div class="timeline-marker bg-success"></div>
                            <div class="timeline-content">
                                <p class="fw-medium m-0">Criado - ${new Date(data.data_criacao).toLocaleString('pt-BR')}</p>
                                <p class="small text-muted m-0">Entrega registrada no sistema</p>
                            </div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-marker ${statusClass == 'status-transito' ? 'bg-warning' : 'bg-light'}"></div>
                            <div class="timeline-content">
                                <p class="fw-medium m-0">Em Trânsito ${statusClass == 'status-transito' ? '- Status Atual' : ''}</p>
                                <p class="small text-muted m-0">Entrega enviada</p>
                            </div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-marker ${statusClass == 'status-entregue' ? 'bg-success' : 'bg-light'}"></div>
                            <div class="timeline-content">
                                <p class="fw-medium m-0">Entregue ${statusClass == 'status-entregue' ? '- Status Atual' : ''}</p>
                                <p class="small text-muted m-0">Entrega finalizada com sucesso</p>
                            </div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-marker ${statusClass == 'status-devolvido' ? 'bg-danger' : 'bg-light'}"></div>
                            <div class="timeline-content">
                                <p class="fw-medium m-0">Devolvido ${statusClass == 'status-devolvido' ? '- Status Atual' : ''}</p>
                                <p class="small text-muted m-0">Entrega não realizada</p>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('modalBody').innerHTML = html;
        })
        .catch(error => {
            document.getElementById('modalBody').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Erro ao carregar detalhes. Tente novamente.
                </div>
            `;
        });
}

function calcularTempoTransito(dataInicio, dataFim) {
    const inicio = new Date(dataInicio);
    const fim = new Date(dataFim);
    const diffTime = Math.abs(fim - inicio);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    
    if (diffDays === 0) return 'Hoje';
    if (diffDays === 1) return '1 dia';
    return diffDays + ' dias';
}
</script>

<style>
/* Ajustes para garantir que os cards não saiam da tela */
.row {
    display: flex;
    flex-wrap: wrap;
    margin-right: -10px;
    margin-left: -10px;
}

/* Ajustes nos cards do primeira linha */
.row:first-of-type .col-md-3 {
    flex: 0 0 auto;
    width: calc(25% - 20px);
    margin: 0 10px 20px;
}

/* Estilização de cartões e elementos */
.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    transition: transform 0.3s, box-shadow 0.3s;
    overflow: hidden;
    margin-bottom: 20px;
    width: 100%;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
}

.display-4 {
    font-size: 3.2rem;
    font-weight: 700;
}

/* Estilos específicos de tabela e componentes */
.table {
    font-size: 0.95rem;
}

.table thead th {
    font-weight: 600;
    border-top: none;
    border-bottom: 2px solid #e9ecef;
    padding: 15px 10px;
    color: #495057;
}

.table tbody td {
    padding: 15px 10px;
    vertical-align: middle;
    border-bottom: 1px solid #e9ecef;
}

/* Efeito hover nas linhas */
.entrega-row {
    transition: background-color 0.2s;
}

.entrega-row:hover {
    background-color: rgba(46, 125, 50, 0.05);
}

/* Estilos do timeline e modal */
.timeline {
    position: relative;
    padding-left: 40px;
    margin-top: 25px;
}

.timeline-item {
    position: relative;
    padding-bottom: 25px;
}

.timeline-item:before {
    content: "";
    position: absolute;
    left: -30px;
    top: 25px;
    height: 100%;
    width: 2px;
    background-color: #e9ecef;
}

.timeline-item:last-child:before {
    display: none;
}

.timeline-marker {
    position: absolute;
    left: -36px;
    top: 5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px rgba(0,0,0,0.1);
}

.timeline-content {
    padding: 0 15px;
}

/* Responsividade */
@media (max-width: 1199px) {
    .row:first-of-type .col-md-3 {
        width: calc(50% - 20px);
    }
    
    .display-4 {
        font-size: 2.8rem;
    }
}

@media (max-width: 768px) {
    .row:first-of-type .col-md-3 {
        width: calc(100% - 20px);
    }
    
    .display-4 {
        font-size: 2.5rem;
    }
}
</style>

<?php include 'footer.php'; ?>