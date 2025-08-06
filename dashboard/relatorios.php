<?php 
// Incluir o header e verificar login
include 'header.php'; 

// Conectar ao banco
$conn = conectarBD();

// Processar filtros
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01'); // Primeiro dia do mês atual
$data_fim = $_GET['data_fim'] ?? date('Y-m-t'); // Último dia do mês atual
$tipo_relatorio = $_GET['tipo'] ?? 'vendas';

// Buscar dados baseados no tipo de relatório
$dados_relatorio = [];
$titulo_relatorio = '';

switch($tipo_relatorio) {
    case 'vendas':
        $titulo_relatorio = 'Relatório de Vendas';
        $sql = "SELECT 
                    DATE(ultima_atualizacao) as data,
                    COUNT(*) as total_pedidos,
                    SUM(CASE WHEN status = 'ENTREGUE - PAGO' THEN 1 ELSE 0 END) as entregues,
                    SUM(CASE WHEN status = 'DEVOLVIDAS' THEN 1 ELSE 0 END) as devolvidos
                FROM entregas 
                WHERE DATE(ultima_atualizacao) BETWEEN ? AND ?
                GROUP BY DATE(ultima_atualizacao)
                ORDER BY data DESC";
        break;
        
    case 'entregas':
        $titulo_relatorio = 'Relatório de Entregas';
        $sql = "SELECT 
                    status,
                    COUNT(*) as quantidade,
                    ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM entregas WHERE DATE(ultima_atualizacao) BETWEEN ? AND ?)), 2) as percentual
                FROM entregas 
                WHERE DATE(ultima_atualizacao) BETWEEN ? AND ?
                GROUP BY status
                ORDER BY quantidade DESC";
        break;
        
    case 'clientes':
        $titulo_relatorio = 'Relatório de Clientes';
        $sql = "SELECT 
                    destino as cidade,
                    COUNT(*) as total_clientes,
                    SUM(CASE WHEN status = 'ENTREGUE - PAGO' THEN 1 ELSE 0 END) as entregas_sucesso
                FROM entregas 
                WHERE DATE(ultima_atualizacao) BETWEEN ? AND ?
                GROUP BY destino
                ORDER BY total_clientes DESC
                LIMIT 20";
        break;
}

// Executar consulta
$stmt = $conn->prepare($sql);
if ($tipo_relatorio == 'entregas') {
    $stmt->bind_param("ssss", $data_inicio, $data_fim, $data_inicio, $data_fim);
} else {
    $stmt->bind_param("ss", $data_inicio, $data_fim);
}
$stmt->execute();
$dados_relatorio = $stmt->get_result();

// Calcular totais gerais
$sql_totais = "SELECT 
                COUNT(*) as total_geral,
                SUM(CASE WHEN status = 'ENTREGUE - PAGO' THEN 1 ELSE 0 END) as total_entregues,
                SUM(CASE WHEN status = 'DEVOLVIDAS' THEN 1 ELSE 0 END) as total_devolvidos,
                SUM(CASE WHEN status IN ('EM TRÂNSITO', 'EM TRÂNSITO - MENSAL') THEN 1 ELSE 0 END) as total_transito
               FROM entregas 
               WHERE DATE(ultima_atualizacao) BETWEEN ? AND ?";
$stmt_totais = $conn->prepare($sql_totais);
$stmt_totais->bind_param("ss", $data_inicio, $data_fim);
$stmt_totais->execute();
$totais = $stmt_totais->get_result()->fetch_assoc();
?>

<style>
.relatorio-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.metric-box {
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.2);
}

.metric-number {
    font-size: 2.5rem;
    font-weight: bold;
    margin-bottom: 5px;
}

.metric-label {
    font-size: 0.9rem;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.chart-container {
    background: white;
    border-radius: 15px;
    padding: 25px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    margin-bottom: 25px;
}

.filter-card {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: white;
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 25px;
}

.btn-relatorio {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    color: white;
    padding: 10px 20px;
    border-radius: 25px;
    transition: all 0.3s ease;
}

.btn-relatorio:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    color: white;
}
</style>

