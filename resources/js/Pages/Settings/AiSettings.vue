<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Separator } from '@/components/ui/separator'
import {
  BrainCircuitIcon,
  CheckCircle2Icon,
  XCircleIcon,
  EyeIcon,
  EyeOffIcon,
  LoaderIcon,
  ChevronDownIcon,
  SearchIcon,
  ZapIcon,
} from '@lucide/vue'
import { toast } from 'vue-sonner'
import { apiFetch, getCsrfToken } from '@/composables/useApiFetch'

defineOptions({ layout: AppLayout })

// ─────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────

interface AiSettingsData {
  provider: 'rules' | 'openrouter' | 'custom'
  base_url: string | null
  api_key_set: boolean
  api_key_masked: string | null
  model: string | null
  updated_by: { id: number; name: string } | null
  updated_at: string | null
}

interface OpenRouterModel {
  id: string
  name: string
  context_length: number
  pricing: {
    prompt: string
    completion: string
  }
}

interface TestResult {
  success: boolean
  summary?: string
  suggested_next_action?: string
  error?: string
}

// ─────────────────────────────────────────────────────────────
// Popular models curated list
// ─────────────────────────────────────────────────────────────

const POPULAR_MODELS = [
  'google/gemini-2.5-flash',
  'google/gemini-2.5-pro',
  'anthropic/claude-sonnet-4',
  'anthropic/claude-haiku-4',
  'openai/gpt-4.1-mini',
  'openai/gpt-4.1-nano',
  'meta-llama/llama-4-maverick',
  'deepseek/deepseek-chat-v3-0324',
  'deepseek/deepseek-r1',
  'qwen/qwen3-30b-a3b',
  'mistralai/mistral-small-3.2',
  'google/gemma-3-27b-it',
]

// ─────────────────────────────────────────────────────────────
// State
// ─────────────────────────────────────────────────────────────

const loading = ref(true)
const saving = ref(false)
const testing = ref(false)
const showApiKey = ref(false)
const newApiKey = ref('')
const showAllModels = ref(false)
const showFreeOnly = ref(false)
const modelSearch = ref('')
const modelDropdownOpen = ref(false)

const currentSettings = ref<AiSettingsData | null>(null)

// Form state
const selectedProvider = ref<'rules' | 'openrouter' | 'custom'>('rules')
const selectedBaseUrl = ref('')
const selectedModel = ref('')

// Models list from OpenRouter
const openRouterModels = ref<OpenRouterModel[]>([])
const modelsLoading = ref(false)

// Test result
const testResult = ref<TestResult | null>(null)

// ─────────────────────────────────────────────────────────────
// Computed
// ─────────────────────────────────────────────────────────────

const showConfigPanel = computed(() => selectedProvider.value !== 'rules')

const showBaseUrlField = computed(
  () => selectedProvider.value === 'custom',
)

const showModelTextInput = computed(() => selectedProvider.value === 'custom')

const filteredModels = computed(() => {
  let models = openRouterModels.value

  if (!showAllModels.value) {
    models = models.filter((m) => POPULAR_MODELS.includes(m.id))
  }

  if (showFreeOnly.value) {
    models = models.filter((m) => m.pricing?.prompt === '0')
  }

  if (modelSearch.value.trim()) {
    const q = modelSearch.value.toLowerCase()
    models = models.filter(
      (m) => m.id.toLowerCase().includes(q) || m.name.toLowerCase().includes(q),
    )
  }

  return models
})

const selectedModelLabel = computed(() => {
  if (!selectedModel.value) return 'Select a model…'
  const found = openRouterModels.value.find((m) => m.id === selectedModel.value)
  return found ? found.name : selectedModel.value
})

const lastUpdatedText = computed(() => {
  if (!currentSettings.value?.updated_by || !currentSettings.value?.updated_at) return null
  const date = new Date(currentSettings.value.updated_at).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
  return `Last updated by ${currentSettings.value.updated_by.name} on ${date}`
})

// ─────────────────────────────────────────────────────────────
// Lifecycle
// ─────────────────────────────────────────────────────────────

onMounted(() => {
  void loadSettings()
})

