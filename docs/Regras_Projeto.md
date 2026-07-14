# Regras do Projeto

## 1. Identificação

| Campo | Preenchimento |
|---|---|
| Nome do projeto | OctoFlow |
| Sigla | OF |
| Versão | 1.0 |
| Responsável | Rafael Fernando dos Reis Mecabô |
| Data de criação | 10/03/2026 |
| Última atualização | 14/07/2026 |

---

## 2. Objetivo

O OctoFlow é uma aplicação web para centralizar a organização pessoal de tarefas, finanças, perfil de conta e atividades vinculadas ao GitHub. O sistema deve oferecer uma experiência segura, com autenticação por credenciais locais ou Google, e manter os dados protegidos por permissões no backend.

---

## 3. Escopo

### 3.1 Incluído

| Código | Item incluído |
|---|---|
| ESC-IN-001 | Autenticar usuários com credenciais locais e Google OAuth2. |
| ESC-IN-002 | Manter sessão com access token, refresh token rotativo e validação de contexto. |
| ESC-IN-003 | Criar, consultar, editar, concluir e excluir tarefas. |
| ESC-IN-004 | Gerenciar lançamentos, contas, bancos, recorrências, parcelas, investimentos e relatórios financeiros. |
| ESC-IN-005 | Exibir e operar recursos de workspace e issues do GitHub conforme as permissões disponíveis. |
| ESC-IN-006 | Permitir a manutenção de dados do perfil, e-mails e senha da conta. |
| ESC-IN-007 | Disponibilizar componentes compartilhados pelo remote via Module Federation. |

### 3.2 Não incluído

| Código | Item não incluído | Motivo |
|---|---|---|
| ESC-OUT-001 | Aplicativo móvel nativo | A versão atual é uma aplicação web responsiva. |
| ESC-OUT-002 | Processamento de pagamentos, transferências bancárias ou corretoras reais | O módulo financeiro controla informações; não executa operações financeiras externas. |
| ESC-OUT-003 | Autorização baseada apenas no frontend | A validação final de acesso deve ocorrer no backend. |
| ESC-OUT-004 | Gestão de sessão e autenticação pelo remote federado | Essas responsabilidades pertencem ao host. |

---

## 4. Termos e Definições

| Termo | Definição |
|---|---|
| Host | Aplicação principal que controla autenticação, navegação, sessão e carregamento dos componentes remotos. |
| Remote | Aplicação de componentes compartilhados carregada pelo host por Module Federation. |
| Access token | Token de curta duração usado nas requisições autenticadas e mantido em memória no frontend. |
| Refresh token | Token armazenado em cookie `HttpOnly`, usado para renovar a sessão e rotacionado a cada uso. |
| Rotação de token | Emissão de um novo refresh token e revogação do token anterior a cada renovação de sessão. |
| Lançamento financeiro | Registro de receita, despesa, transferência, parcela ou evento financeiro relacionado. |
| Recorrência | Regra que gera ou representa lançamentos repetidos em uma periodicidade definida. |

---

## 5. Regras do Projeto

| Código | Tipo | Título | Descrição | Condição | Resultado esperado | Exceção | Prioridade | Status |
|---|---|---|---|---|---|---|---|---|
| `RN-001` | Regra de Negócio | Acesso autenticado | Somente usuários autenticados podem acessar dados pessoais e áreas protegidas. | Ao acessar uma rota ou recurso protegido. | O sistema deve permitir o acesso apenas à sessão válida do usuário. | Rotas públicas de autenticação. | Alta | Implementada |
| `RN-002` | Regra de Negócio | Propriedade dos dados | O usuário só pode visualizar e alterar os próprios dados, tarefas e informações financeiras, salvo permissão explícita. | Ao consultar ou alterar um recurso. | O backend deve validar a permissão antes da operação. | Recursos públicos definidos pelo sistema. | Alta | Implementada |
| `RN-003` | Regra de Negócio | Integridade financeira | Todo lançamento financeiro deve respeitar os dados e as categorias exigidas pelo seu tipo. | Ao criar ou editar um lançamento. | O sistema deve aceitar apenas registros válidos e consistentes para cálculo e relatório. | Tipos que não exigirem categoria, conforme regra específica do domínio. | Alta | Implementada |
| `RN-004` | Regra de Negócio | Tarefas independentes | Cada tarefa pertence ao usuário que a criou e pode ter seu estado alterado sem afetar tarefas de outros usuários. | Ao criar, editar, concluir ou excluir uma tarefa. | A alteração deve ser aplicada somente à tarefa autorizada. | N/A | Alta | Implementada |
| `RN-005` | Regra de Negócio | Integração com GitHub | Recursos do GitHub só podem ser usados com credenciais e permissões válidas para a integração. | Ao carregar workspace, projetos ou issues do GitHub. | O sistema deve exibir apenas as operações autorizadas. | Indisponibilidade temporária do GitHub. | Média | Implementada |
| `RS-001` | Regra de Sistema | Renovação segura de sessão | O refresh token deve ser armazenado em cookie `HttpOnly` e rotacionado a cada renovação de sessão. | Ao restaurar a sessão ou receber resposta `401` em rota protegida. | O sistema deve emitir novos tokens, revogar o token anterior e repetir a requisição original somente uma vez. | Sessão sem refresh token válido. | Alta | Implementada |
| `RS-002` | Regra de Sistema | Detecção de reuso de token | O reuso de refresh token revogado deve invalidar a família de tokens relacionada. | Ao identificar token revogado em tentativa de refresh. | O sistema deve encerrar a sessão relacionada e exigir nova autenticação. | N/A | Alta | Implementada |
| `RS-003` | Regra de Sistema | Limite de sessões | Cada usuário pode manter até 10 sessões ativas por refresh token. | Ao criar uma nova sessão acima do limite. | Os tokens ativos mais antigos devem ser revogados. | N/A | Média | Implementada |
| `RS-004` | Regra de Sistema | Separação do Module Federation | O host orquestra autenticação, autorização, sessão, navegação e carregamento. O remote renderiza componentes e recebe dados e callbacks prontos. | Ao implementar ou consumir componente federado. | Não deve haver duplicação de regras de sessão ou autorização no remote. | Estado interno exclusivo de componentes e preview do remote. | Alta | Implementada |
| `RS-005` | Regra de Sistema | Autorização no backend | Guardas de rota no frontend servem para experiência do usuário, mas não substituem a validação do servidor. | Em qualquer chamada à API protegida. | O backend deve retornar `401` ou `403` quando o acesso não for permitido. | N/A | Alta | Implementada |

