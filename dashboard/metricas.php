<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

// Conectar ao banco
$conn = conectarBD();

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

// Data atual e períodos
$data_atual = date('Y-m-d');
$mes_atual = date('m');
$ano_atual = date('Y');
$primeiro_dia_mes = date('Y-m-01');
$ultimo_dia_mes = date('Y-m-t');

// TOTAIS GERAIS (TODOS OS TEMPOS)
$total_pedidos = $conn->query("SELECT COUNT(*) FROM entregas")->fetch_row()[0];
$total_entregues = $conn->query("SELECT COUNT(*) FROM entregas WHERE status = '$STATUS_ENTREGUE'")->fetch_row()[0];
$total_em_transito = $conn->query("SELECT COUNT(*) FROM entregas WHERE status = '$STATUS_TRANSITO' OR status = '$STATUS_TRANSITO_MENSAL'")->fetch_row()[0];
$total_devolvidas = $conn->query("SELECT COUNT(*) FROM entregas WHERE status = '$STATUS_DEVOLVIDAS'")->fetch_row()[0];
$total_aguardando_envio = $conn->query("SELECT COUNT(*) FROM entregas WHERE status = '$STATUS_PRONTO_ENVIO' OR status = '$STATUS_PRONTO_MENSAL'")->fetch_row()[0];
$total_novos_clientes = $conn->query("SELECT COUNT(*) FROM entregas WHERE status = '$STATUS_NOVO'")->fetch_row()[0];

// MÉTRICAS DO MÊS ATUAL
$pedidos_mes = $conn->query("SELECT COUNT(*) FROM entregas WHERE MONTH(data_criacao) = '$mes_atual' AND YEAR(data_criacao) = '$ano_atual'")->fetch_row()[0];
$entregues_mes = $conn->query("SELECT COUNT(*) FROM entregas WHERE status = '$STATUS_ENTREGUE' AND MONTH(ultima_atualizacao) = '$mes_atual' AND YEAR(ultima_atualizacao) = '$ano_atual'")->fetch_row()[0];
$devolvidas_mes = $conn->query("SELECT COUNT(*) FROM entregas WHERE status = '$STATUS_DEVOLVIDAS' AND MONTH(ultima_atualizacao) = '$mes_atual' AND YEAR(ultima_atualizacao) = '$ano_atual'")->fetch_row()[0];

// CÁLCULO DE TAXAS
$taxa_entrega = ($total_pedidos > 0) ? round(($total_entregues / $total_pedidos) * 100, 1) : 0;
$taxa_devolucao = ($total_pedidos > 0) ? round(($total_devolvidas / $total_pedidos) * 100, 1) : 0;

// MÉDIA DIÁRIA
$dias_passados_mes = min(date('d'), date('t'));
$media_diaria = ($dias_passados_mes > 0) ? round($entregues_mes / $dias_passados_mes, 1) : 0;

// DADOS PARA GRÁFICOS

