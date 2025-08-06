# 📊 Métricas Meta Pixel - Comparação WhatsApp vs Facebook

## 🎯 Objetivo
Documentar as diferenças de tracking entre `index_whatsapp.html` e `index_facebook.html` e implementar o mesmo nível de "agressividade" de tracking em ambos os arquivos.

## 📋 Análise Atual do Tracking

### 🟢 WhatsApp (Tracking Agressivo) ✅

#### 1. **PageView** - Carregamento Inicial
```javascript
fbq('track', 'PageView');
```

#### 2. **ViewContent** (Produto) - Carregamento da Página
```javascript
sendMetaConversionEvent('ViewContent', {}, {
    content_type: 'product',
    content_name: 'RENOVAGOLD - Página de Produto',
    currency: 'EUR',
    value: 74.98
});
```

#### 3. **InitiateCheckout** - Quando lead entra no chat (Métrica de Conversão Precisa)
```javascript
// DISPARADO: Na função startChat() quando usuário clica para iniciar o chat
await sendMetaConversionEvent('InitiateCheckout', {
    country: 'Portugal'
}, {
    content_type: 'chat_interaction',
    content_name: 'WhatsApp Lead Entrou no Chat',
    currency: 'EUR',
    value: 74.98,
    source: 'whatsapp_chat'
});
```
**Localização**: `index_whatsapp.html` - função `startChat()` (linha ~3876)
**Objetivo**: Medir conversão precisa do funil - quantos leads iniciaram o chat vs quantos converteram

#### 4. **Contact** - Dados Pessoais Coletados
```javascript
sendMetaConversionEvent('Contact', {
    firstName: nome,
    phone: telefone
}, {
    content_type: 'user_info',
    content_name: 'Dados Pessoais Coletados',
    currency: 'EUR'
});
```

#### 5. **AddPaymentInfo** - Informações de Pagamento
```javascript
sendMetaConversionEvent('AddPaymentInfo', {
    firstName: nome,
    phone: telefone
}, {
    content_type: 'delivery_info',
    content_name: 'Dados de Entrega Confirmados',
    currency: 'EUR',
    value: 74.98
});
```

#### 6. **Purchase** - Compra Finalizada
```javascript
sendMetaConversionEvent('Purchase', {
    firstName: nome,
    phone: telefone
}, {
    content_type: 'product',
    content_name: desconto ? 'RENOVAGOLD - Compra com Desconto' : 'RENOVAGOLD - Compra',
    currency: 'EUR',
    value: valorFinal,
    num_items: 1
});
```

---

### 🔴 Facebook (Tracking Conservador - ANTES) ❌

#### 1. **PageView** - Carregamento Inicial
```javascript
fbq('track', 'PageView');
```

#### 2. **PageView** - Segundo Evento (2 segundos depois)
```javascript
setTimeout(() => {
    fbq('track', 'PageView');
}, 2000);
```

#### 3. **AddPaymentInfo** - Informações de Pagamento
```javascript
sendMetaConversionEvent('AddPaymentInfo', {
    phone: telefone
}, {
    content_type: 'delivery_info',
    content_name: 'Dados de Entrega Confirmados',
    currency: 'EUR',
    value: 74.98
});
```

#### 4. **Purchase** - Compra Finalizada
```javascript
sendMetaConversionEvent('Purchase', {
    phone: telefone
}, {
    content_type: 'product',
    content_name: desconto ? 'RENOVAGOLD - Compra com Desconto' : 'RENOVAGOLD - Compra',
    currency: 'EUR',
    value: valorFinal,
    num_items: 1
});
```

---

### 🟢 Facebook (Tracking Agressivo - DEPOIS) ✅

#### 1. **PageView** - Carregamento Inicial
```javascript
fbq('track', 'PageView');
```

#### 2. **ViewContent** (Produto) - 1 segundo após carregamento
```javascript
sendMetaConversionEvent('ViewContent', {}, {
    content_type: 'product',
    content_name: 'RENOVAGOLD - Página de Produto',
    currency: 'EUR',
    value: 74.98
});
```

#### 3. **InitiateCheckout** - Quando lead entra no chat (Métrica de Conversão Precisa)
```javascript
// DISPARADO: Na função startChat() quando usuário clica para iniciar o chat
sendMetaConversionEvent('InitiateCheckout', {
    country: 'Portugal'
}, {
    content_type: 'chat_interaction',
    content_name: 'Facebook Lead Entrou no Chat',
    currency: 'EUR',
    value: 74.98,
    source: 'facebook_chat'
});
```
**Localização**: `index_facebook.html` - função `startChat()` (linha ~3870)
**Objetivo**: Medir conversão precisa do funil - quantos leads iniciaram o chat vs quantos converteram

#### 4. **Contact** - Dados Pessoais Coletados
```javascript
sendMetaConversionEvent('Contact', {
    firstName: nome,
    phone: telefone
}, {
    content_type: 'user_info',
    content_name: 'Dados Pessoais Coletados',
    currency: 'EUR'
});
```

#### 5. **AddPaymentInfo** - Informações de Pagamento
```javascript
sendMetaConversionEvent('AddPaymentInfo', {
    firstName: nome,
    phone: telefone
}, {
    content_type: 'delivery_info',
    content_name: 'Dados de Entrega Confirmados',
    currency: 'EUR',
    value: 74.98
});
```

