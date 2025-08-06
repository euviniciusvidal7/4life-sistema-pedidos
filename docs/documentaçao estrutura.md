# ESTRUTURA COMPLETA DO FUNIL DE VENDAS - 4LIFE NUTRITION

## 📋 VISÃO GERAL DO SISTEMA

Este documento detalha completamente a estrutura técnica e funcional do funil de vendas da 4Life Nutrition, incluindo todos os componentes, integrações, fluxos de dados e funcionamento interno dos sistemas.

### Arquitetura Geral
```
Facebook Ads → Quiz → VSL → Páginas de Contato → Sistema de Pedidos → Dashboard → Trello
     ↓           ↓      ↓           ↓                    ↓              ↓         ↓
Meta Pixel → Tracking → Pixel → Chatbot N8N → Arquivos JSON → MySQL → Trello API
```

---

## 🏗️ MAPEAMENTO DETALHADO DAS PASTAS E COMUNICAÇÃO

### Estrutura de Pastas do Funil
```
c:\Users\Vinicius\Desktop\Estrutura\
├── quiz-rejuvenescimento/     # ETAPA 1: Captura de Leads
├── b/                         # ETAPA 2: VSL (Video Sales Letter)
├── bruno-celso/              # ETAPA 3: Página de Autoridade/Contato
├── 2025/                     # ETAPA 4: Sistema de Chat e Pedidos
├── dashboard/                # ETAPA 5: Gestão Administrativa
└── checkout/                 # [NÃO UTILIZADA NO FUNIL ATUAL]
```

### Fluxo de Comunicação Entre Pastas

#### 1️⃣ **quiz-rejuvenescimento/** → **b/**
- **Comunicação**: Redirecionamento via JavaScript após conclusão do quiz
- **Dados Transferidos**: Respostas do quiz via URL parameters ou localStorage
- **Tracking**: Meta Pixel eventos `Lead` → `ViewContent`
- **Arquivo Chave**: `quiz-rejuvenescimento/assets/js/quiz.js`

#### 2️⃣ **b/** → **bruno-celso/**
- **Comunicação**: Botão CTA no final do VSL redireciona para página de autoridade
- **Dados Transferidos**: UTM parameters e dados de tracking
- **Tracking**: Meta Pixel evento `ViewContent` → `InitiateCheckout`
- **Arquivo Chave**: `b/index.html` (botões de redirecionamento)

#### 3️⃣ **bruno-celso/** → **2025/**
- **Comunicação**: Botões WhatsApp/Facebook redirecionam para sistema de chat
- **Dados Transferidos**: Origem do tráfego (whatsapp/facebook)
- **Tracking**: Meta Pixel evento `InitiateCheckout` → `AddToCart`
- **Arquivos Chave**: 
  - `bruno-celso/index.html` → `2025/index_whatsapp.html`
  - `bruno-celso/index.html` → `2025/index_facebook.html`

#### 4️⃣ **2025/** → **dashboard/**
- **Comunicação**: API REST via `pedidos.php` e sincronização de banco de dados
- **Dados Transferidos**: Pedidos JSON → MySQL → Interface Dashboard
- **Tracking**: Sistema interno de logs e métricas
- **Arquivos Chave**:
  - `2025/pedidos.php` (API)
  - `2025/pedidos/` (Armazenamento JSON)
  - `dashboard/dashboard.php` (Interface)

#### 5️⃣ **dashboard/** → **Trello** (Externo)
- **Comunicação**: Trello API para sincronização de entregas
- **Dados Transferidos**: Status de pedidos e informações de entrega
- **Tracking**: Logs de sincronização
- **Arquivo Chave**: `dashboard/config.php` (configurações Trello)

---

## 🎯 1. COMPONENTES PRINCIPAIS DETALHADOS

### 1.1 Quiz de Rejuvenescimento (`quiz-rejuvenescimento/`)
- **Função**: Captura inicial de leads e qualificação
- **Tecnologia**: PHP/HTML/CSS/JavaScript
- **Estrutura**:
  - `index.php`: Página principal do quiz
  - `resultado.php`: Processamento e redirecionamento
  - `assets/js/quiz.js`: Lógica do quiz e tracking
  - `assets/css/style.css`: Estilos visuais
  - `img/`: Imagens dos problemas de pele
