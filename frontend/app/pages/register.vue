<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle, CardDescription, CardFooter } from '~/components/ui/card'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Label } from '~/components/ui/label'
import { Loader2 } from 'lucide-vue-next'

definePageMeta({ layout: 'auth', middleware: 'guest' })

const auth = useAuthStore()

const form = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})
const loading = ref(false)
const error = ref('')

async function onSubmit() {
  error.value = ''
  loading.value = true
  try {
    await auth.register({ ...form })
    await navigateTo('/')
  } catch (e: any) {
    const errors = e?.data?.errors
    error.value = errors
      ? Object.values(errors).flat()[0] as string
      : (e?.data?.message || 'Registration failed. Please try again.')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Card class="border-border/80 shadow-2xl shadow-black/40">
    <CardHeader>
      <CardTitle class="text-lg">Create account</CardTitle>
      <CardDescription>Start running awareness campaigns</CardDescription>
    </CardHeader>
    <CardContent>
      <form class="space-y-4" @submit.prevent="onSubmit">
        <div class="space-y-2">
          <Label for="name">Full name</Label>
          <Input id="name" v-model="form.name" type="text" placeholder="Jane Doe" autocomplete="name" required />
        </div>
        <div class="space-y-2">
          <Label for="email">Email</Label>
          <Input id="email" v-model="form.email" type="email" placeholder="you@company.com" autocomplete="email" required />
        </div>
        <div class="space-y-2">
          <Label for="password">Password</Label>
          <Input id="password" v-model="form.password" type="password" placeholder="••••••••" autocomplete="new-password" required />
        </div>
        <div class="space-y-2">
          <Label for="password_confirmation">Confirm password</Label>
          <Input id="password_confirmation" v-model="form.password_confirmation" type="password" placeholder="••••••••" autocomplete="new-password" required />
        </div>

        <p v-if="error" class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {{ error }}
        </p>

        <Button type="submit" class="w-full" :disabled="loading">
          <Loader2 v-if="loading" class="size-4 animate-spin" />
          {{ loading ? 'Creating…' : 'Create account' }}
        </Button>
      </form>
    </CardContent>
    <CardFooter class="justify-center">
      <p class="text-sm text-muted-foreground">
        Already registered?
        <NuxtLink to="/login" class="font-medium text-primary hover:underline">Sign in</NuxtLink>
      </p>
    </CardFooter>
  </Card>
</template>
