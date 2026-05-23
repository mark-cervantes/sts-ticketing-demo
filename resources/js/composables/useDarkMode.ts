import { ref, watch, onMounted } from 'vue'

const STORAGE_KEY = 'dark-mode'

const isDark = ref(false)

function applyClass(dark: boolean): void {
  if (dark) {
    document.documentElement.classList.add('dark')
  } else {
    document.documentElement.classList.remove('dark')
  }
}

function initFromStorage(): void {
  const stored = localStorage.getItem(STORAGE_KEY)
  if (stored !== null) {
    isDark.value = stored === 'true'
  } else {
    isDark.value = window.matchMedia('(prefers-color-scheme: dark)').matches
  }
  applyClass(isDark.value)
}

export function useDarkMode() {
  onMounted(() => {
    initFromStorage()
  })

  watch(isDark, (val) => {
    applyClass(val)
    localStorage.setItem(STORAGE_KEY, String(val))
  })

  function toggle(): void {
    isDark.value = !isDark.value
  }

  return {
    isDark,
    toggle,
  }
}
