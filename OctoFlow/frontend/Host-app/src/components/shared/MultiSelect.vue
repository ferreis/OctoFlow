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

function emitSelectedOptions(nextOptions) {
  emit('update:modelValue', sanitizeOptions(nextOptions))
}

function buildChipStyle(option) {
  return {
    backgroundColor: `#${normalizeOptionColor(option?.color)}`,
  }
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
}

function closeDropdown() {
  dropdownOpen.value = false
}

function handleFieldFocusOut() {
  window.setTimeout(() => {
    if (fieldRef.value?.contains(document.activeElement)) {
      return
    }

    closeDropdown()
  }, 0)
}

function focusField() {
  openDropdown()
  focusInput()
}

function moveHighlight(direction) {
  openDropdown()

  if (dropdownItems.value.length === 0) {
    return
  }

  if (highlightedOptionIndex.value < 0) {
    highlightedOptionIndex.value = direction > 0 ? 0 : dropdownItems.value.length - 1
    return
  }

  highlightedOptionIndex.value = (
    highlightedOptionIndex.value + direction + dropdownItems.value.length
  ) % dropdownItems.value.length
}

function applyHighlightedOption() {
  const highlightedItem = dropdownItems.value[highlightedOptionIndex.value] || null

  if (highlightedItem) {
    handleDropdownSelection(highlightedItem)
    return
  }

  if (canCreateOption.value) {
    createOptionFromSearch()
  }
}

function handleSearchBackspace() {
  if (trimmedSearchTerm.value !== '' || selectedOptions.value.length === 0) {
    return
  }

  emitSelectedOptions(selectedOptions.value.slice(0, -1))
}

function handleDropdownSelection(item) {
  if (item?.type === 'create') {
    createOptionFromSearch(item.name)
    return
  }

  if (item?.type === 'existing') {
    selectExistingOption(item.option)
  }
}

function selectExistingOption(option) {
  const normalizedOption = cloneSelectedOption(option)
  const optionNameKey = buildOptionNameKey(normalizedOption.label)

  if (normalizedOption.label === '' || selectedOptionNameKeys.value.has(optionNameKey)) {
    searchTerm.value = ''
    focusInput()
    return
  }

  normalizedOption.isNew = false
  emitSelectedOptions([...selectedOptions.value, normalizedOption])
  searchTerm.value = ''
  openDropdown()
  focusInput()
}

function createOptionFromSearch(rawName = searchTerm.value) {
  const normalizedName = normalizeOptionLabel(rawName)
  const optionNameKey = buildOptionNameKey(normalizedName)

  if (optionNameKey === '') {
    return
  }

  const existingOption = normalizedOptions.value.find((option) => buildOptionNameKey(option.label) === optionNameKey) || null
  if (existingOption) {
    selectExistingOption(existingOption)
    return
  }

  if (selectedOptionNameKeys.value.has(optionNameKey)) {
    searchTerm.value = ''
    focusInput()
    return
  }

  emitSelectedOptions([
    ...selectedOptions.value,
    {
      [props.optionValueKey]: '',
      [props.optionLabelKey]: normalizedName,
      [props.optionColorKey]: props.newOptionColor,
      [props.optionDescriptionKey]: null,
      value: '',
      label: normalizedName,
      color: props.newOptionColor,
      description: null,
      isNew: true,
    },
  ])
  searchTerm.value = ''
  openDropdown()
  focusInput()
}

function removeSelectedOption(optionToRemove) {
  const optionValue = String(optionToRemove?.value || '').trim()
  const optionNameKey = buildOptionNameKey(optionToRemove?.label)

  emitSelectedOptions(selectedOptions.value.filter((option) => {
    if (optionValue !== '' && String(option?.value || '').trim() === optionValue) {
      return false
    }

    return buildOptionNameKey(option?.label) !== optionNameKey
  }))

  focusInput()
}
</script>

