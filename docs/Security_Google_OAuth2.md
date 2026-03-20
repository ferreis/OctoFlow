# Configuração do Google OAuth 2.0

Este documento descreve como criar e configurar as credenciais do Google OAuth 2.0 para funcionar com este projeto.

---

## Sumário

1. [Criar projeto no Google Cloud Console](#1-criar-projeto-no-google-cloud-console)
2. [Configurar a tela de consentimento OAuth](#2-configurar-a-tela-de-consentimento-oauth)
3. [Criar as credenciais OAuth 2.0](#3-criar-as-credenciais-oauth-20)
4. [Registrar as origens autorizadas](#4-registrar-as-origens-autorizadas)
5. [Configurar as variáveis de ambiente](#5-configurar-as-variáveis-de-ambiente)
6. [Executar a migration do banco](#6-executar-a-migration-do-banco)
7. [Como o fluxo funciona](#7-como-o-fluxo-funciona)
8. [Restrição por domínio Google Workspace (opcional)](#8-restrição-por-domínio-google-workspace-opcional)
9. [Diferença entre ID do cliente e Chave secreta](#9-diferença-entre-id-do-cliente-e-chave-secreta)
10. [Erros comuns](#10-erros-comuns)

---

## 1. Criar projeto no Google Cloud Console

1. Acesse [https://console.cloud.google.com](https://console.cloud.google.com)
2. No topo da página, clique em **Selecionar um projeto** → **Novo projeto**
3. Dê um nome (ex.: `ModFederation`) e clique em **Criar**
4. Após criado, selecione-o na lista de projetos

---

## 2. Configurar a tela de consentimento OAuth

A tela de consentimento é o que o Google exibe ao usuário antes de permitir o login.

1. No menu lateral, vá em **APIs e serviços** → **Tela de consentimento OAuth**
2. Escolha o tipo de usuário:
   - **Externo** — para qualquer conta Google (uso geral)
   - **Interno** — apenas para contas do seu Google Workspace (use se for restrito à organização)
3. Clique em **Criar** e preencha:
   - **Nome do aplicativo**: `ModFederation` (ou o nome que preferir)
   - **E-mail de suporte do usuário**: seu e-mail
   - **Domínio autorizado**: `localhost` (em desenvolvimento) ou o domínio de produção
   - **E-mail do desenvolvedor**: seu e-mail
4. Clique em **Salvar e continuar**
5. Na etapa **Escopos**, clique em **Salvar e continuar** (os escopos padrão já são suficientes)
6. Na etapa **Usuários de teste**, adicione e-mails que poderão fazer login enquanto o app estiver em modo **Testing**
7. Clique em **Salvar e continuar** e depois **Voltar ao painel**

> **Importante**: enquanto o app estiver em modo *Testing*, apenas os e-mails listados como usuários de teste conseguem fazer login. Para liberar para todos, publique o app clicando em **Publicar aplicativo**.

---

## 3. Criar as credenciais OAuth 2.0

1. No menu lateral, vá em **APIs e serviços** → **Credenciais**
2. Clique em **+ Criar credenciais** → **ID do cliente OAuth**
3. Em **Tipo de aplicativo**, selecione **Aplicativo da Web**
4. Dê um nome (ex.: `ModFederation Web Client`)
5. Veja a próxima seção antes de salvar

---

## 4. Registrar as origens autorizadas

Este é o campo mais importante. O Google recusa qualquer login cuja origem (`protocol://host:porta`) não esteja registrada aqui.

### A origem é o endereço que aparece na barra do navegador:

| Ambiente | URL de acesso | Origem a registrar |
|---|---|---|
| Dev (via Nginx Docker) | `https://localhost:4483` | `https://localhost:4483` |
| Dev (via Nginx HTTP) | `http://localhost:680` | `http://localhost:680` |
| Dev (Vite direto) | `http://localhost:5173` | `http://localhost:5173` |
| Produção | `https://meudominio.com` | `https://meudominio.com` |

### Como registrar:

1. No formulário de criação das credenciais (ou editando credenciais existentes), localize **Origens JavaScript autorizadas**
2. Clique em **+ Adicionar URI** e insira as origens que serão usadas
3. Em **URIs de redirecionamento autorizados**: **deixe vazio** — este projeto usa o fluxo de ID Token (Google Identity Services), que não faz redirect

4. Clique em **Criar**

Após salvar, o Google exibe o **ID do cliente** no formato:
```
XXXXXXXXXX-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.apps.googleusercontent.com
```

> **Propagação**: mudanças nas origens autorizadas podem levar até **5 minutos** para serem efetivas no Google.

---

## 5. Configurar as variáveis de ambiente

### Backend — `www/.env.local`

Crie ou edite o arquivo `www/.env.local` (nunca commite este arquivo no Git):

```dotenv
GOOGLE_OAUTH_CLIENT_ID=SEU_CLIENT_ID.apps.googleusercontent.com
GOOGLE_OAUTH_ALLOWED_HD=
```

| Variável | Descrição |
|---|---|
| `GOOGLE_OAUTH_CLIENT_ID` | ID do cliente OAuth 2.0 obtido no Google Console |
| `GOOGLE_OAUTH_ALLOWED_HD` | *(Opcional)* Restringe login a um domínio Google Workspace (ex.: `empresa.com`). Deixe vazio para aceitar qualquer conta Google. |

### Frontend — `frontend/Host-app/.env`

Crie o arquivo `frontend/Host-app/.env`:

```dotenv
VITE_GOOGLE_CLIENT_ID=SEU_CLIENT_ID.apps.googleusercontent.com
```

> O mesmo Client ID é usado nos dois lugares. A **Chave secreta do cliente** (`GOCSPX-...`) **não é necessária** neste projeto e não deve ser exposta no frontend.

### O botão Google só aparece na UI se `VITE_GOOGLE_CLIENT_ID` estiver preenchido

Se a variável estiver vazia (ou o arquivo `.env` não existir), o botão de login com Google simplesmente não é renderizado.

---

## 6. Executar a migration do banco

A feature de login Google requer a coluna `google_subject` na tabela `app_user`. Execute a migration:

```bash
cd /path/do/projeto/ModFederation

# Com o banco rodando:
docker compose exec backend php bin/console doctrine:migrations:migrate
```

Digite `yes` para confirmar. A migration é reversível (tem `down()` implementado).

---

## 7. Como o fluxo funciona

```
Navegador                        Backend (Symfony)              Google API
    |                                  |                             |
    |-- Clica "Entrar com Google" ---→ |                             |
    |                                  |                             |
    |  [Google renderiza popup]        |                             |
    |← credential (ID Token JWT) ------| (Google Identity Services)  |
    |                                  |                             |
    |-- POST /auth/google              |                             |
    |   { credential: "eyJ..." } ----→ |                             |
    |                                  |-- GET tokeninfo?id_token=→  |
    |                                  |←-- payload JSON -----------  |
    |                                  |                             |
    |                                  | Valida: aud, iss, sub,      |
    |                                  | email_verified, exp, hd     |
    |                                  |                             |
    |                                  | Busca/cria usuário no banco |
    |                                  |                             |
    |←-- 200 { token, user }  ------   |                             |
    |    Set-Cookie: refresh_token     |                             |
```

**O backend nunca vê a senha do Google** — apenas valida o ID Token pela API oficial do Google.

---

## 8. Restrição por domínio Google Workspace (opcional)

Se você quer que apenas usuários de uma organização específica façam login (ex.: apenas `@empresa.com`), preencha:

```dotenv
GOOGLE_OAUTH_ALLOWED_HD=empresa.com
```

O backend verifica o campo `hd` (Hosted Domain) do token Google. Qualquer conta fora desse domínio recebe `401 Unauthorized`.

---

## 9. Diferença entre ID do cliente e Chave secreta

| Item | O que é | Usado neste projeto? |
|---|---|---|
| **ID do cliente** (`...apps.googleusercontent.com`) | Identifica o app publicamente | ✅ Sim — backend e frontend |
| **Chave secreta do cliente** (`GOCSPX-...`) | Usado no fluxo Authorization Code (server-side) | ❌ Não — nosso fluxo usa ID Token |

Este projeto usa o **fluxo de ID Token** (Google Identity Services / One Tap), onde o Google emite um JWT diretamente no navegador e o backend o valida via `tokeninfo`. Não há troca de código de autorização, portanto a chave secreta não é necessária.

---

## 10. Erros comuns

### `Error 400: origin_mismatch`

A origem do navegador não está registrada no Google Console.

**Solução**: vá em Credenciais → edite o Client ID → adicione a origem exata em **Origens JavaScript autorizadas** (incluindo protocolo e porta).

**Dica**: abra o DevTools → Console para ver a origem exata sendo usada.

---

### `Error 400: redirect_uri_mismatch`

Geralmente não ocorre com este fluxo. Se ocorrer, verifique se o tipo de credencial está como **Aplicativo da Web** (não "App para computador").

---

### Login funciona mas o backend retorna `401`

Possíveis causas:
- `GOOGLE_OAUTH_CLIENT_ID` não configurado ou incorreto no `www/.env.local`
- Token expirado (o ID Token tem validade de 1 hora)
- E-mail do usuário não verificado na conta Google

---

### Login retorna `409 Conflict`

Já existe uma conta local com o mesmo e-mail criada via login tradicional (e-mail + senha). Este projeto bloqueia auto-link automático por segurança.

**Solução atual**: use o login tradicional com e-mail e senha. Um endpoint futuro (`POST /auth/link-google`) permitirá vincular a conta Google a uma conta local existente.

---

### Usuários de teste (modo Testing)

Se o app está em modo **Testing** no Google Console e o e-mail do usuário não foi adicionado como usuário de teste, o Google exibe uma tela de erro antes mesmo de gerar o token.

**Solução**: adicione o e-mail em **Tela de consentimento OAuth** → **Usuários de teste**, ou publique o app.