// 1. Entregas por status
$status_data = $conn->query("
    SELECT 
        CASE 
            WHEN status = '$STATUS_NOVO' THEN 'Novo Cliente'
            WHEN status = '$STATUS_LIGAR' THEN 'A Ligar'
            WHEN status = '$STATUS_PRONTO_ENVIO' OR status = '$STATUS_PRONTO_MENSAL' THEN 'Pronto para Envio'
            WHEN status = '$STATUS_TRANSITO' OR status = '$STATUS_TRANSITO_MENSAL' THEN 'Em Trânsito'
            WHEN status = '$STATUS_ENTREGUE' THEN 'Entregue'
            WHEN status = '$STATUS_DEVOLVIDAS' THEN 'Devolvido'
            WHEN status = '$STATUS_MENSAL' THEN 'Cliente Mensal'
            ELSE status
        END as categoria,
        COUNT(*) as total
    FROM entregas
    GROUP BY categoria
    ORDER BY total DESC
");

$categorias = [];
$totais = [];

while ($row = $status_data->fetch_assoc()) {
    $categorias[] = $row['categoria'];
    $totais[] = $row['total'];
}

// 2. Evolução mensal (últimos 6 meses)
$evolucao_mensal = $conn->query("
    SELECT 
        DATE_FORMAT(data_criacao, '%Y-%m') as mes,
        COUNT(*) as total_pedidos,
        SUM(CASE WHEN status = '$STATUS_ENTREGUE' THEN 1 ELSE 0 END) as total_entregues
    FROM entregas
    WHERE data_criacao >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(data_criacao, '%Y-%m')
    ORDER BY mes
");

$meses = [];
$pedidos_por_mes = [];
$entregues_por_mes = [];

while ($row = $evolucao_mensal->fetch_assoc()) {
    $meses[] = date('M/Y', strtotime($row['mes'] . '-01'));
    $pedidos_por_mes[] = $row['total_pedidos'];
    $entregues_por_mes[] = $row['total_entregues'];
}

// 3. Entregas por dia do mês atual
$entregas_por_dia = $conn->query("
    SELECT 
        DAY(ultima_atualizacao) as dia,
        COUNT(*) as total
    FROM entregas
    WHERE 
        status = '$STATUS_ENTREGUE'
        AND MONTH(ultima_atualizacao) = '$mes_atual'
        AND YEAR(ultima_atualizacao) = '$ano_atual'
    GROUP BY DAY(ultima_atualizacao)
    ORDER BY dia
");

$dias = [];
$entregas_dia = [];

while ($row = $entregas_por_dia->fetch_assoc()) {
    $dias[] = $row['dia'];
    $entregas_dia[] = $row['total'];
}

// 4. Comparação de taxas por mês (últimos 6 meses)
$taxas_mensais = $conn->query("
    SELECT 
        DATE_FORMAT(data_criacao, '%Y-%m') as mes,
        COUNT(*) as total_pedidos,
        SUM(CASE WHEN status = '$STATUS_ENTREGUE' THEN 1 ELSE 0 END) as entregues,
        SUM(CASE WHEN status = '$STATUS_DEVOLVIDAS' THEN 1 ELSE 0 END) as devolvidos
    FROM entregas
    WHERE data_criacao >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(data_criacao, '%Y-%m')
    ORDER BY mes
");

$meses_taxa = [];
$taxa_entrega_mes = [];
$taxa_devolucao_mes = [];

while ($row = $taxas_mensais->fetch_assoc()) {
    $meses_taxa[] = date('M/Y', strtotime($row['mes'] . '-01'));
    $taxa_entrega_mes[] = ($row['total_pedidos'] > 0) ? round(($row['entregues'] / $row['total_pedidos']) * 100, 1) : 0;
    $taxa_devolucao_mes[] = ($row['total_pedidos'] > 0) ? round(($row['devolvidos'] / $row['total_pedidos']) * 100, 1) : 0;
}
?>
<?php include 'header.php'; ?>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body text-center p-4">
                <h1 class="display-4 fw-bold text-success mb-3"><?php echo $total_entregues; ?></h1>
                <p class="text-uppercase fw-bold mb-3">Total de Entregas</p>
                <div class="d-flex justify-content-center">
                    <span class="badge bg-success-subtle text-success px-3 py-2">
                        Taxa de Entrega: <?php echo $taxa_entrega; ?>%
                    </span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body text-center p-4">
                <h1 class="display-4 fw-bold text-primary mb-3"><?php echo $entregues_mes; ?></h1>
                <p class="text-uppercase fw-bold mb-3">Entregas no Mês</p>
                <div class="d-flex justify-content-center">
                    <span class="badge bg-primary-subtle text-primary px-3 py-2">
                        Média Diária: <?php echo $media_diaria; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body text-center p-4">
                <h1 class="display-4 fw-bold text-warning mb-3"><?php echo $total_em_transito; ?></h1>
                <p class="text-uppercase fw-bold mb-3">Em Trânsito</p>
                <div class="d-flex justify-content-center">
                    <span class="badge bg-warning-subtle text-warning px-3 py-2">
                        Aguardando Entrega
                    </span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body text-center p-4">
                <h1 class="display-4 fw-bold text-danger mb-3"><?php echo $total_devolvidas; ?></h1>
                <p class="text-uppercase fw-bold mb-3">Devoluções Totais</p>
                <div class="d-flex justify-content-center">
                    <span class="badge bg-danger-subtle text-danger px-3 py-2">
                        Taxa de Devolução: <?php echo $taxa_devolucao; ?>%
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Distribuição por Status</h5>
            </div>
            <div class="card-body">
                <canvas id="statusChart" height="250"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Evolução Mensal</h5>
            </div>
            <div class="card-body">
                <canvas id="evolutionChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-calendar-day me-2"></i>Entregas por Dia (Mês Atual)</h5>
            </div>
            <div class="card-body">
                <canvas id="dailyChart" height="250"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Taxas Mensais</h5>
            </div>
            <div class="card-body">
                <canvas id="ratesChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-boxes me-2"></i>Aguardando Envio</h5>
            </div>
            <div class="card-body text-center">
                <div class="metric">
                    <h2 class="value text-primary"><?php echo $total_aguardando_envio; ?></h2>
                    <p class="label text-uppercase">Pedidos Prontos</p>
                </div>
                <hr>
                <p class="text-muted mb-0">Pedidos aguardando despacho no centro de distribuição</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>Novos Clientes</h5>
            </div>
            <div class="card-body text-center">
                <div class="metric">
                    <h2 class="value text-info"><?php echo $total_novos_clientes; ?></h2>
                    <p class="label text-uppercase">Clientes Novos</p>
                </div>
                <hr>
                <p class="text-muted mb-0">Clientes recém-cadastrados no sistema</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-percentage me-2"></i>Índice de Eficiência</h5>
            </div>
            <div class="card-body text-center">
                <div class="metric">
                    <h2 class="value text-success"><?php echo $taxa_entrega - $taxa_devolucao; ?>%</h2>
                    <p class="label text-uppercase">Eficiência Geral</p>
                </div>
                <hr>
                <p class="text-muted mb-0">Taxa de entrega menos taxa de devolução</p>
            </div>
        </div>
    </div>
</div>

<script>
// Configuração para gráficos
document.addEventListener('DOMContentLoaded', function() {
    Chart.defaults.font.family = "'Segoe UI', 'Helvetica Neue', 'Arial', sans-serif";
    Chart.defaults.color = '#666';
    Chart.defaults.font.size = 13;
    
    // Paleta de cores
    const primaryColors = [
        '#2E7D32', // Verde principal - 4Life
        '#4CAF50', // Verde médio
        '#388E3C', // Verde escuro
        '#81C784', // Verde claro
        '#1976D2', // Azul principal
        '#64B5F6', // Azul claro
        '#E53935', // Vermelho
        '#FFB74D', // Laranja
        '#9575CD'  // Roxo
    ];
    
    // 1. Gráfico de distribuição por status
    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($categorias); ?>,
            datasets: [{
                data: <?php echo json_encode($totais); ?>,
                backgroundColor: primaryColors,
                borderWidth: 1,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        padding: 20,
                        boxWidth: 12,
                        font: {
                            weight: 'bold'
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.95)',
                    titleColor: '#333',
                    bodyColor: '#666',
                    bodyFont: {
                        size: 14
                    },
                    borderColor: '#e0e0e0',
                    borderWidth: 1,
                    cornerRadius: 8,
                    padding: 15,
                    boxPadding: 10,
                    displayColors: true,
                    boxWidth: 12,
                    boxHeight: 12,
                    usePointStyle: true
                }
            },
            cutout: '65%',
            animation: {
                animateScale: true,
                animateRotate: true
            }
        }
    });
    
    // 2. Gráfico de evolução mensal
    new Chart(document.getElementById('evolutionChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($meses); ?>,
            datasets: [
                {
                    label: 'Total de Pedidos',
                    data: <?php echo json_encode($pedidos_por_mes); ?>,
                    backgroundColor: 'rgba(33, 150, 243, 0.2)',
                    borderColor: '#2196F3',
                    borderWidth: 3,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#2196F3',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Entregas Concluídas',
                    data: <?php echo json_encode($entregues_por_mes); ?>,
                    backgroundColor: 'rgba(46, 125, 50, 0.2)',
                    borderColor: '#2E7D32',
                    borderWidth: 3,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#2E7D32',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        drawBorder: false,
                        color: '#f0f0f0'
                    },
                    ticks: {
                        padding: 10
                    }
                },
                x: {
                    grid: {
                        display: false,
                        drawBorder: false
                    },
                    ticks: {
                        padding: 10
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        padding: 20,
                        boxWidth: 12,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.95)',
                    titleColor: '#333',
                    bodyColor: '#666',
                    bodyFont: {
                        size: 14
                    },
                    borderColor: '#e0e0e0',
                    borderWidth: 1,
                    cornerRadius: 8,
                    padding: 15,
                    boxPadding: 10
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            }
        }
    });
    
    // 3. Gráfico de entregas por dia
    new Chart(document.getElementById('dailyChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($dias); ?>,
            datasets: [
                {
                    label: 'Entregas por Dia',
                    data: <?php echo json_encode($entregas_dia); ?>,
                    backgroundColor: 'rgba(46, 125, 50, 0.7)',
                    borderColor: '#2E7D32',
                    borderWidth: 1,
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        drawBorder: false,
                        color: '#f0f0f0'
                    },
                    ticks: {
                        padding: 10,
                        stepSize: 1
                    }
                },
                x: {
                    grid: {
                        display: false,
                        drawBorder: false
                    },
                    ticks: {
                        padding: 10
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.95)',
                    titleColor: '#333',
                    bodyColor: '#666',
                    bodyFont: {
                        size: 14
                    },
                    borderColor: '#e0e0e0',
                    borderWidth: 1,
                    cornerRadius: 8,
                    padding: 15,
                    boxPadding: 10,
                    callbacks: {
                        title: function(context) {
                            return 'Dia ' + context[0].label + ' de ' + new Date().toLocaleString('pt-BR', { month: 'long' });
                        }
                    }
                }
            }
        }
    });
    
    // 4. Gráfico de taxas mensais
    new Chart(document.getElementById('ratesChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($meses_taxa); ?>,
            datasets: [
                {
                    label: 'Taxa de Entrega (%)',
                    data: <?php echo json_encode($taxa_entrega_mes); ?>,
                    backgroundColor: 'rgba(46, 125, 50, 0.8)',
                    borderColor: '#2E7D32',
                    borderWidth: 1,
                    borderRadius: 4,
                    order: 1
                },
                {
                    label: 'Taxa de Devolução (%)',
                    data: <?php echo json_encode($taxa_devolucao_mes); ?>,
                    backgroundColor: 'rgba(229, 57, 53, 0.8)',
                    borderColor: '#E53935',
                    borderWidth: 1,
                    borderRadius: 4,
                    order: 2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        drawBorder: false,
                        color: '#f0f0f0'
                    },
                    ticks: {
                        padding: 10,
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                },
                x: {
                    grid: {
                        display: false,
                        drawBorder: false
                    },
                    ticks: {
                        padding: 10
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        padding: 20,
                        boxWidth: 12,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.95)',
                    titleColor: '#333',
                    bodyColor: '#666',
                    bodyFont: {
                        size: 14
                    },
                    borderColor: '#e0e0e0',
                    borderWidth: 1,
                    cornerRadius: 8,
                    padding: 15,
                    boxPadding: 10,
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.raw + '%';
                        }
                    }
                }
            }
        }
    });
});
</script>

