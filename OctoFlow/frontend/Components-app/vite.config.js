import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import federation from '@originjs/vite-plugin-federation'

// https://vite.dev/config/
export default defineConfig({
  base: "/OctoFlow-mf/",
  plugins: [
    vue(),
    tailwindcss(),
    federation({
      name: "octoflow_components",
      filename: "remoteEntry.js",
      exposes: {
        "./TaskCrudPanel": "./src/components/TaskCrudPanel.vue",
        "./GithubWorkspacePanel": "./src/components/GithubWorkspacePanel.vue",
        "./GithubWorkspaceSummaryCard": "./src/components/workspace/GithubWorkspaceSummaryCard.vue",
        "./GithubIssueComposerPanel": "./src/components/workspace/GithubIssueComposerPanel.vue",
        "./GithubProjectsCard": "./src/components/workspace/GithubProjectsCard.vue",
        "./MarkdownPreview": "./src/components/content/MarkdownPreview.vue",
        "./MultiSelect": "./src/components/form/MultiSelect.vue",
        "./IssueTemplateForm": "./src/components/issue/IssueTemplateForm.vue",
        "./IssueTemplatePreview": "./src/components/issue/IssueTemplatePreview.vue",
        "./FinanceKpiCard": "./src/components/finance/FinanceKpiCard.vue",
        "./FinanceStatusBadge": "./src/components/finance/FinanceStatusBadge.vue",
        "./FinanceEmptyState": "./src/components/finance/FinanceEmptyState.vue",
        "./FinanceTrendMiniChart": "./src/components/finance/FinanceTrendMiniChart.vue",
      },
      shared: {
        vue: {
          requiredVersion: "^3.5.25",
          singleton: true,
        },
        "vue-router": {
          requiredVersion: "^4.6.4",
          singleton: true,
        },
        pinia: {
          requiredVersion: "^2.3.1",
          singleton: true,
        },
      },
    }),
  ],
  server: {
    port: 5175,
    strictPort: false,
    cors: true,
  },
  preview: {
    port: 5175,
    cors: true,
  },
  build: {
    target: "esnext",
    minify: false,
    cssCodeSplit: true,
    css: {
      modules: {
        localsConvention: "camelCase",
      },
    },
  },
});