<div class="row">
    <!-- Filtros -->
    <div class="col-12">
        <div class="filter-card">
            <h5 class="mb-3"><i class="fas fa-filter"></i> Filtros do Relatório</h5>
            <form method="get" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Data Início</label>
                    <input type="date" name="data_inicio" class="form-control" value="<?php echo $data_inicio; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Data Fim</label>
                    <input type="date" name="data_fim" class="form-control" value="<?php echo $data_fim; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tipo de Relatório</label>
                    <select name="tipo" class="form-select">
                        <option value="vendas" <?php echo $tipo_relatorio == 'vendas' ? 'selected' : ''; ?>>Vendas por Data</option>
                        <option value="entregas" <?php echo $tipo_relatorio == 'entregas' ? 'selected' : ''; ?>>Status de Entregas</option>
                        <option value="clientes" <?php echo $tipo_relatorio == 'clientes' ? 'selected' : ''; ?>>Clientes por Cidade</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-relatorio w-100">
                        <i class="fas fa-search"></i> Gerar Relatório
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Métricas Gerais -->
    <div class="col-12">
        <div class="relatorio-card">
            <h4 class="mb-4"><i class="fas fa-chart-bar"></i> Resumo do Período</h4>
            <div class="row">
                <div class="col-md-3">
                    <div class="metric-box">
                        <div class="metric-number"><?php echo number_format($totais['total_geral']); ?></div>
                        <div class="metric-label">Total de Pedidos</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="metric-box">
                        <div class="metric-number"><?php echo number_format($totais['total_entregues']); ?></div>
                        <div class="metric-label">Entregues</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="metric-box">
                        <div class="metric-number"><?php echo number_format($totais['total_transito']); ?></div>
                        <div class="metric-label">Em Trânsito</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="metric-box">
                        <div class="metric-number"><?php echo number_format($totais['total_devolvidos']); ?></div>
                        <div class="metric-label">Devolvidos</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Dados do Relatório -->
    <div class="col-12">
        <div class="chart-container">
            <h5 class="mb-4"><?php echo $titulo_relatorio; ?></h5>
            
            <?php if ($dados_relatorio->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-dark">
                            <?php if ($tipo_relatorio == 'vendas'): ?>
                                <tr>
                                    <th>Data</th>
                                    <th>Total de Pedidos</th>
                                    <th>Entregues</th>
                                    <th>Devolvidos</th>
                                    <th>Taxa de Sucesso</th>
                                </tr>
                            <?php elseif ($tipo_relatorio == 'entregas'): ?>
                                <tr>
                                    <th>Status</th>
                                    <th>Quantidade</th>
                                    <th>Percentual</th>
                                    <th>Visualização</th>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <th>Cidade</th>
                                    <th>Total de Clientes</th>
                                    <th>Entregas com Sucesso</th>
                                    <th>Taxa de Sucesso</th>
                                </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php while ($row = $dados_relatorio->fetch_assoc()): ?>
                                <tr>
                                    <?php if ($tipo_relatorio == 'vendas'): ?>
                                        <td><?php echo date('d/m/Y', strtotime($row['data'])); ?></td>
                                        <td><?php echo number_format($row['total_pedidos']); ?></td>
                                        <td><span class="badge bg-success"><?php echo number_format($row['entregues']); ?></span></td>
                                        <td><span class="badge bg-danger"><?php echo number_format($row['devolvidos']); ?></span></td>
                                        <td>
                                            <?php 
                                            $taxa = $row['total_pedidos'] > 0 ? ($row['entregues'] / $row['total_pedidos']) * 100 : 0;
                                            $cor_taxa = $taxa >= 80 ? 'success' : ($taxa >= 60 ? 'warning' : 'danger');
                                            ?>
                                            <span class="badge bg-<?php echo $cor_taxa; ?>"><?php echo number_format($taxa, 1); ?>%</span>
                                        </td>
                                    <?php elseif ($tipo_relatorio == 'entregas'): ?>
                                        <td><?php echo $row['status']; ?></td>
                                        <td><?php echo number_format($row['quantidade']); ?></td>
                                        <td><?php echo number_format($row['percentual'], 1); ?>%</td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar" style="width: <?php echo $row['percentual']; ?>%">
                                                    <?php echo number_format($row['percentual'], 1); ?>%
                                                </div>
                                            </div>
                                        </td>
                                    <?php else: ?>
                                        <td><?php echo $row['cidade']; ?></td>
                                        <td><?php echo number_format($row['total_clientes']); ?></td>
                                        <td><span class="badge bg-success"><?php echo number_format($row['entregas_sucesso']); ?></span></td>
                                        <td>
                                            <?php 
                                            $taxa = $row['total_clientes'] > 0 ? ($row['entregas_sucesso'] / $row['total_clientes']) * 100 : 0;
                                            $cor_taxa = $taxa >= 80 ? 'success' : ($taxa >= 60 ? 'warning' : 'danger');
                                            ?>
                                            <span class="badge bg-<?php echo $cor_taxa; ?>"><?php echo number_format($taxa, 1); ?>%</span>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Nenhum dado encontrado para o período selecionado.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Ações -->
    <div class="col-12">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="card-title">Exportar Relatório</h6>
                <p class="card-text">Baixe os dados do relatório em diferentes formatos</p>
                <button class="btn btn-outline-success me-2" onclick="exportarCSV()">
                    <i class="fas fa-file-csv"></i> Exportar CSV
                </button>
                <button class="btn btn-outline-primary me-2" onclick="imprimirRelatorio()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <button class="btn btn-outline-info" onclick="compartilharRelatorio()">
                    <i class="fas fa-share"></i> Compartilhar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function exportarCSV() {
    alert('Funcionalidade de exportação CSV será implementada em breve!');
}

function imprimirRelatorio() {
    window.print();
}

function compartilharRelatorio() {
    if (navigator.share) {
        navigator.share({
            title: '<?php echo $titulo_relatorio; ?>',
            text: 'Confira este relatório do sistema 4Life Nutri',
            url: window.location.href
        });
    } else {
        // Fallback para navegadores que não suportam Web Share API
        navigator.clipboard.writeText(window.location.href).then(() => {
            alert('Link do relatório copiado para a área de transferência!');
        });
    }
}
</script>

<?php include 'footer.php'; ?>