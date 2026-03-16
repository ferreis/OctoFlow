import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import federation from '@originjs/vite-plugin-federation'

// https://vite.dev/config/
export default defineConfig({
  base: '/ModFederation-mf/',
  plugins: [
    vue(),
    federation({
      name: 'my_vue_mf',
      filename: 'remoteEntry.js',
      exposes: {
        './TaskCrudPanel': './src/components/TaskCrudPanel.vue',
        './GithubWorkspacePanel': './src/components/GithubWorkspacePanel.vue'
      },
      shared: ['vue']
    })
  ],
  server: {
    port: 5175,
    strictPort: false,
    cors: true
  },
  preview: {
    port: 5175,
    cors: true
  },
  build: {
    target: 'esnext',
    minify: false,
    cssCodeSplit: false
  }
})
