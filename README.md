# DW Payment Fallback v0.0.1

![Plugin Version](https://img.shields.io/badge/version-0.0.1-blue.svg)
![WooCommerce Compatible](https://img.shields.io/badge/WooCommerce-8.x%20%2F%209.x-96588a.svg)
![WordPress Compatible](https://img.shields.io/badge/WordPress-6.x-21759b.svg)
![PHP](https://img.shields.io/badge/PHP-7.4+-purple.svg)
![HPOS](https://img.shields.io/badge/HPOS-Compat%C3%ADvel-green.svg)

Plugin de fallback de pagamento para WooCommerce: exibe o gateway principal no checkout e, quando há falha, oferece retry com gateway alternativo sem criar pedido duplicado.

## 🚀 Funcionalidades Principais

### 💳 Fallback entre gateways
- **Gateways principais**: métodos usados na tentativa inicial (ex.: Pagar.me/Asaas).
- **Gateways de fallback**: métodos usados no retry (ex.: Mercado Pago).
- **Seleção múltipla**: suporte a mais de um gateway em cada grupo.

### 🔁 Retry no mesmo pedido (sem duplicidade)
- Mantém o fluxo no mesmo pedido quando possível.
- Marca pedido com metadados de fallback para rastreio.
- Permite retry no `order-pay` ou no checkout em modo fallback (quando necessário).

### 🧩 Compatibilidade com checkouts customizados
- Suporte para checkout clássico WooCommerce.
- Compatibilidade com **FunnelKit** (incluindo modo fallback no checkout).
- Endpoint AJAX para montar URL de retry com segurança.

### 🔐 Segurança
- Nonce para ativação de modo fallback em checkout.
- Nonce para requisições AJAX de retry.
- Validação de acesso ao pedido (owner logado / `order_key` / sessão `order_awaiting_payment`).

### ⚡ HPOS
- Declaração de compatibilidade com **High-Performance Order Storage** (`custom_order_tables`).

## 📋 Requisitos

- **WordPress**: 5.8+
- **WooCommerce**: 6.0+ (testado em 8.x/9.x)
- **PHP**: 7.4+
- Pelo menos 2 gateways ativos no WooCommerce

## 🔧 Instalação

1. Copie a pasta do plugin para:
   ```
   wp-content/plugins/dw-payment-fallback/
   ```
2. Ative o plugin no painel WordPress.
3. Acesse:
   - **WooCommerce → Configurações → Fallback de pagamento**
4. Configure:
   - **Gateways principais**
   - **Gateways de fallback**
   - opções auxiliares (status, e-mail, fallback em "Em espera").

## ⚙️ Configuração

### Configurações essenciais
- **Gateways principais**: métodos mostrados na tentativa inicial.
- **Gateways de fallback**: métodos ocultos na tentativa inicial e mostrados no retry.

### Comportamento de pedido
- **Colocar pedido em "Pendente"**: recomendado para garantir link de pagamento.
- **Oferecer fallback em "Em espera"**: útil para gateways que não usam status "Falhou".

### Comunicação
- **Enviar e-mail com link**: envia URL de retry para o cliente.

## 🎯 Como funciona (fluxo)

1. Cliente tenta pagar com gateway principal.
2. Pagamento falha (ex.: recusa, erro de processamento).
3. Plugin detecta a falha e prepara fallback.
4. Cliente recebe botão/link **"Tentar com outro meio"**.
5. Retry abre o fluxo alternativo (order-pay ou checkout fallback), com gateway alternativo disponível.

## 🔌 Compatibilidade

### Checkout
- ✅ WooCommerce Checkout (clássico)
- ✅ FunnelKit Checkout (modo fallback no checkout)

### Infra WooCommerce
- ✅ HPOS (`custom_order_tables`)
- ✅ Action Scheduler / sessões WooCommerce (fluxo padrão)

### Gateways
- ✅ Compatível com gateways que seguem fluxo padrão WooCommerce
- ⚠️ Alguns gateways custom podem exigir ajuste fino de front-end/script

## 🛠️ Estrutura do Plugin

```
dw-payment-fallback/
├── assets/
│   ├── css/
│   │   └── dw-payment-fallback-frontend.css
│   └── js/
│       └── dw-payment-fallback-frontend.js
├── includes/
│   ├── class-dw-payment-fallback.php
│   ├── class-dw-payment-fallback-admin.php
│   ├── class-dw-payment-fallback-settings.php
│   └── class-dw-payment-fallback-security.php
├── dw-payment-fallback.php
└── README.md
```

## 🔒 Boas práticas de produção

- Testar em staging com:
  - usuário logado e visitante;
  - falha em gateway principal;
  - retry por botão e por área "Minha conta";
  - tema/checkout em uso real.
- Confirmar envio de e-mail de fallback.
- Verificar se cache/minificação não remove o JS/CSS do plugin.

## 📊 Changelog

### v0.0.1
- Estrutura inicial do plugin
- Fallback entre gateways com seleção múltipla
- Compatibilidade com FunnelKit
- Hardening de segurança (nonce + validação de acesso a pedido)
- Compatibilidade HPOS
- Assets frontend externalizados (JS/CSS)

## 🐛 Suporte

- Repositório: https://github.com/agenciadw/dw-payment-fallback
- Autor: **David William da Costa**

---

**⭐ Se este plugin te ajudar, considere favoritar o repositório no GitHub.**
