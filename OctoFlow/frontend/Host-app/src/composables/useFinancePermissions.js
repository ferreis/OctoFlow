import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useSessionStore } from '../stores/sessionStore'

const NON_RESTRICTIVE_ROLE_ALIASES = new Set(['user'])

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
  const normalizedRoleAliases = computed(() => normalizedRoles.value
    .map((normalizedRole) => (
      normalizedRole.startsWith('role_')
        ? normalizedRole.slice(5)
        : normalizedRole
    ))
    .filter((normalizedRoleAlias) => normalizedRoleAlias !== ''))

  const hasPermissionModel = computed(() => (
    normalizedPermissions.value.length > 0
    || normalizedRoleAliases.value.some((normalizedRoleAlias) => !NON_RESTRICTIVE_ROLE_ALIASES.has(normalizedRoleAlias))
  ))

  function hasPermission(permissionCode) {
    const normalizedPermissionCode = String(permissionCode || '').trim().toLowerCase()
    if (normalizedPermissionCode === '') {
      return false
    }

    if (!hasPermissionModel.value) {
      return true
    }

    const normalizedRoleAliasSet = new Set(normalizedRoleAliases.value)

    return normalizedPermissions.value.includes(normalizedPermissionCode)
      || normalizedPermissions.value.includes('finance:*')
      || normalizedPermissions.value.includes('*')
      || normalizedRoleAliasSet.has('admin')
      || normalizedRoleAliasSet.has('finance-admin')
      || normalizedRoleAliasSet.has('finance_admin')
      || normalizedRoleAliasSet.has('finance')
  }

  const canReadFinance = computed(() => hasPermission('finance:read'))
  const canWriteFinance = computed(() => hasPermission('finance:write'))

  return {
    canReadFinance,
    canWriteFinance,
    hasPermission,
  }
}
