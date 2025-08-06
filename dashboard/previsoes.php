<?php include 'header.php'; 

// Conectar ao banco
$conn = conectarBD();

// Dados históricos para previsões
$dados_historicos = $conn->query("
    SELECT 
        DATE_FORMAT(data_criacao, '%Y-%m') as mes,
        COUNT(*) as total_entregas
    FROM entregas
    WHERE data_criacao >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(data_criacao, '%Y-%m')
    ORDER BY mes
");

// Preparar dados para o gráfico
$meses_historico = [];
$valores_historico = [];
while ($row = $dados_historicos->fetch_assoc()) {
    $meses_historico[] = date('M/Y', strtotime($row['mes'] . '-01'));
    $valores_historico[] = $row['total_entregas'];
}

// Calcular média e tendência
$total_meses = count($valores_historico);
$media_entregas = 0;
$tendencia_percentual = 0;

if ($total_meses > 0) {
    $media_entregas = array_sum($valores_historico) / $total_meses;
    
    // Cálculo de tendência simples (comparação dos últimos 3 meses com os 3 anteriores)
    if ($total_meses >= 6) {
        $ultimos_3_meses = array_slice($valores_historico, -3);
        $anteriores_3_meses = array_slice($valores_historico, -6, 3);
        
        $media_ultimos = array_sum($ultimos_3_meses) / 3;
        $media_anteriores = array_sum($anteriores_3_meses) / 3;
        
        if ($media_anteriores > 0) {
            $tendencia_percentual = (($media_ultimos - $media_anteriores) / $media_anteriores) * 100;
        }
    }
}

// Meses futuros para previsão
$meses_futuros = [];
$previsao_valores = [];

// Prever próximos 3 meses com base em tendência linear simples
for ($i = 1; $i <= 3; $i++) {
    $data_futura = date('M/Y', strtotime("+$i month"));
    $meses_futuros[] = $data_futura;
    
    // Fator de ajuste baseado na tendência (simplificado)
    $fator_tendencia = 1 + ($tendencia_percentual / 100);
    $previsao = $media_entregas * pow($fator_tendencia, $i);
    $previsao_valores[] = round($previsao);
}

// Destinos com maior crescimento
$destinos_crescimento = $conn->query("
    SELECT 
        destino,
        COUNT(*) as total,
        MAX(data_criacao) as ultima_data
    FROM entregas
    WHERE destino != ''
    GROUP BY destino
    HAVING COUNT(*) > 3
    ORDER BY ultima_data DESC, total DESC
    LIMIT 5
");

// Entregas por dia da semana
$dia_semana = $conn->query("
    SELECT 
        DAYNAME(data_criacao) as dia_semana,
        COUNT(*) as total
    FROM entregas
    GROUP BY DAYNAME(data_criacao)
    ORDER BY FIELD(DAYNAME(data_criacao), 
        'Monday', 'Tuesday', 'Wednesday', 'Thursday', 
        'Friday', 'Saturday', 'Sunday')
");

$dias = [];
$totais_dias = [];
while ($row = $dia_semana->fetch_assoc()) {
    // Traduzir nome do dia
    $nome_dia = $row['dia_semana'];
    switch($nome_dia) {
        case 'Monday': $nome_dia = 'Segunda'; break;
        case 'Tuesday': $nome_dia = 'Terça'; break;
        case 'Wednesday': $nome_dia = 'Quarta'; break;
        case 'Thursday': $nome_dia = 'Quinta'; break;
        case 'Friday': $nome_dia = 'Sexta'; break;
        case 'Saturday': $nome_dia = 'Sábado'; break;
        case 'Sunday': $nome_dia = 'Domingo'; break;
    }
    
    $dias[] = $nome_dia;
    $totais_dias[] = $row['total'];
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Previsões & Insights de Negócio</h5>
                <div>
                    <button class="btn btn-sm btn-outline-primary" onclick="window.print();">
                        <i class="fas fa-print"></i> Imprimir Relatório
                    </button>
                </div>
            </div>
            <div class="card-body">
                
                <!-- Resumo de Previsão -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h5 class="card-title">Tendência de Entregas</h5>
                                <div class="d-flex align-items-center">
                                    <h3 class="me-3 mb-0">
                                        <?php 
                                            echo ($tendencia_percentual >= 0) ? '+' : '';
                                            echo round($tendencia_percentual, 1) . '%'; 
                                        ?>
                                    </h3>
                                    <div>
                                        <?php if ($tendencia_percentual > 0): ?>
                                            <i class="fas fa-arrow-up text-success fa-2x"></i>
                                        <?php elseif ($tendencia_percentual < 0): ?>
                                            <i class="fas fa-arrow-down text-danger fa-2x"></i>
                                        <?php else: ?>
                                            <i class="fas fa-equals text-secondary fa-2x"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <p class="text-muted mt-2">
                                    Comparação dos últimos 3 meses com o período anterior
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h5 class="card-title">Volume Projetado (Próximo Mês)</h5>
                                <div class="d-flex align-items-center">
                                    <h3 class="me-3 mb-0">
                                        <?php echo (count($previsao_valores) > 0) ? $previsao_valores[0] : 0; ?> 
                                        entregas
                                    </h3>
                                    <div>
                                        <i class="fas fa-box-open text-primary fa-2x"></i>
                                    </div>
                                </div>
                                <p class="text-muted mt-2">
                                    Estimativa baseada na tendência histórica e sazonalidade
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Gráfico de Previsão -->
                <div class="row">
                    <div class="col-12 mb-4">
                        <div class="card h-100">
                            <div class="card-header">
                                <h6 class="mb-0">Previsão de Volume para os Próximos 3 Meses</h6>
                            </div>
                            <div class="card-body">
                                <canvas id="previsaoChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header">
                                <h6 class="mb-0">Destinos com Maior Potencial de Crescimento</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Destino</th>
                                            <th>Total</th>
                                            <th>Último Envio</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($row = $destinos_crescimento->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['destino']); ?></td>
                                            <td><?php echo $row['total']; ?></td>
                                            <td>
                                                <?php echo date('d/m/Y', strtotime($row['ultima_data'])); ?>
                                                <?php 
                                                    // Verificar se é recente (últimos 30 dias)
                                                    $dias_desde_ultimo = (time() - strtotime($row['ultima_data'])) / 86400;
                                                    if ($dias_desde_ultimo <= 30):
                                                ?>
                                                <span class="badge bg-success">Recente</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header">
                                <h6 class="mb-0">Distribuição por Dia da Semana</h6>
                            </div>
                            <div class="card-body">
                                <canvas id="diasSemanaChart" height="250"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recomendações -->
                <div class="row">
                    <div class="col-12 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">Recomendações Operacionais</h6>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info">
                                    <h5><i class="fas fa-lightbulb"></i> Insights</h5>
                                    <ul class="mb-0">
                                        <?php if ($tendencia_percentual > 10): ?>
                                        <li>A tendência de crescimento é significativa (<?php echo round($tendencia_percentual, 1); ?>%). 
                                            Considere aumentar a capacidade operacional para os próximos meses.</li>
                                        <?php endif; ?>
                                        
                                        <?php 
                                        // Verificar dia com maior volume
                                        $max_dia = array_search(max($totais_dias), $totais_dias);
                                        if ($max_dia !== false):
                                        ?>
                                        <li>O dia com maior volume de entregas é <strong><?php echo $dias[$max_dia]; ?></strong>. 
                                            Considere otimizar recursos neste dia da semana.</li>
                                        <?php endif; ?>
                                        
                                        <?php 
                                        // Verificar se há destinos em crescimento
                                        $destinos_crescimento->data_seek(0);
                                        $primeiro_destino = $destinos_crescimento->fetch_assoc();
                                        if ($primeiro_destino):
                                        ?>
                                        <li>O destino <strong><?php echo htmlspecialchars($primeiro_destino['destino']); ?></strong> 
                                            apresenta maior potencial de crescimento baseado em dados recentes.</li>
                                        <?php endif; ?>
                                        
                                        <li>O volume mensal médio é de <strong><?php echo round($media_entregas); ?></strong> 
                                            entregas. Planeje recursos considerando a previsão de 
                                            <strong><?php echo (count($previsao_valores) > 0) ? $previsao_valores[0] : 0; ?></strong> 
                                            para o próximo mês.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Previsão Chart
const previsaoCtx = document.getElementById('previsaoChart').getContext('2d');
const previsaoChart = new Chart(previsaoCtx, {
    type: 'line',
    data: {
        labels: [
            <?php 
            // Dados históricos
            foreach ($meses_historico as $mes) {
                echo "'$mes', ";
            }
            // Dados futuros
            foreach ($meses_futuros as $mes) {
                echo "'$mes (Prev)', ";
            }
            ?>
        ],
        datasets: [{
            label: 'Entregas Reais',
            data: [
                <?php 
                // Dados históricos reais
                foreach ($valores_historico as $valor) {
                    echo "$valor, ";
                }
                // Preencher com null para a parte de previsão
                for ($i = 0; $i < count($meses_futuros); $i++) {
                    echo "null, ";
                }
                ?>
            ],
            borderColor: '#2C3E50',
            backgroundColor: 'rgba(44, 62, 80, 0.1)',
            borderWidth: 2,
            fill: true
        },
        {
            label: 'Previsão',
            data: [
                <?php 
                // Preencher com null para a parte histórica
                for ($i = 0; $i < count($meses_historico); $i++) {
                    echo "null, ";
                }
                // Dados de previsão
                foreach ($previsao_valores as $valor) {
                    echo "$valor, ";
                }
                ?>
            ],
            borderColor: '#568C1C',
            backgroundColor: 'rgba(86, 140, 28, 0.1)',
            borderWidth: 2,
            borderDash: [5, 5],
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

// Dias da Semana Chart
const diasSemanaCtx = document.getElementById('diasSemanaChart').getContext('2d');
const diasSemanaChart = new Chart(diasSemanaCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($dias); ?>,
        datasets: [{
            label: 'Entregas',
            data: <?php echo json_encode($totais_dias); ?>,
            backgroundColor: '#568C1C',
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
</script>

<?php include 'footer.php'; ?>