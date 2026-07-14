<script setup>
import { computed } from 'vue'

const props = defineProps({
  currentPage: {
    type: Number,
    required: true,
  },
  totalPages: {
    type: Number,
    required: true,
  },
  summary: {
    type: String,
    default: '',
  },
  previousLabel: {
    type: String,
    default: 'Anterior',
  },
  nextLabel: {
    type: String,
    default: 'Próxima',
  },
})

const emit = defineEmits(['previous', 'next'])

const normalizedCurrentPage = computed(() => Math.max(1, Number(props.currentPage) || 1))
const normalizedTotalPages = computed(() => Math.max(1, Number(props.totalPages) || 1))
const hasMultiplePages = computed(() => normalizedTotalPages.value > 1)

function requestPreviousPage() {
  if (normalizedCurrentPage.value > 1) {
    emit('previous')
  }
}

function requestNextPage() {
  if (normalizedCurrentPage.value < normalizedTotalPages.value) {
    emit('next')
  }
}
</script>

<template>
  <nav v-if="hasMultiplePages" class="finance-pagination" aria-label="Paginação">
    <span class="finance-pagination-summary">{{ summary }}</span>
    <div class="finance-pagination-actions">
      <button
        type="button"
        class="finance-inline-action finance-pagination-button"
        :disabled="normalizedCurrentPage <= 1"
        @click="requestPreviousPage"
      >
        {{ previousLabel }}
      </button>
      <span class="finance-pagination-page">{{ normalizedCurrentPage }} / {{ normalizedTotalPages }}</span>
      <button
        type="button"
        class="finance-inline-action finance-pagination-button"
        :disabled="normalizedCurrentPage >= normalizedTotalPages"
        @click="requestNextPage"
      >
        {{ nextLabel }}
      </button>
    </div>
  </nav>
</template>
