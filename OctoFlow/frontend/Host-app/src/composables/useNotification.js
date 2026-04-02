import { useSessionStore } from '../stores/sessionStore'

export function useNotification(notify) {
  const sessionStore = useSessionStore()

  function resolveNotifier() {
    if (typeof notify === 'function') {
      return notify
    }

    return sessionStore.showNotification
  }

  function notifyUser(payload, type = 'info') {
    const resolvedNotify = resolveNotifier()
    if (typeof resolvedNotify !== 'function') {
      return
    }

    if (typeof payload === 'string') {
      const normalizedMessage = payload.trim()
      if (normalizedMessage === '') {
        return
      }

      resolvedNotify({
        message: normalizedMessage,
        type,
      })
      return
    }

    const normalizedMessage = String(payload?.message || '').trim()
    if (normalizedMessage === '') {
      return
    }

    resolvedNotify({
      ...payload,
      message: normalizedMessage,
      type: typeof payload?.type === 'string' && payload.type.trim() !== ''
        ? payload.type.trim()
        : type,
    })
  }

  return {
    notifyUser,
  }
}