// ─────────────────────────────────────────────────────────────
// Functions
// ─────────────────────────────────────────────────────────────

async function loadSettings(): Promise<void> {
  loading.value = true
  try {
    const resp = await apiFetch<{ data: AiSettingsData }>('/api/settings/ai')
    currentSettings.value = resp.data
    selectedProvider.value = resp.data.provider
    selectedBaseUrl.value = resp.data.base_url ?? ''
    selectedModel.value = resp.data.model ?? ''

    if (resp.data.provider === 'openrouter') {
      void loadOpenRouterModels()
    }
  } catch {
    toast.error('Failed to load AI settings')
  } finally {
    loading.value = false
  }
}

async function loadOpenRouterModels(): Promise<void> {
  if (modelsLoading.value) return
  modelsLoading.value = true
  try {
    const resp = await apiFetch<{ data: OpenRouterModel[] }>('/api/settings/ai/models')
    // OpenRouter returns { data: [...] }
    if (Array.isArray(resp.data)) {
      openRouterModels.value = resp.data
    }
  } catch {
    // Non-fatal: user may not have a key yet
    openRouterModels.value = []
  } finally {
    modelsLoading.value = false
  }
}

function onProviderChange(value: string | number | bigint | Record<string, unknown> | null): void {
  const provider = value as 'rules' | 'openrouter' | 'custom'
  selectedProvider.value = provider

  if (provider === 'openrouter') {
    selectedBaseUrl.value = 'https://openrouter.ai/api/v1'
    void loadOpenRouterModels()
  } else if (provider === 'custom') {
    selectedBaseUrl.value = ''
  } else {
    selectedBaseUrl.value = ''
    selectedModel.value = ''
  }

  testResult.value = null
}

function selectModel(modelId: string): void {
  selectedModel.value = modelId
  modelDropdownOpen.value = false
  modelSearch.value = ''
}

async function save(): Promise<void> {
  if (saving.value) return
  saving.value = true
  testResult.value = null

  try {
    const body: Record<string, unknown> = {
      provider: selectedProvider.value,
    }

    if (selectedProvider.value !== 'rules') {
      if (selectedBaseUrl.value) body.base_url = selectedBaseUrl.value
      if (selectedModel.value) body.model = selectedModel.value
      if (newApiKey.value.trim()) body.api_key = newApiKey.value.trim()
    }

    const res = await fetch('/api/settings/ai', {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    })

    if (!res.ok) {
      const err = (await res.json()) as { message?: string }
      toast.error(err.message ?? 'Failed to save settings')
      return
    }

    const saved = (await res.json()) as { data: AiSettingsData }
    currentSettings.value = saved.data
    newApiKey.value = ''
    toast.success('AI settings saved')
  } catch {
    toast.error('Failed to save settings')
  } finally {
    saving.value = false
  }
}

async function testConnection(): Promise<void> {
  if (testing.value) return
  testing.value = true
  testResult.value = null

  try {
    const body: Record<string, unknown> = {
      provider: selectedProvider.value,
    }

    if (selectedProvider.value !== 'rules') {
      if (selectedBaseUrl.value) body.base_url = selectedBaseUrl.value
      if (selectedModel.value) body.model = selectedModel.value
      if (newApiKey.value.trim()) body.api_key = newApiKey.value.trim()
    }

    const res = await fetch('/api/settings/ai/test', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    })

    const data = (await res.json()) as { summary?: string; suggested_next_action?: string; error?: string }

    if (!res.ok || data.error) {
      testResult.value = { success: false, error: data.error ?? 'Connection test failed' }
      return
    }

    testResult.value = {
      success: true,
      summary: data.summary,
      suggested_next_action: data.suggested_next_action,
    }
  } catch (err) {
    testResult.value = { success: false, error: String(err) }
  } finally {
    testing.value = false
  }
}

function formatPrice(price: string): string {
  if (price === '0') return 'Free'
  const n = parseFloat(price)
  if (isNaN(n)) return price
  // Price is per token, display per million tokens
  return `$${(n * 1_000_000).toFixed(2)}/M`
}

