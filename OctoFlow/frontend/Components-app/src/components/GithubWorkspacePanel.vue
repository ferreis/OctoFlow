<script setup>
import BaseAlert from './base/BaseAlert.vue'
import LoadingState from './base/LoadingState.vue'
import GithubWorkspaceSummaryCard from './workspace/GithubWorkspaceSummaryCard.vue'
import GithubProjectsCard from './workspace/GithubProjectsCard.vue'
import GithubIssueComposerPanel from './workspace/GithubIssueComposerPanel.vue'

defineProps({
  profile: {
    type: Object,
    default: null,
  },
  workspace: {
    type: Object,
    default: null,
  },
  currentUser: {
    type: Object,
    default: null,
  },
  composer: {
    type: Object,
    default: () => ({}),
  },
  assigneePlaceholderLabel: {
    type: String,
    default: 'Sem atribuição inicial',
  },
  loading: {
    type: Boolean,
    default: false,
  },
  error: {
    type: String,
    default: '',
  },
  submitError: {
    type: String,
    default: '',
  },
  submitting: {
    type: Boolean,
    default: false,
  },
})

defineEmits([
  'update:selectedTemplateKey',
  'update:title',
  'update:assigneeId',
  'update:selectedLabels',
  'update:fieldValues',
  'submit',
  'reset',
])
</script>

<template>
  <section class="workspace-shell">
    <LoadingState v-if="loading">
      Carregando configuração do repositório, labels e Projects do GitHub...
    </LoadingState>

    <template v-else-if="workspace">
      <GithubWorkspaceSummaryCard :workspace="workspace" :current-user="currentUser" />
      <GithubProjectsCard :workspace="workspace" />
      <GithubIssueComposerPanel
        :workspace="workspace"
        :composer="composer"
        :assignee-placeholder-label="assigneePlaceholderLabel"
        :submitting="submitting"
        :submit-error="submitError"
        @update:selected-template-key="$emit('update:selectedTemplateKey', $event)"
        @update:title="$emit('update:title', $event)"
        @update:assignee-id="$emit('update:assigneeId', $event)"
        @update:selected-labels="$emit('update:selectedLabels', $event)"
        @update:field-values="$emit('update:fieldValues', $event)"
        @submit="$emit('submit')"
        @reset="$emit('reset')"
      />
    </template>

    <BaseAlert v-else-if="error" type="error">
      {{ error }}
    </BaseAlert>
  </section>
</template>

<style scoped>
.workspace-shell {
  display: grid;
  gap: 1.25rem;
}
</style>
