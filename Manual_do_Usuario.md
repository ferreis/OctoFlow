# 📖 Manual do Usuário - OctoFlow

Bem-vindo ao sistema de Gestão de Tarefas (Task CRUD) do OctoFlow. Este manual foi feito para orientar como acessar e utilizar os diversos recursos do sistema.

## 1. Acesso ao Sistema

Para acessar a interface principal do sistema, abra seu navegador de preferência e acesse o seguinte endereço:

**🔗 URL de Acesso:** [https://localhost:4481/OctoFlow](https://localhost:4481/OctoFlow)

*(Se você estiver utilizando a versão de desenvolvimento local sem o proxy Nginx, o acesso também pode ser feito através de `http://localhost:3000`)*

## 2. Autenticação (Login)

Como o sistema lida com dados privados e é restrito a usuários autorizados, você precisa fazer login para acessar o painel de tarefas de forma segura.

Você possui duas opções na tela de login:
- **Login Local:** Insira seu e-mail e senha cadastrados no sistema. 
- **Entrar com Google (OAuth):** Você pode utilizar sua conta do Google para entrar de forma rápida, dispensando o cadastro de uma nova senha.

> 💡 **Credenciais de Teste:**
> Caso o ambiente seja de testes, você pode usar as credenciais padrão do administrador:
> - **Email:** `admin@example.com`
> - **Senha:** `Senha@123`

## 3. Utilizando o Painel de Tarefas

Ao realizar o login com sucesso, você será redirecionado para o **Painel de Tarefas**. No menu de ações principal, você terá acesso às seguintes funcionalidades:

### 📋 Listar Tarefas
- **O que faz:** Exibe todas as tarefas que já foram criadas e estão associadas à sua conta.
- **Como usar:** Clique no botão **"📋 Listar"**. A tela será atualizada mostrando a lista de tarefas. O sistema carrega as informações dinamicamente, evitando que a página toda precise recarregar, proporcionando uma transição rápida e suave.

### ➕ Criar Tarefa
- **O que faz:** Abre um formulário para você adicionar uma nova tarefa ao sistema.
- **Como usar:** Clique no botão **"➕ Criar"**. Preencha as informações necessárias, como o **Título** e a **Descrição** da tarefa, e após isso, clique em "Salvar" ou confirmar. A nova tarefa passará a ser exibida na sua lista.

### 👁️ Visualizar Tarefa
- **O que faz:** Permite que você veja os detalhes completos de uma tarefa específica, caso a descrição ou os detalhes sejam longos demais para a visualização na lista simples.
- **Como usar:** A partir da lista de tarefas, selecione a tarefa desejada e clique em visualizar (ou clique na opção "👁️ Visualizar"). Você terá acesso a uma tela com todos os detalhes adicionados a ela.

### ✏️ Editar Tarefa
- **O que faz:** Permite alterar informações (como título, descrição ou status) de uma tarefa já existente, mantendo-a atualizada do seu progresso.
- **Como usar:** Acesse a funcionalidade de edição a partir de uma tarefa na lista, faça as alterações necessárias no formulário que será exibido, e depois salve.

### 🗑️ Excluir Tarefa
- **O que faz:** Remove a tarefa definitivamente.
- **Como usar:** Cada tarefa na sua lista geralmente possui a opção de ser removida permanentemente do sistema (ícone ou botão de exclusão), caso ela não seja mais útil.

## 4. Segurança e Sessão Ativa

- **Renovação Automática (Sem interrupções):** O sistema utiliza uma arquitetura moderna e gerencia sua sessão de forma inteligente e altamente segura. Mesmo se você ficar inativo por um período e parte da sua sessão expirar (após 15 minutos de inatividade), a renovação será feita **lentamente de forma imperceptível**, sem que o sistema peça sua senha novamente por até 14 dias (prazo para a renovação automática parar de funcionar).
- **Sair do Sistema (Logout):** Para garantir sua segurança, principalmente em computadores compartilhados ou de terceiros, quando finalizar o uso, certifique-se de clicar no botão para realizar o **Logout ("Sair")**. Todo o seu acesso em andamento será revogado no banco de dados na mesma hora, redirecionando você para a tela de login inicial novamente e bloqueando novas tentativas de uso até que suas credenciais sejam revalidadas.

---

*Em caso de problemas para acessar o sistema (como página indisponível), garanta primeiramente que o Docker e os containers do seu servidor estão ativos executando o comando para subir o ambiente (como abordado no manual técnico do administrador).*
