<script setup lang="ts">
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    canResetPassword?: boolean;
    status?: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Sign in" />

        <Card class="border-border shadow-sm">
            <CardHeader class="space-y-1 pb-4">
                <CardTitle class="text-2xl font-bold text-foreground">
                    Welcome back
                </CardTitle>
                <CardDescription class="text-muted-foreground">
                    Sign in to your account to continue
                </CardDescription>
            </CardHeader>

            <CardContent>
                <!-- Status message (e.g., after password reset) -->
                <div
                    v-if="status"
                    class="mb-4 rounded-md bg-primary/10 px-4 py-3 text-sm font-medium text-primary"
                >
                    {{ status }}
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <!-- Email -->
                    <div class="space-y-1.5">
                        <Label for="email" class="text-foreground">Email</Label>
                        <Input
                            id="email"
                            type="email"
                            v-model="form.email"
                            placeholder="you@example.com"
                            required
                            autofocus
                            autocomplete="username"
                            :aria-invalid="!!form.errors.email"
                        />
                        <p v-if="form.errors.email" class="text-sm text-destructive">
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <!-- Password -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <Label for="password" class="text-foreground">Password</Label>
                            <Link
                                v-if="canResetPassword"
                                :href="route('password.request')"
                                class="text-sm text-muted-foreground underline-offset-4 hover:text-primary hover:underline"
                            >
                                Forgot password?
                            </Link>
                        </div>
                        <Input
                            id="password"
                            type="password"
                            v-model="form.password"
                            placeholder="••••••••"
                            required
                            autocomplete="current-password"
                            :aria-invalid="!!form.errors.password"
                        />
                        <p v-if="form.errors.password" class="text-sm text-destructive">
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <!-- Remember me -->
                    <div class="flex items-center gap-2">
                        <Checkbox
                            id="remember"
                            name="remember"
                            v-model:checked="form.remember"
                        />
                        <Label
                            for="remember"
                            class="cursor-pointer text-sm font-normal text-muted-foreground"
                        >
                            Remember me for 30 days
                        </Label>
                    </div>

                    <!-- Submit -->
                    <Button
                        type="submit"
                        class="w-full"
                        :disabled="form.processing"
                    >
                        <span v-if="form.processing">Signing in…</span>
                        <span v-else>Sign in</span>
                    </Button>
                </form>
            </CardContent>

            <CardFooter class="flex justify-center border-t border-border pt-4">
                <p class="text-sm text-muted-foreground">
                    Don't have an account?
                    <Link
                        :href="route('register')"
                        class="font-medium text-primary underline-offset-4 hover:underline"
                    >
                        Create one
                    </Link>
                </p>
            </CardFooter>
        </Card>
    </GuestLayout>
</template>