<template>
  <div
    ref="fieldRef"
    class="relative"
    @focusout="handleFieldFocusOut"
  >
    <div
      class="flex min-h-[132px] flex-wrap items-start gap-2 rounded-xl border bg-white px-3 py-3 text-sm text-slate-900 shadow-sm transition"
      :class="[
        disabled ? 'cursor-not-allowed bg-slate-50 text-slate-400' : 'cursor-text',
        dropdownOpen ? 'border-cyan-300 ring-2 ring-cyan-100' : 'border-slate-200',
      ]"
      @click="focusField"
    >
      <span
        v-for="option in selectedOptions"
        :key="`${option.value || 'new'}:${option.label}`"
        class="inline-flex max-w-full items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm font-medium text-slate-700"
      >
        <span class="h-2.5 w-2.5 rounded-full" :style="buildChipStyle(option)" />
        <span class="truncate">{{ option.label }}</span>
        <span
          v-if="option.isNew"
          class="rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.14em] text-slate-600"
        >
          {{ newOptionBadge }}
        </span>
        <button
          type="button"
          class="inline-flex h-5 w-5 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-200 hover:text-slate-900"
          :aria-label="`Remover ${option.label}`"
          :disabled="disabled"
          @click.stop="removeSelectedOption(option)"
        >
          x
        </button>
      </span>

      <input
        ref="inputRef"
        v-model="searchTerm"
        type="text"
        :placeholder="searchPlaceholder"
        :disabled="disabled"
        class="min-h-[32px] min-w-[180px] flex-1 border-0 bg-transparent px-0 py-1 text-sm text-slate-900 outline-none placeholder:text-slate-400 disabled:cursor-not-allowed disabled:text-slate-400"
        role="combobox"
        aria-autocomplete="list"
        :aria-expanded="dropdownOpen ? 'true' : 'false'"
        :aria-controls="dropdownId"
        @focus="openDropdown"
        @input="openDropdown"
        @keydown.enter.prevent="applyHighlightedOption"
        @keydown.down.prevent="moveHighlight(1)"
        @keydown.up.prevent="moveHighlight(-1)"
        @keydown.esc.prevent="closeDropdown"
        @keydown.backspace="handleSearchBackspace"
      >
    </div>

    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
      <span v-if="helperText">{{ helperText }}</span>
      <span v-else />
      <span>{{ selectedOptions.length }} {{ selectedCountSuffix }}</span>
    </div>

    <div
      v-if="dropdownOpen && !disabled"
      :id="dropdownId"
      class="absolute z-20 mt-2 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_18px_40px_rgba(15,23,42,0.12)]"
    >
      <div v-if="dropdownItems.length" class="max-h-72 overflow-auto p-2">
        <button
          v-for="(item, index) in dropdownItems"
          :key="item.key"
          type="button"
          class="flex w-full items-start gap-3 rounded-2xl px-3 py-3 text-left transition"
          :class="index === highlightedOptionIndex ? 'bg-cyan-50 text-cyan-950' : 'text-slate-700 hover:bg-slate-50'"
          @mouseenter="highlightedOptionIndex = index"
          @mousedown.prevent="handleDropdownSelection(item)"
        >
          <template v-if="item.type === 'create'">
            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-cyan-100 text-base font-black text-cyan-700">
              +
            </span>
            <span class="min-w-0">
              <span class="block truncate text-sm font-semibold text-slate-950">{{ createLabelPrefix }} "{{ item.name }}"</span>
              <span class="block text-xs leading-5 text-slate-500">{{ createHelperText }}</span>
            </span>
          </template>

          <template v-else>
            <span class="mt-1 h-3 w-3 shrink-0 rounded-full" :style="buildChipStyle(item.option)" />
            <span class="min-w-0">
              <span class="block truncate text-sm font-semibold text-slate-950">{{ item.option.label }}</span>
              <span class="block text-xs leading-5 text-slate-500">{{ item.option.description || existingOptionHelperText }}</span>
            </span>
          </template>
        </button>
      </div>

      <p v-else class="px-4 py-4 text-sm text-slate-500">
        {{ emptyStateLabel }}
      </p>
    </div>
  </div>
</template>
