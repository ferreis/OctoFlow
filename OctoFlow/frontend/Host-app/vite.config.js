import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import federation from '@originjs/vite-plugin-federation'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const fallbackRemoteUrl = env.VITE_REMOTE_APP_URL || 'http://localhost:5175/OctoFlow-mf/assets/remoteEntry.js'
  const apiProxyTarget = env.VITE_API_PROXY_TARGET || 'https://nginx:443'
  const remoteExternal = `Promise.resolve((globalThis.location && globalThis.location.protocol === 'https:') ? globalThis.location.origin + '/OctoFlow-mf/assets/remoteEntry.js' : '${fallbackRemoteUrl}')`

  return {
    base: '/OctoFlow/',
    plugins: [
      vue(),
      tailwindcss(),
      federation({
        name: 'octoflow_host',
        remotes: {
          remoteApp: {
            external: remoteExternal,
            externalType: 'promise',
            from: 'vite',
            format: 'esm',
          },
        },
        shared: ['vue'],
      }),
    ],
    server: {
      port: 5173,
      strictPort: false,
      proxy: {
        '/OctoFlow/api': {
          target: apiProxyTarget,
          changeOrigin: true,
          secure: false,
        },
      },
    },
    preview: {
      port: 5173,
    },
    build: {
      target: 'esnext',
      minify: false,
      cssCodeSplit: false,
    },
  }
})
