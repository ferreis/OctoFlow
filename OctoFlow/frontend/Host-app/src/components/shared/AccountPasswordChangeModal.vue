<script setup>
import {
  computed,
  nextTick,
  onActivated,
  onBeforeUnmount,
  onDeactivated,
  onMounted,
  ref,
  watch,
} from 'vue'
import { useI18n } from '../../composables/useI18n'

const SECURITY_CODE_MAX_LENGTH = 16
const PASSWORD_MAX_LENGTH = 128

const props = defineProps({
  isOpen: {
    type: Boolean,
    default: false,
  },
  step: {
    type: String,
    default: 'code',
  },
  processing: {
    type: Boolean,
    default: false,
  },
  email: {
    type: String,
    default: '',
  },
  codeExpiresAtLabel: {
    type: String,
    default: '',
  },
  code: {
    type: String,
    default: '',
  },
  password: {
    type: String,
    default: '',
  },
  confirmPassword: {
    type: String,
    default: '',
  },
})

const emit = defineEmits([
  'close',
  'resend-code',
  'verify-code',
  'submit-password',
  'update:code',
  'update:password',
  'update:confirmPassword',
])

const { translate } = useI18n()
const codeInputRef = ref(null)
const passwordInputRef = ref(null)
const componentIdSuffix = Math.random().toString(36).slice(2, 10)

const dialogTitleId = computed(() => `account-password-change-modal-title-${componentIdSuffix}`)
const dialogDescriptionId = computed(() => `account-password-change-modal-description-${componentIdSuffix}`)
const isPasswordStep = computed(() => props.step === 'password')

const dialogTitle = computed(() => (
  isPasswordStep.value
    ? translate('shared.accountPasswordChangeModal.titlePasswordStep')
    : translate('shared.accountPasswordChangeModal.titleCodeStep')
))

const dialogDescription = computed(() => {
  if (isPasswordStep.value) {
    return translate('shared.accountPasswordChangeModal.descriptionPasswordStep')
  }

  const normalizedEmail = String(props.email || '').trim()
  return translate('shared.accountPasswordChangeModal.descriptionCodeStep', {
    email: normalizedEmail !== ''
      ? normalizedEmail
      : translate('shared.accountPasswordChangeModal.emailNotProvided'),
  })
})

const codeExpiresAtText = computed(() => {
  if (isPasswordStep.value || String(props.codeExpiresAtLabel || '').trim() === '') {
    return ''
  }

  return translate('shared.accountPasswordChangeModal.codeExpiresAt', {
    value: props.codeExpiresAtLabel,
  })
})

function replaceControlCharactersWithSpaces(rawValue) {
  let normalizedText = ''
  const inputText = String(rawValue || '')

  for (const currentCharacter of inputText) {
    const characterCode = currentCharacter.charCodeAt(0)
    const isControlCharacter = characterCode < 32 || characterCode === 127
    normalizedText += isControlCharacter ? ' ' : currentCharacter
  }

  return normalizedText
}

function sanitizeSecurityCode(rawValue) {
  return replaceControlCharactersWithSpaces(rawValue)
    .replace(/\s+/g, '')
    .trim()
    .slice(0, SECURITY_CODE_MAX_LENGTH)
}

function closeDialog() {
  if (props.processing) {
    return
  }

  emit('close')
}

function updateCode(event) {
  const normalizedCode = sanitizeSecurityCode(event?.target?.value)
  emit('update:code', normalizedCode)
}

function updatePassword(event) {
  emit('update:password', String(event?.target?.value || '').slice(0, PASSWORD_MAX_LENGTH))
}

function updateConfirmPassword(event) {
  emit('update:confirmPassword', String(event?.target?.value || '').slice(0, PASSWORD_MAX_LENGTH))
}

function requestCodeValidation() {
  if (props.processing) {
    return
  }

  emit('verify-code')
}

function requestPasswordUpdate() {
  if (props.processing) {
    return
  }

  emit('submit-password')
}

function requestCodeResend() {
  if (props.processing) {
    return
  }

  emit('resend-code')
}

async function focusCurrentField() {
  if (!props.isOpen) {
    return
  }

  await nextTick()

  if (isPasswordStep.value) {
    passwordInputRef.value?.focus()
    return
  }

  codeInputRef.value?.focus()
}

