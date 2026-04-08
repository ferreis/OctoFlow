<script setup>
import { computed } from 'vue'
import { useI18n } from '../../composables/useI18n'

const currentYear = new Date().getFullYear()
const supportEmailAddress = 'ferreis@univali.br'

const { translate } = useI18n()

const footerDescription = computed(() => {
  return translate('footer.about')
})

const footerCopyright = computed(() => {
  return translate('footer.copyright', { year: currentYear })
})

const footerRightsReserved = computed(() => {
  return translate('footer.rightsReserved')
})

const footerContactLabel = computed(() => {
  return translate('footer.contactLabel')
})
</script>

<template>
  <footer class="app-footer">
    <p class="app-footer-description">
      {{ footerDescription }}
    </p>

    <div class="app-footer-meta">
      <p class="app-footer-copy">
        {{ footerCopyright }}
        <br>
        {{ footerRightsReserved }}
        <br>
        {{ footerContactLabel }}
        <a :href="`mailto:${supportEmailAddress}`" class="app-footer-contact-link">
          {{ supportEmailAddress }}
        </a>
      </p>
    </div>
  </footer>
</template>
