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

// Criar tabela de eventos se não existir
$sql_create_table = "
CREATE TABLE IF NOT EXISTS eventos_calendario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    data_inicio DATETIME NOT NULL,
    data_fim DATETIME,
    cor VARCHAR(7) DEFAULT '#568C1C',
    usuario_id INT NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
)";
$conn->query($sql_create_table);

// Incluir o header comum
include 'header.php';
?>

<style>
    .calendar-container {
        background: white;
        border-radius: 12px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        padding: 25px;
        margin-bottom: 25px;
    }
    
    .calendar-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 2px solid #e9ecef;
    }
    
    .calendar-nav {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .calendar-nav button {
        background: var(--primary-color);
        color: white;
        border: none;
        padding: 8px 12px;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .calendar-nav button:hover {
        background: #225f26;
        transform: translateY(-2px);
    }
    
    .month-year {
        font-size: 24px;
        font-weight: 600;
        color: var(--primary-color);
        margin: 0 20px;
    }
    
    .btn-novo-evento {
        background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
        color: white;
        border: none;
        padding: 12px 20px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 3px 10px rgba(0,0,0,0.2);
    }
    
    .btn-novo-evento:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.3);
    }
    
    .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 1px;
        background: #e9ecef;
        border-radius: 8px;
        overflow: hidden;
    }
    
    .calendar-day-header {
        background: var(--primary-color);
        color: white;
        padding: 15px 5px;
        text-align: center;
        font-weight: 600;
        font-size: 14px;
    }
    
    .calendar-day {
        background: white;
        min-height: 120px;
        padding: 8px;
        position: relative;
        cursor: pointer;
        transition: background-color 0.3s;
    }
    
    .calendar-day:hover {
        background: #f8f9fa;
    }
    
    .calendar-day.other-month {
        background: #f8f9fa;
        color: #6c757d;
    }
    
    .calendar-day.today {
        background: #e8f5e9;
        border: 2px solid var(--primary-color);
    }
    
    .day-number {
        font-weight: 600;
        margin-bottom: 5px;
    }
    
    .evento-item {
        background: var(--primary-color);
        color: white;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 11px;
        margin-bottom: 2px;
        cursor: pointer;
        transition: all 0.3s;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    
    .evento-item:hover {
        transform: scale(1.05);
        box-shadow: 0 2px 5px rgba(0,0,0,0.3);
    }
    
    /* Modal Styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
        backdrop-filter: blur(5px);
    }
    
    .modal-content {
        background-color: white;
        margin: 5% auto;
        padding: 0;
        border-radius: 12px;
        width: 90%;
        max-width: 500px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        animation: modalSlideIn 0.3s ease;
    }
    
    @keyframes modalSlideIn {
        from { transform: translateY(-50px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
    
    .modal-header {
        background: var(--primary-color);
        color: white;
        padding: 20px;
        border-radius: 12px 12px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-header h3 {
        margin: 0;
        font-weight: 600;
    }
    
    .close {
        color: white;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .close:hover {
        transform: scale(1.2);
    }
    
    .modal-body {
        padding: 25px;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #333;
    }
    
    .form-group input,
    .form-group textarea,
    .form-group select {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 14px;
        transition: border-color 0.3s;
    }
    
    .form-group input:focus,
    .form-group textarea:focus,
    .form-group select:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(86, 140, 28, 0.1);
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 80px;
    }
    
    .color-picker {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 8px;
    }
    
    .color-option {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        cursor: pointer;
        border: 3px solid transparent;
        transition: all 0.3s;
    }
    
    .color-option.selected {
        border-color: #333;
        transform: scale(1.2);
    }
    
    .modal-footer {
        padding: 20px 25px;
        border-top: 1px solid #e9ecef;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    
    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s;
    }
    
    .btn-primary {
        background: var(--primary-color);
        color: white;
    }
    
    .btn-primary:hover {
        background: #225f26;
        transform: translateY(-2px);
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #545b62;
    }
    
    .btn-danger {
        background: #dc3545;
        color: white;
    }
    
    .btn-danger:hover {
        background: #c82333;
    }
    
    .evento-detalhes {
        padding: 15px 0;
    }
    
    .evento-detalhes h4 {
        color: var(--primary-color);
        margin-bottom: 10px;
    }
    
    .evento-info {
        margin-bottom: 10px;
    }
    
    .evento-info strong {
        color: #333;
    }
    
    /* Estilos para feriados */
    .feriado-item {
        background: #dc3545;
        color: white;
        padding: 1px 4px;
        border-radius: 3px;
        font-size: 10px;
        margin-bottom: 1px;
        font-weight: 600;
        text-align: center;
        opacity: 0.9;
    }
    
    .calendar-day.feriado {
        background: linear-gradient(135deg, #fff5f5, #ffe6e6);
        border-left: 4px solid #dc3545;
    }
    
    .calendar-day.feriado.today {
        background: linear-gradient(135deg, #e8f5e9, #ffe6e6);
        border: 2px solid var(--primary-color);
        border-left: 4px solid #dc3545;
    }
    
    .legenda-container {
        display: flex;
        justify-content: center;
        gap: 20px;
        margin-bottom: 15px;
        flex-wrap: wrap;
    }
    
    .legenda-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 12px;
        color: #666;
    }
    
    .legenda-cor {
        width: 16px;
        height: 16px;
        border-radius: 3px;
    }
    
    .legenda-evento {
        background: var(--primary-color);
    }
    
    .legenda-feriado {
        background: #dc3545;
    }
    
    .legenda-hoje {
        background: #e8f5e9;
        border: 2px solid var(--primary-color);
        width: 12px;
        height: 12px;
    }
    
    /* Responsividade para dispositivos móveis */
    @media (max-width: 768px) {
        .calendar-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .calendar-nav {
            justify-content: center;
        }
        
        .month-year {
            margin: 0;
            font-size: 20px;
        }
        
        .calendar-day {
            min-height: 80px;
            padding: 4px;
        }
        
        .calendar-day-header {
            padding: 10px 2px;
            font-size: 12px;
        }
        
        .evento-item {
            font-size: 10px;
            padding: 1px 4px;
        }
        
        .modal-content {
            width: 95%;
            margin: 10% auto;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .color-picker {
            justify-content: center;
        }
        
        .btn-novo-evento {
            padding: 10px 16px;
            font-size: 14px;
        }
    }
    
    @media (max-width: 480px) {
        .calendar-day {
            min-height: 60px;
            padding: 2px;
        }
        
        .day-number {
            font-size: 12px;
        }
        
        .evento-item {
            font-size: 9px;
            margin-bottom: 1px;
        }
        
        .modal-header h3 {
            font-size: 18px;
        }
        
        .form-group input,
        .form-group textarea {
            padding: 10px;
            font-size: 16px; /* Evita zoom no iOS */
        }
        
        .feriado-item {
            font-size: 8px;
            padding: 1px 2px;
        }
        
        .legenda-container {
            gap: 10px;
        }
        
        .legenda-item {
            font-size: 11px;
        }
        
        .legenda-cor {
            width: 12px;
            height: 12px;
        }
    }
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="calendar-container">
                <div class="calendar-header">
                    <div class="calendar-nav">
                        <button onclick="navegarMes(-1)">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <div class="month-year" id="monthYear"></div>
                        <button onclick="navegarMes(1)">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    <button class="btn-novo-evento" onclick="abrirModalNovoEvento()">
                        <i class="fas fa-plus"></i> Novo Evento
                    </button>
                </div>
                
                <!-- Legenda -->
                <div class="legenda-container">
                    <div class="legenda-item">
                        <div class="legenda-cor legenda-evento"></div>
                        <span>Eventos</span>
                    </div>
                    <div class="legenda-item">
                        <div class="legenda-cor legenda-feriado"></div>
                        <span>Feriados</span>
                    </div>
                    <div class="legenda-item">
                        <div class="legenda-cor legenda-hoje"></div>
                        <span>Hoje</span>
                    </div>
                </div>
                
                <div class="calendar-grid" id="calendarGrid">
                    <!-- Cabeçalhos dos dias da semana -->
                    <div class="calendar-day-header">Dom</div>
                    <div class="calendar-day-header">Seg</div>
                    <div class="calendar-day-header">Ter</div>
                    <div class="calendar-day-header">Qua</div>
                    <div class="calendar-day-header">Qui</div>
                    <div class="calendar-day-header">Sex</div>
                    <div class="calendar-day-header">Sáb</div>
                    <!-- Os dias serão gerados dinamicamente -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Novo Evento -->
<div id="modalNovoEvento" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Novo Evento</h3>
            <span class="close" onclick="fecharModal('modalNovoEvento')">&times;</span>
        </div>
        <div class="modal-body">
            <form id="formNovoEvento">
                <div class="form-group">
                    <label for="titulo">Título do Evento *</label>
                    <input type="text" id="titulo" name="titulo" required>
                </div>
                
                <div class="form-group">
                    <label for="descricao">Descrição</label>
                    <textarea id="descricao" name="descricao" placeholder="Descrição opcional do evento"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="dataInicio">Data e Hora de Início *</label>
                    <input type="datetime-local" id="dataInicio" name="dataInicio" required>
                </div>
                
                <div class="form-group">
                    <label for="dataFim">Data e Hora de Fim</label>
                    <input type="datetime-local" id="dataFim" name="dataFim">
                </div>
                
                <div class="form-group">
                    <label>Cor do Evento</label>
                    <div class="color-picker">
                        <div class="color-option selected" style="background-color: #568C1C" data-color="#568C1C"></div>
                        <div class="color-option" style="background-color: #1976D2" data-color="#1976D2"></div>
                        <div class="color-option" style="background-color: #388E3C" data-color="#388E3C"></div>
                        <div class="color-option" style="background-color: #F57C00" data-color="#F57C00"></div>
                        <div class="color-option" style="background-color: #7B1FA2" data-color="#7B1FA2"></div>
                        <div class="color-option" style="background-color: #C62828" data-color="#C62828"></div>
                        <div class="color-option" style="background-color: #455A64" data-color="#455A64"></div>
                        <div class="color-option" style="background-color: #E65100" data-color="#E65100"></div>
                    </div>
                    <input type="hidden" id="cor" name="cor" value="#568C1C">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="fecharModal('modalNovoEvento')">Cancelar</button>
            <button type="button" class="btn btn-primary" onclick="salvarEvento()">Salvar Evento</button>
        </div>
    </div>
</div>

<!-- Modal Ver Evento -->
<div id="modalVerEvento" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Detalhes do Evento</h3>
            <span class="close" onclick="fecharModal('modalVerEvento')">&times;</span>
        </div>
        <div class="modal-body">
            <div class="evento-detalhes" id="eventoDetalhes">
                <!-- Conteúdo será preenchido dinamicamente -->
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-danger" onclick="deletarEvento()" id="btnDeletarEvento">
                <i class="fas fa-trash"></i> Deletar
            </button>
            <button type="button" class="btn btn-secondary" onclick="fecharModal('modalVerEvento')">Fechar</button>
        </div>
    </div>
</div>

<script>
let mesAtual = new Date().getMonth();
let anoAtual = new Date().getFullYear();
let eventoSelecionado = null;
let eventos = [];
let feriados = [];

const meses = [
    'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
    'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'
];

// Função para calcular a Páscoa (algoritmo de Gauss)
function calcularPascoa(ano) {
    const a = ano % 19;
    const b = Math.floor(ano / 100);
    const c = ano % 100;
    const d = Math.floor(b / 4);
    const e = b % 4;
    const f = Math.floor((b + 8) / 25);
    const g = Math.floor((b - f + 1) / 3);
    const h = (19 * a + b - d - g + 15) % 30;
    const i = Math.floor(c / 4);
    const k = c % 4;
    const l = (32 + 2 * e + 2 * i - h - k) % 7;
    const m = Math.floor((a + 11 * h + 22 * l) / 451);
    const n = Math.floor((h + l - 7 * m + 114) / 31);
    const p = (h + l - 7 * m + 114) % 31;
    
    return new Date(ano, n - 1, p + 1);
}

// Função para calcular feriados do Brasil e Portugal
function calcularFeriados(ano) {
    const feriados = [];
    const pascoa = calcularPascoa(ano);
    
    // Feriados fixos do Brasil
    const feriadosBrasil = [
        { data: new Date(ano, 0, 1), nome: 'Confraternização Universal', pais: 'BR' },
        { data: new Date(ano, 3, 21), nome: 'Tiradentes', pais: 'BR' },
        { data: new Date(ano, 4, 1), nome: 'Dia do Trabalhador', pais: 'BR' },
        { data: new Date(ano, 8, 7), nome: 'Independência do Brasil', pais: 'BR' },
        { data: new Date(ano, 9, 12), nome: 'Nossa Senhora Aparecida', pais: 'BR' },
        { data: new Date(ano, 10, 2), nome: 'Finados', pais: 'BR' },
        { data: new Date(ano, 10, 15), nome: 'Proclamação da República', pais: 'BR' },
        { data: new Date(ano, 11, 25), nome: 'Natal', pais: 'BR' }
    ];
    
    // Feriados fixos de Portugal
    const feriadosPortugal = [
        { data: new Date(ano, 0, 1), nome: 'Ano Novo', pais: 'PT' },
        { data: new Date(ano, 3, 25), nome: 'Dia da Liberdade', pais: 'PT' },
        { data: new Date(ano, 4, 1), nome: 'Dia do Trabalhador', pais: 'PT' },
        { data: new Date(ano, 5, 10), nome: 'Dia de Portugal', pais: 'PT' },
        { data: new Date(ano, 7, 15), nome: 'Assunção de Nossa Senhora', pais: 'PT' },
        { data: new Date(ano, 9, 5), nome: 'Implantação da República', pais: 'PT' },
        { data: new Date(ano, 10, 1), nome: 'Todos os Santos', pais: 'PT' },
        { data: new Date(ano, 11, 1), nome: 'Restauração da Independência', pais: 'PT' },
        { data: new Date(ano, 11, 8), nome: 'Imaculada Conceição', pais: 'PT' },
        { data: new Date(ano, 11, 25), nome: 'Natal', pais: 'PT' }
    ];
    
    // Feriados móveis baseados na Páscoa
    const feriadosMoveis = [
        // Carnaval (47 dias antes da Páscoa) - Brasil
        { 
            data: new Date(pascoa.getTime() - 47 * 24 * 60 * 60 * 1000), 
            nome: 'Carnaval', 
            pais: 'BR' 
        },
        // Sexta-feira Santa (2 dias antes da Páscoa)
        { 
            data: new Date(pascoa.getTime() - 2 * 24 * 60 * 60 * 1000), 
            nome: 'Sexta-feira Santa', 
            pais: 'BR/PT' 
        },
        // Páscoa
        { 
            data: new Date(pascoa.getTime()), 
            nome: 'Páscoa', 
            pais: 'BR/PT' 
        },
        // Corpus Christi (60 dias após a Páscoa) - Brasil
        { 
            data: new Date(pascoa.getTime() + 60 * 24 * 60 * 60 * 1000), 
            nome: 'Corpus Christi', 
            pais: 'BR' 
        }
    ];
    
    // Combinar todos os feriados
    return [...feriadosBrasil, ...feriadosPortugal, ...feriadosMoveis];
}

// Função para verificar se uma data é feriado
function isFeriado(data) {
    return feriados.find(feriado => {
        const feriadoData = feriado.data;
        return feriadoData.getDate() === data.getDate() &&
               feriadoData.getMonth() === data.getMonth() &&
               feriadoData.getFullYear() === data.getFullYear();
    });
}

// Inicializar calendário
document.addEventListener('DOMContentLoaded', function() {
    feriados = calcularFeriados(anoAtual);
    carregarEventos();
    renderizarCalendario();
    
    // Event listeners para seleção de cor
    document.querySelectorAll('.color-option').forEach(option => {
        option.addEventListener('click', function() {
            document.querySelectorAll('.color-option').forEach(opt => opt.classList.remove('selected'));
            this.classList.add('selected');
            document.getElementById('cor').value = this.dataset.color;
        });
    });
});

function navegarMes(direcao) {
    const anoAnterior = anoAtual;
    mesAtual += direcao;
    if (mesAtual > 11) {
        mesAtual = 0;
        anoAtual++;
    } else if (mesAtual < 0) {
        mesAtual = 11;
        anoAtual--;
    }
    
    // Recalcular feriados se o ano mudou
    if (anoAtual !== anoAnterior) {
        feriados = calcularFeriados(anoAtual);
    }
    
    renderizarCalendario();
}

function renderizarCalendario() {
    const monthYear = document.getElementById('monthYear');
    monthYear.textContent = `${meses[mesAtual]} ${anoAtual}`;
    
    const calendarGrid = document.getElementById('calendarGrid');
    
    // Limpar dias anteriores (manter cabeçalhos)
    const dayHeaders = calendarGrid.querySelectorAll('.calendar-day-header');
    calendarGrid.innerHTML = '';
    dayHeaders.forEach(header => calendarGrid.appendChild(header));
    
    // Primeiro dia do mês e último dia do mês anterior
    const primeiroDia = new Date(anoAtual, mesAtual, 1);
    const ultimoDia = new Date(anoAtual, mesAtual + 1, 0);
    const diasNoMes = ultimoDia.getDate();
    const diaSemanaInicio = primeiroDia.getDay();
    
    // Dias do mês anterior
    const ultimoDiaMesAnterior = new Date(anoAtual, mesAtual, 0).getDate();
    for (let i = diaSemanaInicio - 1; i >= 0; i--) {
        const dia = ultimoDiaMesAnterior - i;
        const dayElement = criarElementoDia(dia, true);
        calendarGrid.appendChild(dayElement);
    }
    
    // Dias do mês atual
    const hoje = new Date();
    for (let dia = 1; dia <= diasNoMes; dia++) {
        const isToday = hoje.getDate() === dia && 
                       hoje.getMonth() === mesAtual && 
                       hoje.getFullYear() === anoAtual;
        const dayElement = criarElementoDia(dia, false, isToday);
        calendarGrid.appendChild(dayElement);
    }
    
    // Dias do próximo mês para completar a grade
    const diasRestantes = 42 - (diaSemanaInicio + diasNoMes);
    for (let dia = 1; dia <= diasRestantes; dia++) {
        const dayElement = criarElementoDia(dia, true);
        calendarGrid.appendChild(dayElement);
    }
}

function criarElementoDia(numeroDia, outroMes = false, isToday = false) {
    const dayElement = document.createElement('div');
    const dataAtual = new Date(anoAtual, mesAtual, numeroDia);
    const feriado = !outroMes ? isFeriado(dataAtual) : null;
    
    dayElement.className = `calendar-day ${outroMes ? 'other-month' : ''} ${isToday ? 'today' : ''} ${feriado ? 'feriado' : ''}`;
    
    const dayNumber = document.createElement('div');
    dayNumber.className = 'day-number';
    dayNumber.textContent = numeroDia;
    dayElement.appendChild(dayNumber);
    
    if (!outroMes) {
        // Adicionar feriado se existir
        if (feriado) {
            const feriadoElement = document.createElement('div');
            feriadoElement.className = 'feriado-item';
            feriadoElement.textContent = feriado.nome;
            feriadoElement.title = `${feriado.nome} (${feriado.pais})`;
            feriadoElement.onclick = (e) => {
                e.stopPropagation();
                mostrarFeriado(feriado, dataAtual);
            };
            dayElement.appendChild(feriadoElement);
        }
        
        // Adicionar eventos do dia
        const eventosNoDia = eventos.filter(evento => {
            const dataEvento = new Date(evento.data_inicio);
            return dataEvento.getDate() === numeroDia &&
                   dataEvento.getMonth() === mesAtual &&
                   dataEvento.getFullYear() === anoAtual;
        });
        
        eventosNoDia.forEach(evento => {
            const eventoElement = document.createElement('div');
            eventoElement.className = 'evento-item';
            eventoElement.style.backgroundColor = evento.cor;
            eventoElement.textContent = evento.titulo;
            eventoElement.onclick = (e) => {
                e.stopPropagation();
                mostrarEvento(evento);
            };
            dayElement.appendChild(eventoElement);
        });
        
        // Adicionar evento de clique para criar novo evento
        dayElement.onclick = () => {
            const dataClicada = new Date(anoAtual, mesAtual, numeroDia);
            abrirModalNovoEvento(dataClicada);
        };
    }
    
    return dayElement;
}

function carregarEventos() {
    fetch('api_calendario.php?action=listar')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                eventos = data.eventos;
                renderizarCalendario();
            }
        })
        .catch(error => console.error('Erro ao carregar eventos:', error));
}

