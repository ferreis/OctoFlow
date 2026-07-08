# GOD-43 — Diagnóstico Module Federation

## Visão Geral

Projeto: OctoFlow  
Stack: Vue 3 + Vite + Tailwind CSS v4 + `@originjs/vite-plugin-federation` v1.4.1  
Arquitetura: Host-app (consumidor) → Components-app (expositor)

---

## Configuração Atual

### Host-app (`frontend/Host-app/vite.config.js`)

| Propriedade | Valor |
|---|---|
| Plugin | `@originjs/vite-plugin-federation` v1.4.1 |
| Nome | `octoflow_host` |
| Remote | `remoteApp` via Promise (`externalType: 'promise'`) |
| URL remota | Dinâmica (http/https conforme protocolo) |
| Shared | `['vue']` |
| Build | `target: esnext`, `cssCodeSplit: false` |
| Plugin extra | Vue, Tailwind CSS v4 |
| Base | `/OctoFlow/` |

### Components-app (`frontend/Components-app/vite.config.js`)

| Propriedade | Valor |
|---|---|
| Plugin | `@originjs/vite-plugin-federation` v1.4.1 |
| Nome | `octoflow_components` |
| Filename | `remoteEntry.js` |
| Exposes | 13 componentes (TaskCrudPanel, GithubWorkspacePanel, MarkdownPreview, MultiSelect, IssueTemplateForm/P更review, FinanceKpiCard/Badge/EmptyState/TrendMiniChart, etc.) |
| Shared | `['vue']` |
| Build | `target: esnext`, `cssCodeSplit: false` |
| Plugin extra | Vue, Tailwind CSS v4 |
| Base | `/OctoFlow-mf/` |
| CORS | Ativo (server e preview) |

### Dependências

| Pacote | Host-app | Components-app |
|---|---|---|
| vue | ^3.5.25 | ^3.5.25 |
| vue-router | ^4.6.4 | ^4.6.3 |
| pinia | ^2.3.1 | ^2.3.1 |
| axios | ^1.13.6 | — |
| dompurify | ^3.3.3 | ^3.3.3 |
| marked | ^17.0.4 | ^17.0.4 |

### Estilos Compartilhados

Arquivo: `shared/styles/app-visual-standard.css` (416 linhas)

- Design tokens via CSS custom properties (ex: `--app-panel-bg`, `--app-field-bg`)
- Classes utilitárias (ex: `.app-panel-standard`, `.app-field-control`, `.app-status-badge`)
- Suporte a high-contrast mode via `:root[data-high-contrast='true']`
- Fator de densidade via `--app-layout-density-factor`
- **Nenhum mecanismo de isolamento** — classes são globais

---

## Diagnóstico

### 1. Isolamento de CSS — Crítico

Ambos os apps importam Tailwind CSS e o `app-visual-standard.css` separadamente. Como `cssCodeSplit: false`, cada app emite seu próprio bundle CSS completo. Quando o Host carrega um componente remoto, **duas versoes do Tailwind e das classes customizadas coexistem** no mesmo escopo global.

**Risco:** Conflito de classes, sobrescrita de estilos, layout quebrado ao carregar componentes de apps diferentes.

### 2. Shared Dependencies — Alto

Apenas `vue` está no array `shared`. `pinia`, `vue-router`, `dompurify` e `marked` são bundlados separadamente em cada app. Consequências:

- Duplicação de bundle (peso maior no download)
- Duas instâncias de Pinia se ambos apps tentarem usar store
- Duas instâncias de Vue Router — rota empilhada ou perda de estado de navegação

### 3. Version Mismatch — Baixo

`vue-router` com patch diferente (4.6.4 vs 4.6.3). Sem shared, isso não causa erro, mas se for adicionado ao shared, o Module Federation vai tentar resolver a versão mais alta (4.6.4).

### 4. cssCodeSplit: false — Médio

Único bundle CSS por app. Impede carregamento sob demanda de estilos por componente remoto. Não é blocker, mas limita otimização.

### 5. Ausência de Estratégia de Versionamento — Médio

Não há lock de versão no shared. O Module Federation usa o `requiredVersion` do plugin para resolver conflitos. Sem configurar, ele aceita qualquer versão compatível, o que pode introduzir breaking changes silenciosas.

---

## Recomendações

### Prioridade 1 — Isolamento de CSS

**Decisão do CTO:** Adotar `@vue/component-scoped` + prefixo `mf-` em classes globais.

**Ação:**
1. Substituir classes globais `.app-*` no `app-visual-standard.css` por tokens CSS aplicados via `@apply` do Tailwind com escopo de componente
2. Adicionar plugin PostCSS `postcss-prefixer` para prefixar classes utilitárias do Tailwind com `mf-` no Components-app
3. Garantir que todo componente Vue SFC use `<style scoped>`

### Prioridade 2 — Shared Dependencies

**Ação:**
1. Adicionar ao `shared: ['vue', 'pinia', 'vue-router']` em ambos os `vite.config.js`
2. Alinhar `vue-router` para ^4.6.4 em ambos (ou usar `requiredVersion` explícito)
3. Avaliar se `dompurify` e `marked` devem ser shared (são libs de utilidade, sem estado — podem ficar duplicadas sem risco de bug, apenas peso extra)

### Prioridade 3 — Lock de Versões

**Ação:**
1. Usar `shared: { vue: { requiredVersion: '^3.5.25' }, pinia: { requiredVersion: '^2.3.1' }, vue-router: { requiredVersion: '^4.6.4' } }`
2. Adicionar `singleton: true` para Pinia e Vue Router (garantir instância única)

### Prioridade 4 — cssCodeSplit

**Ação:**
1. Mudar `cssCodeSplit: false` → `true` no Components-app para permitir CSS por entry point
2. Manter `false` no Host-app se o CSS global for pequeno

---

## Dependências para Implementação

- PostCSS + `postcss-prefixer` (npm)
- Teste visual em staging para validar isolamento
- Revisão de segurança (mexe em carregamento de assets e estilo — risco baixo)

---

## Próximos Passos

1. [ ] Criar tarefa: Implementar isolamento de CSS (Prefix + Scoped)
2. [ ] Criar tarefa: Atualizar shared dependencies
3. [ ] Criar tarefa: Lock de versões no Module Federation
4. [ ] Validar em ambiente de staging
5. [ ] Revisão final do CTO antes do merge

---

**Aprovado por:** CTO  
**Data:** 2026-07-07  
**Status:** Aguardando criação de tarefas de implementação
