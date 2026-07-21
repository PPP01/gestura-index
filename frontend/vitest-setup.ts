import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/svelte';
import { afterEach } from 'vitest';

// @testing-library/svelte räumt Komponenten nach jedem Test ab; mit
// `test.globals: true` überspringt der svelteTesting()-Vite-Plugin seinen
// eigenen Auto-Cleanup-Hook, daher hier explizit registriert.
afterEach(() => cleanup());