function handleEscapeKeydown(event) {
  if (event?.key !== 'Escape' || !props.isOpen) {
    return
  }

  event.preventDefault()
  closeDialog()
}

function registerEscapeListener() {
  if (typeof window === 'undefined') {
    return
  }

  window.addEventListener('keydown', handleEscapeKeydown)
}

function unregisterEscapeListener() {
  if (typeof window === 'undefined') {
    return
  }

  window.removeEventListener('keydown', handleEscapeKeydown)
}

onMounted(() => {
  registerEscapeListener()
})

onActivated(() => {
  registerEscapeListener()
})

onDeactivated(() => {
  unregisterEscapeListener()
})

onBeforeUnmount(() => {
  unregisterEscapeListener()
})

watch(
  () => [props.isOpen, props.step],
  ([dialogIsOpen]) => {
    if (!dialogIsOpen) {
      return
    }

    void focusCurrentField()
  },
  {
    immediate: true,
  },
)
</script>

<template>
  <div
    v-if="isOpen"
    class="app-modal-overlay flex items-center justify-center"
    role="dialog"
    :aria-labelledby="dialogTitleId"
    :aria-describedby="dialogDescriptionId"
    aria-modal="true"
    @click.self="closeDialog"
  >
    <div class="app-modal-frame w-full max-w-lg p-6 md:p-7">
      <header class="grid gap-2">
        <h3 :id="dialogTitleId" class="text-base font-semibold text-slate-950 md:text-lg">
          {{ dialogTitle }}
        </h3>
        <p :id="dialogDescriptionId" class="text-sm text-slate-600">
          {{ dialogDescription }}
        </p>
        <p v-if="codeExpiresAtText !== ''" class="text-sm text-slate-500">
          {{ codeExpiresAtText }}
        </p>
      </header>

      <form v-if="step === 'code'" class="mt-5 grid gap-4" @submit.prevent="requestCodeValidation">
        <label class="field">
          <span>{{ translate('shared.accountPasswordChangeModal.labels.code') }}</span>
          <input
            ref="codeInputRef"
            :value="code"
            type="text"
            autocomplete="one-time-code"
            inputmode="numeric"
            minlength="6"
            maxlength="16"
            required
            :disabled="processing"
            @input="updateCode"
          >
        </label>

        <footer class="mt-1 flex flex-wrap justify-end gap-3">
          <button type="button" class="button-secondary" :disabled="processing" @click="closeDialog">
            {{ translate('shared.accountPasswordChangeModal.actions.cancel') }}
          </button>
          <button type="button" class="button-secondary" :disabled="processing" @click="requestCodeResend">
            {{ translate('shared.accountPasswordChangeModal.actions.resendCode') }}
          </button>
          <button type="submit" class="button-primary" :disabled="processing">
            {{
              processing
                ? translate('shared.accountPasswordChangeModal.actions.verifyCodeLoading')
                : translate('shared.accountPasswordChangeModal.actions.verifyCode')
            }}
          </button>
        </footer>
      </form>

      <form v-else class="mt-5 grid gap-4" @submit.prevent="requestPasswordUpdate">
        <label class="field">
          <span>{{ translate('shared.accountPasswordChangeModal.labels.newPassword') }}</span>
          <input
            ref="passwordInputRef"
            :value="password"
            type="password"
            autocomplete="new-password"
            minlength="8"
            maxlength="128"
            required
            :disabled="processing"
            @input="updatePassword"
          >
        </label>

        <label class="field">
          <span>{{ translate('shared.accountPasswordChangeModal.labels.confirmPassword') }}</span>
          <input
            :value="confirmPassword"
            type="password"
            autocomplete="new-password"
            minlength="8"
            maxlength="128"
            required
            :disabled="processing"
            @input="updateConfirmPassword"
          >
        </label>

        <footer class="mt-1 flex flex-wrap justify-end gap-3">
          <button type="button" class="button-secondary" :disabled="processing" @click="closeDialog">
            {{ translate('shared.accountPasswordChangeModal.actions.cancel') }}
          </button>
          <button type="submit" class="button-primary" :disabled="processing">
            {{
              processing
                ? translate('shared.accountPasswordChangeModal.actions.updatePasswordLoading')
                : translate('shared.accountPasswordChangeModal.actions.updatePassword')
            }}
          </button>
        </footer>
      </form>
    </div>
  </div>
</template>