- **Integração**: Meta Pixel para tracking de eventos
- **Saída**: Redireciona para VSL (`b/`) com dados do lead

### 1.2 VSL - Video Sales Letter (`b/`)
- **Função**: Apresentação do produto e aquecimento do lead
- **Tecnologia**: HTML/CSS/JavaScript + ConvertAI Player
- **Estrutura**:
  - `index.html`: Página única com VSL completo
  - Player de vídeo integrado (ConvertAI)
  - Sistema de tracking avançado
- **Integração**: Meta Pixel para tracking de visualizações
- **Saída**: Redireciona para página de autoridade (`bruno-celso/`)

### 1.3 Página de Autoridade (`bruno-celso/`)
- **Função**: Página de credibilidade com Dr. Bruno Celso
- **Tecnologia**: HTML/CSS/JavaScript
- **Estrutura**:
  - `index.html`: Página única com depoimentos e CTAs
  - Botões para WhatsApp e Facebook
  - Seção de comentários/depoimentos
- **Integração**: Meta Pixel e redirecionamento para chat
- **Saída**: Redireciona para sistema de chat (`2025/`)

### 1.4 Sistema de Chat e Pedidos (`2025/`)
- **Função**: Conversão final via chat inteligente
- **Tecnologia**: HTML/CSS/JavaScript + N8N + PHP
- **Estrutura**:
  - `index_whatsapp.html`: Interface de chat WhatsApp
  - `index_facebook.html`: Interface de chat Facebook
  - `pedidos.php`: API de gerenciamento de pedidos
  - `pedidos/`: Pasta com arquivos JSON dos pedidos
  - `docs/`: Documentação do sistema
- **Integração**: N8N Webhook + Meta Pixel + Armazenamento JSON
- **Saída**: Dados para dashboard e Trello

### 1.5 Dashboard Administrativo (`dashboard/`)
- **Função**: Gestão completa de leads, pedidos e entregas
- **Tecnologia**: PHP + MySQL + Bootstrap + JavaScript
- **Estrutura**:
  - `dashboard.php`: Página principal com métricas
  - `pedidos.php`: Gestão de pedidos
  - `entregas.php`: Controle de entregas
  - `pedidos_recuperacao.php`: Sistema de recuperação
  - `metricas.php`: Análises e relatórios
  - `config.php`: Configurações e conexões
  - `ai_chat.js`: Assistente de IA integrado
- **Integração**: MySQL + Trello API + Sistema de logs
- **Saída**: Relatórios e sincronização com Trello

---

## 🔧 2. TECNOLOGIAS E INTEGRAÇÕES

### 2.1 Frontend
- **HTML5/CSS3**: Estrutura e estilos das páginas
- **JavaScript ES6+**: Lógica de interação e tracking
- **Bootstrap 5**: Framework CSS para responsividade
- **jQuery**: Manipulação DOM e AJAX

### 2.2 Backend
- **PHP 8.0+**: Processamento server-side
- **MySQL 8.0**: Banco de dados principal
- **JSON**: Armazenamento de dados de pedidos
- **Apache/Nginx**: Servidor web

### 2.3 Integrações Externas
- **Meta Pixel**: Tracking de conversões Facebook/Instagram
- **Google Tag Manager**: Gerenciamento de tags e eventos
- **N8N**: Automação de workflows e chatbot
- **Trello API**: Sincronização de entregas
- **WhatsApp Business API**: Chat direto
- **ConvertAI**: Player de vídeo otimizado
- **Umami Analytics**: Analytics alternativo

### 2.4 Ferramentas de Desenvolvimento
- **Git**: Controle de versão
- **Composer**: Gerenciador de dependências PHP
- **NPM**: Gerenciador de pacotes JavaScript

---

## 💬 2. SISTEMA DE CHAT DETALHADO

### 2.1 Tecnologia do Chat
**NÃO são chats oficiais do Facebook/Instagram**

