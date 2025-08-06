# 📋 Guia do Desenvolvedor - Sistema pedidos.php

## 🎯 O que é o pedidos.php?

O `pedidos.php` é uma **API REST simples** que gerencia o ciclo de vida dos pedidos no funil de vendas. Pense nele como o "cérebro" que:

- ✅ Recebe dados dos chatbots (WhatsApp/Facebook)
- ✅ Processa atualizações vindas do N8N
- ✅ Armazena tudo em arquivos JSON
- ✅ Sincroniza com sistemas externos (Supabase)

## 🏗️ Arquitetura Simplificada

```
👤 Cliente no Chat → 🤖 N8N → 📝 pedidos.php → 💾 Arquivo JSON
                                     ↓
                              🔄 Webhook Externo
```

### 📂 Onde estão os arquivos?
```
2025/
├── pedidos.php          ← O endpoint principal
├── pedidos/             ← Pasta com todos os pedidos
│   ├── {session_id}.json ← Cada conversa = 1 arquivo
│   ├── index.json       ← Lista de pedidos finalizados
│   └── pedidos.log      ← Logs de debug
├── index_whatsapp.html  ← Frontend WhatsApp
└── index_facebook.html  ← Frontend Facebook
```

## 🔧 Como Funciona na Prática?

### 🚀 Início Rápido para Devs

**URL do Endpoint:** `https://4lifenutrition.site/2025/pedidos.php`  
**Método:** `POST`  
**Content-Type:** `application/json`

### 📊 Estrutura de um Pedido (JSON)

```json
{
  "session_id": "ws_1748557190768_go4hvlnpx",
  "step": 7,
  "nome": "Maria Silva",
  "telefone": "+351912345678",
  "problema": "Dores nas articulações",
  "endereco": "Rua das Flores, 123, Lisboa",
  "pacote_escolhido": "3 meses",
  "valor_final": 149.97,
  "Rec": false,
  "finalizado": true,
  "timestamp": "2025-01-15 14:30:22",
  "fonte": "whatsapp"
}
```

### 🎮 Ações Disponíveis (API Endpoints)

O `pedidos.php` funciona como um **switch case** baseado na propriedade `acao`:

| Ação | O que faz | Quando usar |
|------|-----------|-------------|
| `iniciar_pedido` | 🆕 Cria pedido vazio | Início da conversa |
| `create` | 📝 Cria pedido completo | Dados completos disponíveis |
| `update` | ✏️ Atualiza pedido existente | Durante a conversa |
| `retrieve` | 📖 Busca dados do pedido | N8N precisa verificar estado |
| `atualizar_pedido` | 🔄 Atualização do N8N | Progressão automática |
| `finalizar_pedido` | ✅ Marca como finalizado | Cliente confirma compra |
| `remover_pedido` | 🗑️ Remove do sistema | Limpeza/cancelamento |

### 📈 Sistema de Steps (Progresso da Conversa)

```
Step 0: 🏁 Início da conversa
Step 1: 👤 Coleta nome
Step 2: 📞 Coleta telefone  
Step 3: 🩺 Identifica problema
Step 4: 🏠 Coleta endereço
Step 5: 💊 Apresenta soluções
Step 6: 📦 Cliente escolhe pacote
Step 7: 🤖 Suporte pós-venda (IA)
Step 8: ✅ Finalização (Rec=false)
```

## 💻 Exemplos Práticos de Uso

### 🆕 Criar um novo pedido
```javascript
// Requisição para iniciar pedido
const response = await fetch('https://4lifenutrition.site/2025/pedidos.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    acao: 'iniciar_pedido',
    session_id: 'ws_1748557190768_go4hvlnpx'
  })
});
```

### ✏️ Atualizar pedido existente
```javascript
// Requisição para atualizar dados
const response = await fetch('https://4lifenutrition.site/2025/pedidos.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    acao: 'update',
    data: {
      session_id: 'ws_1748557190768_go4hvlnpx',
      step: 3,
      nome: 'Maria Silva',
      telefone: '+351912345678',
      problema: 'Dores nas articulações'
    }
  })
});
```

### 📖 Buscar dados de um pedido
```javascript
// Requisição para recuperar dados
const response = await fetch('https://4lifenutrition.site/2025/pedidos.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    acao: 'retrieve',
    session_id: 'ws_1748557190768_go4hvlnpx'
  })
});
```

## 🔄 Fluxo Completo de uma Conversa

### 1️⃣ Cliente inicia conversa
```
👤 Cliente acessa WhatsApp → 🌐 Frontend gera session_id → 🤖 N8N recebe step=0
```

### 2️⃣ Coleta de dados (Steps 1-4)
```
🤖 N8N pergunta nome → 👤 Cliente responde → 📝 pedidos.php salva (step=1)
🤖 N8N pergunta telefone → 👤 Cliente responde → 📝 pedidos.php salva (step=2)
🤖 N8N pergunta problema → 👤 Cliente responde → 📝 pedidos.php salva (step=3)
🤖 N8N pergunta endereço → 👤 Cliente responde → 📝 pedidos.php salva (step=4)
```

### 3️⃣ Apresentação e escolha (Steps 5-6)
```
🤖 N8N mostra soluções → 👤 Cliente escolhe pacote → 📝 pedidos.php salva (step=6)
```

