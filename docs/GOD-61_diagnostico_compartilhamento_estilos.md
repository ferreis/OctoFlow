# GOD-61 — Module Federation: Diagnóstico de Compartilhamento de Estilos

## Estado Atual (pós-GOD-43)

### O que foi implementado

| Ação | Status |
|---|---|
| `shared: ['vue', 'vue-router', 'pinia']` em ambos os apps | ✅ Feito |
| `cssCodeSplit: true` no Components-app | ✅ Feito |
| `cssCodeSplit: false` no Host-app | ✅ Feito |
| PostCSS + `postcss-prefixer` (mf- prefix) no Components-app | ❌ Removido (quebrava scoped styles) |
| Lock de versões + `singleton: true` | ❌ Pendente — **CORRIGIDO NESTE ISSUE** |
| Alinhamento de `vue-router` (4.6.3 → 4.6.4) | ❌ Pendente — **CORRIGIDO NESTE ISSUE** |

---

## Diagnóstico Detalhado

### 1. PostCSS Prefixer: CRÍTICO (corrigido)

**Problema:** O `postcss-prefixer` com prefixo `mf-` foi adicionado ao Components-app, mas ele prefixa **todo** CSS processado, incluindo `<style scoped>` dos componentes Vue.

**Evidência:** Componentes com `<style scoped>` (ex: `FinanceKpiCard.vue`) tinham a classe `.finance-kpi-card` prefixada para `.mf-finance-kpi-card[data-v-xxxx]` no build, mas o template continuava usando `.finance-kpi-card`. O CSS não casava com o template.

**Antes (quebrado):**
```css
/* CSS compilado */
.mf-finance-kpi-card[data-v-d0178bfb] { ... }
/* Template usa: <article class="finance-kpi-card"> → MISMATCH */
```

**Depois (corrigido):**
```css
/* CSS compilado */
.finance-kpi-card[data-v-d0178bfb] { ... }
/* Template usa: <article class="finance-kpi-card"> → MATCH */
```

**Decisão:** Remover `postcss-prefixer`. O isolamento de CSS é garantido por:
- `<style scoped>` nos componentes (já padronizado)
- CSS custom properties (tokens) para consistência temática
- Classes utilitárias do Tailwind e `.app-*` permanecem globais — conflito é mitigado porque componentes remotos usam `<style scoped>` exclusivamente para seus estilos específicos

### 2. Task Panels — Estilo Global (risco baixo)

Os componentes `TaskCrudPanel`, `TaskCreatePanel`, `TaskEditPanel`, `TaskListPanel` e `TaskViewPanel` **não possuem** `<style>` blocks. Eles dependem de:
- `task-panels.css` (importado via `style.css` do Components-app)
- Classes globais do `app-visual-standard.css`
- Classes utilitárias do Tailwind

Estes estilos colidirão com os mesmos nomes de classe do Host-app. **Mitigação:** ambos os apps importam o mesmo `app-visual-standard.css`, definindo as mesmas regras. O resultado é previsível (último CSS carregado vence), sem quebra funcional.

### 3. Lock de Versões (corrigido)

**Antes:**
```js
shared: ['vue', 'vue-router', 'pinia']
```

**Depois (ambos os apps):**
```js
shared: {
  vue: { requiredVersion: '^3.5.25', singleton: true },
  'vue-router': { requiredVersion: '^4.6.4', singleton: true },
  pinia: { requiredVersion: '^2.3.1', singleton: true },
}
```

### 4. vue-router versionado (corrigido)

Components-app: `^4.6.3` → `^4.6.4` (alinhado com Host-app)

### 5. Build (verificado)

| App | Build |
|---|---|
| Host-app | ✅ Sucesso |
| Components-app | ✅ Sucesso |

---

## Riscos Residuais

1. **Classes globais `.app-*` duplicadas**: Ambos os apps emitem seu próprio bundle com `app-visual-standard.css`. Quando o Host carrega um componente remoto, as classes são redefinidas. Impacto visual mínimo pois as definições são idênticas.
2. **Tailwind duplicado**: Ambos os apps incluem Tailwind. Sem shadow DOM, o CSS do Components-app pode sobrescrever o do Host (e vice-versa). Solução futura: migrar para CSS por componente + tokens.
3. **`dompurify` e `marked` não estão no shared**: Duplicados, mas sem estado — impacto apenas no peso do download (~15KB gzip).

---

## Recomendações Futuras

1. **Migrar `app-visual-standard.css` para tokens puros**: Remover classes utilitárias `.app-*` e deixar apenas CSS custom properties. Componentes consomem tokens via `<style scoped>`.
2. **Avaliar Shadow DOM ou CSS Containment** para isolamento completo entre micro-frontends.
3. **Implementar testes visuais** (regression) em staging para detectar conflitos de estilo.

---

**Responsável:** Desenvolvedor Fullstack (God King Dev)  
**Data:** 2026-07-08  
**Status:** Resolvido