O sistema utiliza:
- **Interface**: HTML/CSS/JavaScript customizada
- **Backend**: N8N (plataforma de automação)
- **IA**: Integração com chatbot inteligente
- **Webhook**: `https://webhook.4lifenutrition.site/webhook/suplabase-dados-chat`

### 2.2 Funcionamento do Chat

#### Inicialização (`startChat()`)
```javascript
// Gera ID de sessão único
session_id = generateSessionId();

// Envia evento para Meta Pixel (apenas WhatsApp)
fbq('track', 'ViewContent');

// Inicia pedido no sistema
sendToN8N('0', { session_id, origem: 'whatsapp/facebook' });
```

#### Fluxo de Conversação
1. **Step 0**: Inicialização do chat
2. **Step 1**: Coleta do nome
3. **Step 2**: Coleta do telefone
4. **Step 3**: Coleta do problema relatado
5. **Step 4**: Apresentação do produto
6. **Step 5**: Coleta do endereço
7. **Step 6**: Confirmação do endereço
8. **Step 7**: Finalização do pedido
9. **Step 8**: Pedido concluído

#### Sistema de Fallback
- **Ativação**: Quando usuário fecha popup de compra
- **IA Especializada**: `Fallback_venda`
- **Desconto Automático**: De 97€ para 68€ em perguntas sobre preço
- **Cronômetro**: 10 minutos para decisão

### 2.3 Integração com N8N

#### Endpoints Utilizados
```javascript
const N8N_CONFIG = {
    baseUrl: 'https://webhook.4lifenutrition.site',
    endpoints: {
        process: '/webhook/suplabase-dados-chat'
    }
};
```

#### Dados Enviados para N8N
```json
{
    "Step": "0-8|fallback",
    "session_id": "ws_timestamp_randomid",
    "nome": "Nome do usuário",
    "telefone": "Telefone do usuário",
    "problema": "Problema relatado",
    "endereco": "Endereço completo",
    "duvida": "Dúvida específica",
    "IA": "Fallback_venda|Principal",
    "fonte": "whatsapp|facebook"
}
```

---

## 📊 3. SISTEMA DE PEDIDOS

### 3.1 Estrutura de Arquivos
- **Pasta**: `pedidos/`
- **Formato**: JSON individual por pedido
- **Nomenclatura**: `{origem}_{timestamp}_{randomid}.json`

### 3.2 Estrutura de Dados dos Pedidos

#### Pedidos Versão Padrão
```json
{
    "NOME": "Nome completo",
    "CONTATO": "Telefone",
    "ENDEREÇO": "Endereço completo",
    "PROBLEMA_RELATADO": "Descrição do problema",
    "tratamento": "Tipo de tratamento",
    "origem": "whatsapp|facebook",
    "Rec": true,
    "processado": true,
    "status": "processado|aguardando",
    "timestamp": "timestamp_criacao",
    "criado_em": "data_criacao",
    "atualizado_em": "data_atualizacao",
    "session_id": "id_sessao",
    "recuperado_por": "sistema_recuperacao",
    "processado_recuperacao": true,
    "data_processamento_recuperacao": "data_processamento",
    "id_trello": "id_card_trello",
    "status_trello": "PRONTO PARA ENVIO|EM TRÂNSITO|ENTREGUE"
}
```

#### Pedidos Versão N8N
```json
{
    "session_id": "id_sessao",
    "origem": "whatsapp|facebook",
    "versao": "v2-n8n",
    "timestamp": "timestamp_criacao",
    "criado_em": "data_criacao",
    "Rec": true,
    "etapa_atual": "nome|telefone|problema|endereco|finalizado",
    "marcos_progresso": ["nome", "telefone", "problema"],
    "step": 0-8,
    "ultima_atualizacao": "timestamp",
    "CONTATO": "telefone",
    "PROBLEMA_RELATADO": "problema",
    "NOME": "nome_completo",
    "ENDEREÇO": "endereco_completo"
}
```

### 3.3 API do Sistema de Pedidos (`pedidos.php`)

#### Endpoints Disponíveis
- `iniciar_pedido`: Cria pedido temporário
- `create`: Cria novo pedido (versão N8N)
- `update`: Atualiza pedido existente
- `atualizar_pedido`: Atualiza pedido (versão padrão)
- `finalizar_pedido`: Finaliza pedido
- `remover_pedido`: Remove pedido
- `recuperar_pedido`: Recupera dados do pedido