<style>
/* Estilos gerais para a página de métricas */
.row {
    display: flex;
    flex-wrap: wrap;
    margin-right: -10px;
    margin-left: -10px;
}

.col-md-3, .col-md-4, .col-md-6 {
    padding: 0 10px;
}

.col-md-3 {
    flex: 0 0 auto;
    width: calc(25% - 20px);
    margin: 0 10px 20px;
}

.col-md-4 {
    flex: 0 0 auto;
    width: calc(33.333% - 20px);
    margin: 0 10px 20px;
}

.col-md-6 {
    flex: 0 0 auto;
    width: calc(50% - 20px);
    margin: 0 10px 20px;
}

/* Cards */
.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    transition: transform 0.3s, box-shadow 0.3s;
    overflow: hidden;
    margin-bottom: 20px;
    background-color: #fff;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
}

.card-header {
    background-color: white;
    border-bottom: 1px solid #f3f3f3;
    padding: 15px 20px;
}

.card-header h5 {
    margin: 0;
    font-weight: 600;
    color: #333;
    font-size: 1rem;
}

.card-body {
    padding: 20px;
}

/* Estilos para os indicadores */
.display-4 {
    font-size: 2.8rem;
    font-weight: 700;
    line-height: 1.2;
}

.badge {
    font-weight: 500;
    padding: 6px 12px;
    border-radius: 30px;
}

