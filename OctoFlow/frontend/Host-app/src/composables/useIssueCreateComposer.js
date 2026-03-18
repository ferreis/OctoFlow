import { computed, reactive, ref } from 'vue'
import { buildInitialFieldState, snapshotFieldValues } from '../utils/issueTemplate'

export function useIssueCreateComposer(templatesRef, labelOptionsRef) {
  const selectedTemplateKey = ref('')
  const title = ref('')
  const selectedLabels = ref([])
  const fieldValues = reactive({})
  const templateDrafts = reactive({})

  const selectedTemplate = computed(() => {
    const templates = Array.isArray(templatesRef?.value) ? templatesRef.value : []
    return templates.find((template) => template.key === selectedTemplateKey.value) || null
  })

  function initializeWorkspaceState() {
    selectedTemplateKey.value = ''
    title.value = ''
    selectedLabels.value = []
    clearFieldValues()
    clearDrafts()

    const firstTemplateKey = templatesRef.value?.[0]?.key || ''
    if (firstTemplateKey !== '') {
      selectedTemplateKey.value = firstTemplateKey
    }
  }

  function persistTemplateDraft(templateKey = selectedTemplateKey.value) {
    const template = templatesRef.value?.find((item) => item.key === templateKey)
    if (!template) {
      return
    }

    templateDrafts[templateKey] = {
      title: title.value,
      labels: selectedLabels.value.map((label) => cloneSelectedLabel(label)),
      fields: snapshotFieldValues(template, fieldValues),
    }
  }

  function restoreTemplateDraft(templateKey) {
    const template = templatesRef.value?.find((item) => item.key === templateKey)
    if (!template) {
      return
    }

    const existingDraft = templateDrafts[templateKey]
    const restoredFields = buildInitialFieldState(template)
    const draftFields = existingDraft?.fields || {}

    for (const field of template.fields || []) {
      const fieldKey = typeof field?.key === 'string' ? field.key : ''
      if (fieldKey === '') {
        continue
      }

      const candidateValue = typeof draftFields[fieldKey] === 'string' ? draftFields[fieldKey] : ''
      restoredFields[fieldKey] = candidateValue
    }

    title.value = typeof existingDraft?.title === 'string' ? existingDraft.title : ''
    selectedLabels.value = Array.isArray(existingDraft?.labels)
      ? sanitizeSelectedLabels(existingDraft.labels)
      : resolveDefaultLabels(template)

    syncFieldValues(restoredFields)
  }

  function resetActiveTemplateInputs() {
    const template = selectedTemplate.value
    if (!template) {
      return
    }

    title.value = ''
    selectedLabels.value = resolveDefaultLabels(template)
    syncFieldValues(buildInitialFieldState(template))

    templateDrafts[template.key] = {
      title: '',
      labels: selectedLabels.value.map((label) => cloneSelectedLabel(label)),
      fields: snapshotFieldValues(template, fieldValues),
    }
  }

  function resolveSelectedLabelIds() {
    return selectedLabels.value
      .map((label) => String(label?.id || '').trim())
      .filter(Boolean)
  }

  function resolveNewLabelNames() {
    return selectedLabels.value
      .filter((label) => String(label?.id || '').trim() === '')
      .map((label) => normalizeLabelName(label?.name))
      .filter(Boolean)
  }

  function resolveDefaultLabels(template) {
    const defaultLabels = Array.isArray(template?.defaultLabels) ? template.defaultLabels : []
    const defaultLabelSet = new Set(defaultLabels.map((labelName) => buildLabelNameKey(labelName)).filter(Boolean))

    return (labelOptionsRef.value || [])
      .filter((label) => defaultLabelSet.has(buildLabelNameKey(label.name)))
      .map((label) => cloneSelectedLabel(label))
  }

  function syncFieldValues(nextValues) {
    clearFieldValues()
    Object.assign(fieldValues, nextValues || {})
  }

  function sanitizeSelectedLabels(rawLabels) {
    if (!Array.isArray(rawLabels)) {
      return []
    }

    const sanitizedLabels = []
    const seenIds = new Set()
    const seenNames = new Set()

    for (const rawLabel of rawLabels) {
      const normalizedLabel = cloneSelectedLabel(rawLabel)
      const labelNameKey = buildLabelNameKey(normalizedLabel.name)

      if (labelNameKey === '' || seenNames.has(labelNameKey)) {
        continue
      }

      if (normalizedLabel.id !== '') {
        if (seenIds.has(normalizedLabel.id)) {
          continue
        }

        seenIds.add(normalizedLabel.id)
        normalizedLabel.isNew = false
      }

      seenNames.add(labelNameKey)
      sanitizedLabels.push(normalizedLabel)
    }

    return sanitizedLabels
  }

  function clearFieldValues() {
    for (const key of Object.keys(fieldValues)) {
      delete fieldValues[key]
    }
  }

  function clearDrafts() {
    for (const key of Object.keys(templateDrafts)) {
      delete templateDrafts[key]
    }
  }

  return {
    fieldValues,
    selectedLabels,
    selectedTemplate,
    selectedTemplateKey,
    title,
    initializeWorkspaceState,
    persistTemplateDraft,
    resetActiveTemplateInputs,
    resolveNewLabelNames,
    resolveSelectedLabelIds,
    restoreTemplateDraft,
    sanitizeSelectedLabels,
    syncFieldValues,
  }
}

function normalizeLabelName(value) {
  return String(value || '')
    .trim()
    .replace(/\s+/g, ' ')
}

function buildLabelNameKey(value) {
  return normalizeLabelName(value).toLocaleLowerCase()
}

function normalizeLabelColor(value) {
  const normalizedValue = String(value || '').trim().replace(/^#/, '')
  return /^[0-9a-fA-F]{6}$/.test(normalizedValue) ? normalizedValue.toUpperCase() : '94A3B8'
}

function normalizeLabelOption(label) {
  return {
    id: String(label?.id || '').trim(),
    name: normalizeLabelName(label?.name),
    color: normalizeLabelColor(label?.color),
    description: typeof label?.description === 'string' && label.description.trim() !== ''
      ? label.description.trim()
      : null,
    isNew: String(label?.id || '').trim() === '',
  }
}

function cloneSelectedLabel(label) {
  const normalizedLabel = normalizeLabelOption(label)

  return {
    ...normalizedLabel,
    isNew: normalizedLabel.id === '',
  }
}