---

## 6. Critérios de Aceite

| Código | Regra relacionada | Nome | Dado que | Quando | Então | Status |
|---|---|---|---|---|---|---|
| `CA-001` | `RN-001` | Bloqueio de rota protegida | O visitante não possui sessão válida. | Ele acessa uma rota protegida. | O sistema deve redirecionar para a autenticação ou negar o acesso ao recurso. | Pendente |
| `CA-002` | `RN-002` | Bloqueio de recurso de outro usuário | Um usuário autenticado tenta acessar um recurso que não lhe pertence. | A requisição é enviada à API. | O backend deve negar a operação com resposta de autorização. | Pendente |
| `CA-003` | `RN-003` | Validação de lançamento financeiro | O usuário preenche um lançamento financeiro. | Ele tenta salvar dados obrigatórios ausentes ou inválidos. | O sistema deve informar a validação e não salvar o lançamento. | Pendente |
| `CA-004` | `RN-004` | Atualização de tarefa própria | O usuário possui uma tarefa cadastrada. | Ele altera o estado ou os dados da tarefa. | A alteração deve ser persistida apenas para a tarefa dele. | Pendente |
| `CA-005` | `RS-001` | Renovação após expiração do access token | Existe um refresh token válido no cookie. | Uma requisição protegida recebe `401`. | O sistema deve renovar a sessão e reenviar a requisição original uma única vez. | Pendente |
| `CA-006` | `RS-002` | Revogação por reuso | Um refresh token já foi rotacionado ou revogado. | Ele é usado novamente. | O sistema deve revogar a família de tokens e solicitar novo login. | Pendente |
| `CA-007` | `RS-004` | Responsabilidades do remote | O host carrega um componente remoto. | O componente é renderizado. | Ele deve receber dados e callbacks do host sem controlar sessão, autenticação ou autorização. | Pendente |
| `CA-008` | `RS-005` | Validação de permissão no servidor | Um usuário manipula a interface ou chama a API diretamente. | Ele solicita recurso sem autorização. | O backend deve negar a requisição, independentemente do estado do frontend. | Pendente |

---

## 7. Matriz de Rastreabilidade

| Regra | Requisito ou tarefa | Critério de aceite | Caso de teste | Status |
|---|---|---|---|---|
| `RN-001` | Módulo de autenticação e rotas protegidas | `CA-001` | Teste de acesso a rota protegida | Implementado |
| `RN-002` | Serviços e controladores protegidos da API | `CA-002` | Teste de autorização por proprietário | Implementado |
| `RN-003` | Módulo financeiro | `CA-003` | Testes unitários de validação financeira | Implementado |
| `RN-004` | Módulo de tarefas | `CA-004` | Testes unitários de tarefas | Implementado |
| `RS-001` | Gerenciador de refresh token e store de sessão | `CA-005` | Testes unitários de access e refresh token | Implementado |
| `RS-002` | Gerenciador de refresh token | `CA-006` | Teste de detecção de reuso de token | Implementado |
| `RS-004` | Host App e Components App | `CA-007` | Validação de integração federada | Em teste |
| `RS-005` | Segurança da API | `CA-008` | Testes de CSRF e permissões | Implementado |

---

## 8. Histórico de Alterações

| Versão | Data | Responsável | Alteração |
|---|---|---|---|
| 1.0 | 14/07/2026 | Rafael Fernando dos Reis Mecabô | Criação inicial do documento com escopo, regras e critérios do OctoFlow. |
