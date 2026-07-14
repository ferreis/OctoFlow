# Política de Segurança

## Versões suportadas

O OctoFlow está em desenvolvimento ativo. Correções de segurança são aplicadas apenas à versão mais recente da branch `main`.

## Como relatar uma vulnerabilidade

Não abra uma issue pública com detalhes de exploração, credenciais, tokens, dados pessoais ou outras informações sensíveis.

Use o recurso **Security > Report a vulnerability** do repositório para enviar um relato privado. Inclua, quando possível:

- componente e versão afetados;
- passos mínimos para reproduzir;
- impacto esperado;
- evidências sem dados reais de usuários;
- sugestão de correção, caso disponível.

Não inclua segredos reais. Revogue imediatamente qualquer credencial que possa ter sido exposta.

## Boas práticas para contribuições

- Nunca envie arquivos `.env.local`, chaves JWT, tokens do GitHub ou credenciais de banco.
- Use valores de exemplo sem validade em documentação e testes.
- Mantenha permissões de workflows no menor nível necessário.
- Evite workflows que executem código não confiável com segredos disponíveis.
- Analise alertas do Dependabot e o resultado do workflow de CI antes do merge.

## Escopo

Relatos sobre autenticação, autorização, CSRF, sessão, armazenamento de tokens, integração GitHub, módulo financeiro e exposição de dados são considerados prioritários.