### 4️⃣ Finalização (Steps 7-8)
```
🤖 IA oferece suporte → 👤 Cliente confirma → 📝 pedidos.php marca Rec=false (step=8)
```

## 🔧 Configurações Técnicas

### 🌐 URLs dos Webhooks
```php
// N8N Webhook (sincronização)
$n8n_webhook_url = 'https://webhook.4lifenutrition.site/webhook/suplabase-dados-chat';

// Supabase (backup)
$supabase_webhook_url = 'https://hjciadghbgnijcgwsuyh.supabase.co/functions/v1/sync-pedidos';
```

### 📁 Estrutura de Arquivos
```
pedidos/
├── ws_1748557190768_go4hvlnpx.json  ← Pedido individual
├── fb_1747279264785_wzrrmfvr.json   ← Pedido do Facebook
├── index.json                       ← Lista de finalizados
└── pedidos.log                      ← Logs de debug
```

### 🔒 Headers CORS
```php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
```

## 🔗 Integração com N8N

### 📤 Como o N8N envia dados
```javascript
// N8N faz requisições assim:
const payload = {
  Step: "3",
  session_id: "ws_1748557190768_go4hvlnpx",
  fonte: "whatsapp",
  nome: "Maria Silva",
  telefone: "+351912345678",
  problema: "Dores nas articulações"
};

fetch('https://4lifenutrition.site/2025/pedidos.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ acao: 'atualizar_pedido', ...payload })
});
```

### 🔄 Lógica de Finalização Automática
```php
// O pedido é automaticamente finalizado quando:
$sendo_finalizado = (
  $request_data['Rec'] === false ||  // Marcado como não recuperável
  $request_data['step'] === 8        // Chegou ao step final
);
```

## 📊 Sistema de Logs e Debug

### 📝 Tipos de Log
```
[2025-01-15 14:30:22] Pedido temporário iniciado: ws_1748557190768_go4hvlnpx.json
[2025-01-15 14:32:15] Pedido atualizado: ws_1748557190768_go4hvlnpx.json (step: 3)
[2025-01-15 14:35:45] Pedido está sendo finalizado: ws_1748557190768_go4hvlnpx.json
[2025-01-15 14:35:46] Index.json atualizado com o pedido finalizado
[2025-01-15 14:40:12] ERRO: Falha ao ler arquivo: /path/to/missing_file.json
```

### 🔍 Como debugar problemas
1. **Verificar logs**: `pedidos/pedidos.log`
2. **Verificar arquivo individual**: `pedidos/{session_id}.json`
3. **Verificar index geral**: `pedidos/index.json`
4. **Testar endpoint**: Usar Postman/curl

## 🚨 Troubleshooting Comum

### ❌ Problema: Pedido não está sendo salvo
**Possíveis causas:**
- Session_id não foi enviado
- Pasta `pedidos/` não tem permissão de escrita
- JSON malformado na requisição

**Como verificar:**
```bash
# Verificar permissões da pasta
ls -la pedidos/

# Verificar logs de erro
tail -f pedidos/pedidos.log

# Testar requisição manual
curl -X POST https://4lifenutrition.site/2025/pedidos.php \
  -H "Content-Type: application/json" \
  -d '{"acao":"retrieve","session_id":"test_123"}'
```

### ❌ Problema: Pedido não aparece no dashboard
**Possíveis causas:**
- Pedido não foi finalizado (`Rec` ainda é `true`)
- Não foi adicionado ao `index.json`
- Dashboard está lendo arquivo errado

**Como verificar:**
```bash
# Verificar se pedido existe
cat pedidos/ws_1748557190768_go4hvlnpx.json

# Verificar se está no index
grep "ws_1748557190768_go4hvlnpx" pedidos/index.json

# Verificar status de finalização
cat pedidos/ws_1748557190768_go4hvlnpx.json | grep -E "(Rec|finalizado)"
```

### ❌ Problema: N8N não consegue atualizar pedido
**Possíveis causas:**
- Session_id incorreto
- Endpoint do N8N mudou
- Timeout na requisição

**Como verificar:**
```javascript
// Testar se pedido existe
const response = await fetch('https://4lifenutrition.site/2025/pedidos.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    acao: 'retrieve',
    session_id: 'ws_1748557190768_go4hvlnpx'
  })
});
console.log(await response.json());
```

## 🎯 Resumo para Devs

### ✅ O que você precisa saber:
1. **É uma API REST simples** que aceita POST com JSON
2. **Funciona com switch case** baseado na propriedade `acao`
3. **Cada conversa = 1 arquivo JSON** identificado pelo `session_id`
4. **Step controla o progresso** da conversa (0-8)
5. **Rec=false significa finalizado** e vai para o dashboard
6. **Logs estão em** `pedidos/pedidos.log`

### 🔧 Para modificar/debugar:
1. **Sempre verificar logs primeiro**
2. **Session_id é a chave primária**
3. **Testar com curl/Postman** antes de integrar
4. **Backup da pasta pedidos** antes de mudanças grandes
5. **Verificar permissões de arquivo** se der erro de escrita

### 📞 Contatos Técnicos
- **Servidor**: 4lifenutrition.site
- **N8N**: n8n.4lifenutrition.site
- **Backup**: Supabase (hjciadghbgnijcgwsuyh.supabase.co)

---

**💡 Dica:** Este documento é um guia prático. Para detalhes técnicos específicos, consulte o código fonte do `pedidos.php` diretamente.