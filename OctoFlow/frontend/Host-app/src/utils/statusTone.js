const BADGE_TONE_CLASS_BY_KEY = {
  info: 'app-status-badge--info',
  success: 'app-status-badge--success',
  warning: 'app-status-badge--warning',
  danger: 'app-status-badge--danger',
  neutral: 'app-status-badge--neutral',
}

export function resolveBadgeToneClass(toneKey) {
  const normalizedToneKey = String(toneKey || '').trim().toLowerCase()
  return BADGE_TONE_CLASS_BY_KEY[normalizedToneKey] || BADGE_TONE_CLASS_BY_KEY.neutral
}

export function resolveHistoryBadgeToneClass(historyEventKind) {
  const normalizedEventKind = String(historyEventKind || '').trim().toLowerCase()

  if (normalizedEventKind === 'created') {
    return resolveBadgeToneClass('info')
  }

  if (normalizedEventKind === 'updated' || normalizedEventKind === 'pending') {
    return resolveBadgeToneClass('warning')
  }

  if (normalizedEventKind === 'closed' || normalizedEventKind === 'failed') {
    return resolveBadgeToneClass('danger')
  }

  if (normalizedEventKind === 'synced') {
    return resolveBadgeToneClass('success')
  }

  return resolveBadgeToneClass('neutral')
}

export function resolveTaskEntryBadgeToneClass(entryType, issueState, localSyncState) {
  const normalizedEntryType = String(entryType || '').trim().toLowerCase()
  const normalizedIssueState = String(issueState || '').trim().toUpperCase()
  const normalizedLocalSyncState = String(localSyncState || '').trim().toUpperCase()

  if (normalizedEntryType === 'github') {
    return normalizedIssueState === 'CLOSED'
      ? resolveBadgeToneClass('danger')
      : resolveBadgeToneClass('success')
  }

  return normalizedLocalSyncState === 'FAILED'
    ? resolveBadgeToneClass('danger')
    : resolveBadgeToneClass('warning')
}

export function resolveNotificationToneClasses(notificationType) {
  const normalizedNotificationType = String(notificationType || '').trim().toLowerCase()

  if (normalizedNotificationType === 'success') {
    return {
      frame: 'app-notification-frame--success',
      badge: 'app-notification-badge--success',
      title: 'Sucesso',
    }
  }

  if (normalizedNotificationType === 'error') {
    return {
      frame: 'app-notification-frame--danger',
      badge: 'app-notification-badge--danger',
      title: 'Erro',
    }
  }

  if (normalizedNotificationType === 'warning') {
    return {
      frame: 'app-notification-frame--warning',
      badge: 'app-notification-badge--warning',
      title: 'Aviso',
    }
  }

  return {
    frame: 'app-notification-frame--info',
    badge: 'app-notification-badge--info',
    title: 'Informação',
  }
}
