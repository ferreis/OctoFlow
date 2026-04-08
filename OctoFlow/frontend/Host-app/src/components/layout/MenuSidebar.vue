<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from '../../composables/useI18n'
import { resolveSafeAvatarUrl } from '../../utils/avatarUrl'

const props = defineProps({
  items: {
    type: Array,
    required: true,
  },
  activeKey: {
    type: String,
    required: true,
  },
  authenticated: {
    type: Boolean,
    default: false,
  },
  currentUser: {
    type: Object,
    default: null,
  },
  expanded: {
    type: Boolean,
    default: false,
  },
  compact: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['navigate', 'refresh', 'logout', 'expand', 'collapse'])
const { t } = useI18n()

const sidebarExpanded = computed(() => Boolean(props.expanded))
const openedGroupMap = ref({})
const avatarLoadFailed = ref(false)

function resolveTranslation(translationKey, fallbackText = '') {
  const normalizedKey = typeof translationKey === 'string' ? translationKey.trim() : ''
  const normalizedFallback = typeof fallbackText === 'string' ? fallbackText : ''

  if (normalizedKey === '') {
    return normalizedFallback
  }

  const translatedText = t(normalizedKey)
  if (translatedText === normalizedKey) {
    return normalizedFallback
  }

  return translatedText
}

function localizeNavigationItem(navigationItem) {
  if (!navigationItem || typeof navigationItem !== 'object') {
    return navigationItem
  }

  const localizedItem = {
    ...navigationItem,
    label: resolveTranslation(navigationItem.labelKey, navigationItem.label || ''),
    title: resolveTranslation(navigationItem.titleKey, navigationItem.title || ''),
    eyebrow: resolveTranslation(navigationItem.eyebrowKey, navigationItem.eyebrow || ''),
  }

  if (Array.isArray(navigationItem.children)) {
    localizedItem.children = navigationItem.children.map((childItem) => localizeNavigationItem(childItem))
  }

  return localizedItem
}

const localizedItems = computed(() => {
  return props.items.map((navigationItem) => localizeNavigationItem(navigationItem))
})

const mainItems = computed(() => {
  return localizedItems.value.filter((navigationItem) => navigationItem.key !== 'profile')
})

const profileItem = computed(() => {
  const localizedProfileItem = localizedItems.value.find((navigationItem) => navigationItem.key === 'profile')
  if (localizedProfileItem) {
    return localizedProfileItem
  }

  return {
    key: 'profile',
    label: resolveTranslation('navigation.items.profile.label', 'Perfil'),
  }
})

const profileSelected = computed(() => props.activeKey === profileItem.value.key)

const userLabel = computed(() => {
  const defaultEmail = typeof props.currentUser?.defaultEmail === 'string'
    ? props.currentUser.defaultEmail.trim()
    : ''

  const userEmail = typeof props.currentUser?.email === 'string'
    ? props.currentUser.email.trim()
    : ''

  return defaultEmail || userEmail || resolveTranslation('sidebar.unauthenticatedSession', 'Sessao nao autenticada')
})

const userInitial = computed(() => {
  return userLabel.value.slice(0, 1).toUpperCase() || 'U'
})

const userAvatarUrl = computed(() => {
  if (avatarLoadFailed.value) {
    return ''
  }

  return resolveSafeAvatarUrl(props.currentUser?.avatarUrl)
})

const navigationAriaLabel = computed(() => {
  return resolveTranslation('sidebar.navigationAriaLabel', 'Navegação principal')
})

const sidebarSubtitle = computed(() => {
  return resolveTranslation('sidebar.appSubtitle', 'Painel interno')
})

const profileAvatarAltText = computed(() => {
  return resolveTranslation('sidebar.profileAvatarAlt', 'Avatar do usuário')
})

const logoutExpandedLabel = computed(() => {
  return resolveTranslation('sidebar.logout', 'Sair')
})

const logoutCompactLabel = computed(() => {
  return resolveTranslation('sidebar.logoutCompact', 'S')
})

watch(
  () => localizedItems.value,
  () => {
    const nextGroupMap = {}

    for (const navigationItem of localizedItems.value) {
      if (isGroupItem(navigationItem)) {
        nextGroupMap[navigationItem.key] = Boolean(openedGroupMap.value[navigationItem.key])
      }
    }

    openedGroupMap.value = nextGroupMap
  },
  {
    immediate: true,
    deep: false,
  },
)

watch(
  () => props.currentUser?.avatarUrl,
  () => {
    avatarLoadFailed.value = false
  },
)

function navigate(menuKey) {
  if (!props.authenticated) {
    return
  }

  emit('navigate', menuKey)
}

function isGroupItem(navigationItem) {
  return navigationItem?.type === 'group' && Array.isArray(navigationItem.children) && navigationItem.children.length > 0
}

function isGroupActive(navigationItem) {
  if (!isGroupItem(navigationItem)) {
    return false
  }

  return navigationItem.children.some((childItem) => childItem?.key === props.activeKey)
}

function navigateGroup(navigationItem) {
  if (!props.authenticated || !isGroupItem(navigationItem)) {
    return
  }

  openedGroupMap.value = {
    ...openedGroupMap.value,
    [navigationItem.key]: !isGroupOpen(navigationItem),
  }
}

function isGroupOpen(navigationItem) {
  if (!isGroupItem(navigationItem)) {
    return false
  }

  return Boolean(openedGroupMap.value[navigationItem.key])
}

function resolveGroupContainerId(navigationItem) {
  return `menu-group-${String(navigationItem?.key || '')}`
}

function resolveNavigationAriaCurrent(navigationItemKey) {
  return navigationItemKey === props.activeKey ? 'page' : undefined
}

function resolveGroupToggleAriaLabel(navigationItem) {
  const itemLabel = typeof navigationItem?.label === 'string' ? navigationItem.label : ''

  if (isGroupOpen(navigationItem)) {
    return resolveTranslation('sidebar.collapseGroup', `Recolher ${itemLabel}`)
  }

  return resolveTranslation('sidebar.expandGroup', `Expandir ${itemLabel}`)
}

function openProfile() {
  navigate(profileItem.value.key)
}

function handleAvatarError() {
  avatarLoadFailed.value = true
}

function handleMouseEnter() {
  if (props.compact) {
    return
  }

  emit('expand')
}

function handleMouseLeave() {
  if (props.compact) {
    return
  }

  emit('collapse')
}
</script>

<template>
  <aside
    class="menu-sidebar sticky top-0 flex h-screen max-h-screen flex-col backdrop-blur transition-[width,padding] duration-200"
    :class="sidebarExpanded ? 'w-[272px] px-3 py-4' : 'w-[84px] px-2 py-4'"
    @mouseenter="handleMouseEnter"
    @mouseleave="handleMouseLeave"
  >
    <div class="menu-sidebar-panel rounded-2xl" :class="sidebarExpanded ? 'p-3' : 'p-2'">
      <div class="flex items-center" :class="sidebarExpanded ? 'gap-3' : 'justify-center'">
        <img src="/icon.ico" alt="OctoFlow" class="menu-logo h-11 w-11 shrink-0 rounded-xl object-cover p-1">

        <div
          class="min-w-0 overflow-hidden transition-[max-width,opacity,transform] duration-200"
          :class="sidebarExpanded ? 'max-w-[160px] opacity-100 translate-x-0' : 'max-w-0 opacity-0 -translate-x-2'"
        >
          <h1 class="menu-title truncate whitespace-nowrap text-lg font-semibold">
            OctoFlow
          </h1>
          <p class="menu-subtitle truncate whitespace-nowrap text-xs">
            {{ sidebarSubtitle }}
          </p>
        </div>
      </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto pt-4 [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden">
      <nav class="flex flex-col gap-2" :aria-label="navigationAriaLabel">
        <template v-for="navigationItem in mainItems" :key="navigationItem.key">
          <section v-if="isGroupItem(navigationItem)" class="menu-nav-group">
            <button
              type="button"
              :disabled="!props.authenticated"
              :title="navigationItem.label"
              :aria-expanded="isGroupOpen(navigationItem)"
              :aria-controls="resolveGroupContainerId(navigationItem)"
              :aria-label="resolveGroupToggleAriaLabel(navigationItem)"
              @click="navigateGroup(navigationItem)"
              class="menu-nav-item group rounded-2xl border transition-all duration-200 disabled:cursor-not-allowed disabled:opacity-45"
              :class="[
                sidebarExpanded
                  ? 'flex w-full items-center gap-3 px-3 py-3 text-left'
                  : 'flex h-14 w-full items-center justify-center px-0 py-0',
                isGroupActive(navigationItem)
                  ? 'menu-nav-item-active'
                  : 'menu-nav-item-idle',
              ]"
            >
              <span
                class="menu-nav-icon inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl font-black"
                :class="isGroupActive(navigationItem) ? 'menu-nav-icon-active' : 'menu-nav-icon-idle'"
              >
                {{ navigationItem.short }}
              </span>

              <div
                class="min-w-0 overflow-hidden transition-[max-width,opacity,transform] duration-200"
                :class="sidebarExpanded ? 'max-w-[170px] opacity-100 translate-x-0' : 'max-w-0 opacity-0 -translate-x-2'"
              >
                <strong class="menu-nav-label block truncate whitespace-nowrap text-sm font-semibold">
                  {{ navigationItem.label }}
                </strong>
              </div>

              <span
                v-if="sidebarExpanded"
                class="menu-group-toggle-indicator ml-auto text-sm font-black"
                :class="isGroupOpen(navigationItem) ? 'is-open' : ''"
                aria-hidden="true"
              >
                v
              </span>
            </button>

            <div
              v-if="sidebarExpanded && isGroupOpen(navigationItem)"
              :id="resolveGroupContainerId(navigationItem)"
              class="menu-nav-group-children"
            >
              <button
                v-for="childItem in navigationItem.children"
                :key="childItem.key"
                type="button"
                :disabled="!props.authenticated"
                :title="childItem.label"
                :aria-current="resolveNavigationAriaCurrent(childItem.key)"
                @click="navigate(childItem.key)"
                class="menu-nav-child w-full rounded-xl border px-3 py-2 text-left text-sm font-semibold transition-all duration-200 disabled:cursor-not-allowed disabled:opacity-45"
                :class="childItem.key === props.activeKey ? 'menu-nav-child-active' : 'menu-nav-child-idle'"
              >
                <span class="menu-nav-child-label">{{ childItem.label }}</span>
              </button>
            </div>
          </section>

          <button
            v-else
            type="button"
            :disabled="!props.authenticated"
            :title="navigationItem.label"
            :aria-current="resolveNavigationAriaCurrent(navigationItem.key)"
            @click="navigate(navigationItem.key)"
            class="menu-nav-item group rounded-2xl border transition-all duration-200 disabled:cursor-not-allowed disabled:opacity-45"
            :class="[
              sidebarExpanded
                ? 'flex w-full items-center gap-3 px-3 py-3 text-left'
                : 'flex h-14 w-full items-center justify-center px-0 py-0',
              navigationItem.key === props.activeKey
                ? 'menu-nav-item-active'
                : 'menu-nav-item-idle',
            ]"
          >
            <span
              class="menu-nav-icon inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl font-black"
              :class="navigationItem.key === props.activeKey ? 'menu-nav-icon-active' : 'menu-nav-icon-idle'"
            >
              {{ navigationItem.short }}
            </span>

            <div
              class="min-w-0 overflow-hidden transition-[max-width,opacity,transform] duration-200"
              :class="sidebarExpanded ? 'max-w-[170px] opacity-100 translate-x-0' : 'max-w-0 opacity-0 -translate-x-2'"
            >
              <strong class="menu-nav-label block truncate whitespace-nowrap text-sm font-semibold">
                {{ navigationItem.label }}
              </strong>
            </div>
          </button>
        </template>
      </nav>
    </div>

    <div class="menu-sidebar-panel mt-4 rounded-2xl" :class="sidebarExpanded ? 'p-3' : 'p-2'">
      <button
        type="button"
        :disabled="!props.authenticated"
        :title="profileItem.label"
        :aria-current="profileSelected ? 'page' : undefined"
        @click="openProfile"
        class="menu-profile-button w-full rounded-2xl transition-all duration-200 disabled:cursor-not-allowed disabled:opacity-45"
        :class="[
          sidebarExpanded
            ? 'flex items-center gap-3 px-2 py-2 text-left'
            : 'flex h-14 items-center justify-center',
          profileSelected
            ? 'menu-profile-button-active'
            : 'menu-profile-button-idle',
        ]"
      >
        <span class="menu-profile-avatar inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-sm font-black">
          <img
            v-if="userAvatarUrl"
            :src="userAvatarUrl"
            :alt="profileAvatarAltText"
            class="menu-profile-avatar-image"
            @error="handleAvatarError"
          >
          <span v-else>{{ userInitial }}</span>
        </span>

        <div
          class="min-w-0 overflow-hidden transition-[max-width,opacity,transform] duration-200"
          :class="sidebarExpanded ? 'max-w-[160px] opacity-100 translate-x-0' : 'max-w-0 opacity-0 -translate-x-2'"
        >
          <p class="menu-profile-kicker truncate whitespace-nowrap text-[11px] font-black uppercase tracking-[0.18em]">
            {{ profileItem.label }}
          </p>
          <strong class="menu-profile-user block truncate whitespace-nowrap text-sm font-semibold">
            {{ userLabel }}
          </strong>
        </div>
      </button>

      <button
        type="button"
        :disabled="!props.authenticated"
        :title="sidebarExpanded ? '' : logoutExpandedLabel"
        @click.stop="$emit('logout')"
        class="menu-logout mt-2 rounded-2xl border font-semibold transition disabled:cursor-not-allowed disabled:opacity-50"
        :class="sidebarExpanded ? 'h-11 w-full px-4' : 'h-12 w-full px-0'"
      >
        {{ sidebarExpanded ? logoutExpandedLabel : logoutCompactLabel }}
      </button>
    </div>
  </aside>
</template>
