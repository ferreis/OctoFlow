# Auditoria de segurança

Arquivos desta pasta documentam a auditoria de segurança realizada em 03/09/2026 na branch `dev`.

## Regenerar o PDF

Use um ambiente Python isolado:

```bash
cd docs/security-audit
python3 -m venv .venv
. .venv/bin/activate
python -m pip install --upgrade pip
python -m pip install -r requirements.txt
python gerar_relatorio.py
```

Saída esperada:

```text
docs/security-audit/relatorio-auditoria-seguranca.pdf
```

## Verificação visual

O relatório foi validado com 7 páginas e rasterização das páginas 1, 2, 4 e 7. O script usa A4, margens próximas de 2 cm e cabeçalho/rodapé com numeração.

## Testes Playwright de segurança

Os testes em `tests/seguranca-autorizacao.spec.js` verificam que endpoints sensíveis rejeitam chamadas anônimas. Também existe um teste autenticado opcional para confirmar que uma issue de repositório acessível pelo token do GitHub, mas não cadastrado no OctoFlow, é bloqueada com HTTP 403.

Eles não exigem alteração no `package.json` do frontend. Para executá-los sem instalar dependências no projeto, copie o teste para um diretório temporário isolado:

```bash
cd docs/security-audit
PLAYWRIGHT_TMP="$(mktemp -d)"
cp tests/seguranca-autorizacao.spec.js "$PLAYWRIGHT_TMP/seguranca-autorizacao.spec.js"
cd "$PLAYWRIGHT_TMP"
npm init -y
npm install --save-dev @playwright/test
OCTOFLOW_API_BASE_URL="https://localhost:4481/OctoFlow/api" npx playwright test seguranca-autorizacao.spec.js
```

Para executar também a regressão IDOR autenticada, informe um access token válido do OctoFlow e o node ID de uma issue pertencente a um repositório que o mesmo token GitHub consegue acessar, mas que não esteja cadastrado no OctoFlow:

```bash
OCTOFLOW_API_BASE_URL="https://localhost:4481/OctoFlow/api" \
OCTOFLOW_ACCESS_TOKEN="TOKEN_DA_SESSAO_DE_TESTE" \
OCTOFLOW_UNREGISTERED_ISSUE_ID="NODE_ID_DE_TESTE" \
npx playwright test seguranca-autorizacao.spec.js
```

Use somente credenciais descartáveis de ambiente de teste e nunca registre os valores reais em arquivos ou commits. Ajuste `OCTOFLOW_API_BASE_URL` para a URL real da instância auditada. Para HTTPS local com certificado de desenvolvimento, configure o ambiente Playwright para aceitar o certificado somente no ambiente de teste.

## Observação

O gerador lê `dados-auditoria.json`; portanto, alterações futuras nos achados devem atualizar esse arquivo antes de regenerar o PDF.
