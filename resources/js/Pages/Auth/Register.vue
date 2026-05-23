<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Register" />

        <Card class="border-border shadow-sm">
            <CardHeader class="space-y-1 pb-4">
                <CardTitle class="text-2xl font-bold text-foreground">
                    Create an account
                </CardTitle>
                <CardDescription class="text-muted-foreground">
                    Get started with Smart Ticketing System
                </CardDescription>
            </CardHeader>

            <CardContent>
                <form @submit.prevent="submit" class="space-y-4">
                    <!-- Name -->
                    <div class="space-y-1.5">
                        <Label for="name" class="text-foreground">Full name</Label>
                        <Input
                            id="name"
                            type="text"
                            v-model="form.name"
                            placeholder="Jane Smith"
                            required
                            autofocus
                            autocomplete="name"
                            :aria-invalid="!!form.errors.name"
                        />
                        <p v-if="form.errors.name" class="text-sm text-destructive">
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <!-- Email -->
                    <div class="space-y-1.5">
                        <Label for="email" class="text-foreground">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            v-model="form.email"
                            placeholder="you@example.com"
                            required
                            autocomplete="username"
                            :aria-invalid="!!form.errors.email"
                        />
                        <p v-if="form.errors.email" class="text-sm text-destructive">
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <!-- Password -->
                    <div class="space-y-1.5">
                        <Label for="password" class="text-foreground">Password</Label>
                        <Input
                            id="password"
                            type="password"
                            v-model="form.password"
                            placeholder="••••••••"
                            required
                            autocomplete="new-password"
                            :aria-invalid="!!form.errors.password"
                        />
                        <p v-if="form.errors.password" class="text-sm text-destructive">
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <!-- Confirm Password -->
                    <div class="space-y-1.5">
                        <Label for="password_confirmation" class="text-foreground">
                            Confirm password
                        </Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            v-model="form.password_confirmation"
                            placeholder="••••••••"
                            required
                            autocomplete="new-password"
                            :aria-invalid="!!form.errors.password_confirmation"
                        />
                        <p v-if="form.errors.password_confirmation" class="text-sm text-destructive">
                            {{ form.errors.password_confirmation }}
                        </p>
                    </div>

                    <!-- Submit -->
                    <Button
                        type="submit"
                        class="w-full"
                        :disabled="form.processing"
                    >
                        <span v-if="form.processing">Creating account…</span>
                        <span v-else>Create account</span>
                    </Button>
                </form>
            </CardContent>

            <CardFooter class="flex justify-center border-t border-border pt-4">
                <p class="text-sm text-muted-foreground">
                    Already have an account?
                    <Link
                        :href="route('login')"
                        class="font-medium text-primary underline-offset-4 hover:underline"
                    >
                        Sign in
                    </Link>
                </p>
            </CardFooter>
        </Card>
    </GuestLayout>
</template>
