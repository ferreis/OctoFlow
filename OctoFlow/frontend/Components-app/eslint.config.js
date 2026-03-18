import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'

export default [
  {
    ignores: ['dist/**', 'node_modules/**'],
  },
  js.configs.recommended,
  ...pluginVue.configs['flat/essential'],
  {
    rules: {
      'no-console': 'off',
      'no-undef': 'off',
      'no-useless-assignment': 'off',
      'no-unused-vars': 'warn',
    },
  },
]