function abrirModalNovoEvento(data = null) {
    const modal = document.getElementById('modalNovoEvento');
    const form = document.getElementById('formNovoEvento');
    form.reset();
    
    // Resetar seleção de cor
    document.querySelectorAll('.color-option').forEach(opt => opt.classList.remove('selected'));
    document.querySelector('.color-option[data-color="#568C1C"]').classList.add('selected');
    document.getElementById('cor').value = '#568C1C';
    
    if (data) {
        const dataFormatada = data.toISOString().slice(0, 16);
        document.getElementById('dataInicio').value = dataFormatada;
    }
    
    modal.style.display = 'block';
}

function mostrarEvento(evento) {
    eventoSelecionado = evento;
    const modal = document.getElementById('modalVerEvento');
    const detalhes = document.getElementById('eventoDetalhes');
    const btnDeletar = document.getElementById('btnDeletarEvento');
    
    // Mostrar botão de deletar para eventos normais
    btnDeletar.style.display = 'inline-block';
    
    const dataInicio = new Date(evento.data_inicio);
    const dataFim = evento.data_fim ? new Date(evento.data_fim) : null;
    
    detalhes.innerHTML = `
        <h4 style="color: ${evento.cor}">${evento.titulo}</h4>
        <div class="evento-info">
            <strong>Data de Início:</strong> ${dataInicio.toLocaleString('pt-BR')}
        </div>
        ${dataFim ? `<div class="evento-info"><strong>Data de Fim:</strong> ${dataFim.toLocaleString('pt-BR')}</div>` : ''}
        ${evento.descricao ? `<div class="evento-info"><strong>Descrição:</strong><br>${evento.descricao}</div>` : ''}
    `;
    
    modal.style.display = 'block';
}