#### Integração com N8N
```php
function send_to_n8n_webhook($data) {
    $url = 'https://webhook.4lifenutrition.site/webhook/suplabase-dados-chat';
    // Envia dados via POST para N8N
}
```

---

## 🎛️ 4. DASHBOARD ADMINISTRATIVO

### 4.1 Funcionalidades do Dashboard
- **Métricas em Tempo Real**: Novos clientes, pronto para envio, em trânsito, entregues
- **Filtros de Busca**: Por cliente, tracking, destino, status
- **Gestão de Status**: Atualização manual de status de pedidos
- **Sincronização Trello**: Busca automática de dados do Trello

### 4.2 Status de Pedidos Gerenciados
```php
$status_options = [
    'NOVO CLIENTE',
    'LIGAR',
    'PRONTO PARA ENVIO',
    'EM TRÂNSITO',
    'ENTREGUE - PAGO',
    'DEVOLVIDO',
    'MENSAL'
];
```

### 4.3 Sistema de Recuperação do Dashboard

#### Como Funciona
1. **Identificação**: Pedidos com `Rec: true` são marcados para recuperação
2. **Processamento**: Sistema processa pedidos não finalizados
3. **Marcação**: `processado_recuperacao: true` indica processamento
4. **Data**: `data_processamento_recuperacao` registra quando foi processado
5. **Integração**: Dados são enviados para N8N para ações de recuperação

#### Critérios de Recuperação
- Pedidos iniciados mas não finalizados
- Tempo limite excedido sem atividade
- Abandono durante o processo de compra
- Fechamento de popup de finalização

### 4.4 Integração com Trello

#### Configuração (via `config.php`)
```php
define('TRELLO_API_KEY', 'sua_api_key');
define('TRELLO_TOKEN', 'seu_token');
define('TRELLO_BOARD_ID', 'id_do_board');
```

#### Sincronização Automática
- **Criação de Cards**: Pedidos finalizados viram cards no Trello
- **Atualização de Status**: Status do Trello sincroniza com dashboard
- **Mapeamento de Listas**: Cada lista do Trello corresponde a um status

#### Função de Sincronização
```php
function carregarPedidosTrello() {
    // Busca cards do board Trello
    // Extrai informações (nome, endereço, contato, etc.)
    // Identifica pedidos removidos na descrição
    // Retorna array com dados estruturados
}
```

---

## 📈 5. SISTEMA DE TRACKING E ANALYTICS

### 5.1 Meta Pixel Integration

#### Configuração
```javascript
// Meta Pixel ID e Access Token definidos em cada página
const META_ACCESS_TOKEN = 'token_de_acesso';
const META_DATASET_ID = 'id_do_dataset';
```

#### Eventos Rastreados
- **PageView**: Visualização de páginas
- **ViewContent**: Início do chat
- **Lead**: Coleta de informações
- **Purchase**: Finalização de pedido

#### Conversions API
```javascript
// Dados enviados para Meta Conversions API
{
    event_name: 'PageView|ViewContent|Lead|Purchase',
    event_time: timestamp,
    user_data: {
        em: hash_sha256(email),
        ph: hash_sha256(phone),
        fn: hash_sha256(first_name),
        client_ip_address: ip,
        client_user_agent: user_agent,
        fbc: facebook_click_id,
        fbp: facebook_browser_id
    },
    custom_data: dados_personalizados
}
```

### 5.2 Sistema de Logs
- **Arquivo**: `debug_log.txt`
- **Registra**: Todas as requisições e respostas do sistema
- **Formato**: `[timestamp] Ação: dados`

---

## 🔄 6. FLUXOS DE DADOS E INTEGRAÇÕES

### 6.1 Fluxo Principal de Conversão
```
1. Facebook Ads → Quiz (Meta Pixel: PageView)
2. Quiz → VSL (Meta Pixel: ViewContent)
3. VSL → Página de Contato (Meta Pixel: Lead)
4. Chat Iniciado → N8N (Webhook)
5. Dados Coletados → pedidos.php (JSON)
6. Pedido Finalizado → Trello (API)
7. Dashboard → Visualização (MySQL + Trello)
```

