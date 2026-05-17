// Global test setup — runs before every test file
import { vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

// Stub window.location so navigation tests don't break jsdom/happy-dom
Object.defineProperty(window, 'location', {
    value: { href: '', assign: vi.fn(), replace: vi.fn() },
    writable: true,
});

beforeEach(() => {
    setActivePinia(createPinia());
});
