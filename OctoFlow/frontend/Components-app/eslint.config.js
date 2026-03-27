import js from '@eslint/js'
import globals from 'globals'
import pluginVue from 'eslint-plugin-vue'

export default [
  {
    ignores: ['dist/**', 'node_modules/**'],
  },
  js.configs.recommended,
  ...pluginVue.configs['flat/essential'],
  {
    languageOptions: {
      globals: {
        ...globals.browser,
        ...globals.node,
      },
    },
    rules: {
      'no-console': 'off',
      'no-unused-vars': 'warn',
      'no-alert': 'error',
      'no-restricted-properties': [
        'error',
        {
          object: 'window',
          property: 'alert',
          message: 'Use notificação do app em vez de window.alert.',
        },
        {
          object: 'window',
          property: 'confirm',
          message: 'Use modal de confirmação do app em vez de window.confirm.',
        },
        {
          object: 'window',
          property: 'prompt',
          message: 'Use formulário/modal do app em vez de window.prompt.',
        },
      ],
    },
  },
]
