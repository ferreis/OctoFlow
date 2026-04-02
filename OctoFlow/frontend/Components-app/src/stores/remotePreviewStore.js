import { defineStore } from 'pinia'

export const useRemotePreviewStore = defineStore('remotePreview', {
  state: () => ({
    title: 'OctoFlow Components',
    description: 'Este projeto expoe componentes remotos para o host do OctoFlow.',
    details: 'O host monta apenas os blocos que a sessao atual pode carregar.',
  }),
})
