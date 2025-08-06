<?php
session_start();

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

// Incluir configurações
require_once 'config.php';

// Conectar ao banco
$conn = conectarBD();

// Definir variável para o header.php
$current_page = basename($_SERVER['PHP_SELF']);

// Obter o nível de acesso do usuário (caso não tenha sido definido)
if (!isset($nivel_acesso)) {
    $stmt = $conn->prepare("SELECT nivel_acesso FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['usuario_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $usuario = $result->fetch_assoc();
    $nivel_acesso = $usuario['nivel_acesso'] ?? 'logistica'; // Padrão é logística se não especificado
}

// Definir os status exatos do Trello
$STATUS_NOVO = 'NOVO CLIENTE';
$STATUS_LIGAR = 'CLIENTES A LIGAR NA DATA DE COMEÇO';
$STATUS_PRONTO_ENVIO = 'PRONTO PARA ENVIO';
$STATUS_TRANSITO = 'EM TRÂNSITO';
$STATUS_ENTREGUE = 'ENTREGUE - PAGO';
$STATUS_DEVOLVIDAS = 'DEVOLVIDAS';
$STATUS_MENSAL = 'CLIENTE MESAL';
$STATUS_PRONTO_MENSAL = 'PRONTO PARA ENVIO - MENSAL';
$STATUS_TRANSITO_MENSAL = 'EM TRÂNSITO - MENSAL';

// Buscar dados para o dashboard
$novos_clientes = $conn->query("SELECT COUNT(*) FROM entregas WHERE status = '$STATUS_NOVO'")->fetch_row()[0];
$pronto_envio = $conn->query("SELECT COUNT(*) FROM entregas WHERE status = '$STATUS_PRONTO_ENVIO' OR status = '$STATUS_PRONTO_MENSAL'")->fetch_row()[0];
$em_transito = $conn->query("SELECT COUNT(*) FROM entregas WHERE status = '$STATUS_TRANSITO' OR status = '$STATUS_TRANSITO_MENSAL'")->fetch_row()[0];
$entregues = $conn->query("SELECT COUNT(*) FROM entregas WHERE status = '$STATUS_ENTREGUE'")->fetch_row()[0];

// Buscar a última atualização (data mais recente)
$ultima_att = $conn->query("SELECT MAX(ultima_atualizacao) FROM entregas")->fetch_row()[0] ?? 'Nunca';
if ($ultima_att != 'Nunca') {
    $ultima_att = date('d/m/Y H:i', strtotime($ultima_att));
}

// Processar filtro de busca
$busca = $_GET['busca'] ?? '';
$filtro_status = $_GET['status'] ?? '';
$filtro_sql = [];
$params = [];
$types = '';

if (!empty($busca)) {
    $filtro_sql[] = "(cliente LIKE ? OR tracking LIKE ? OR destino LIKE ?)";
    $busca_param = "%$busca%";
    $params[] = $busca_param;
    $params[] = $busca_param;
    $params[] = $busca_param;
    $types .= 'sss';
}

if (!empty($filtro_status)) {
    $filtro_sql[] = "status = ?";
    $params[] = $filtro_status;
    $types .= 's';
}

// Construir a consulta SQL
$sql = "SELECT * FROM entregas";
if (!empty($filtro_sql)) {
    $sql .= " WHERE " . implode(" AND ", $filtro_sql);
}
$sql .= " ORDER BY ultima_atualizacao DESC LIMIT 50";

// Preparar e executar consulta
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$entregas = $stmt->get_result();

// Obter lista de status disponíveis para o filtro
$status_query = $conn->query("SELECT DISTINCT status FROM entregas ORDER BY status");
$status_list = [];
while ($row = $status_query->fetch_assoc()) {
    $status_list[] = $row['status'];
}

// Incluir o header comum
include 'header.php';
?>

    <style>
    /* Adicionar estilos específicos para a dashboard */
        .metric-card {
            background: linear-gradient(135deg, var(--primary-color), #9575CD);
            color: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
            height: 100%;
            overflow: hidden;
            position: relative;
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }
        
        .metric-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        
        .metric-card.blue {
            background: linear-gradient(135deg, #1976D2, #64B5F6);
        }
        
        .metric-card.green {
            background: linear-gradient(135deg, #388E3C, #81C784);
        }
        
        .metric-card.orange {
            background: linear-gradient(135deg, #F57C00, #FFB74D);
        }
        
        .metric-card .number {
            font-size: 36px;
            font-weight: 700;
            margin: 10px 0;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
        }
        
        .metric-card .label {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.9;
        }
        
        .metric-card .icon {
            position: absolute;
            bottom: -15px;
            right: -15px;
            font-size: 80px;
            opacity: 0.15;
        }
        
        .dashboard-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .dashboard-table th {
            background-color: #f8f9fa;
            color: #495057;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
            padding: 15px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .dashboard-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        .dashboard-table tr:hover {
            background-color: #f8f9fa;
        }
        
        .btn-view {
            padding: 5px 10px;
            border-radius: 5px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            transition: all 0.3s;
        }
        
        .btn-view:hover {
            background-color: #5e35b1;
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
            font-weight: 600;
        display: inline-block;
    }
    
    .status-novo { background-color: #fff3cd; color: #856404; }
    .status-ligar { background-color: #fff3cd; color: #856404; }
    .status-pronto { background-color: #cff4fc; color: #055160; }
    .status-transito { background-color: #cce5ff; color: #004085; }
    .status-entregue { background-color: #d4edda; color: #155724; }
    .status-devolvido { background-color: #f8d7da; color: #721c24; }
    .status-mensal { background-color: #e2d9f3; color: #5b2da2; }
    </style>

            <!-- Indicadores Principais -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="metric-card orange">
                        <div class="label">Novos Clientes</div>
                        <div class="number"><?= $novos_clientes ?></div>
                        <div>Pedidos a processar</div>
                        <div class="icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="metric-card">
                        <div class="label">Pronto para Envio</div>
                        <div class="number"><?= $pronto_envio ?></div>
                        <div>Aguardando despacho</div>
                        <div class="icon">
                            <i class="fas fa-box-open"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="metric-card blue">
                        <div class="label">Em Trânsito</div>
                        <div class="number"><?= $em_transito ?></div>
                        <div>Pedidos a caminho</div>
                        <div class="icon">
                            <i class="fas fa-shipping-fast"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="metric-card green">
                        <div class="label">Entregas Concluídas</div>
                        <div class="number"><?= $entregues ?></div>
                        <div>Pedidos entregues</div>
                        <div class="icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Busca Avançada -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="m-0"><i class="fas fa-search me-2"></i> Filtrar Pedidos</h5>
                    <span class="badge bg-primary rounded-pill"><?= $entregas->num_rows ?> resultados</span>
                </div>
                <div class="card-body">
                    <form method="get" class="row g-3">
                        <div class="col-md-6">
                            <label for="busca" class="form-label">Buscar</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" id="busca" name="busca" class="form-control" placeholder="Cliente, tracking ou destino..." value="<?= htmlspecialchars($busca ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="status" class="form-label">Status</label>
                            <select id="status" name="status" class="form-select">
                                <option value="">Todos os status</option>
                                <?php foreach ($status_list as $status): ?>
                                <option value="<?= htmlspecialchars($status) ?>" <?= ($filtro_status == $status) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($status) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="d-grid gap-2 w-100">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-1"></i> Filtrar
                                </button>
                                <?php if (!empty($busca) || !empty($filtro_status)): ?>
                                    <a href="dashboard.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-1"></i> Limpar
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Tabela de Entregas -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="m-0"><i class="fas fa-list me-2"></i> Lista de Pedidos</h5>
                    <a href="entregas.php" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-external-link-alt me-1"></i> Ver Todos
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table dashboard-table m-0">
                            <thead>
                                <tr>
                                    <th>Tracking</th>
                                    <th>Cliente</th>
                                    <th>Destino</th>
                                    <th>Status</th>
                                    <th>Data</th>
                                    <th class="text-center">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($entregas->num_rows == 0): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5">
                                            <i class="fas fa-search fa-2x mb-3 text-muted"></i>
                                            <p class="text-muted">Nenhum pedido encontrado com os filtros aplicados</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php while ($entrega = $entregas->fetch_assoc()): ?>
                                        <?php
                                        // Determinar a classe de status
                                        $status_class = 'status-novo';
                                        
                                        if ($entrega['status'] == $STATUS_NOVO) {
                                            $status_class = 'status-novo';
                                        } elseif ($entrega['status'] == $STATUS_LIGAR) {
                                            $status_class = 'status-ligar';
                                        } elseif ($entrega['status'] == $STATUS_PRONTO_ENVIO || $entrega['status'] == $STATUS_PRONTO_MENSAL) {
                                            $status_class = 'status-pronto';
                                        } elseif ($entrega['status'] == $STATUS_TRANSITO || $entrega['status'] == $STATUS_TRANSITO_MENSAL) {
                                            $status_class = 'status-transito';
                                        } elseif ($entrega['status'] == $STATUS_ENTREGUE) {
                                            $status_class = 'status-entregue';
                                        } elseif ($entrega['status'] == $STATUS_DEVOLVIDAS) {
                                            $status_class = 'status-devolvido';
                                        } elseif ($entrega['status'] == $STATUS_MENSAL) {
                                            $status_class = 'status-mensal';
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <?php if (!empty($entrega['tracking'])): ?>
                                                    <strong><?= htmlspecialchars($entrega['tracking']) ?></strong>
                                                <?php else: ?>
                                                    <span class="text-muted">Sem tracking</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($entrega['cliente']) ?></td>
                                            <td>
                                                <?php if (!empty($entrega['destino'])): ?>
                                                    <?= htmlspecialchars($entrega['destino']) ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Não definido</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="status-badge <?= $status_class ?>"><?= htmlspecialchars($entrega['status']) ?></span></td>
                                            <td><?= date('d/m/Y', strtotime($entrega['data_criacao'])) ?></td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-view" onclick="verDetalhes(<?= $entrega['id'] ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-4 text-muted">
                © 2025 4Life Nutri - Todos os direitos reservados
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
                    <a href="#" id="verNoTrello" class="btn btn-primary" target="_blank">
                        <i class="fab fa-trello me-1"></i> Ver no Trello
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    function verDetalhes(id) {
        // Limpar conteúdo modal
        document.getElementById('modalBody').innerHTML = `
            <div class="d-flex justify-content-center my-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Carregando...</span>
                </div>
            </div>
        `;
        
        // Mostrar modal
        var myModal = new bootstrap.Modal(document.getElementById('detalhesModal'));
        myModal.show();
        
        // Buscar dados via AJAX
        fetch('get_detalhes.php?id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('modalBody').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i> ${data.error}
                        </div>
                    `;
                    document.getElementById('verNoTrello').style.display = 'none';
                    return;
                }
                
                // Determinar classe de status
                let statusClass = 'status-novo';
                if (data.status == '<?= $STATUS_TRANSITO ?>' || data.status == '<?= $STATUS_TRANSITO_MENSAL ?>') {
                    statusClass = 'status-transito';
                } else if (data.status == '<?= $STATUS_ENTREGUE ?>') {
                    statusClass = 'status-entregue';
                } else if (data.status == '<?= $STATUS_DEVOLVIDAS ?>') {
                    statusClass = 'status-devolvido';
                } else if (data.status == '<?= $STATUS_PRONTO_ENVIO ?>' || data.status == '<?= $STATUS_PRONTO_MENSAL ?>') {
                    statusClass = 'status-pronto';
                }
                
                // Configurar link para o Trello
                if (data.id_trello) {
                    document.getElementById('verNoTrello').href = 'https://trello.com/c/' + data.id_trello;
                    document.getElementById('verNoTrello').style.display = 'block';
                } else {
                    document.getElementById('verNoTrello').style.display = 'none';
                }
                
                // Formatação de datas
                let dataCriacao = new Date(data.data_criacao).toLocaleDateString('pt-BR', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                let dataAtualizacao = new Date(data.ultima_atualizacao).toLocaleDateString('pt-BR', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                // Extrair descrição do Trello (se disponível)
                let descricaoHtml = '';
                if (data.descricao_trello) {
                    descricaoHtml = `
                        <div class="col-12 mb-3">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="m-0"><i class="fas fa-align-left me-2"></i> Descrição</h6>
                                </div>
                                <div class="card-body">
                                    <pre style="white-space: pre-wrap;">${data.descricao_trello}</pre>
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                // Atualizar título do modal
                document.getElementById('detalhesModalLabel').innerHTML = `
                    <i class="fas fa-info-circle me-2"></i> Pedido: ${data.tracking || 'Sem tracking'}
                `;
                
                // Atualizar conteúdo do modal
                document.getElementById('modalBody').innerHTML = `
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="detail-item">
                                <div class="detail-label">Cliente</div>
                                <div class="detail-value">${data.cliente || 'N/A'}</div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="detail-item">
                                <div class="detail-label">Tracking</div>
                                <div class="detail-value">
                                    ${data.tracking ? `<strong>${data.tracking}</strong>` : '<span class="text-muted">Não informado</span>'}
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="detail-item">
                                <div class="detail-label">Destino</div>
                                <div class="detail-value">${data.destino || '<span class="text-muted">Não informado</span>'}</div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="detail-item">
                                <div class="detail-label">Status</div>
                                <div class="detail-value">
                                    <span class="status-badge ${statusClass}">${data.status}</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="detail-item">
                                <div class="detail-label">Data de Criação</div>
                                <div class="detail-value">
                                    <i class="far fa-calendar-alt me-1"></i> ${dataCriacao}
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="detail-item">
                                <div class="detail-label">Última Atualização</div>
                                <div class="detail-value">
                                    <i class="far fa-clock me-1"></i> ${dataAtualizacao}
                                </div>
                            </div>
                        </div>
                        
                        ${descricaoHtml}
                        
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="fas fa-link me-2"></i> ID Trello: 
                                <a href="https://trello.com/c/${data.id_trello}" target="_blank" class="alert-link">
                                    ${data.id_trello}
                                </a>
                            </div>
                        </div>
                    </div>
                `;
            })
            .catch(error => {
                document.getElementById('modalBody').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i> Erro ao carregar detalhes. Tente novamente.
                    </div>
                `;
                document.getElementById('verNoTrello').style.display = 'none';
            });
    }
    </script>

<?php include 'footer.php'; ?>