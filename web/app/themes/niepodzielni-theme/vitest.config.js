import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'node',
        include: ['resources/js/__tests__/**/*.test.js'],
        reporters: ['verbose'],
    },
});
