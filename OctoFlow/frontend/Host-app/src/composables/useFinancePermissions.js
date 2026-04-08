import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useSessionStore } from '../stores/sessionStore'

function normalizeStringCollection(rawCollection) {
  if (!Array.isArray(rawCollection)) {
    return []
  }

  return rawCollection
    .map((rawValue) => String(rawValue || '').trim().toLowerCase())
    .filter((normalizedValue) => normalizedValue !== '')
}

export function useFinancePermissions() {
  const sessionStore = useSessionStore()
  const { currentUser } = storeToRefs(sessionStore)

  const normalizedPermissions = computed(() => normalizeStringCollection(currentUser.value?.permissions))
  const normalizedRoles = computed(() => normalizeStringCollection(currentUser.value?.roles))

  const hasPermissionModel = computed(() => (
    normalizedPermissions.value.length > 0
    || normalizedRoles.value.length > 0
  ))

  function hasPermission(permissionCode) {
    const normalizedPermissionCode = String(permissionCode || '').trim().toLowerCase()
    if (normalizedPermissionCode === '') {
      return false
    }

    if (!hasPermissionModel.value) {
      return true
    }

    return normalizedPermissions.value.includes(normalizedPermissionCode)
      || normalizedPermissions.value.includes('finance:*')
      || normalizedPermissions.value.includes('*')
      || normalizedRoles.value.includes('admin')
      || normalizedRoles.value.includes('finance-admin')
      || normalizedRoles.value.includes('finance')
  }

  const canReadFinance = computed(() => hasPermission('finance:read'))
  const canWriteFinance = computed(() => hasPermission('finance:write'))

  return {
    canReadFinance,
    canWriteFinance,
    hasPermission,
  }
}
