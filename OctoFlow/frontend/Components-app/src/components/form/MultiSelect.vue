<script setup>
import { computed, nextTick, ref, watch } from 'vue'

let multiSelectIdSeed = 0

const props = defineProps({
  modelValue: {
    type: Array,
    default: () => [],
  },
  options: {
    type: Array,
    default: () => [],
  },
  searchPlaceholder: {
    type: String,
    default: 'Pesquisar',
  },
  helperText: {
    type: String,
    default: '',
  },
  selectedCountSuffix: {
    type: String,
    default: 'selecionada(s)',
  },
  createLabelPrefix: {
    type: String,
    default: 'Criar',
  },
  createHelperText: {
    type: String,
    default: 'A nova opcao sera criada ao salvar.',
  },
  existingOptionHelperText: {
    type: String,
    default: 'Opcao existente',
  },
  emptyOptionsText: {
    type: String,
    default: 'Nenhuma opcao cadastrada. Digite para criar a primeira.',
  },
  emptySearchText: {
    type: String,
    default: 'Nenhuma opcao encontrada para essa busca.',
  },
  emptyIdleText: {
    type: String,
    default: 'Digite para pesquisar opcoes existentes.',
  },
  emptyCreateText: {
    type: String,
    default: '',
  },
  optionLabelKey: {
    type: String,
    default: 'name',
  },
  optionValueKey: {
    type: String,
    default: 'id',
  },
  optionColorKey: {
    type: String,
    default: 'color',
  },
  optionDescriptionKey: {
    type: String,
    default: 'description',
  },
  newOptionColor: {
    type: String,
    default: '94A3B8',
  },
  newOptionBadge: {
    type: String,
    default: 'Nova',
  },
  allowCreate: {
    type: Boolean,
    default: true,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue'])

const searchTerm = ref('')
const dropdownOpen = ref(false)
const highlightedOptionIndex = ref(-1)
const fieldRef = ref(null)
const inputRef = ref(null)
const dropdownId = `multi-select-options-${++multiSelectIdSeed}`

const normalizedOptions = computed(() => props.options
  .map((option) => normalizeOption(option))
  .filter((option) => option.label !== '')
  .sort((left, right) => left.label.localeCompare(right.label, 'pt-BR', { sensitivity: 'base' }))
)
const selectedOptions = computed(() => sanitizeOptions(props.modelValue))
const selectedOptionNameKeys = computed(() => new Set(
  selectedOptions.value
    .map((option) => buildOptionNameKey(option.label))
    .filter(Boolean)
))
const trimmedSearchTerm = computed(() => normalizeOptionLabel(searchTerm.value))
const exactExistingOptionMatch = computed(() => {
  const searchKey = buildOptionNameKey(trimmedSearchTerm.value)
  if (searchKey === '') {
    return null
  }

  return normalizedOptions.value.find((option) => buildOptionNameKey(option.label) === searchKey) || null
})
const filteredOptions = computed(() => {
  const searchKey = buildOptionNameKey(trimmedSearchTerm.value)

  return normalizedOptions.value.filter((option) => {
    if (selectedOptionNameKeys.value.has(buildOptionNameKey(option.label))) {
      return false
    }

    if (searchKey === '') {
      return true
    }

    return buildSearchKey(option.label).includes(searchKey)
  })
})
const canCreateOption = computed(() => {
  const searchKey = buildOptionNameKey(trimmedSearchTerm.value)
  if (!props.allowCreate || searchKey === '' || selectedOptionNameKeys.value.has(searchKey)) {
    return false
  }

  return exactExistingOptionMatch.value === null
})
const dropdownItems = computed(() => {
  const items = []

  if (canCreateOption.value) {
    items.push({
      key: `create:${buildOptionNameKey(trimmedSearchTerm.value)}`,
      type: 'create',
      name: trimmedSearchTerm.value,
    })
  }

  for (const option of filteredOptions.value) {
    items.push({
      key: `option:${option.value || option.label}`,
      type: 'existing',
      option,
    })
  }

  return items
})
const emptyStateLabel = computed(() => {
  if (dropdownItems.value.length > 0) {
    return ''
  }

  if (normalizedOptions.value.length === 0) {
    return props.emptyOptionsText
  }

  if (trimmedSearchTerm.value !== '') {
    return canCreateOption.value && props.emptyCreateText.trim() !== ''
      ? props.emptyCreateText
      : props.emptySearchText
  }

  return props.emptyIdleText
})

watch(dropdownOpen, (isOpen) => {
  if (!isOpen) {
    highlightedOptionIndex.value = -1
    return
  }

  highlightedOptionIndex.value = dropdownItems.value.length > 0 ? 0 : -1
})

watch(dropdownItems, (items) => {
  if (!dropdownOpen.value) {
    return
  }

  if (items.length === 0) {
    highlightedOptionIndex.value = -1
    return
  }

  if (highlightedOptionIndex.value < 0 || highlightedOptionIndex.value >= items.length) {
    highlightedOptionIndex.value = 0
  }
})

function normalizeOptionLabel(value) {
  return String(value || '')
    .trim()
    .replace(/\s+/g, ' ')
}

function buildOptionNameKey(value) {
  return normalizeOptionLabel(value).toLocaleLowerCase()
}

function buildSearchKey(value) {
  return buildOptionNameKey(value)
}

function normalizeOptionColor(value) {
  const normalizedValue = String(value || '').trim().replace(/^#/, '')
  return /^[0-9a-fA-F]{6}$/.test(normalizedValue) ? normalizedValue.toUpperCase() : props.newOptionColor
}

function normalizeOption(option) {
  const label = normalizeOptionLabel(option?.[props.optionLabelKey])
  const value = String(option?.[props.optionValueKey] || '').trim()
  const description = typeof option?.[props.optionDescriptionKey] === 'string' && option[props.optionDescriptionKey].trim() !== ''
    ? option[props.optionDescriptionKey].trim()
    : null

  return {
    ...(option || {}),
    [props.optionLabelKey]: label,
    [props.optionValueKey]: value,
    [props.optionColorKey]: normalizeOptionColor(option?.[props.optionColorKey]),
    [props.optionDescriptionKey]: description,
    label,
    value,
    color: normalizeOptionColor(option?.[props.optionColorKey]),
    description,
    isNew: value === '',
  }
}

function cloneSelectedOption(option) {
  const normalizedOption = normalizeOption(option)

  return {
    ...normalizedOption,
    isNew: normalizedOption.value === '',
  }
}

function sanitizeOptions(rawOptions) {
  if (!Array.isArray(rawOptions)) {
    return []
  }

  const sanitizedOptions = []
  const seenValues = new Set()
  const seenNames = new Set()

  for (const rawOption of rawOptions) {
    const normalizedOption = cloneSelectedOption(rawOption)
    const optionNameKey = buildOptionNameKey(normalizedOption.label)

    if (optionNameKey === '' || seenNames.has(optionNameKey)) {
      continue
    }

    if (normalizedOption.value !== '') {
      if (seenValues.has(normalizedOption.value)) {
        continue
      }

      seenValues.add(normalizedOption.value)
      normalizedOption.isNew = false
    }

    seenNames.add(optionNameKey)
    sanitizedOptions.push(normalizedOption)
  }

  return sanitizedOptions
}

function focusInput() {
  nextTick(() => {
    inputRef.value?.focus()
  })
}

function openDropdown() {
  if (props.disabled) {
    return
  }

  dropdownOpen.value = true
  focusInput()
}

function closeDropdown() {
  dropdownOpen.value = false
}

function emitSelection(rawOptions) {
  emit('update:modelValue', sanitizeOptions(rawOptions))
}

function selectExistingOption(option) {
  emitSelection([...selectedOptions.value, option])
  searchTerm.value = ''
  openDropdown()
}

function createOption() {
  if (!canCreateOption.value) {
    return
  }

  emitSelection([
    ...selectedOptions.value,
    {
      [props.optionValueKey]: '',
      [props.optionLabelKey]: trimmedSearchTerm.value,
      [props.optionColorKey]: props.newOptionColor,
      isNew: true,
    },
  ])

  searchTerm.value = ''
  openDropdown()
}

function removeOption(optionToRemove) {
  const optionKey = optionToRemove.value !== '' ? optionToRemove.value : buildOptionNameKey(optionToRemove.label)
  emitSelection(selectedOptions.value.filter((option) => {
    const currentKey = option.value !== '' ? option.value : buildOptionNameKey(option.label)
    return currentKey !== optionKey
  }))
  openDropdown()
}

function handleInputKeydown(event) {
  if (!dropdownOpen.value && ['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) {
    event.preventDefault()
    openDropdown()
    return
  }

  if (!dropdownOpen.value) {
    return
  }

  if (event.key === 'ArrowDown') {
    event.preventDefault()
    if (dropdownItems.value.length === 0) {
      return
    }

    highlightedOptionIndex.value = (highlightedOptionIndex.value + 1 + dropdownItems.value.length) % dropdownItems.value.length
    return
  }

  if (event.key === 'ArrowUp') {
    event.preventDefault()
    if (dropdownItems.value.length === 0) {
      return
    }

    highlightedOptionIndex.value = (highlightedOptionIndex.value - 1 + dropdownItems.value.length) % dropdownItems.value.length
    return
  }

  if (event.key === 'Enter') {
    event.preventDefault()
    const highlightedItem = dropdownItems.value[highlightedOptionIndex.value]
    if (!highlightedItem) {
      return
    }

    if (highlightedItem.type === 'create') {
      createOption()
      return
    }

    selectExistingOption(highlightedItem.option)
    return
  }

  if (event.key === 'Backspace' && searchTerm.value === '' && selectedOptions.value.length > 0) {
    event.preventDefault()
    removeOption(selectedOptions.value[selectedOptions.value.length - 1])
    return
  }

  if (event.key === 'Escape') {
    event.preventDefault()
    closeDropdown()
  }
}

function handleFieldBlur(event) {
  const nextFocusTarget = event.relatedTarget
  if (fieldRef.value?.contains(nextFocusTarget)) {
    return
  }

  closeDropdown()
}

function optionChipStyle(option) {
  const color = `#${option.color}`

  return {
    borderColor: color,
    background: `${color}18`,
    color,
  }
}
</script>

<template>
  <div
    ref="fieldRef"
    class="multi-select"
    :class="{ 'is-open': dropdownOpen, 'is-disabled': disabled }"
    @focusout="handleFieldBlur"
  >
    <button
      type="button"
      class="selection-shell"
      :disabled="disabled"
      @click="openDropdown"
    >
      <div v-if="selectedOptions.length" class="selection-list">
        <span
          v-for="option in selectedOptions"
          :key="`${option.value || 'new'}:${option.label}`"
          class="selection-chip"
          :style="optionChipStyle(option)"
        >
          <span>{{ option.label }}</span>
          <button
            type="button"
            class="selection-chip-remove"
            :disabled="disabled"
            @click.stop="removeOption(option)"
          >
            x
          </button>
        </span>
      </div>

      <input
        ref="inputRef"
        v-model="searchTerm"
        type="text"
        class="selection-input"
        :placeholder="selectedOptions.length === 0 ? searchPlaceholder : ''"
        :aria-expanded="dropdownOpen"
        :aria-controls="dropdownId"
        :disabled="disabled"
        @focus="openDropdown"
        @keydown="handleInputKeydown"
      >
    </button>

    <small v-if="helperText" class="helper-text">
      {{ helperText }}
      <span v-if="selectedOptions.length"> {{ selectedOptions.length }} {{ selectedCountSuffix }}.</span>
    </small>

    <div
      v-if="dropdownOpen"
      :id="dropdownId"
      class="dropdown-panel"
      role="listbox"
    >
      <button
        v-for="(item, index) in dropdownItems"
        :key="item.key"
        type="button"
        class="dropdown-option"
        :class="{ highlighted: index === highlightedOptionIndex }"
        @mouseenter="highlightedOptionIndex = index"
        @click="item.type === 'create' ? createOption() : selectExistingOption(item.option)"
      >
        <template v-if="item.type === 'create'">
          <strong>{{ createLabelPrefix }} "{{ item.name }}"</strong>
          <small>{{ createHelperText }}</small>
        </template>

        <template v-else>
          <strong>{{ item.option.label }}</strong>
          <small>{{ item.option.description || existingOptionHelperText }}</small>
        </template>
      </button>

      <p v-if="emptyStateLabel" class="empty-label">
        {{ emptyStateLabel }}
      </p>
    </div>
  </div>
</template>

<style scoped>
.multi-select {
  display: grid;
  gap: 0.5rem;
  position: relative;
}

.selection-shell {
  align-items: center;
  background: white;
  border: 1px solid var(--line, rgba(148, 163, 184, 0.22));
  border-radius: 18px;
  display: flex;
  flex-wrap: wrap;
  gap: 0.55rem;
  min-height: 3rem;
  padding: 0.55rem 0.75rem;
  text-align: left;
  width: 100%;
}

.selection-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.selection-chip {
  align-items: center;
  border: 1px solid;
  border-radius: 999px;
  display: inline-flex;
  gap: 0.4rem;
  max-width: 100%;
  padding: 0.28rem 0.72rem;
}

.selection-chip-remove {
  background: transparent;
  border: 0;
  color: inherit;
  cursor: pointer;
  font-size: 0.85rem;
  padding: 0;
}

.selection-input {
  background: transparent;
  border: 0;
  color: var(--ink, #0f172a);
  flex: 1 1 180px;
  min-width: 120px;
  outline: none;
}

.helper-text {
  color: var(--muted, #475569);
  font-size: 0.9rem;
}

.dropdown-panel {
  background: var(--surface-strong, white);
  border: 1px solid var(--line, rgba(148, 163, 184, 0.22));
  border-radius: 20px;
  box-shadow: 0 18px 48px rgba(15, 23, 42, 0.09);
  display: grid;
  gap: 0.35rem;
  left: 0;
  margin-top: 0.15rem;
  max-height: 280px;
  overflow: auto;
  padding: 0.5rem;
  position: absolute;
  right: 0;
  top: 100%;
  z-index: 30;
}

.dropdown-option {
  background: transparent;
  border: 0;
  border-radius: 16px;
  cursor: pointer;
  display: grid;
  gap: 0.25rem;
  padding: 0.8rem 0.9rem;
  text-align: left;
}

.dropdown-option strong {
  color: var(--ink, #0f172a);
}

.dropdown-option small {
  color: var(--muted, #475569);
}

.dropdown-option.highlighted,
.dropdown-option:hover {
  background: color-mix(in srgb, var(--color-secondary, #06b6d4) 8%, white);
}

.empty-label {
  color: var(--muted, #475569);
  font-size: 0.92rem;
  margin: 0;
  padding: 0.8rem 0.9rem;
}

.is-disabled .selection-shell {
  opacity: 0.6;
}
</style>