### 6.2 Sistema de Recuperação
```
1. Pedido Abandonado → Marcado para Recuperação
2. Sistema Identifica → processado_recuperacao: false
3. N8N Ativado → Ações de Recuperação
4. Processamento → processado_recuperacao: true
5. Data Registrada → data_processamento_recuperacao
```

### 6.3 Sincronização Trello-Dashboard
```
1. Dashboard → Busca Cards Trello (API)
2. Extração → Dados dos Cards
3. Mapeamento → Status Interno
4. Atualização → Banco MySQL
5. Exibição → Interface Dashboard
```

---

## 🛠️ 7. CONFIGURAÇÕES TÉCNICAS

### 7.1 Estrutura de Pastas
```
2025/
├── index_whatsapp.html     # Chat WhatsApp
├── index_facebook.html     # Chat Facebook
├── pedidos.php            # API de Pedidos
├── debug_log.txt          # Logs do Sistema
├── pedidos/               # Arquivos JSON dos Pedidos
└── docs/                  # Documentação
```

### 7.2 Dependências Externas
- **N8N**: Automação e chatbot
- **Meta Pixel**: Tracking e conversões
- **Trello API**: Gestão de entregas
- **MySQL**: Banco de dados dashboard
- **iScroll**: Rolagem suave no chat

### 7.3 Webhooks e APIs
- **N8N Webhook**: `https://webhook.4lifenutrition.site/webhook/suplabase-dados-chat`
- **Meta Conversions API**: `https://graph.facebook.com/v18.0/{dataset_id}/events`
- **Trello API**: `https://api.trello.com/1/`

---

## 🔐 8. SEGURANÇA E VALIDAÇÕES

### 8.1 Validações de Dados
- **Session ID**: Obrigatório para todas as operações
- **JSON**: Validação de formato em todas as entradas
- **Sanitização**: Limpeza de dados antes do armazenamento

### 8.2 Tratamento de Erros
- **Logs Detalhados**: Registro de todos os erros
- **Fallbacks**: Respostas padrão em caso de falha
- **Timeouts**: Configurados para todas as requisições externas

---

## 📊 9. MÉTRICAS E MONITORAMENTO

### 9.1 KPIs Principais
- **Taxa de Conversão**: Quiz → Chat → Pedido
- **Taxa de Recuperação**: Pedidos abandonados recuperados
- **Tempo de Resposta**: N8N e APIs externas
- **Status de Entregas**: Sincronização Trello

### 9.2 Health Checks
- **N8N**: Status do webhook
- **Trello API**: Conectividade
- **Meta Pixel**: Eventos enviados
- **Banco de Dados**: Conexão MySQL

---

## 🚀 10. VERSÕES E ATUALIZAÇÕES

### 10.1 Versões do Sistema
- **v1**: Sistema original com pedidos básicos
- **v2-n8n**: Integração completa com N8N
- **v2-main-n8n**: Versão principal com N8N

### 10.2 Identificação de Versão
```json
{
    "versao": "v2-n8n",  // Indica versão N8N
    "versao": "v1"       // Versão padrão
}
```

---

## 📝 RESUMO EXECUTIVO

Este funil de vendas é um sistema completo e integrado que:

1. **Captura leads** através de quiz e VSL com tracking avançado
2. **Converte através de chat inteligente** com IA e sistema de fallback
3. **Gerencia pedidos** via API robusta com armazenamento JSON
4. **Recupera abandonos** através de sistema automatizado
5. **Sincroniza entregas** com Trello para gestão operacional
6. **Monitora performance** via dashboard administrativo

**Tecnologias Principais**: N8N, Meta Pixel, Trello API, MySQL, JavaScript
**Integrações**: Facebook Ads, WhatsApp, Chatbot IA, Sistema de Recuperação
**Armazenamento**: Arquivos JSON + Banco MySQL
**Monitoramento**: Logs detalhados + Dashboard em tempo real

O sistema é completamente automatizado desde a captura até a entrega, com múltiplos pontos de recuperação e monitoramento contínuo de performance.