.bg-success-subtle {
    background-color: rgba(46, 125, 50, 0.1);
}

.bg-primary-subtle {
    background-color: rgba(25, 118, 210, 0.1);
}

.bg-warning-subtle {
    background-color: rgba(255, 152, 0, 0.1);
}

.bg-danger-subtle {
    background-color: rgba(229, 57, 53, 0.1);
}

.text-success {
    color: #2E7D32 !important;
}

.text-primary {
    color: #1976D2 !important;
}

.text-warning {
    color: #FF9800 !important;
}

.text-danger {
    color: #E53935 !important;
}

.text-info {
    color: #0288D1 !important;
}

/* Métricas adicionais */
.metric {
    padding: 15px 0;
}

.metric .value {
    font-size: 2.6rem;
    font-weight: 700;
    margin-bottom: 5px;
}

.metric .label {
    font-weight: 600;
    color: #666;
    font-size: 0.85rem;
}

/* Responsividade */
@media (max-width: 1199px) {
    .col-md-3 {
        width: calc(50% - 20px);
    }
    
    .col-md-4 {
        width: calc(50% - 20px);
    }
}

@media (max-width: 768px) {
    .col-md-3, .col-md-4, .col-md-6 {
        width: calc(100% - 20px);
    }
    
    .display-4 {
        font-size: 2.5rem;
    }
    
    .metric .value {
        font-size: 2.2rem;
    }
}
</style>

<?php include 'footer.php'; ?>