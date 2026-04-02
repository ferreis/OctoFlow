import { useSessionStore } from '../stores/sessionStore'

function requestWithSession(config) {
  const sessionStore = useSessionStore()
  return sessionStore.authRequest(config)
}

export function uploadProfileAvatar(avatarFile) {
  const formData = new FormData()
  formData.append('avatar', avatarFile)

  return requestWithSession({
    url: '/auth/profile/avatar',
    method: 'POST',
    csrfActionId: 'auth.profile.avatar.upload',
    data: formData,
  })
}

export function removeProfileAvatar() {
  return requestWithSession({
    url: '/auth/profile/avatar',
    method: 'DELETE',
    csrfActionId: 'auth.profile.avatar.delete',
  })
}
