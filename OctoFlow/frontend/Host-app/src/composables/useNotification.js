export function useNotification(notify) {
  function notifyUser(payload, type = 'info') {
    if (typeof notify !== 'function') {
      return
    }

    if (typeof payload === 'string') {
      const normalizedMessage = payload.trim()
      if (normalizedMessage === '') {
        return
      }

      notify({
        message: normalizedMessage,
        type,
      })
      return
    }

    const normalizedMessage = String(payload?.message || '').trim()
    if (normalizedMessage === '') {
      return
    }

    notify({
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