function mostrarFeriado(feriado, dataAtual) {
    eventoSelecionado = null; // Limpar evento selecionado
    const modal = document.getElementById('modalVerEvento');
    const detalhes = document.getElementById('eventoDetalhes');
    const btnDeletar = document.getElementById('btnDeletarEvento');
    
    // Ocultar botão de deletar para feriados
    btnDeletar.style.display = 'none';
    
    const paisNome = {
        'BR': 'Brasil',
        'PT': 'Portugal',
        'BR/PT': 'Brasil/Portugal'
    };
    
    detalhes.innerHTML = `
        <h4 style="color: #dc3545">${feriado.nome}</h4>
        <div class="evento-info">
            <strong>Data:</strong> ${dataAtual.toLocaleDateString('pt-BR')}
        </div>
        <div class="evento-info">
            <strong>País:</strong> ${paisNome[feriado.pais] || feriado.pais}
        </div>
        <div class="evento-info">
            <strong>Tipo:</strong> Feriado Nacional
        </div>
    `;
    
    modal.style.display = 'block';
}

function salvarEvento() {
    const form = document.getElementById('formNovoEvento');
    const formData = new FormData(form);
    
    if (!formData.get('titulo') || !formData.get('dataInicio')) {
        alert('Por favor, preencha os campos obrigatórios.');
        return;
    }
    
    fetch('api_calendario.php?action=criar', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            fecharModal('modalNovoEvento');
            carregarEventos();
            alert('Evento criado com sucesso!');
        } else {
            alert('Erro ao criar evento: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao salvar evento.');
    });
}

function deletarEvento() {
    if (!eventoSelecionado) return;
    
    if (confirm('Tem certeza que deseja deletar este evento?')) {
        fetch('api_calendario.php?action=deletar', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ id: eventoSelecionado.id })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                fecharModal('modalVerEvento');
                carregarEventos();
                alert('Evento deletado com sucesso!');
            } else {
                alert('Erro ao deletar evento: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('Erro ao deletar evento.');
        });
    }
}

function fecharModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
    eventoSelecionado = null;
}

// Fechar modal clicando fora dele
window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}
</script>

<?php include 'footer.php'; ?> 