#### 6. **Purchase** - Compra Finalizada
```javascript
sendMetaConversionEvent('Purchase', {
    firstName: nome,
    phone: telefone
}, {
    content_type: 'product',
    content_name: desconto ? 'RENOVAGOLD - Compra com Desconto' : 'RENOVAGOLD - Compra',
    currency: 'EUR',
    value: valorFinal,
    num_items: 1
});
```

## 🎯 Mudanças Implementadas no Facebook

### 1. **Adicionar ViewContent (Produto) no Carregamento**
**Localização**: Após o PageView inicial (linha ~280)
```javascript
// NOVO: ViewContent do produto no carregamento
setTimeout(() => {
    sendMetaConversionEvent('ViewContent', {}, {
        content_type: 'product',
        content_name: 'RENOVAGOLD - Página de Produto',
        currency: 'EUR',
        value: 74.98
    });
}, 1000);
```

### 2. **Substituir PageView por InitiateCheckout**
**Localização**: Segundo PageView (linha ~290)
```javascript
// MUDANÇA: De PageView para InitiateCheckout quando chat está pronto
setTimeout(() => {
    sendMetaConversionEvent('InitiateCheckout', {
        country: 'Portugal'
    }, {
        content_type: 'chat_interaction',
        content_name: 'Facebook Chat Pronto',
        currency: 'EUR',
        value: 74.98
    });
}, 2000);
```

### 3. **Adicionar Contact na Coleta de Dados**
**Localização**: Função `processN8NResponse` quando dados são confirmados
```javascript
// NOVO: Contact quando nome e telefone são confirmados
if (chatData.currentStep === 2 && chatData.tempUserPhone) {
    chatData.userPhone = chatData.tempUserPhone;
    chatData.tempUserPhone = null;
    
    // Disparar evento Contact quando nome e telefone são confirmados
    if (chatData.userName && chatData.userPhone) {
        sendMetaConversionEvent('Contact', {
            firstName: chatData.userName,
            phone: chatData.userPhone
        }, {
            content_type: 'user_info',
            content_name: 'Dados Pessoais Coletados',
            currency: 'EUR'
        });
    }
}
```

## 📊 Comparação Final (Após Implementação)

| Evento | WhatsApp | Facebook (Atual) | Facebook (Novo) |
|--------|----------|------------------|-----------------|
| PageView (Carregamento) | ✅ | ✅ | ✅ |
| ViewContent (Produto) | ✅ | ❌ | ✅ |
| InitiateCheckout (Chat) | ❌ | ❌ | ✅ |
| ViewContent (Chat) | ✅ | ❌ | ❌ |
| Contact (Dados) | ✅ | ❌ | ✅ |
| AddPaymentInfo | ✅ | ✅ | ✅ |
| Purchase | ✅ | ✅ | ✅ |

## 🔧 Configuração Meta Pixel

### Tokens e IDs Utilizados
```javascript
const META_ACCESS_TOKEN = 'EAAWWBU3BJdwBO5ov7wVRT07byZBJPffTqPkRGTJ9F8Y0yoPeaFIbL2NvM8ZA4B0fwIPHNoIZCtZCniAgcGlv1Of9yZBShZCWmKWkb6TUyoqpTagl7owlwZCufRKYxe9x45uHUfowiF9V0HvIe6ZAGgvShZCLx6h5awqrHkKk6sYrd6TiMX9Bp4NNWKCuZBLNmm96IzCQZDZD';
const META_DATASET_ID = '1332485478002907';
```

### Função sendMetaConversionEvent
A função `sendMetaConversionEvent` é responsável por:
1. Enviar eventos via Meta Pixel (fbq)
2. Enviar eventos via Meta Conversions API
3. Gerar hashes SHA-256 para dados pessoais
4. Incluir cookies Facebook (_fbp, _fbc)
5. Capturar IP do cliente
6. Validar qualidade dos dados

## 🚀 Implementação

### Etapas de Implementação:
1. ✅ Extrair dados do WhatsApp
2. ✅ Documentar diferenças
3. 🔄 Implementar mudanças no Facebook
4. 🔄 Testar eventos
5. 🔄 Validar tracking

### Localização dos Arquivos:
- **WhatsApp**: `c:\Users\Vinicius\Desktop\Estrutura\2025\index_whatsapp.html`
- **Facebook**: `c:\Users\Vinicius\Desktop\Estrutura\2025\index_facebook.html`
- **Documentação**: `c:\Users\Vinicius\Desktop\Estrutura\2025\docs\`

## 📈 Benefícios Esperados

### Tracking Mais Agressivo no Facebook:
1. **Melhor Matching**: Mais pontos de contato para identificar usuários
2. **Otimização de Campanhas**: Mais dados para algoritmo do Facebook
3. **Funil Completo**: Tracking de todas as etapas do funil
4. **Paridade**: Mesmo nível de tracking entre WhatsApp e Facebook
5. **Conversões Mais Precisas**: Melhor atribuição de conversões

### Eventos Adicionais:
- **ViewContent (Produto)**: Otimização para audiências de produto
- **InitiateCheckout**: Otimização para intenção de compra
- **Contact**: Otimização para coleta de leads

## 🔍 Monitoramento

### Ferramentas de Validação:
1. **Facebook Events Manager**: Verificar eventos em tempo real
2. **Meta Pixel Helper**: Extensão Chrome para debug
3. **Console do Navegador**: Logs da função sendMetaConversionEvent
4. **Facebook Analytics**: Análise de performance dos eventos

### Métricas a Acompanhar:
- Taxa de eventos disparados
- Qualidade do matching (Event Match Quality)
- Performance das campanhas
- Custo por conversão
- ROAS (Return on Ad Spend)