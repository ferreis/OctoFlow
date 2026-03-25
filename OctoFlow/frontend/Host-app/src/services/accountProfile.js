export function uploadProfileAvatar(request, avatarFile) {
  const formData = new FormData()
  formData.append('avatar', avatarFile)

  return request({
    url: '/auth/profile/avatar',
    method: 'POST',
    csrfActionId: 'auth.profile.avatar.upload',
    data: formData,
  })
}

export function removeProfileAvatar(request) {
  return request({
    url: '/auth/profile/avatar',
    method: 'DELETE',
    csrfActionId: 'auth.profile.avatar.delete',
  })
}
