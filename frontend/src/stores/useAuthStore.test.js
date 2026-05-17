import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useAuthStore } from '@/stores/useAuthStore.js';

// Mock the useApi composable
vi.mock('@/composables/useApi.js', () => ({
    useApi: vi.fn(),
    resetCsrf: vi.fn(),
}));

import { useApi, resetCsrf } from '@/composables/useApi.js';

describe('useAuthStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    // ── initial state ─────────────────────────────────────────────────────────

    it('starts unauthenticated', () => {
        const store = useAuthStore();
        expect(store.isAuthenticated).toBe(false);
        expect(store.user).toBeNull();
    });

    it('displayName is empty when not logged in', () => {
        const store = useAuthStore();
        expect(store.displayName).toBe('');
    });

    it('role is empty when not logged in', () => {
        const store = useAuthStore();
        expect(store.role).toBe('');
    });

    // ── login ─────────────────────────────────────────────────────────────────

    it('sets user on successful login', async () => {
        const mockUser = { id: 1, display_name: 'Alice', role: 'admin' };
        useApi.mockReturnValue({
            execute: vi.fn().mockResolvedValue({ success: true, data: { user: mockUser } }),
        });

        const store = useAuthStore();
        const result = await store.login('alice@example.com', 'password123');

        expect(result.success).toBe(true);
        expect(store.user).toEqual(mockUser);
        expect(store.isAuthenticated).toBe(true);
        expect(store.displayName).toBe('Alice');
        expect(store.role).toBe('admin');
    });

    it('returns error on failed login', async () => {
        useApi.mockReturnValue({
            execute: vi.fn().mockResolvedValue({ success: false, error: 'Ungültige Anmeldedaten' }),
        });

        const store = useAuthStore();
        const result = await store.login('bad@example.com', 'wrong');

        expect(result.success).toBe(false);
        expect(result.error).toBe('Ungültige Anmeldedaten');
        expect(store.user).toBeNull();
    });

    it('returns generic error when API returns no error field', async () => {
        useApi.mockReturnValue({
            execute: vi.fn().mockResolvedValue({ success: false }),
        });

        const store = useAuthStore();
        const result = await store.login('x@x.de', 'y');
        expect(result.success).toBe(false);
        expect(result.error).toBeTruthy();
    });

    // ── logout ────────────────────────────────────────────────────────────────

    it('clears user on logout', async () => {
        const store = useAuthStore();
        // Pre-populate a user
        store.user = { id: 1, display_name: 'Alice', role: 'admin' };

        useApi.mockReturnValue({ execute: vi.fn().mockResolvedValue({}) });

        await store.logout();

        expect(store.user).toBeNull();
        expect(store.isAuthenticated).toBe(false);
        expect(resetCsrf).toHaveBeenCalled();
    });

    // ── fetchUser ─────────────────────────────────────────────────────────────

    it('sets user on successful fetchUser', async () => {
        const mockUser = { id: 2, display_name: 'Bob', role: 'isb' };
        useApi.mockReturnValue({
            execute: vi.fn().mockResolvedValue({ success: true, data: { user: mockUser } }),
        });

        const store = useAuthStore();
        await store.fetchUser();

        expect(store.user).toEqual(mockUser);
        expect(store.isAuthenticated).toBe(true);
    });

    it('clears user and throws on failed fetchUser', async () => {
        useApi.mockReturnValue({
            execute: vi.fn().mockResolvedValue({ success: false }),
        });

        const store = useAuthStore();
        store.user = { id: 1, display_name: 'Stale', role: 'admin' };

        await expect(store.fetchUser()).rejects.toThrow();
        expect(store.user).toBeNull();
    });
});