function formatContext(length: number): string {
  if (length >= 1_000_000) return `${(length / 1_000_000).toFixed(0)}M ctx`
  if (length >= 1_000) return `${(length / 1_000).toFixed(0)}K ctx`
  return `${length} ctx`
}
</script>

<template>
  <div class="mx-auto max-w-3xl px-4 py-8">
    <!-- Page header -->
    <div class="mb-6 flex items-center gap-3">
      <div class="flex size-10 items-center justify-center rounded-lg bg-primary/10">
        <BrainCircuitIcon class="size-5 text-primary" />
      </div>
      <div>
        <h1 class="text-xl font-semibold text-foreground">AI Settings</h1>
        <p class="text-sm text-muted-foreground">Configure the AI provider used for issue summaries</p>
      </div>
    </div>

    <!-- Loading skeleton -->
    <div v-if="loading" class="space-y-4">
      <Skeleton class="h-40 w-full rounded-xl" />
      <Skeleton class="h-32 w-full rounded-xl" />
    </div>

    <template v-else>
      <!-- Current status card -->
      <Card class="mb-4">
        <CardHeader>
          <div class="flex items-center justify-between py-3 px-1">
            <div class="space-y-1">
              <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Active Configuration</p>
              <div class="flex items-center gap-2">
                <Badge variant="secondary" class="capitalize">
                  {{ currentSettings?.provider ?? '—' }}
                </Badge>
                <span v-if="currentSettings?.model" class="text-sm text-muted-foreground font-mono">
                  {{ currentSettings.model }}
                </span>
              </div>
              <p v-if="lastUpdatedText" class="text-xs text-muted-foreground">
                {{ lastUpdatedText }}
              </p>
            </div>
          </div>
        </CardHeader>
      </Card>

      <!-- Provider selection card -->
      <Card class="mb-4">
        <CardHeader>
          <h2 class="pt-3 px-1 text-sm font-semibold text-foreground">Provider</h2>
        </CardHeader>
        <CardContent>
          <RadioGroup
            :model-value="selectedProvider"
            class="space-y-3"
            @update:model-value="onProviderChange"
          >
            <!-- Rules Engine -->
            <label
              for="provider-rules"
              class="flex cursor-pointer items-start gap-3 rounded-lg border border-border p-4 transition-colors hover:bg-muted/40"
              :class="{ 'border-primary bg-primary/5': selectedProvider === 'rules' }"
            >
              <RadioGroupItem id="provider-rules" value="rules" class="mt-0.5" />
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <span class="text-sm font-medium text-foreground">Rules Engine</span>
                  <Badge variant="outline" class="text-xs">No key needed</Badge>
                </div>
                <p class="mt-0.5 text-xs text-muted-foreground">
                  Deterministic summaries using category/priority rules. No API key needed.
                </p>
              </div>
            </label>

            <!-- OpenRouter -->
            <label
              for="provider-openrouter"
              class="flex cursor-pointer items-start gap-3 rounded-lg border border-border p-4 transition-colors hover:bg-muted/40"
              :class="{ 'border-primary bg-primary/5': selectedProvider === 'openrouter' }"
            >
              <RadioGroupItem id="provider-openrouter" value="openrouter" class="mt-0.5" />
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <span class="text-sm font-medium text-foreground">OpenRouter</span>
                  <Badge variant="outline" class="text-xs">300+ models</Badge>
                </div>
                <p class="mt-0.5 text-xs text-muted-foreground">
                  Access 300+ AI models via OpenRouter. Requires API key.
                </p>
              </div>
            </label>

            <!-- Custom / Ollama -->
            <label
              for="provider-custom"
              class="flex cursor-pointer items-start gap-3 rounded-lg border border-border p-4 transition-colors hover:bg-muted/40"
              :class="{ 'border-primary bg-primary/5': selectedProvider === 'custom' }"
            >
              <RadioGroupItem id="provider-custom" value="custom" class="mt-0.5" />
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <span class="text-sm font-medium text-foreground">Custom / Ollama</span>
                  <Badge variant="outline" class="text-xs">Self-hosted</Badge>
                </div>
                <p class="mt-0.5 text-xs text-muted-foreground">
                  Connect to any OpenAI-compatible API endpoint.
                </p>
              </div>
            </label>
          </RadioGroup>
        </CardContent>
      </Card>

      <!-- Configuration panel (hidden for Rules) -->
      <Card v-if="showConfigPanel" class="mb-4">
        <CardHeader>
          <h2 class="pt-3 px-1 text-sm font-semibold text-foreground">Configuration</h2>
        </CardHeader>
        <CardContent class="space-y-5">
          <!-- API Key -->
          <div class="space-y-1.5">
            <Label for="api-key" class="text-sm font-medium">
              API Key
              <span v-if="currentSettings?.api_key_set" class="ml-1 text-xs font-normal text-muted-foreground">(currently set)</span>
            </Label>
            <div class="relative">
              <Input
                id="api-key"
                v-model="newApiKey"
                :type="showApiKey ? 'text' : 'password'"
                :placeholder="currentSettings?.api_key_masked ?? 'Enter API key…'"
                class="pr-10 font-mono text-sm"
                autocomplete="off"
              />
              <button
                type="button"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground transition-colors hover:text-foreground"
                :aria-label="showApiKey ? 'Hide API key' : 'Show API key'"
                @click="showApiKey = !showApiKey"
              >
                <EyeOffIcon v-if="showApiKey" class="size-4" />
                <EyeIcon v-else class="size-4" />
              </button>
            </div>
            <p class="text-xs text-muted-foreground">
              Leave blank to keep the existing key.
            </p>
          </div>

          <!-- Base URL (custom provider only) -->
          <div v-if="showBaseUrlField" class="space-y-1.5">
            <Label for="base-url" class="text-sm font-medium">Base URL</Label>
            <Input
              id="base-url"
              v-model="selectedBaseUrl"
              type="url"
              placeholder="http://localhost:11434/v1"
              class="font-mono text-sm"
            />
            <p class="text-xs text-muted-foreground">
              OpenAI-compatible endpoint (e.g. Ollama at localhost:11434).
            </p>
          </div>

          <Separator />

          <!-- Model — text input for custom -->
          <div v-if="showModelTextInput" class="space-y-1.5">
            <Label for="model-input" class="text-sm font-medium">Model</Label>
            <Input
              id="model-input"
              v-model="selectedModel"
              type="text"
              placeholder="llama3.2:3b"
              class="font-mono text-sm"
            />
            <p class="text-xs text-muted-foreground">
              Model name as recognized by your endpoint.
            </p>
          </div>

          <!-- Model — searchable dropdown for OpenRouter -->
          <div v-else-if="selectedProvider === 'openrouter'" class="space-y-1.5">
            <div class="flex items-center justify-between">
              <Label class="text-sm font-medium">Model</Label>
              <div class="flex items-center gap-3">
                <label class="flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground select-none">
                  <input
                    v-model="showFreeOnly"
                    type="checkbox"
                    class="size-3.5 rounded"
                  />
                  <ZapIcon class="size-3" />
                  Free only
                </label>
                <button
                  type="button"
                  class="text-xs text-primary underline-offset-2 hover:underline"
                  @click="showAllModels = !showAllModels"
                >
                  {{ showAllModels ? 'Show popular' : 'Show all models' }}
                </button>
              </div>
            </div>

            <!-- Dropdown trigger -->
            <div class="relative">
              <button
                type="button"
                class="flex w-full items-center justify-between rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm ring-offset-background transition-colors hover:bg-accent focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                @click="modelDropdownOpen = !modelDropdownOpen"
              >
                <span class="truncate font-mono text-sm" :class="{ 'text-muted-foreground': !selectedModel }">
                  {{ selectedModelLabel }}
                </span>
                <ChevronDownIcon class="ml-2 size-4 shrink-0 text-muted-foreground" :class="{ 'rotate-180': modelDropdownOpen }" />
              </button>

              <!-- Dropdown panel -->
              <div
                v-if="modelDropdownOpen"
                class="absolute z-50 mt-1 w-full rounded-md border border-border bg-popover text-popover-foreground shadow-lg"
              >
                <!-- Search -->
                <div class="flex items-center border-b border-border px-3 py-2">
                  <SearchIcon class="mr-2 size-3.5 shrink-0 text-muted-foreground" />
                  <input
                    v-model="modelSearch"
                    type="text"
                    placeholder="Search models…"
                    class="w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                    @click.stop
                  />
                </div>

                <!-- Loading state -->
                <div v-if="modelsLoading" class="p-3 space-y-2">
                  <Skeleton class="h-7 w-full" />
                  <Skeleton class="h-7 w-full" />
                  <Skeleton class="h-7 w-full" />
                </div>

                <!-- Model list -->
                <div v-else-if="filteredModels.length > 0" class="max-h-64 overflow-y-auto">
                  <button
                    v-for="model in filteredModels"
                    :key="model.id"
                    type="button"
                    class="flex w-full items-center justify-between px-3 py-2 text-left text-sm transition-colors hover:bg-accent"
                    :class="{ 'bg-accent font-medium': selectedModel === model.id }"
                    @click="selectModel(model.id)"
                  >
                    <div class="min-w-0 flex-1">
                      <div class="truncate font-mono text-xs text-foreground">{{ model.id }}</div>
                      <div class="truncate text-xs text-muted-foreground">{{ model.name }}</div>
                    </div>
                    <div class="ml-2 flex shrink-0 items-center gap-1.5">
                      <Badge
                        v-if="model.pricing?.prompt === '0'"
                        variant="secondary"
                        class="text-xs py-0 h-4"
                      >Free</Badge>
                      <span v-else-if="model.pricing?.prompt" class="text-xs text-muted-foreground">
                        {{ formatPrice(model.pricing.prompt) }}
                      </span>
                      <span v-if="model.context_length" class="text-xs text-muted-foreground">
                        {{ formatContext(model.context_length) }}
                      </span>
                    </div>
                  </button>
                </div>

                <!-- Empty state -->
                <div v-else class="p-4 text-center text-sm text-muted-foreground">
                  <template v-if="openRouterModels.length === 0">
                    Save an OpenRouter API key first to fetch models.
                  </template>
                  <template v-else>
                    No models match your search.
                  </template>
                </div>
              </div>
            </div>

            <!-- Currently selected display -->
            <p v-if="selectedModel" class="text-xs text-muted-foreground font-mono">
              Selected: {{ selectedModel }}
            </p>
          </div>
        </CardContent>
      </Card>

      <!-- Test result -->
      <div v-if="testResult" class="mb-4">
        <div
          class="rounded-lg border p-4"
          :class="testResult.success
            ? 'border-green-500/40 bg-green-500/5 text-green-700 dark:text-green-400'
            : 'border-destructive/40 bg-destructive/5 text-destructive'"
        >
          <div class="flex items-start gap-3">
            <CheckCircle2Icon v-if="testResult.success" class="mt-0.5 size-4 shrink-0" />
            <XCircleIcon v-else class="mt-0.5 size-4 shrink-0" />
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium">
                {{ testResult.success ? 'Connection successful' : 'Connection failed' }}
              </p>
              <p v-if="testResult.error" class="mt-1 text-xs opacity-80">
                {{ testResult.error }}
              </p>
              <div v-if="testResult.summary" class="mt-2 space-y-1">
                <p class="text-xs font-medium opacity-70 uppercase tracking-wide">Sample summary</p>
                <p class="text-xs opacity-80">{{ testResult.summary }}</p>
              </div>
              <div v-if="testResult.suggested_next_action" class="mt-1">
                <p class="text-xs opacity-80">
                  <span class="font-medium">Next action:</span> {{ testResult.suggested_next_action }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Action buttons -->
      <div class="flex items-center gap-3">
        <Button
          :disabled="saving"
          class="gap-2"
          @click="save"
        >
          <LoaderIcon v-if="saving" class="size-4 animate-spin" />
          Save Settings
        </Button>

        <Button
          variant="outline"
          :disabled="testing"
          class="gap-2"
          @click="testConnection"
        >
          <LoaderIcon v-if="testing" class="size-4 animate-spin" />
          Test Connection
        </Button>
      </div>
    </template>
  </div>
</template>
