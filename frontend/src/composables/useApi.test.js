import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// We test the module-level logic in useApi, so we import the real implementation
// but mock global fetch.
import { useApi, resetCsrf } from '@/composables/useApi.js';

describe('useApi composable', () => {
    let fetchSpy;

    beforeEach(() => {
        // Reset CSRF state between tests
        resetCsrf();

        fetchSpy = vi.spyOn(globalThis, 'fetch');
    });

    afterEach(() => {
        vi.restoreAllMocks();
        resetCsrf();
    });

    // ── CSRF token injection ─────────────────────────────────────────────────

    it('fetches CSRF token before the first POST request', async () => {
        // First call returns the CSRF token
        fetchSpy
            .mockResolvedValueOnce(mockJsonResponse({ data: { csrf_token: 'test-csrf-token' } }))
            // Second call is the actual POST
            .mockResolvedValueOnce(mockJsonResponse({ success: true, data: {} }));

        const { execute } = useApi('/api/some-endpoint', { method: 'POST' });
        await execute({ body: { foo: 'bar' } });

        expect(fetchSpy).toHaveBeenCalledTimes(2);
        // First call should be the CSRF endpoint
        expect(fetchSpy.mock.calls[0][0]).toContain('/api/auth/csrf-token');
        // Second call should include X-CSRF-Token header
        const [, secondOptions] = fetchSpy.mock.calls[1];
        expect(secondOptions.headers['X-CSRF-Token']).toBe('test-csrf-token');
    });

    it('reuses cached CSRF token for subsequent POST requests', async () => {
        // CSRF token fetch
        fetchSpy
            .mockResolvedValueOnce(mockJsonResponse({ data: { csrf_token: 'cached-token' } }))
            .mockResolvedValueOnce(mockJsonResponse({ success: true, data: {} }))
            .mockResolvedValueOnce(mockJsonResponse({ success: true, data: {} }));

        const { execute: execute1 } = useApi('/api/a', { method: 'POST' });
        const { execute: execute2 } = useApi('/api/b', { method: 'POST' });

        await execute1({ body: {} });
        await execute2({ body: {} });

        // fetch was called 3 times: CSRF + 2 POSTs (not 4 — no second CSRF fetch)
        expect(fetchSpy).toHaveBeenCalledTimes(3);
        expect(fetchSpy.mock.calls[0][0]).toContain('csrf-token');
    });

    it('does NOT inject CSRF token for GET requests', async () => {
        fetchSpy.mockResolvedValueOnce(mockJsonResponse({ success: true, data: {} }));

        const { execute } = useApi('/api/items');
        await execute();

        expect(fetchSpy).toHaveBeenCalledTimes(1);
        const [, options] = fetchSpy.mock.calls[0];
        expect(options?.headers?.['X-CSRF-Token']).toBeUndefined();
    });

    // ── response handling ─────────────────────────────────────────────────────

    it('returns parsed JSON on success', async () => {
        fetchSpy.mockResolvedValueOnce(mockJsonResponse({ success: true, data: { items: [1, 2, 3] } }));

        const { execute } = useApi('/api/items');
        const result = await execute();

        expect(result.success).toBe(true);
        expect(result.data.items).toEqual([1, 2, 3]);
    });

    it('returns error field on API failure', async () => {
        fetchSpy.mockResolvedValueOnce(
            mockJsonResponse({ success: false, error: 'Not found' }, 404)
        );

        const { execute } = useApi('/api/items/999');
        const result = await execute();

        expect(result.success).toBe(false);
        expect(result.error).toBe('Not found');
    });
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function mockJsonResponse(body, status = 200) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}
